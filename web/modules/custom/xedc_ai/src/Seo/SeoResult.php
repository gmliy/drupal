<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Seo;

use Drupal\xedc_ai\Review\ReviewParser;

/**
 * Strict SEO payload. The score is computed here, never copied from the model.
 */
final class SeoResult {

  /**
   * @param array<string, mixed> $context
   *   Extracted text. Empty when the caller has no node.
   *
   * @return array{ok: bool, error: string, result: array<string, mixed>}
   *   Parsed suggestion. ok is false when the payload cannot be stored.
   */
  public static function parse(string $text, array $context = []): array {
    $data = ReviewParser::decode($text);
    if ($data === NULL || array_is_list($data)) {
      return self::fail('模型没有返回有效 JSON。');
    }
    if (isset($data['issues']) && !is_array($data['issues'])) {
      return self::fail('问题字段不是数组。');
    }
    if (isset($data['suggestions']) && !is_array($data['suggestions'])) {
      return self::fail('建议字段不是数组。');
    }
    $issues = self::issues(is_array($data['issues'] ?? NULL) ? $data['issues'] : []);
    $rawSuggestions = is_array($data['suggestions'] ?? NULL) ? $data['suggestions'] : [];
    $summary = trim((string) ($data['summary'] ?? ''));
    $title = self::firstText($data, ['suggested_title', 'seo_title', 'title']);
    $description = self::firstText($data, ['suggested_description', 'meta_description', 'description']);
    $keywords = self::stringList($data['suggested_keywords'] ?? $data['keywords'] ?? []);
    $tagNames = self::stringList($data['tags'] ?? []);
    $alts = self::alts($data['suggested_alt'] ?? $data['alts'] ?? []);
    $links = self::links($data['internal_link_suggestions'] ?? $data['internal_links'] ?? []);
    $quality = self::clip(trim((string) ($data['content_quality'] ?? $data['relevance'] ?? '')), 800);
    $items = self::items($rawSuggestions, $title, $description, $keywords, $tagNames, $alts, $links, $context);
    $summaryMissing = $summary === '';
    if ($summaryMissing && ($issues !== [] || $items !== [])) {
      $summary = '模型未提供摘要。系统保留了 ' . count($issues) . ' 个问题和 ' . count($items) . ' 条建议，未把空摘要当成没有问题。';
    }
    if ($summary === '' && $issues === [] && $items === []) {
      return self::fail('分析结果没有摘要、问题或建议，未保存为空成功。');
    }
    $scored = self::score($context, $issues, (bool) ($data['stuffing'] ?? FALSE));
    $modelScore = $data['seo_score'] ?? $data['score'] ?? NULL;
    $risk = $scored['score'] >= 80 && self::highCount($issues) === 0 ? 'low' : ($scored['score'] >= 55 ? 'medium' : 'high');
    $lines = [];
    $storedIssues = [];
    foreach ($issues as $issue) {
      $lines[] = $issue['message'];
      $storedIssues[] = $issue;
    }
    $suggestionLines = [];
    foreach ($items as $item) {
      if ($item['suggested'] !== '') {
        $suggestionLines[] = trim($item['label'] . '：' . $item['suggested']);
      }
    }
    return [
      'ok' => TRUE,
      'error' => '',
      'result' => [
        'status' => $risk === 'low' ? 'pass' : 'attention',
        'risk_level' => $risk,
        'score' => $scored['score'],
        'seo_score' => $scored['score'],
        'issues' => $lines,
        'suggestions' => $suggestionLines,
        'summary' => self::clip($summary, 800),
        'seo_title' => self::clip($title, 180),
        'meta_description' => self::clip($description, 300),
        'suggested_title' => self::clip($title, 180),
        'suggested_description' => self::clip($description, 300),
        'tags' => $keywords,
        'keywords' => $keywords,
        'stuffing' => (bool) ($data['stuffing'] ?? FALSE),
        'relevance' => $quality,
        'content_quality' => $quality,
        'internal_links' => $links,
        'issue_items' => $storedIssues,
        'suggestion_items' => self::legacySuggestions($items),
        'items' => $items,
        'score_rules' => $scored['rules'],
        'base_revision_id' => (int) ($context['revision_id'] ?? 0),
        'applied_revision_id' => 0,
        'analysis_metadata' => [
          'score_source' => 'rules',
          'summary_missing' => $summaryMissing,
          'model_score_ignored' => is_scalar($modelScore) ? (string) $modelScore : '',
          'fields_not_applicable' => array_values(array_map(static fn (array $item): string => $item['key'], array_filter($items, static fn (array $item): bool => !$item['applicable']))),
        ],
      ],
    ];
  }

