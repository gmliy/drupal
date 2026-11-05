<?php

namespace Drupal\xedc_tags;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;

/**
 * Synonym / history aliases that map to an existing tags term.
 *
 * Aliases are not taxonomy terms and never rewrite published content.
 */
final class TagAliasManager {

  public const TYPES = [
    'synonym' => '同义词',
    'near' => '近义词',
    'typo' => '常见错别字',
    'history' => '历史名称',
    'en' => '英文名称',
    'zh' => '中文名称',
    'trad' => '简繁体映射',
    'pinyin' => '拼音映射',
  ];

  public function __construct(
    private Connection $database,
    private TagCatalog $catalog,
    private TagAudit $audit,
    private AccountInterface $currentUser,
    private TimeInterface $time,
    private CacheTagsInvalidatorInterface $cacheTags,
  ) {}

  public function ready(): bool {
    return $this->database->schema()->tableExists('xedc_tag_alias');
  }

  /**
   * @return object[]
   */
  public function list(string $q = '', int $tid = 0, string $status = '', int $limit = 100): array {
    if (!$this->ready()) {
      return [];
    }
    $query = $this->database->select('xedc_tag_alias', 'a')->fields('a')->orderBy('id', 'DESC')->range(0, $limit);
    if ($q !== '') {
      $query->condition('alias', '%' . $this->database->escapeLike($q) . '%', 'LIKE');
    }
    if ($tid > 0) {
      $query->condition('tid', $tid);
    }
    if ($status === 'active' || $status === 'disabled') {
      $query->condition('status', $status);
    }
    return $query->execute()->fetchAll();
  }

  public function load(int $id): ?object {
    if ($id <= 0 || !$this->ready()) {
      return NULL;
    }
    $row = $this->database->select('xedc_tag_alias', 'a')->fields('a')->condition('id', $id)->execute()->fetch();
    return $row ?: NULL;
  }

  /**
   * @return array{ok: bool, message: string, id: int}
   */
  public function save(array $values, ?int $uid = NULL): array {
    if (!$this->ready()) {
      return ['ok' => FALSE, 'message' => '别名表尚未安装。', 'id' => 0];
    }
    $alias = TagText::normalize((string) ($values['alias'] ?? ''));
    $display = trim((string) ($values['alias'] ?? ''));
    $tid = (int) ($values['tid'] ?? 0);
    $type = (string) ($values['type'] ?? 'synonym');
    $status = (string) ($values['status'] ?? 'active');
    $source = trim((string) ($values['source'] ?? 'manual'));
    $id = (int) ($values['id'] ?? 0);
    $terms = $this->catalog->terms();
    if ($display === '' || $alias === '') {
      return ['ok' => FALSE, 'message' => '别名不能为空。', 'id' => 0];
    }
    if (!isset($terms[$tid])) {
      return ['ok' => FALSE, 'message' => '目标标签不存在。', 'id' => 0];
    }
    if (!isset(self::TYPES[$type])) {
      return ['ok' => FALSE, 'message' => '别名类型无效。', 'id' => 0];
    }
    if (!in_array($status, ['active', 'disabled'], TRUE)) {
      return ['ok' => FALSE, 'message' => '状态无效。', 'id' => 0];
    }
    foreach ($terms as $term) {
      if (TagText::normalize((string) $term['default_name']) === $alias) {
        return ['ok' => FALSE, 'message' => '别名与现有标签名称冲突，请改用标签合并。', 'id' => 0];
      }
    }
    $existing = $this->database->select('xedc_tag_alias', 'a')
      ->fields('a', ['id', 'tid'])
      ->condition('normalized', $alias)
      ->execute()
      ->fetch();
    if ($existing && (int) $existing->id !== $id) {
      return ['ok' => FALSE, 'message' => '该别名已映射到标签 #' . $existing->tid . '。', 'id' => 0];
    }
    $fields = [
      'tid' => $tid,
      'alias' => mb_substr($display, 0, 191),
      'normalized' => mb_substr($alias, 0, 191),
      'type' => $type,
      'status' => $status,
      'source' => mb_substr($source, 0, 64),
      'uid' => $uid ?? (int) $this->currentUser->id(),
      'changed' => $this->time->getRequestTime(),
    ];
    if ($id > 0) {
      $this->database->update('xedc_tag_alias')->fields($fields)->condition('id', $id)->execute();
      $this->audit->log('alias_update', '更新别名 ' . $display, $fields, $tid, $uid);
      $this->cacheTags->invalidateTags($this->catalog->cacheTags());
      return ['ok' => TRUE, 'message' => '别名已更新。', 'id' => $id];
    }
    $fields['created'] = $this->time->getRequestTime();
    $id = (int) $this->database->insert('xedc_tag_alias')->fields($fields)->execute();
    $this->audit->log('alias_create', '新增别名 ' . $display, $fields, $tid, $uid);
    $this->cacheTags->invalidateTags($this->catalog->cacheTags());
    return ['ok' => TRUE, 'message' => '别名已创建。', 'id' => $id];
  }

