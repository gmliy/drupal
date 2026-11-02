<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;

/**
 * Reads stored SHA-256 and pHash rows. It does not download files.
 */
final class DuplicateFinder {

  public function __construct(
    private readonly Connection $database,
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * @return array{groups: list<array<string, mixed>>, scanned: int, limited: bool}
   *   Exact hash groups.
   */
  public function exact(int $limit = 40): array {
    if (!$this->database->schema()->tableExists('xedc_ai_filehash')) {
      return ['groups' => [], 'scanned' => 0, 'limited' => FALSE];
    }
    $query = $this->database->select('xedc_ai_filehash', 'f');
    $query->addField('f', 'sha256');
    $query->addExpression('COUNT(*)', 'copies');
    $query->condition('sha256', '', '<>');
    $query->groupBy('sha256');
    $query->having('COUNT(*) > 1');
    $query->orderBy('copies', 'DESC');
    $query->range(0, $limit);
    $groups = [];
    foreach ($query->execute() as $row) {
      $files = $this->filesForHash((string) $row->sha256);
      $groups[] = [
        'sha256' => (string) $row->sha256,
        'copies' => (int) $row->copies,
        'files' => $files,
      ];
    }
    return [
      'groups' => $groups,
      'scanned' => $this->hashedCount(),
      'limited' => FALSE,
    ];
  }

  /**
   * @return array{pairs: list<array<string, mixed>>, scanned: int, limited: bool}
   *   Suspected image pairs. pHash is a hint, not a final decision.
   */
  public function similar(int $distance = 10, int $scanLimit = 600, int $pairLimit = 30): array {
    if (!$this->database->schema()->tableExists('xedc_ai_filehash')) {
      return ['pairs' => [], 'scanned' => 0, 'limited' => FALSE];
    }
    $count = (int) $this->database->select('xedc_ai_filehash', 'f')
      ->condition('kind', 'image')
      ->condition('phash', '', '<>')
      ->countQuery()
      ->execute()
      ->fetchField();
    $query = $this->database->select('xedc_ai_filehash', 'f')
      ->fields('f', ['fid', 'phash'])
      ->condition('kind', 'image')
      ->condition('phash', '', '<>')
      ->orderBy('changed', 'DESC')
      ->range(0, $scanLimit);
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $pairs = [];
    $total = count($rows);
    for ($i = 0; $i < $total; $i++) {
      for ($j = $i + 1; $j < $total; $j++) {
        $left = (string) $rows[$i]['phash'];
        $right = (string) $rows[$j]['phash'];
        $gap = PerceptualHash::distance($left, $right);
        if ($gap <= $distance) {
          $pairs[] = [
            'left' => (int) $rows[$i]['fid'],
            'right' => (int) $rows[$j]['fid'],
            'distance' => $gap,
          ];
          if (count($pairs) >= $pairLimit) {
            break 2;
          }
        }
      }
    }
    if ($pairs !== []) {
      $names = $this->names(array_merge(
        array_column($pairs, 'left'),
        array_column($pairs, 'right'),
      ));
      foreach ($pairs as &$pair) {
        $pair['left_name'] = $names[$pair['left']] ?? ('文件 ' . $pair['left']);
        $pair['right_name'] = $names[$pair['right']] ?? ('文件 ' . $pair['right']);
      }
      unset($pair);
    }
    return [
      'pairs' => $pairs,
      'scanned' => $total,
      'limited' => $count > $total,
    ];
  }

  public function duplicateFileCount(): int {
    if (!$this->database->schema()->tableExists('xedc_ai_filehash')) {
      return 0;
    }
    $query = $this->database->select('xedc_ai_filehash', 'f');
    $query->addExpression('COUNT(*)', 'copies');
    $query->condition('sha256', '', '<>');
    $query->groupBy('sha256');
    $query->having('COUNT(*) > 1');
    $total = 0;
    foreach ($query->execute() as $row) {
      $total += (int) $row->copies;
    }
    return $total;
  }

  public function enqueue(int $nid): void {
    if ($nid > 0) {
      $this->queueFactory->get('xedc_ai_local')->createItem(['nid' => $nid]);
    }
  }

  public function queueSize(): int {
    return (int) $this->queueFactory->get('xedc_ai_local')->numberOfItems();
  }

  /**
   * @return list<array{fid: int, filename: string, bytes: int}>
   *   Files sharing one hash.
   */
  private function filesForHash(string $hash): array {
    $ids = $this->database->select('xedc_ai_filehash', 'f')
      ->fields('f', ['fid'])
      ->condition('sha256', $hash)
      ->orderBy('fid')
      ->range(0, 12)
      ->execute()
      ->fetchCol();
    $names = $this->names(array_map('intval', $ids));
    $files = [];
    foreach ($ids as $fid) {
      $fid = (int) $fid;
      $files[] = [
        'fid' => $fid,
        'filename' => $names[$fid] ?? ('文件 ' . $fid),
      ];
    }
    return $files;
  }

  private function hashedCount(): int {
    return (int) $this->database->select('xedc_ai_filehash', 'f')
      ->condition('sha256', '', '<>')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * @param list<int> $ids
   *   File ids.
   *
   * @return array<int, string>
   *   Filenames.
   */
  private function names(array $ids): array {
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids === [] || !$this->database->schema()->tableExists('file_managed')) {
      return [];
    }
    $names = [];
    $query = $this->database->select('file_managed', 'f')
      ->fields('f', ['fid', 'filename'])
      ->condition('fid', $ids, 'IN');
    foreach ($query->execute() as $row) {
      $names[(int) $row->fid] = (string) $row->filename;
    }
    return $names;
  }

}
