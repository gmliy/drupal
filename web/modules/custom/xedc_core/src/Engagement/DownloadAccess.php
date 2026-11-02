<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\Entity\DownloadResource;

/**
 * Server-side decision for one download resource.
 */
final class DownloadAccess {

  public function __construct(
    protected MembershipManager $membership,
    protected OriginalFiles $originals,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * @return array{allow: bool, status: int, message: string, tier: string, required: string}
   *   The decision. It never includes a file URL.
   */
  public function decide(DownloadResource $resource, AccountInterface $account, ?int $fid = NULL): array {
    $tier = $this->membership->tier($account);
    $required = (string) $resource->get('access_level')->value;
    if (!in_array($required, ['public', 'authenticated', 'vip'], TRUE)) {
      return $this->deny(404, '下载资源不可用。', $tier, 'public');
    }
    if (!$resource->isPublished()) {
      return $this->deny(404, '下载资源已停用。', $tier, $required);
    }
    $node = $resource->get('parent')->entity;
    if (!$node instanceof NodeInterface || !$node->isPublished() || !$node->access('view', $account)) {
      return $this->deny(404, '内容不存在或当前不能访问。', $tier, $required);
    }
    if (!in_array($node->bundle(), ['photo', 'video', 'article'], TRUE)) {
      return $this->deny(404, '这类内容不能下载。', $tier, $required);
    }
    if ($required === 'authenticated' && !$account->isAuthenticated()) {
      return $this->deny(403, '请登录后下载。', $tier, $required);
    }
    if ($required === 'vip' && $tier !== 'vip') {
      $message = $account->isAuthenticated()
        ? '这是 VIP 专属资源。你当前是普通会员。'
        : '这是 VIP 专属资源。请先登录，并由管理员开通 VIP。';
      return $this->deny(403, $message, $tier, $required);
    }
    $source = (string) $resource->get('source_type')->value;
    if ($source === 'upload') {
      $file = $resource->get('file')->entity;
      if (!$file instanceof FileInterface) {
        return $this->deny(404, '文件不存在。', $tier, $required);
      }
      if ((int) $file->id() !== (int) $resource->get('file')->target_id) {
        return $this->deny(403, '文件与资源不匹配。', $tier, $required);
      }
      $uri = (string) $file->getFileUri();
      if (!str_starts_with($uri, 'private://xedc-downloads/') || !$this->exists($uri)) {
        return $this->deny(404, '文件不存在。', $tier, $required);
      }
    }
    elseif ($source === 'external') {
      if ($required === 'vip') {
        return $this->deny(403, 'VIP 资源不能使用外部永久链接。', $tier, $required);
      }
      try {
        $resource->externalUrl();
      }
      catch (\InvalidArgumentException) {
        return $this->deny(404, '下载地址无效。', $tier, $required);
      }
    }
    elseif ($source === 'originals') {
      if ($node->bundle() !== 'photo' || $fid === NULL || $this->originals->one($node, $fid) === NULL) {
        return $this->deny(404, '原图不存在。', $tier, $required);
      }
    }
    elseif ($source === 'zip') {
      if ($node->bundle() !== 'photo' || $this->originals->forNode($node) === []) {
        return $this->deny(404, '没有可打包的原图。', $tier, $required);
      }
    }
    else {
      return $this->deny(404, '下载方式无效。', $tier, $required);
    }
    return [
      'allow' => TRUE,
      'status' => 200,
      'message' => '',
      'tier' => $tier,
      'required' => $required,
    ];
  }

  protected function exists(string $uri): bool {
    $real = $this->fileSystem->realpath($uri);
    if (is_string($real)) {
      return is_file($real) && !is_link($real);
    }
    return is_file($uri);
  }

  /**
   * @return array{allow: bool, status: int, message: string, tier: string, required: string}
   *   A denial.
   */
  protected function deny(int $status, string $message, string $tier, string $required): array {
    return [
      'allow' => FALSE,
      'status' => $status,
      'message' => $message,
      'tier' => $tier,
      'required' => $required,
    ];
  }

}