  /**
   * @return array{ok: bool, message: string}
   */
  public function setStatus(int $id, string $status, ?int $uid = NULL): array {
    $row = $this->load($id);
    if (!$row) {
      return ['ok' => FALSE, 'message' => '别名不存在。'];
    }
    if (!in_array($status, ['active', 'disabled'], TRUE)) {
      return ['ok' => FALSE, 'message' => '状态无效。'];
    }
    $this->database->update('xedc_tag_alias')->fields([
      'status' => $status,
      'changed' => $this->time->getRequestTime(),
    ])->condition('id', $id)->execute();
    $this->audit->log('alias_status', $status . ' ' . $row->alias, ['id' => $id], (int) $row->tid, $uid);
    $this->cacheTags->invalidateTags($this->catalog->cacheTags());
    return ['ok' => TRUE, 'message' => '别名状态已更新。'];
  }

  /**
   * @return array{ok: bool, message: string}
   */
  public function delete(int $id, ?int $uid = NULL): array {
    $row = $this->load($id);
    if (!$row) {
      return ['ok' => FALSE, 'message' => '别名不存在。'];
    }
    $this->database->delete('xedc_tag_alias')->condition('id', $id)->execute();
    $this->audit->log('alias_delete', '删除别名 ' . $row->alias, ['id' => $id], (int) $row->tid, $uid);
    $this->cacheTags->invalidateTags($this->catalog->cacheTags());
    return ['ok' => TRUE, 'message' => '别名已删除。'];
  }

  /**
   * Suggest standard tags for an alias input. Does not mutate content.
   *
   * @return array<int, array{tid: int, name: string, score: int, reason: string}>
   */
  public function suggestTargets(string $input): array {
    $needle = TagText::normalize($input);
    if ($needle === '') {
      return [];
    }
    $out = [];
    foreach ($this->catalog->terms() as $tid => $term) {
      $name = TagText::normalize((string) $term['default_name']);
      if ($name === '') {
        continue;
      }
      if ($name === $needle) {
        $out[] = ['tid' => (int) $tid, 'name' => (string) $term['name'], 'score' => 100, 'reason' => '名称完全匹配'];
        continue;
      }
      $compared = TagText::compare((string) $term['default_name'], $input);
      if ($compared) {
        $out[] = [
          'tid' => (int) $tid,
          'name' => (string) $term['name'],
          'score' => !empty($compared['recommend_merge']) ? 90 : 70,
          'reason' => implode('；', $compared['reasons']),
        ];
      }
      elseif (mb_strpos($name, $needle) !== FALSE || mb_strpos($needle, $name) !== FALSE) {
        $out[] = ['tid' => (int) $tid, 'name' => (string) $term['name'], 'score' => 55, 'reason' => '名称包含关系'];
      }
    }
    usort($out, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice($out, 0, 8);
  }

  public function resolve(string $input): ?int {
    if (!$this->ready()) {
      return NULL;
    }
    $normalized = TagText::normalize($input);
    if ($normalized === '') {
      return NULL;
    }
    $tid = $this->database->select('xedc_tag_alias', 'a')
      ->fields('a', ['tid'])
      ->condition('normalized', $normalized)
      ->condition('status', 'active')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $tid ? (int) $tid : NULL;
  }

}
