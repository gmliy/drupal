<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\Engagement\DownloadAccess;
use Drupal\xedc_core\Engagement\MembershipManager;
use Drupal\xedc_core\Entity\DownloadResource;

/**
 * Member and VIP checks for a private file. It does not issue a URL.
 */
final class DownloadGate {

  public function __construct(
    protected EntityTypeManagerInterface $entities,
    protected Connection $database,
    protected FileSystemInterface $fileSystem,
    protected StorageConfig $config,
    protected StorageUriResolver $uris,
    protected StorageJournal $journal,
    protected TimeInterface $time,
    protected MembershipManager $membership,
    protected DownloadAccess $downloads,
  ) {}

  /**
   * @return array{allow: bool, status: int, message: string, tier: string, required: string}
   *   Decision. A URL is never included.
   */
  public function decide(int $fid, AccountInterface $account): array {
    $tier = $this->tier($account);
    if (!$account->isAuthenticated() || $tier === 'anonymous') {
      $this->log($fid, (int) $account->id(), 'anonymous', 'denied');
      return $this->deny(403, '匿名用户不能访问受保护文件。', 'anonymous', 'member');
    }
    $file = $this->entities->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      $this->log($fid, (int) $account->id(), $tier, 'missing');
      return $this->deny(404, '文件不存在。', $tier, 'member');
    }
    $uri = $file->getFileUri();
    if (!$this->uris->isPrivate($uri)) {
      $this->log($fid, (int) $account->id(), $tier, 'public');
      return $this->deny(403, '此入口只接受对象存储中的受保护文件，不会读取本地公开文件。', $tier, 'member');
    }
    if (!$this->exists($file)) {
      $this->log($fid, (int) $account->id(), $tier, 'missing');
      return $this->deny(404, '文件记录存在，但文件内容不存在。', $tier, 'member');
    }
    $resource = $this->resourceDecision($file, $account);
    if (is_array($resource) && empty($resource['allow'])) {
      $this->log($fid, (int) $account->id(), (string) $resource['tier'], 'resource');
      return $resource;
    }
    $linkedByResource = is_array($resource) && !empty($resource['allow']);
    $nodes = $this->nodes($fid);
    if (!$linkedByResource && $nodes === [] && $tier !== 'admin') {
      $this->log($fid, (int) $account->id(), $tier, 'unlinked');
      return $this->deny(403, '文件没有挂在可下载的内容上。', $tier, 'member');
    }
    $required = $linkedByResource ? (string) $resource['required'] : 'member';
    $visible = $linkedByResource || ($tier === 'admin' && $nodes === []);
    if (!$linkedByResource) {
      foreach ($nodes as $node) {
        if ($node->access('view', $account)) {
          $visible = TRUE;
        }
        if ($this->requiresVip($node)) {
          $required = 'vip';
        }
      }
    }
    if (!$visible) {
      $this->log($fid, (int) $account->id(), $tier, 'forbidden');
      return $this->deny(403, '当前用户不能下载这个内容的文件。', $tier, $required);
    }
    if ($required === 'vip' && !in_array($tier, ['vip', 'admin'], TRUE)) {
      $this->log($fid, (int) $account->id(), $tier, 'vip');
      return $this->deny(403, '这个文件只允许 VIP 会员下载。', $tier, 'vip');
    }
    $limit = $this->limit($tier);
    if ($limit > 0 && $this->usedToday((int) $account->id()) >= $limit) {
      $this->log($fid, (int) $account->id(), $tier, 'limit');
      return $this->deny(429, '今日下载次数已用完。', $tier, $required);
    }
    if ($this->uris->scheme($uri) === 'private' && !(bool) \Drupal\Core\Site\Settings::get('xedc_storage.private_takeover', FALSE)) {
      $this->log($fid, (int) $account->id(), $tier, 'local');
      return $this->deny(503, '私有文件仍在服务器本地。私有接管未开启，因此不会生成对象存储签名。', $tier, $required);
    }
    if ($this->uris->scheme($uri) === 's3' && !(bool) \Drupal\Core\Site\Settings::get('xedc_storage.settings_accepted', FALSE)) {
      $this->log($fid, (int) $account->id(), $tier, 'runtime');
      return $this->deny(503, '对象存储运行时未启用，不能生成下载地址。', $tier, $required);
    }
    $this->log($fid, (int) $account->id(), $tier, 'allowed');
    return [
      'allow' => TRUE,
      'status' => 302,
      'message' => '',
      'tier' => $tier,
      'required' => $required,
    ];
  }

  private function tier(AccountInterface $account): string {
    if (!$account->isAuthenticated()) {
      return 'anonymous';
    }
    $roles = $account->getRoles();
    if ($account->hasPermission('administer xedc object storage') || in_array('administrator', $roles, TRUE)) {
      return 'admin';
    }
    return $this->membership->tier($account);
  }

  private function exists(FileInterface $file): bool {
    $uri = $file->getFileUri();
    if ($this->uris->isRemote($uri)) {
      return is_file($uri);
    }
    $real = $this->fileSystem->realpath($uri);
    return is_string($real) && is_file($real) && !is_link($real);
  }

  /**
   * @return array{allow: bool, status: int, message: string, tier: string, required: string}|null
   *   NULL when this file is not a download resource.
   */
  private function resourceDecision(FileInterface $file, AccountInterface $account): ?array {
    if (!$this->entities->hasDefinition('xedc_download')) {
      return NULL;
    }
    $ids = $this->entities->getStorage('xedc_download')->getQuery()
      ->accessCheck(FALSE)
      ->condition('file', (int) $file->id())
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $denial = NULL;
    foreach ($this->entities->getStorage('xedc_download')->loadMultiple($ids) as $resource) {
      if (!$resource instanceof DownloadResource) {
        continue;
      }
      $decision = $this->downloads->decide($resource, $account, (int) $file->id());
      if (!empty($decision['allow'])) {
        return $decision;
      }
      $denial = $decision;
    }
    return $denial;
  }

  /**
   * @return list<\Drupal\node\NodeInterface>
   *   Nodes that reference the file.
   */
  private function nodes(int $fid): array {
    if (!$this->database->schema()->tableExists('file_usage')) {
      return [];
    }
    $ids = $this->database->select('file_usage', 'u')
      ->fields('u', ['id'])
      ->condition('fid', $fid)
      ->condition('type', 'node')
      ->execute()
      ->fetchCol();
    $nodes = [];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
      $node = $this->entities->getStorage('node')->load($id);
      if ($node instanceof NodeInterface) {
        $nodes[] = $node;
      }
    }
    return $nodes;
  }

  private function requiresVip(NodeInterface $node): bool {
    foreach ($node->getFieldDefinitions() as $name => $definition) {
      if (!str_contains($name, 'vip') || !$node->hasField($name) || $node->get($name)->isEmpty()) {
        continue;
      }
      $value = $node->get($name)->value;
      if ($value === 1 || $value === '1' || $value === TRUE || $value === 'vip') {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function limit(string $tier): int {
    $data = $this->config->data();
    $key = $tier === 'vip' || $tier === 'admin' ? 'vip_daily_limit' : 'member_daily_limit';
    return max(0, (int) ($data[$key] ?? 0));
  }

  private function usedToday(int $uid): int {
    if ($uid < 1 || !$this->database->schema()->tableExists('xedc_storage_download_log')) {
      return 0;
    }
    $start = (int) strtotime('today');
    return (int) $this->database->select('xedc_storage_download_log', 'd')
      ->condition('uid', $uid)
      ->condition('result', 'allowed')
      ->condition('created', $start, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  private function log(int $fid, int $uid, string $tier, string $result): void {
    if (!$this->database->schema()->tableExists('xedc_storage_download_log')) {
      return;
    }
    $this->database->insert('xedc_storage_download_log')
      ->fields([
        'fid' => $fid,
        'uid' => $uid,
        'tier' => substr($tier, 0, 32),
        'result' => substr($result, 0, 32),
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
    $this->journal->add($result === 'allowed' ? 'info' : 'error', '下载校验 ' . $result . '，文件 ' . $fid . '。');
  }

  /**
   * @return array{allow: bool, status: int, message: string, tier: string, required: string}
   *   Denial.
   */
  private function deny(int $status, string $message, string $tier, string $required): array {
    return [
      'allow' => FALSE,
      'status' => $status,
      'message' => $message,
      'tier' => $tier,
      'required' => $required,
    ];
  }

}