  public static function rejectedFinish(string $reason): ?string {
    if ($reason === 'length') {
      return '模型响应被截断（finish_reason=length），未把不完整结果标成分析成功。';
    }
    if ($reason === 'content_filter') {
      return '模型因内容过滤中断，未保存 SEO 结果。';
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $result
   *   Stored SEO result.
   */
  /**
   * Fills structured rows for results saved before suggestion items existed.
   *
   * @param array<string, mixed> $result
   *   Stored result.
   *
   * @return array<string, mixed>
   *   Result safe to render and confirm.
   */
  public static function hydrate(array $result): array {
    if (!isset($result['issue_items']) || !is_array($result['issue_items']) || $result['issue_items'] === []) {
      $issues = [];
      foreach ((array) ($result['issues'] ?? []) as $issue) {
        if (is_string($issue) && trim($issue) !== '') {
          $issues[] = ['type' => 'content', 'severity' => 'medium', 'field' => '', 'message' => $issue, 'suggestion' => ''];
        }
        elseif (is_array($issue) && trim((string) ($issue['message'] ?? '')) !== '') {
          $issues[] = $issue;
        }
      }
      $result['issue_items'] = $issues;
    }
    if (self::itemRows($result) !== []) {
      return $result;
    }
    $items = [];
    $title = trim((string) ($result['seo_title'] ?? $result['suggested_title'] ?? ''));
    if ($title !== '') {
      $items[] = [
        'key' => 'title', 'field' => 'title', 'label' => '标题', 'current' => '', 'suggested' => $title,
        'reason' => 'SEO 标题建议', 'priority' => 'high', 'applicable' => TRUE, 'applicable_note' => '', 'state' => 'pending', 'media_id' => 0,
      ];
    }
    $description = trim((string) ($result['meta_description'] ?? $result['suggested_description'] ?? ''));
    if ($description !== '') {
      $items[] = [
        'key' => 'summary', 'field' => 'field_summary', 'label' => '简介 / 页面描述', 'current' => '', 'suggested' => $description,
        'reason' => '简介会进入美图和文章的页面描述。没有简介字段的类型不能写入。', 'priority' => 'high', 'applicable' => TRUE, 'applicable_note' => '', 'state' => 'pending', 'media_id' => 0,
      ];
    }
    foreach ((array) ($result['suggestions'] ?? []) as $index => $suggestion) {
      if (!is_string($suggestion) || trim($suggestion) === '') {
        continue;
      }
      $items[] = [
        'key' => 'note-' . $index, 'field' => '', 'label' => '说明', 'current' => '', 'suggested' => $suggestion,
        'reason' => '', 'priority' => 'low', 'applicable' => FALSE, 'applicable_note' => '不适用：这是说明性建议，没有对应的可写入字段。', 'state' => 'not_applicable', 'media_id' => 0,
      ];
    }
    $result['items'] = $items;
    return $result;
  }

  public static function opsStatus(string $status, string $handled, array $result, bool $stale): string {
    if ($status === 'pending') {
      return 'queued';
    }
    if ($status === 'processing') {
      return 'running';
    }
    if ($status === 'failed') {
      return 'failed';
    }
    if ($status === 'ignored' || $status === 'cancelled' || $handled === 'ignored') {
      return 'ignored';
    }
    if ($status !== 'completed') {
      return 'queued';
    }
    if ($stale) {
      return 'stale';
    }
    $applicable = 0;
    $adopted = 0;
    $rejected = 0;
    foreach (self::itemRows($result) as $item) {
      if (!$item['applicable']) {
        continue;
      }
      $applicable++;
      if ($item['state'] === 'adopted') {
        $adopted++;
      }
      elseif ($item['state'] === 'rejected') {
        $rejected++;
      }
    }
    if ($applicable === 0) {
      return 'success';
    }
    if ($adopted === $applicable) {
      return 'adopted';
    }
    if ($adopted > 0 || ($rejected > 0 && $rejected < $applicable)) {
      return 'partial';
    }
    if ($rejected === $applicable) {
      return 'ignored';
    }
    return 'awaiting';
  }

  /**
   * @param array<string, mixed> $result
   *   Stored result.
   *
   * @return list<array<string, mixed>>
   *   Suggestion rows.
   */
  /**
   * Adds local ALT candidates that the model did not already suggest.
   *
   * @param array<string, mixed> $result
   *   Stored SEO result.
   * @param array<string, mixed> $report
   *   AltInspector report.
   *
   * @return array<string, mixed>
   *   Result with extra pending ALT rows.
   */
  public static function mergeLocalAlt(array $result, array $report): array {
    $items = is_array($result['items'] ?? NULL) ? $result['items'] : [];
    $keys = [];
    foreach ($items as $item) {
      if (is_array($item)) {
        $keys[(string) ($item['key'] ?? '')] = TRUE;
      }
    }
    foreach (is_array($report['rows'] ?? NULL) ? $report['rows'] : [] as $row) {
      if (!is_array($row)) {
        continue;
      }
      $mid = (int) ($row['mid'] ?? 0);
      $candidate = trim((string) ($row['candidate'] ?? ''));
      $key = 'alt:' . $mid;
      if ($mid < 1 || $candidate === '' || isset($keys[$key])) {
        continue;
      }
      $items[] = [
        'key' => $key,
        'field' => 'field_media_image',
        'label' => '图片 ALT',
        'current' => (string) ($row['alt'] ?? ''),
        'suggested' => $candidate,
        'reason' => (string) ($row['reason'] ?? '本地规则生成，0 Token。'),
        'priority' => 'medium',
        'applicable' => TRUE,
        'applicable_note' => '',
        'state' => 'pending',
        'media_id' => $mid,
        'source' => 'local_alt',
      ];
      $keys[$key] = TRUE;
    }
    $result['items'] = $items;
    return $result;
  }

  public static function itemRows(array $result): array {
    $items = $result['items'] ?? NULL;
    if (!is_array($items)) {
      return [];
    }
    $rows = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      if ((string) ($item['key'] ?? '') === 'tags') {
        $item['tag_names'] = self::splitTerms($item['tag_names'] ?? ($item['suggested'] ?? ''));
        if ($item['tag_names'] !== []) {
          $item['suggested'] = implode('、', $item['tag_names']);
        }
      }
      $item['state_label'] = self::stateLabel((string) ($item['state'] ?? ''));
      $rows[] = $item;
    }
    return $rows;
  }

  /**
   * Decides whether an archived node may move to published after a suggestion write.
   *
   * Other workflow states always stay as they are. Archived content is
   * republished only when every selected suggestion was adopted, a real
   * transition exists, and the user may use it.
   */
  public static function republishDecision(string $state, int $adopted, int $failed, int $conflict, bool $allowed, bool $transitionOk): string {
    if ($state !== 'archived') {
      return 'keep';
    }
    if ($conflict > 0) {
      return 'conflict';
    }
    if ($failed > 0 || $adopted < 1) {
      return 'apply_failed';
    }
    if (!$transitionOk) {
      return 'no_transition';
    }
    if (!$allowed) {
      return 'denied';
    }
    return 'republish';
  }

  public static function stateLabel(string $state): string {
    return match ($state) {
      'pending' => '待确认',
      'adopted', 'applied' => '已采纳',
      'rejected', 'ignored' => '已忽略',
      'failed' => '采纳失败',
      'conflict' => '版本冲突',
      'partially_applied', 'partial' => '部分已采纳',
      'success' => '成功',
      'not_applicable' => '不适用',
      default => $state === '' ? '待确认' : \Drupal\xedc_ai\Labels::itemState($state),
    };
  }

  /**
   * Reads checked suggestion keys from a Form API checkbox group.
   *
   * Unchecked boxes arrive as 0. Unknown values are dropped so a edited POST
   * cannot target a key that this analysis does not contain.
   *
   * @param mixed $posted
   *   Form value for the items tree.
   * @param list<string> $allowed
   *   Keys that belong to this analysis.
   *
   * @return list<string>
   *   Checked keys, in post order, without duplicates.
   */
  public static function chosenKeys(mixed $posted, array $allowed): array {
    if (!is_array($posted)) {
      return [];
    }
    $chosen = [];
    foreach ($posted as $value) {
      if (!is_string($value) || $value === '' || !in_array($value, $allowed, TRUE) || in_array($value, $chosen, TRUE)) {
        continue;
      }
      $chosen[] = $value;
    }
    return $chosen;
  }

  /**
   * Describes the text actually available at analysis time.
   *
   * This is stored with the review. It is not a new model call.
   *
   * @param array<string, mixed> $fields
   *   Extracted text plus field-existence flags.
   *
   * @return array<string, mixed>
   *   Rows safe to render.
   */
  public static function sourceSnapshot(array $fields): array {
    $rawBody = (string) ($fields['raw_body'] ?? '');
    $links = [];
    if ($rawBody !== '' && preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/iu', $rawBody, $matches)) {
      foreach ($matches[1] as $href) {
        $href = trim((string) $href);
        if ($href !== '' && (str_starts_with($href, '/') || str_starts_with($href, '#'))) {
          $links[] = $href;
        }
      }
    }
    $links = array_values(array_unique($links));
    $body = trim((string) ($fields['body'] ?? ''));
    $excerpt = $body === '' ? '' : (mb_strlen($body) > 180 ? mb_substr($body, 0, 180) . '…' : $body);
    $alts = is_array($fields['alts'] ?? NULL) ? $fields['alts'] : [];
    $filled = 0;
    foreach ($alts as $alt) {
      if (is_array($alt) && mb_strlen(trim((string) ($alt['alt'] ?? ''))) >= 2) {
        $filled++;
      }
    }
    $row = static function (string $label, string $status, string $value): array {
      return ['label' => $label, 'status' => $status, 'value' => $value];
    };
    $textRow = static function (string $label, bool $exists, string $value) use ($row): array {
      if (!$exists) {
        return $row($label, '字段不存在', '');
      }
      if (trim($value) === '') {
        return $row($label, '内容为空', '');
      }
      return $row($label, '已读取', $value);
    };
    $list = static function (array $values): string {
      $values = array_values(array_filter(array_map(static fn ($value): string => trim((string) $value), $values)));
      return $values === [] ? '' : implode('、', $values);
    };
    $hasDuration = !empty($fields['has_duration_field']);
    $duration = (int) ($fields['duration'] ?? 0);
    $hasImages = !empty($fields['has_image_field']);
    return [
      'entity_id' => (int) ($fields['entity_id'] ?? 0),
      'revision_id' => (int) ($fields['revision_id'] ?? 0),
      'langcode' => (string) ($fields['langcode'] ?? ''),
      'bundle' => (string) ($fields['bundle'] ?? ''),
      'rows' => [
        $textRow('原始标题', TRUE, (string) ($fields['title'] ?? '')),
        $textRow('原始简介', !empty($fields['has_summary_field']), (string) ($fields['summary'] ?? '')),
        $textRow('正文提取摘要', !empty($fields['has_body_field']), $excerpt),
        $textRow('当前标签', !empty($fields['has_tags_field']), $list((array) ($fields['tags'] ?? []))),
        $textRow('当前栏目', !empty($fields['has_category_field']), $list((array) ($fields['categories'] ?? []))),
        $hasImages
          ? $row('图片数量', '已读取', (string) count($alts))
          : $row('图片数量', '字段不存在', ''),
        $hasImages
          ? $row('ALT', count($alts) === 0 ? '内容为空' : '已读取', count($alts) === 0 ? '' : $filled . '/' . count($alts) . ' 张已填写')
          : $row('ALT', '不适用', ''),
        empty($fields['has_body_field'])
          ? $row('内部链接', '不适用', '')
          : $row('内部链接', $rawBody === '' ? '内容为空' : '已读取', $rawBody === '' ? '' : (($links === [] ? '正文里没有站内链接' : implode('、', array_slice($links, 0, 8))) . '。模型收到的是去标签正文，' . (preg_match('/href=|\/node\/|\/taxonomy\//i', $body) === 1 ? '其中仍能看到链接痕迹。' : '其中看不到链接地址。'))),
        !$hasDuration
          ? $row('视频时长', '不适用', '')
          : $row('视频时长', $duration > 0 ? '已读取' : '内容为空', $duration > 0 ? $duration . ' 秒' : ''),
      ],
      'model_reads' => ['标题', '简介', '正文纯文本', '栏目', '标签', '人物', 'ALT 文字', '视频时长', '当前 Meta 令牌结果'],
      'score_reads' => ['标题', '简介或描述', '正文或时长', '标签', '栏目', 'ALT', '正文中的链接文字'],
      'not_sent' => ['图片文件', '视频文件'],
    ];
  }

  /**
   * @param array<string, mixed> $context
   *   Extracted text.
   * @param list<array{type: string, severity: string, field: string, message: string, suggestion: string}> $issues
   *   Validated issues.
   *
   * @return array{score: int, rules: list<string>}
   *   Rule score and the deductions that produced it.
   */
  public static function score(array $context, array $issues, bool $stuffing): array {
    unset($issues, $stuffing);
    $rules = ['按适用维度计分后换算成 100 分。模型自报分数、问题严重程度和堆砌标记不进入分数。'];
    $earned = 0;
    $possible = 0;
    foreach (self::dimensions($context) as $dimension) {
      if ($dimension['status'] === 'na') {
        $rules[] = $dimension['label'] . ' 不适用，不计入分母。' . $dimension['reason'];
        continue;
      }
      if ($dimension['status'] === 'unchecked') {
        $rules[] = $dimension['label'] . ' 未检查，不给满分，也不计入分母。' . $dimension['reason'];
        continue;
      }
      $possible += $dimension['weight'];
      $earned += $dimension['points'];
      $rules[] = $dimension['label'] . ' ' . $dimension['points'] . '/' . $dimension['weight'] . '。' . $dimension['reason'];
    }
    if ($possible < 1) {
      $rules[] = '没有已检查的适用项目，分数为 0，不是默认满分。';
      return ['score' => 0, 'rules' => $rules];
    }
    $score = (int) round($earned / $possible * 100);
    $rules[] = '已检查 ' . $earned . '/' . $possible . '，换算 ' . $score . ' 分。';
    return ['score' => max(0, min(100, $score)), 'rules' => $rules];
  }

  /**
   * @param array<string, mixed> $context
   *   Extracted text.
   *
   * @return list<array{label: string, weight: int, points: int, status: string, reason: string}>
   *   One row per scoring dimension.
   */
  public static function dimensions(array $context): array {
    return [
      self::scoreTitle($context),
      self::scoreDescription($context),
      self::scoreCompleteness($context),
      self::scoreKeywords($context),
      self::scoreTags($context),
      self::scoreAlt($context),
      self::scoreLinks($context),
    ];
  }

  /**
   * @return array{ok: bool, error: string, result: array<string, mixed>}
   *   Failure payload.
   */
  private static function fail(string $error): array {
    return ['ok' => FALSE, 'error' => $error, 'result' => []];
  }

  /**
   * @param array<string, mixed> $data
   *   Model object.
   * @param list<string> $keys
   *   Accepted names, first non-empty wins.
   */
  private static function firstText(array $data, array $keys): string {
    foreach ($keys as $key) {
      $value = $data[$key] ?? NULL;
      if (is_string($value) || is_numeric($value)) {
        $text = trim((string) $value);
        if ($text !== '') {
          return $text;
        }
      }
    }
    return '';
  }

  /**
   * @return list<array{type: string, severity: string, field: string, message: string, suggestion: string}>
   *   Issues safe to store.
   */
  private static function issues(array $items): array {
    $rows = [];
    foreach ($items as $item) {
      if (is_string($item) || is_numeric($item)) {
        $message = trim((string) $item);
        $severity = 'low';
        $field = '';
        $suggestion = '';
        $type = 'content';
      }
      elseif (is_array($item)) {
        $message = trim((string) ($item['message'] ?? $item['text'] ?? ''));
        $severity = (string) ($item['severity'] ?? 'low');
        $field = trim((string) ($item['field'] ?? ''));
        $suggestion = trim((string) ($item['suggestion'] ?? ''));
        $type = trim((string) ($item['type'] ?? 'content'));
      }
      else {
        continue;
      }
      if ($message === '') {
        continue;
      }
      if (!in_array($severity, ['low', 'medium', 'high'], TRUE)) {
        $severity = 'low';
      }
      $rows[] = [
        'type' => self::clip($type, 32),
        'severity' => $severity,
        'field' => self::clip($field, 64),
        'message' => self::clip($message, 500),
        'suggestion' => self::clip($suggestion, 500),
      ];
      if (count($rows) >= 30) {
        break;
      }
    }
    return $rows;
  }

  /**
   * @param list<mixed> $suggestions
   *   Model suggestions.
   * @param list<string> $keywords
   *   Keyword advice, not a meta tag.
   * @param list<string> $tagNames
   *   Tag names suggested for the existing vocabulary.
   * @param list<array{media_id: int, current: string, suggested: string, reason: string}> $alts
   *   ALT advice.
   * @param list<array{anchor: string, target: string, reason: string}> $links
   *   Link advice.
   * @param array<string, mixed> $context
   *   Node text.
   *
   * @return list<array<string, mixed>>
   *   Normalized items.
   */
  private static function items(array $suggestions, string $title, string $description, array $keywords, array $tagNames, array $alts, array $links, array $context): array {
    $known = !empty($context['known']);
    $rows = [];
    $push = static function (array $row) use (&$rows): void {
      foreach ($rows as $existing) {
        if ($existing['key'] === $row['key']) {
          return;
        }
      }
      $rows[] = $row;
    };
    foreach ($suggestions as $item) {
      if (!is_array($item)) {
        $text = trim((string) $item);
        if ($text !== '') {
          $push(self::note('note-' . count($rows), $text));
        }
        continue;
      }
      $field = strtolower(trim((string) ($item['field'] ?? '')));
      $suggested = trim((string) ($item['suggested'] ?? $item['suggestion'] ?? ''));
      $reason = trim((string) ($item['reason'] ?? ''));
      $priority = (string) ($item['priority'] ?? 'medium');
      if ($suggested === '') {
        continue;
      }
      if (in_array($field, ['title', 'seo_title', 'suggested_title'], TRUE)) {
        $title = $title !== '' ? $title : $suggested;
        continue;
      }
      if (in_array($field, ['summary', 'description', 'meta_description', 'suggested_description'], TRUE)) {
        $description = $description !== '' ? $description : $suggested;
        continue;
      }
      if ($field === 'alt' || $field === 'suggested_alt') {
        $alts[] = [
          'media_id' => (int) ($item['media_id'] ?? 0),
          'current' => trim((string) ($item['current'] ?? $item['original'] ?? '')),
          'suggested' => $suggested,
          'reason' => $reason,
        ];
        continue;
      }
      if ($field === 'tags' || $field === 'keywords' || $field === 'internal_link' || $field === 'internal_links') {
        continue;
      }
      $push(self::note($field !== '' ? $field . '-' . count($rows) : 'note-' . count($rows), $suggested, $reason, $priority));
    }
    if ($title !== '') {
      $push(self::fieldItem('title', '标题', 'title', (string) ($context['title'] ?? ''), $title, '标题会同步影响页面默认 SEO 标题。', 'high', TRUE, ''));
    }
    $summaryApplicable = !$known || !empty($context['has_summary_field']);
    if ($description !== '') {
      $push(self::fieldItem(
        'summary',
        '简介 / 页面描述',
        'field_summary',
        (string) ($context['summary'] ?? ''),
        $description,
        $summaryApplicable ? '简介写入内容的简介字段。美图和文章的页面描述会读取该字段。' : '这个内容类型没有简介字段。页面描述来自站点默认规则，不能单独写入。',
        'high',
        $summaryApplicable,
        $summaryApplicable ? '' : '不适用：没有可写入的简介字段。',
      ));
    }
    if ($keywords !== []) {
      $push(self::fieldItem('keywords', '关键词', '', '', implode('、', $keywords), '站点没有关键词字段，也没有关键词标签。', 'low', FALSE, '不适用：只作为运营建议，不会写入。'));
    }
    $tagText = $tagNames;
    foreach ($suggestions as $item) {
      if (is_array($item) && strtolower((string) ($item['field'] ?? '')) === 'tags') {
        foreach (self::stringList((string) ($item['suggested'] ?? '')) as $name) {
          $tagText[] = $name;
        }
      }
    }
    $tagText = array_values(array_unique(array_filter($tagText)));
    if ($tagText !== []) {
      $tagsApplicable = !$known || !empty($context['has_tags_field']);
      $push(self::fieldItem(
        'tags',
        '标签',
        'field_tags',
        implode('、', (array) ($context['tags'] ?? [])),
        implode('、', $tagText),
        '只关联标签词表里已经存在的术语，不会新建术语。',
        'medium',
        $tagsApplicable,
        $tagsApplicable ? '' : '不适用：这个内容类型没有标签字段。',
      ));
    }
    foreach ($alts as $alt) {
      $mid = (int) $alt['media_id'];
      $knownAlt = self::currentAlt($context, $mid);
      $applicable = $mid > 0 && (!$known || $knownAlt !== NULL);
      $push(self::fieldItem(
        'alt:' . $mid,
        '图片 ALT',
        'field_media_image',
        $knownAlt ?? (string) $alt['current'],
        (string) $alt['suggested'],
        (string) $alt['reason'],
        'medium',
        $applicable,
        $applicable ? '' : '不适用：建议没有对应到这个内容上的媒体。',
        $mid,
      ));
    }
    foreach ($links as $index => $link) {
      $push(self::fieldItem(
        'link-' . $index,
        '内部链接',
        '',
        '',
        trim($link['anchor'] . ' → ' . $link['target']),
        (string) $link['reason'],
        'low',
        FALSE,
        '不适用：没有单独的内部链接字段，不会自动改正文。',
      ));
    }
    foreach ($rows as $index => $row) {
      $row['entity_id'] = (int) ($row['entity_id'] ?? $context['entity_id'] ?? 0);
      $row['revision_id'] = (int) ($row['revision_id'] ?? $context['revision_id'] ?? 0);
      $row['review_id'] = (int) ($row['review_id'] ?? 0);
      $row['acted_uid'] = (int) ($row['acted_uid'] ?? 0);
      $row['acted_at'] = (int) ($row['acted_at'] ?? 0);
      if ((string) ($row['key'] ?? '') === 'tags') {
        $row['tag_names'] = self::splitTerms($row['tag_names'] ?? ($row['suggested'] ?? ''));
        $row['suggested'] = implode('、', $row['tag_names']);
      }
      $rows[$index] = $row;
    }
    return $rows;
  }

  /**
   * @param array<string, mixed> $context
   *   Extracted text.
   */
  private static function currentAlt(array $context, int $mid): ?string {
    if ($mid < 1 || !is_array($context['alts'] ?? NULL)) {
      return NULL;
    }
    foreach ($context['alts'] as $alt) {
      if (is_array($alt) && (int) ($alt['mid'] ?? 0) === $mid) {
        return (string) ($alt['alt'] ?? '');
      }
    }
    return NULL;
  }

  /**
   * @return array<string, mixed>
   *   One suggestion row.
   */
  private static function fieldItem(string $key, string $label, string $field, string $current, string $suggested, string $reason, string $priority, bool $applicable, string $note, int $mediaId = 0): array {
    if (!in_array($priority, ['low', 'medium', 'high'], TRUE)) {
      $priority = 'medium';
    }
    return [
      'key' => $key,
      'field' => $field,
      'label' => $label,
      'current' => self::clip($current, 500),
      'suggested' => self::clip($suggested, 500),
      'reason' => self::clip($reason, 500),
      'priority' => $priority,
      'applicable' => $applicable,
      'applicable_note' => $note,
      'state' => $applicable ? 'pending' : 'not_applicable',
      'media_id' => $mediaId,
    ];
  }

  /**
   * @return array<string, mixed>
   *   Non-writable note.
   */
  private static function note(string $key, string $suggested, string $reason = '', string $priority = 'low'): array {
    return self::fieldItem($key, '说明', '', '', $suggested, $reason, $priority, FALSE, '不适用：这是说明性建议，没有对应的可写入字段。');
  }

  /**
   * @param mixed $value
   *   Model list.
   *
   * @return list<string>
   *   Strings.
   */
  private static function stringList(mixed $value): array {
    if (is_string($value) || is_numeric($value)) {
      $value = preg_split('/[,，、]/u', (string) $value) ?: [];
    }
    if (!is_array($value)) {
      return [];
    }
    $rows = [];
    foreach ($value as $item) {
      if (is_array($item)) {
        $item = $item['name'] ?? $item['suggested'] ?? '';
      }
      foreach (self::pieces($item) as $text) {
        if (!isset($rows[$text])) {
          $rows[$text] = self::clip($text, 80);
        }
      }
      if (count($rows) >= 20) {
        break;
      }
    }
    return array_values($rows);
  }

  /**
   * @param mixed $value
   *   A tag string or list.
   *
   * @return list<string>
   *   One term name per element.
   */
  public static function splitTerms(mixed $value): array {
    return self::stringList($value);
  }

  /**
   * @return list<string>
   *   Pieces of one model value.
   */
  private static function pieces(mixed $item): array {
    $text = trim((string) $item);
    if ($text === '') {
      return [];
    }
    $parts = preg_split('/[,，、;；\r\n]+/u', $text) ?: [];
    $rows = [];
    foreach ($parts as $part) {
      $part = trim((string) $part);
      if ($part !== '') {
        $rows[] = $part;
      }
    }
    return $rows;
  }

  /**
   * @return list<array{media_id: int, current: string, suggested: string, reason: string}>
   *   ALT rows.
   */
  private static function alts(mixed $value): array {
    if (is_string($value) && trim($value) !== '') {
      return [['media_id' => 0, 'current' => '', 'suggested' => self::clip(trim($value), 500), 'reason' => '']];
    }
    if (!is_array($value)) {
      return [];
    }
    $rows = [];
    foreach ($value as $item) {
      if (is_string($item) && trim($item) !== '') {
        $rows[] = ['media_id' => 0, 'current' => '', 'suggested' => self::clip(trim($item), 500), 'reason' => ''];
      }
      elseif (is_array($item)) {
        $suggested = trim((string) ($item['suggested'] ?? $item['alt'] ?? ''));
        if ($suggested === '') {
          continue;
        }
        $rows[] = [
          'media_id' => (int) ($item['media_id'] ?? $item['mid'] ?? 0),
          'current' => self::clip(trim((string) ($item['current'] ?? $item['original'] ?? '')), 500),
          'suggested' => self::clip($suggested, 500),
          'reason' => self::clip(trim((string) ($item['reason'] ?? '')), 500),
        ];
      }
      if (count($rows) >= 12) {
        break;
      }
    }
    return $rows;
  }

  /**
   * @return list<array{anchor: string, target: string, reason: string}>
   *   Link advice.
   */
  private static function links(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $rows = [];
    foreach ($value as $item) {
      if (is_string($item) && trim($item) !== '') {
        $rows[] = ['anchor' => self::clip(trim($item), 180), 'target' => '', 'reason' => ''];
      }
      elseif (is_array($item)) {
        $anchor = trim((string) ($item['anchor'] ?? $item['text'] ?? ''));
        $target = trim((string) ($item['target'] ?? $item['url'] ?? $item['target_title'] ?? ''));
        if ($anchor === '' && $target === '') {
          continue;
        }
        $rows[] = [
          'anchor' => self::clip($anchor, 180),
          'target' => self::clip($target, 180),
          'reason' => self::clip(trim((string) ($item['reason'] ?? '')), 500),
        ];
      }
      if (count($rows) >= 12) {
        break;
      }
    }
    return $rows;
  }

  /**
   * @param list<array<string, mixed>> $items
   *   Normalized items.
   *
   * @return list<array{field: string, original: string, suggested: string, reason: string}>
   *   Legacy comparison rows.
   */
  private static function legacySuggestions(array $items): array {
    $rows = [];
    foreach ($items as $item) {
      if (trim((string) $item['suggested']) === '') {
        continue;
      }
      $rows[] = [
        'field' => (string) $item['field'],
        'original' => (string) $item['current'],
        'suggested' => (string) $item['suggested'],
        'reason' => (string) $item['reason'],
      ];
    }
    return $rows;
  }

  /**
   * @param list<array{severity: string}> $issues
   *   Issues.
   */
  private static function highCount(array $issues): int {
    $count = 0;
    foreach ($issues as $issue) {
      if ($issue['severity'] === 'high') {
        $count++;
      }
    }
    return $count;
  }

  private static function clip(string $value, int $limit): string {
    return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
  }

  /**
   * @param array<string, mixed> $context
   *   Extracted text.
   *
   * @return array{label: string, weight: int, points: int, status: string, reason: string}
   *   Title dimension.
   */
  private static function scoreTitle(array $context): array {
    if (!array_key_exists('title', $context)) {
      return self::dimension('SEO 标题', 20, 0, 'unchecked', '上下文没有标题，不能默认满分。');
    }
    $title = trim((string) $context['title']);
    if ($title === '') {
      return self::dimension('SEO 标题', 20, 0, 'checked', '标题为空。');
    }
    $length = mb_strlen($title);
    if ($length < 4) {
      return self::dimension('SEO 标题', 20, 8, 'checked', '标题短于 4 个字。');
    }
    if ($length > 60) {
      return self::dimension('SEO 标题', 20, 12, 'checked', '标题长于 60 个字。');
    }
    return self::dimension('SEO 标题', 20, 20, 'checked', '标题长度在 4 到 60 个字之间。');
  }

  /**
   * @param array<string, mixed> $context
   *   Extracted text.
   *
   * @return array{label: string, weight: int, points: int, status: string, reason: string}
   *   Meta description dimension.
   */
  private static function scoreDescription(array $context): array {
    if (!array_key_exists('meta_description', $context)) {
      return self::dimension('页面描述', 20, 0, 'unchecked', '没有读到已解析的页面描述。');
    }
    $title = trim((string) ($context['title'] ?? ''));
    $description = trim((string) $context['meta_description']);
    if ($description === '') {
      return self::dimension('页面描述', 20, 0, 'checked', '页面描述为空。');
    }
    $length = mb_strlen($description);
    if ($length < 20) {
      return self::dimension('页面描述', 20, 6, 'checked', '页面描述短于 20 个字。');
    }
    if (self::descriptionIsTitleOnly($title, $description)) {
      return self::dimension('页面描述', 20, 10, 'checked', '描述几乎只有标题，缺少补充信息。');
    }
    if ($length > 180) {
      return self::dimension('页面描述', 20, 12, 'checked', '页面描述长于 180 个字。');
    }
    return self::dimension('页面描述', 20, 20, 'checked', '描述长度可用，并且不是标题的简单重复。');
  }

  /**
   * @param array<string, mixed> $context
   *   Extracted text.
   *
   * @return array{label: string, weight: int, points: int, status: string, reason: string}
   *   Completeness dimension.
   */
  private static function scoreCompleteness(array $context): array {
    $bundle = (string) ($context['bundle'] ?? '');
    if (!in_array($bundle, ['article', 'photo', 'video', 'page'], TRUE)) {
      return self::dimension('内容完整性', 20, 0, 'unchecked', '内容类型未知，不按其他类型给满分。');
    }
    $summary = trim((string) ($context['summary'] ?? ''));
    $body = trim((string) ($context['body'] ?? ''));
    if ($bundle === 'article' || $bundle === 'page') {
      if (!array_key_exists('body', $context)) {
        return self::dimension('内容完整性', 20, 0, 'unchecked', '没有读到正文。');
      }
      $length = mb_strlen($body);
      $minimum = $bundle === 'page' ? 40 : 80;
      if ($length < 1) {
        return self::dimension('内容完整性', 20, 0, 'checked', '正文为空。');
      }
      if ($length < $minimum) {
        return self::dimension('内容完整性', 20, 8, 'checked', '正文短于 ' . $minimum . ' 个字。');
      }
      return self::dimension('内容完整性', 20, 20, 'checked', '正文长度达到 ' . $minimum . ' 个字。');
    }
    if ($bundle === 'photo') {
      if ($summary === '') {
        return self::dimension('内容完整性', 20, 4, 'checked', '美图简介为空，完整性只剩下标题。');
      }
      if (mb_strlen($summary) < 15) {
        return self::dimension('内容完整性', 20, 10, 'checked', '美图简介短于 15 个字。');
      }
      return self::dimension('内容完整性', 20, 20, 'checked', '美图有可用简介。');
    }
    $text = trim($summary . $body);
    $duration = (int) ($context['duration'] ?? 0);
    if ($text === '' && $duration < 1) {
      return self::dimension('内容完整性', 20, 0, 'checked', '视频没有简介、正文和时长。');
    }
    if ($text === '') {
      return self::dimension('内容完整性', 20, 8, 'checked', '视频有时长，但没有简介或正文。');
    }
    if ($duration < 1) {
      return self::dimension('内容完整性', 20, 10, 'checked', '视频有文字，但时长为空。');
    }
    return self::dimension('内容完整性', 20, 20, 'checked', '视频有文字和时长。');
  }

  /**
   * @param array<string, mixed> $context
   *   Extracted text.
   *
   * @return array{label: string, weight: int, points: int, status: string, reason: string}
   *   Keyword overlap dimension.
   */
  private static function scoreKeywords(array $context): array {
    if (!array_key_exists('title', $context)) {
      return self::dimension('关键词相关性', 15, 0, 'unchecked', '没有标题可对照。');
    }
    $title = trim((string) $context['title']);
    if ($title === '') {
      return self::dimension('关键词相关性', 15, 0, 'checked', '标题为空，无法判断相关性。');
    }
    $hay = trim(implode(' ', [
      (string) ($context['summary'] ?? ''),
      (string) ($context['body'] ?? ''),
      (string) ($context['meta_description'] ?? ''),
      implode(' ', (array) ($context['tags'] ?? [])),
      implode(' ', (array) ($context['categories'] ?? [])),
    ]));
    if ($hay === '') {
      return self::dimension('关键词相关性', 15, 0, 'checked', '没有简介、正文、栏目或标签可对照。');
    }
    if (self::overlaps($title, $hay)) {
      return self::dimension('关键词相关性', 15, 15, 'checked', '标题与简介、正文、栏目或标签有共同用词。');
    }
    return self::dimension('关键词相关性', 15, 5, 'checked', '标题与简介、正文、栏目、标签没有共同用词。');
  }

  /**
   * @param array<string, mixed> $context
   *   Extracted text.
   *
   * @return array{label: string, weight: int, points: int, status: string, reason: string}
   *   Tag dimension.
   */
  private static function scoreTags(array $context): array {
    if (!array_key_exists('has_tags_field', $context)) {
      return self::dimension('标签相关性', 10, 0, 'unchecked', '不知道这个类型有没有标签字段。');
    }
    if (empty($context['has_tags_field'])) {
      return self::dimension('标签相关性', 10, 0, 'na', '这个内容类型没有标签字段。');
    }
    $tags = [];
    foreach ((array) ($context['tags'] ?? []) as $tag) {
      $tag = trim((string) $tag);
      if ($tag !== '') {
        $tags[] = $tag;
      }
    }
    if ($tags === []) {
      return self::dimension('标签相关性', 10, 0, 'checked', '标签为空。');
    }
    if (count($tags) > 10) {
      return self::dimension('标签相关性', 10, 4, 'checked', '标签超过 10 个。');
    }
    $hay = trim(implode(' ', [
      (string) ($context['title'] ?? ''),
      (string) ($context['summary'] ?? ''),
      implode(' ', (array) ($context['categories'] ?? [])),
    ]));
    foreach ($tags as $tag) {
      if (self::overlaps($tag, $hay) || ($hay !== '' && mb_strpos($hay, $tag) !== FALSE)) {
        return self::dimension('标签相关性', 10, 10, 'checked', '至少有一个标签与标题、简介或栏目重合。');
      }
    }
    return self::dimension('标签相关性', 10, 5, 'checked', '标签与标题、简介、栏目没有共同用词。');
  }

  /**
   * @param array<string, mixed> $context
   *   Extracted text.
   *
   * @return array{label: string, weight: int, points: int, status: string, reason: string}
   *   ALT dimension.
   */
  private static function scoreAlt(array $context): array {
    $bundle = (string) ($context['bundle'] ?? '');
    if (!array_key_exists('alts', $context)) {
      return self::dimension('图片 ALT', 10, 0, 'unchecked', '没有读取图片 ALT。');
    }
    $alts = is_array($context['alts']) ? $context['alts'] : [];
    if ($alts === [] && $bundle !== 'photo') {
      return self::dimension('图片 ALT', 10, 0, 'na', '这个内容没有图片，不计入分母。');
    }
    if ($alts === []) {
      return self::dimension('图片 ALT', 10, 0, 'checked', '美图没有可读的图片 ALT。');
    }
    $scored = AltInspector::points($alts, (string) ($context['title'] ?? ''), $bundle);
    if ($scored['status'] === 'na') {
      return self::dimension('图片 ALT', 10, 0, 'na', $scored['reason']);
    }
    return self::dimension('图片 ALT', 10, $scored['points'], 'checked', $scored['reason']);
  }

  /**
   * @param array<string, mixed> $context
   *   Extracted text.
   *
   * @return array{label: string, weight: int, points: int, status: string, reason: string}
   *   Internal link dimension.
   */
  private static function scoreLinks(array $context): array {
    $bundle = (string) ($context['bundle'] ?? '');
    if (in_array($bundle, ['photo', 'video'], TRUE)) {
      return self::dimension('内部链接', 5, 0, 'na', '美图和视频没有正文链接字段，不计入分母。');
    }
    if (!in_array($bundle, ['article', 'page'], TRUE)) {
      return self::dimension('内部链接', 5, 0, 'unchecked', '内容类型未知，不默认给内部链接满分。');
    }
    if (array_key_exists('has_body_field', $context) && empty($context['has_body_field'])) {
      return self::dimension('内部链接', 5, 0, 'na', '没有正文栏位。');
    }
    if (!array_key_exists('body', $context)) {
      return self::dimension('内部链接', 5, 0, 'unchecked', '没有读到正文，不能默认有内部链接。');
    }
    $body = (string) $context['body'];
    if (trim($body) === '') {
      return self::dimension('内部链接', 5, 0, 'checked', '正文为空，没有内部链接。');
    }
    if (preg_match('/<a\s|href=|\/node\/|\/taxonomy\//i', $body) === 1) {
      return self::dimension('内部链接', 5, 5, 'checked', '正文里有链接。');
    }
    return self::dimension('内部链接', 5, 1, 'checked', '正文里没有链接。');
  }

  /**
   * @return array{label: string, weight: int, points: int, status: string, reason: string}
   *   One dimension row.
   */
  private static function dimension(string $label, int $weight, int $points, string $status, string $reason): array {
    return [
      'label' => $label,
      'weight' => $weight,
      'points' => max(0, min($weight, $points)),
      'status' => $status,
      'reason' => $reason,
    ];
  }

  private static function descriptionIsTitleOnly(string $title, string $description): bool {
    if ($title === '' || $description === $title) {
      return $title !== '';
    }
    if (!str_starts_with($description, $title)) {
      return FALSE;
    }
    $rest = trim(mb_substr($description, mb_strlen($title)), " \t-—|｜:：");
    return mb_strlen($rest) <= 12;
  }

  private static function overlaps(string $needle, string $hay): bool {
    $needle = trim($needle);
    $hay = trim($hay);
    if ($needle === '' || $hay === '' || mb_strlen($needle) < 2) {
      return FALSE;
    }
    $length = mb_strlen($needle);
    for ($i = 0; $i < $length - 1; $i++) {
      if (mb_strpos($hay, mb_substr($needle, $i, 2)) !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
