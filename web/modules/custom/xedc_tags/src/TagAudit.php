<?php

namespace Drupal\xedc_tags;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;

/**
 * Append-only audit log, review decisions and merge records.
 */
final class TagAudit {

  public function __construct(
    private Connection $database,
    private AccountInterface $currentUser,
    private TimeInterface $time,
  ) {}

  public function log(string $op, string $message, array $context = [], int $tid = 0, ?int $uid = NULL): void {
    if (!$this->database->schema()->tableExists('xedc_tag_audit')) {
      return;
    }
    $this->database->insert('xedc_tag_audit')->fields([
      'uid' => $uid ?? (int) $this->currentUser->id(),
      'op' => mb_substr($op, 0, 64),
      'tid' => $tid,
      'message' => $message,
      'context' => json_encode($context, JSON_UNESCAPED_UNICODE) ?: '{}',
      'created' => $this->time->getRequestTime(),
    ])->execute();
  }

  /**
   * @return array<string, object>
   */
  public function decisions(string $kind = ''): array {
    if (!$this->database->schema()->tableExists('xedc_tag_decision')) {
      return [];
    }
    $query = $this->database->select('xedc_tag_decision', 'd');
    $query->fields('d');
    if ($kind !== '') {
      $query->condition('kind', $kind);
    }
    $out = [];
    foreach ($query->execute() as $row) {
      $out[$row->kind . ':' . $row->subject] = $row;
    }
    return $out;
  }

  public function decide(string $kind, string $subject, string $decision, string $note = '', ?int $uid = NULL): void {
    if (!$this->database->schema()->tableExists('xedc_tag_decision')) {
      throw new \RuntimeException('标签决策表还不存在。');
    }
    $this->database->merge('xedc_tag_decision')
      ->keys(['kind' => $kind, 'subject' => $subject])
      ->fields([
        'decision' => $decision,
        'uid' => $uid ?? (int) $this->currentUser->id(),
        'note' => $note,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
    $this->log($kind, $decision . ' ' . $subject, ['note' => $note], 0, $uid);
  }

  public function dismissedPair(int $left, int $right): bool {
    $subject = $this->pairSubject($left, $right);
    $decisions = $this->decisions('duplicate');
    return isset($decisions['duplicate:' . $subject]);
  }

  public function pairSubject(int $left, int $right): string {
    $ids = [min($left, $right), max($left, $right)];
    return $ids[0] . ':' . $ids[1];
  }

  /**
   * @return object[]
   */
  public function merges(int $limit = 20): array {
    if (!$this->database->schema()->tableExists('xedc_tag_merge')) {
      return [];
    }
    return $this->database->select('xedc_tag_merge', 'm')
      ->fields('m')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  public function merge(int $id): ?object {
    if ($id <= 0 || !$this->database->schema()->tableExists('xedc_tag_merge')) {
      return NULL;
    }
    $row = $this->database->select('xedc_tag_merge', 'm')->fields('m')->condition('id', $id)->execute()->fetch();
    return $row ?: NULL;
  }

  /**
   * @return object[]
   */
  public function history(int $limit = 50, int $tid = 0): array {
    if (!$this->database->schema()->tableExists('xedc_tag_audit')) {
      return [];
    }
    $query = $this->database->select('xedc_tag_audit', 'a')->fields('a')->orderBy('id', 'DESC')->range(0, $limit);
    if ($tid > 0) {
      $query->condition('tid', $tid);
    }
    return $query->execute()->fetchAll();
  }

  public function sourceNotice(int $tid): ?object {
    if (!$this->database->schema()->tableExists('xedc_tag_merge')) {
      return NULL;
    }
    $rows = $this->database->select('xedc_tag_merge', 'm')
      ->fields('m')
      ->condition('status', 'completed')
      ->orderBy('id', 'DESC')
      ->range(0, 30)
      ->execute();
    foreach ($rows as $row) {
      $sources = array_map('intval', array_filter(explode(',', (string) $row->sources)));
      if (in_array($tid, $sources, TRUE)) {
        return $row;
      }
    }
    return NULL;
  }

}
