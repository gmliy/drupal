<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;

/**
 * Local file and text checks. No bytes leave the server.
 */
final class LocalInspector {

  public function __construct(
    private readonly Connection $database,
    private readonly FileSystemInterface $files,
    private readonly EntityTypeManagerInterface $entities,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly VisionAuditInterface $vision,
  ) {}

  /**
   * @return array<string, mixed>
   *   Local findings. vision is always false.
   */
  public function inspect(NodeInterface $node): array {
    $limits = RuleLimits::fromConfig($this->configFactory);
    $extractor = new TextExtractor($this->entities, $this->configFactory);
    $fields = $extractor->extract($node);
    $text = TextRules::assess($fields, $this->duplicateTitles($node, $fields['title']), $this->duplicateText($node, $fields), $limits);
    $text = array_merge($text, $this->referenceIssues($node), $this->contentIssues($node, $limits));
    $files = [];
    foreach ($extractor->fileIds($node) as $fid) {
      $files[] = $this->inspectFile($fid);
    }
    return [
      'text' => $text,
      'files' => $files,
      'vision' => FALSE,
    ];
  }

  /**
   * @return array<string, mixed>
   *   One local file report.
   */
  public function inspectFile(int $fid): array {
    $file = $this->entities->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      return ['fid' => $fid, 'messages' => ['文件记录不存在。'], 'kind' => 'missing'];
    }
    $uri = $file->getFileUri();
    $path = $uri ? $this->files->realpath($uri) : FALSE;
    $mime = (string) $file->getMimeType();
    $bytes = (int) $file->getSize();
    $report = [
      'fid' => $fid,
      'mime' => $mime,
      'bytes' => $bytes,
      'kind' => str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : 'other'),
      'width' => 0,
      'height' => 0,
      'sha256' => '',
      'phash' => '',
      'messages' => [],
      'vision' => FALSE,
    ];
    $report['messages'] = array_merge($report['messages'], $this->extensionIssues($file, (string) $report['kind']));
    if ($bytes < 1) {
      $report['messages'][] = '文件大小异常。';
    }
    if (!is_string($path) || !is_file($path)) {
      $report['messages'][] = '文件不在本地磁盘，未下载，也未做哈希。';
      return $report;
    }
    $limits = RuleLimits::fromConfig($this->configFactory);
    $actual = filesize($path);
    if ($actual === FALSE) {
      $report['messages'][] = '文件无法读取，完整性检查未通过。';
      return $report;
    }
    if ($bytes > 0 && (int) $actual !== $bytes) {
      $report['messages'][] = '文件大小和记录不一致。';
    }
    if ((int) $actual > (int) $limits['max_hash_bytes']) {
      $report['messages'][] = '文件超过 64MB，未计算哈希。';
      $report['messages'][] = '文件大小异常。';
      return $report;
    }
    $sha = hash_file('sha256', $path);
    $report['sha256'] = is_string($sha) ? $sha : '';
    if ($report['sha256'] === '') {
      $report['messages'][] = '文件无法读取，完整性检查未通过。';
    }
    $edge = (int) $limits['min_image_edge'];
    if ($report['kind'] === 'image') {
      $info = @getimagesize($path);
      if (is_array($info)) {
        $report['width'] = (int) $info[0];
        $report['height'] = (int) $info[1];
      }
      else {
        $report['messages'][] = '文件不能作为图片读取。';
      }
      if ($report['width'] > 0 && $edge > 0 && ($report['width'] < $edge || $report['height'] < $edge)) {
        $report['messages'][] = '图片尺寸较小。';
      }
      $report['phash'] = PerceptualHash::fromFile($path) ?? '';
      if ($this->vision->enabled()) {
        foreach ($this->vision->inspectImage($path)['messages'] as $message) {
          $report['messages'][] = $message;
        }
        $report['vision'] = TRUE;
      }
    }
    elseif ($report['kind'] === 'video') {
      $report['messages'][] = '只核对了视频文件的类型、大小和 SHA-256，没有抽取画面。';
      if ($this->vision->enabled()) {
        foreach ($this->vision->inspectVideo($path)['messages'] as $message) {
          $report['messages'][] = $message;
        }
        $report['vision'] = TRUE;
      }
    }
    else {
      $report['messages'][] = '文件类型不是图片或视频。';
    }
    $this->remember($report);
    $report['messages'] = array_merge($report['messages'], $this->duplicates($report));
    return $report;
  }

  /**
   * @param array<string, mixed> $report
   *   File report.
   */
  private function remember(array $report): void {
    if ($report['sha256'] === '') {
      return;
    }
    $fields = [
      'sha256' => $report['sha256'],
      'phash' => $report['phash'],
      'width' => $report['width'],
      'height' => $report['height'],
      'bytes' => $report['bytes'],
      'mime' => substr((string) $report['mime'], 0, 128),
      'kind' => $report['kind'],
      'changed' => $this->time->getRequestTime(),
    ];
    $exists = $this->database->select('xedc_ai_filehash', 'f')
      ->condition('fid', $report['fid'])
      ->countQuery()
      ->execute()
      ->fetchField();
    if ((int) $exists > 0) {
      $this->database->update('xedc_ai_filehash')->fields($fields)->condition('fid', $report['fid'])->execute();
      return;
    }
    $fields['fid'] = $report['fid'];
    $this->database->insert('xedc_ai_filehash')->fields($fields)->execute();
  }

  /**
   * @param array<string, mixed> $report
   *   Current file.
   *
   * @return list<string>
   *   Duplicate messages.
   */
  private function duplicates(array $report): array {
    $messages = [];
    if ($report['sha256'] !== '') {
      $same = $this->database->select('xedc_ai_filehash', 'f')
        ->fields('f', ['fid'])
        ->condition('sha256', $report['sha256'])
        ->condition('fid', $report['fid'], '<>')
        ->range(0, 5)
        ->execute()
        ->fetchCol();
      if ($same !== []) {
        $messages[] = 'SHA-256 与已有文件完全相同：' . implode(', ', $same) . '。';
      }
    }
    if ($report['kind'] !== 'image' || $report['phash'] === '') {
      return $messages;
    }
    $query = $this->database->select('xedc_ai_filehash', 'f')
      ->fields('f', ['fid', 'phash'])
      ->condition('kind', 'image')
      ->condition('phash', '', '<>')
      ->condition('fid', $report['fid'], '<>');
    $count = (int) (clone $query)->countQuery()->execute()->fetchField();
    if ($count > 2000) {
      $query->condition('phash', $this->database->escapeLike(substr($report['phash'], 0, 2)) . '%', 'LIKE');
      $query->range(0, 500);
    }
    foreach ($query->execute() as $row) {
      $distance = RuleLimits::fromConfig($this->configFactory)['phash_distance'];
      if (PerceptualHash::distance($report['phash'], (string) $row->phash) <= $distance) {
        $messages[] = '本地感知哈希接近文件 ' . $row->fid . '。这不是视觉模型判断。';
        break;
      }
    }
    return $messages;
  }

  /**
   * @return list<int>
   *   Other node ids.
   */
  private function duplicateTitles(NodeInterface $node, string $title): array {
    if ($title === '') {
      return [];
    }
    return array_map('intval', $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid'])
      ->condition('title', $title)
      ->condition('type', $node->bundle())
      ->condition('nid', $node->id(), '<>')
      ->condition('default_langcode', 1)
      ->range(0, 5)
      ->execute()
      ->fetchCol());
  }

  /**
   * @param array<string, mixed> $fields
   *   Extracted text.
   *
   * @return list<int>
   *   Other node ids with the same body or summary.
   */
  private function duplicateText(NodeInterface $node, array $fields): array {
    $ids = [];
    foreach ([
      'body' => ['node__field_body', 'field_body_value'],
      'summary' => ['node__field_summary', 'field_summary_value'],
    ] as $key => [$table, $column]) {
      $value = trim((string) ($fields[$key] ?? ''));
      if (mb_strlen($value) < 20 || !$this->database->schema()->tableExists($table)) {
        continue;
      }
      $found = $this->database->select($table, 't')
        ->fields('t', ['entity_id'])
        ->condition($column, $value)
        ->condition('entity_id', $node->id(), '<>')
        ->range(0, 5)
        ->execute()
        ->fetchCol();
      $ids = array_merge($ids, array_map('intval', $found));
    }
    return array_values(array_unique($ids));
  }

  /**
   * @return list<array{level: string, message: string}>
   *   Broken term references.
   */
  private function referenceIssues(NodeInterface $node): array {
    $map = [
      'field_categories' => '存在无效分类。',
      'field_tags' => '存在无效标签。',
      'field_model' => '存在无效人物。',
    ];
    $issues = [];
    foreach ($map as $field => $message) {
      if (!$node->hasField($field)) {
        continue;
      }
      foreach ($node->get($field) as $item) {
        if ((int) $item->target_id > 0 && $item->entity === NULL) {
          $issues[] = ['level' => 'high', 'message' => $message];
          break;
        }
      }
    }
    return $issues;
  }

  /**
   * @param array<string, int> $limits
   *   Rule thresholds.
   *
   * @return list<array{level: string, message: string}>
   *   Bundle-specific gaps.
   */
  private function contentIssues(NodeInterface $node, array $limits): array {
    $issues = [];
    if ($node->bundle() === 'photo' && $this->fieldsEmpty($node, ['field_images', 'field_cover_image'])) {
      $issues[] = ['level' => 'medium', 'message' => '缺少图片文件。'];
    }
    if ($node->bundle() === 'video' && $this->fieldsEmpty($node, ['field_video_file', 'field_video_url'])) {
      $issues[] = ['level' => 'medium', 'message' => '缺少视频文件。'];
    }
    if ($node->bundle() === 'video' && $node->hasField('field_duration')) {
      $duration = (int) $node->get('field_duration')->value;
      $max = (int) ($limits['max_video_seconds'] ?? 86400);
      if ($duration < 1 || ($max > 0 && $duration > $max)) {
        $issues[] = ['level' => 'medium', 'message' => '视频时长不合理。'];
      }
    }
    return $issues;
  }

  /**
   * @param list<string> $fields
   *   Fields that can satisfy the check.
   */
  private function fieldsEmpty(NodeInterface $node, array $fields): bool {
    $seen = FALSE;
    foreach ($fields as $name) {
      if (!$node->hasField($name)) {
        continue;
      }
      $seen = TRUE;
      if (!$node->get($name)->isEmpty()) {
        return FALSE;
      }
    }
    return $seen;
  }

  /**
   * @return list<string>
   *   Extension and MIME mismatches.
   */
  private function extensionIssues(FileInterface $file, string $kind): array {
    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    $images = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
    $videos = ['mp4', 'webm', 'mov', 'm4v', 'mkv'];
    if ($kind === 'image' && $extension !== '' && !in_array($extension, $images, TRUE)) {
      return ['图片扩展名和类型不一致。'];
    }
    if ($kind === 'video' && $extension !== '' && !in_array($extension, $videos, TRUE)) {
      return ['视频扩展名和类型不一致。'];
    }
    if ($kind === 'other' && in_array($extension, array_merge($images, $videos), TRUE)) {
      return ['文件扩展名和记录的类型不一致。'];
    }
    return [];
  }

}
