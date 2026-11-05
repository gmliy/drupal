<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Seo;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Review\ReviewRepository;
use Drupal\xedc_ai\Review\TextExtractor;

/**
 * Resolves a content ID to the node a person is allowed to analyze.
 */
final class ContentLookup {

  public const BUNDLES = ['article', 'photo', 'video', 'page'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly TextExtractor $extractor,
    private readonly ModerationInformationInterface $moderation,
    private readonly DateFormatterInterface $dates,
    private readonly ReviewRepository $reviews,
  ) {}

  /**
   * @return array<string, mixed>
   *   Preview or a specific error. Titles are omitted when access is denied.
   */
  public function identify(int $nid, int $revisionId, AccountInterface $account, string $bundle = ''): array {
    if ($nid < 1) {
      return $this->error('请输入内容 ID。');
    }
    $node = $this->entities->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      return $this->error('未找到对应内容');
    }
    if ($bundle !== '' && $bundle !== 'all' && $node->bundle() !== $bundle) {
      return $this->error('内容 ' . $nid . ' 的类型是' . Labels::bundle($node->bundle()) . '，与当前筛选不一致。');
    }
    if (!in_array($node->bundle(), self::BUNDLES, TRUE)) {
      return $this->error('内容 ' . $nid . ' 的类型不能做 SEO 分析。');
    }
    if (!$node->access('view', $account)) {
      return $this->error('没有权限查看内容 ' . $nid . '。');
    }
    $selected = $node;
    $historical = FALSE;
    if ($revisionId > 0) {
      $selected = $this->extractor->loadRevision('node', $nid, $revisionId, TRUE);
      if (!$selected instanceof NodeInterface) {
        return $this->error('修订版本 ' . $revisionId . ' 不存在，或不属于内容 ' . $nid . '。');
      }
      if (!$selected->access('view', $account)) {
        return $this->error('没有权限查看内容 ' . $nid . ' 的修订版本 ' . $revisionId . '。');
      }
      $historical = (int) $selected->getRevisionId() !== (int) $node->getRevisionId();
    }
    $fields = $this->extractor->extract($selected);
    $text = trim((string) $fields['title'] . (string) $fields['summary'] . (string) $fields['body']);
    if ($text === '' && ($fields['alts'] ?? []) === []) {
      return $this->error('内容 ' . $nid . ' 没有可分析的文字。');
    }
    [$workflow, $state, $stateLabel] = $this->workflow($selected);
    $latest = $this->reviews->latestForEntity($nid, 'seo_optimization');
    $ops = 'unanalyzed';
    if ($latest !== NULL) {
      $stored = json_decode((string) ($latest['result'] ?? ''), TRUE);
      $stored = SeoResult::hydrate(is_array($stored) ? $stored : []);
      $ops = SeoResult::opsStatus((string) $latest['status'], (string) $latest['handled_status'], $stored, FALSE);
    }
    return [
      'ok' => TRUE,
      'message' => '',
      'content' => [
        'bundle' => (string) $selected->bundle(),
        'bundle_label' => Labels::bundle((string) $selected->bundle()),
        'title' => (string) $selected->label(),
        'nid' => (int) $selected->id(),
        'vid' => (int) $selected->getRevisionId(),
        'current_vid' => (int) $node->getRevisionId(),
        'historical' => $historical,
        'created' => $this->dates->format((int) $selected->getCreatedTime(), 'custom', 'Y-m-d H:i'),
        'status' => $selected->isPublished() ? '已发布' : '未发布',
        'published' => $selected->isPublished(),
        'publish_label' => $selected->isPublished() ? '已发布' : '未发布',
        'publish_time' => $this->publishTime($selected),
        'workflow' => $workflow,
        'state' => $state,
        'state_label' => Labels::moderation($state, $stateLabel),
        'moderation_label' => Labels::moderation($state, $stateLabel),
        'url' => Url::fromRoute('entity.node.canonical', ['node' => $selected->id()])->toString(),
        'edit_url' => $selected->access('update', $account) ? Url::fromRoute('entity.node.edit_form', ['node' => $selected->id()])->toString() : '',
        'ops' => Labels::seoOps($ops),
        'meta_title' => (string) $fields['meta_title'],
        'meta_description' => (string) $fields['meta_description'],
      ],
    ];
  }

  /**
   * Title, type and status for the content ID field. Does not call a model.
   *
   * @return array<string, mixed>
   *   Preview. The title is omitted when the account cannot view the node.
   */
  public function preview(int $nid, AccountInterface $account): array {
    if ($nid < 1) {
      return $this->error('请输入有效的内容 ID。');
    }
    $node = $this->entities->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      return $this->error('未找到对应内容');
    }
    if (!$node->access('view', $account)) {
      return $this->error('没有权限查看这个内容。');
    }
    $allowed = in_array($node->bundle(), self::BUNDLES, TRUE);
    return [
      'ok' => $allowed,
      'message' => $allowed ? '' : '这个内容类型不能做 SEO 分析。',
      'content' => [
        'title' => (string) $node->label(),
        'bundle_label' => $this->typeLabel($node),
        'status_label' => $this->previewStatus($node),
        'nid' => (int) $node->id(),
      ],
    ];
  }

  /**
   * @return list<array<string, mixed>>
   *   Title matches the account can view.
   */
  public function search(string $text, string $bundle, AccountInterface $account): array {
    $text = trim($text);
    if ($text === '') {
      return [];
    }
    $storage = $this->entities->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)->range(0, 8)->sort('changed', 'DESC');
    $bundles = $bundle !== '' && $bundle !== 'all' ? [$bundle] : self::BUNDLES;
    $query->condition('type', $bundles, 'IN');
    if (preg_match('/^\d+$/', $text)) {
      $query->condition('nid', (int) $text);
    }
    else {
      $query->condition('title', $text, 'CONTAINS');
    }
    $ids = $query->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->access('view', $account)) {
        continue;
      }
      $rows[] = [
        'nid' => (int) $node->id(),
        'vid' => (int) $node->getRevisionId(),
        'title' => (string) $node->label(),
        'bundle_label' => Labels::bundle($node->bundle()),
      ];
    }
    return $rows;
  }

  private function typeLabel(NodeInterface $node): string {
    try {
      $type = $this->entities->getStorage('node_type')->load($node->bundle());
      if ($type !== NULL) {
        $label = trim((string) $type->label());
        if ($label !== '' && $label !== $node->bundle()) {
          return $label;
        }
      }
    }
    catch (\Throwable) {
      // Fall back to the static map.
    }
    return Labels::bundle($node->bundle());
  }

  /**
   * Editorial status when a workflow is really enabled, otherwise published.
   */
  private function previewStatus(NodeInterface $node): string {
    try {
      if ($node->hasField('moderation_state') && $this->moderation->isModeratedEntity($node)) {
        $state = (string) $node->get('moderation_state')->value;
        $workflow = $this->moderation->getWorkflowForEntity($node);
        $label = $state;
        if ($workflow !== NULL && $state !== '' && method_exists($workflow, 'status') && $workflow->status()) {
          try {
            $label = (string) $workflow->getTypePlugin()->getState($state)->label();
          }
          catch (\Throwable) {
            $label = $state;
          }
          $text = Labels::moderation($state, $label);
          if ($text !== '' && $text !== $state && $text !== '未启用审核') {
            return $text;
          }
          return '未能读取';
        }
        if ($workflow === NULL) {
          return '未能读取';
        }
      }
    }
    catch (\Throwable) {
      return '未能读取';
    }
    return $node->isPublished() ? '已发布' : '未发布';
  }

  /**
   * @return array{0: string, 1: string, 2: string}
   *   Workflow id, state id and state label.
   */
  private function workflow(NodeInterface $node): array {
    if (!$node->hasField('moderation_state') || !$this->moderation->isModeratedEntity($node)) {
      return ['', '', ''];
    }
    $state = (string) $node->get('moderation_state')->value;
    $workflow = $this->moderation->getWorkflowForEntity($node);
    $label = $state;
    if ($workflow !== NULL && $state !== '') {
      try {
        $label = (string) $workflow->getTypePlugin()->getState($state)->label();
      }
      catch (\Throwable) {
        $label = $state;
      }
    }
    return [$workflow ? (string) $workflow->id() : '', $state, $label];
  }

  private function publishTime(NodeInterface $node): string {
    if (!$node->isPublished()) {
      return '暂无';
    }
    if ($node->hasField('field_published_at') && !$node->get('field_published_at')->isEmpty()) {
      try {
        $date = new \Drupal\Core\Datetime\DrupalDateTime((string) $node->get('field_published_at')->value, 'UTC');
        return $this->dates->format($date->getTimestamp(), 'custom', 'Y-m-d H:i');
      }
      catch (\Throwable) {
        return '暂无';
      }
    }
    return '暂无';
  }

  /**
   * @return array<string, mixed>
   *   Error payload.
   */
  private function error(string $message): array {
    return ['ok' => FALSE, 'message' => $message, 'content' => NULL];
  }

}
