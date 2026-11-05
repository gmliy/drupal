<?php

namespace Drupal\xedc_tags\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\xedc_tags\TagAiAnalyzer;
use Drupal\xedc_tags\TagCatalog;
use Drupal\xedc_tags\TagDuplicateDetector;
use Drupal\xedc_tags\TagHealthInspector;
use Drupal\xedc_tags\TagRecommender;
use Drupal\xedc_tags\TagSeoInspector;
use Drupal\xedc_tags\TagTaskManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes tag analysis tasks outside the request cycle.
 */
#[QueueWorker(
  id: 'xedc_tags_scan',
  title: new TranslatableMarkup('XEDC tag analysis scan'),
  cron: ['time' => 25],
)]
final class TagScanWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private TagTaskManager $tasks,
    private TagCatalog $catalog,
    private TagDuplicateDetector $duplicates,
    private TagHealthInspector $health,
    private TagSeoInspector $seo,
    private TagAiAnalyzer $ai,
    private TagRecommender $recommender,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('xedc_tags.tasks'),
      $container->get('xedc_tags.catalog'),
      $container->get('xedc_tags.duplicates'),
      $container->get('xedc_tags.health'),
      $container->get('xedc_tags.seo'),
      $container->get('xedc_tags.ai'),
      $container->get('xedc_tags.recommender'),
    );
  }

  public function processItem($data): void {
    $taskId = (int) ($data['task_id'] ?? 0);
    $this->tasks->expireStale();
    $task = $this->tasks->load($taskId);
    if (!$task || in_array($task->status, ['cancelled', 'completed', 'failed', 'partial'], TRUE)) {
      return;
    }
    if ($task->status === 'paused') {
      return;
    }
    // Claim the task only while it is still pending/running.
    $claimed = \Drupal::database()->update('xedc_tag_task')->fields([
      'status' => 'running',
      'started' => $task->started ?: time(),
      'message' => '执行中',
      'changed' => time(),
    ])
      ->condition('id', $taskId)
      ->condition('status', ['pending', 'running'], 'IN')
      ->execute();
    if (!$claimed) {
      return;
    }
    try {
      // Analysis workers are read-only suggestions; cancel mid-flight must not
      // overwrite the cancelled status with completed/failed.
      if ($this->wasCancelled($taskId)) {
        return;
      }
      $result = match ($task->type) {
        'duplicate_scan' => $this->runDuplicates(),
        'health_scan', 'full_scan' => $this->runHealth($task->type === 'full_scan'),
        'seo_scan' => $this->runSeo(),
        'ai_batch' => $this->runAi(),
        'recommend_scan' => $this->runRecommend(),
        default => ['total' => 0, 'processed' => 0, 'success' => 0, 'failed' => 1, 'result' => ['error' => 'unknown'], 'message' => '未知类型'],
      };
      if ($this->wasCancelled($taskId)) {
        return;
      }
      $failed = (int) $result['failed'];
      $status = $failed > 0 && (int) $result['success'] > 0 ? 'partial' : ($failed > 0 ? 'failed' : 'completed');
      $this->tasks->finishIfActive($taskId, [
        'status' => $status,
        'total' => (int) $result['total'],
        'processed' => (int) $result['processed'],
        'success' => (int) $result['success'],
        'failed' => $failed,
        'message' => (string) $result['message'],
        'result' => json_encode($result['result'], JSON_UNESCAPED_UNICODE) ?: '{}',
      ]);
      \Drupal::service('cache_tags.invalidator')->invalidateTags($this->catalog->cacheTags());
    }
    catch (\Throwable $e) {
      $this->tasks->finishIfActive($taskId, [
        'status' => 'failed',
        'message' => mb_substr($e->getMessage(), 0, 500),
      ]);
    }
  }

  private function wasCancelled(int $taskId): bool {
    $task = $this->tasks->load($taskId);
    return !$task || $task->status === 'cancelled' || $task->status === 'paused';
  }

  /**
   * @return array<string, mixed>
   */
  private function runDuplicates(): array {
    $groups = $this->duplicates->groups();
    return [
      'total' => count($groups),
      'processed' => count($groups),
      'success' => count($groups),
      'failed' => 0,
      'message' => '重复/相似组 ' . count($groups),
      'result' => ['groups' => count($groups)],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function runHealth(bool $full): array {
    $report = $this->health->report();
    if ($full) {
      $groups = $this->duplicates->groups();
      $seo = $this->seo->all();
      $report['duplicate_groups'] = count($groups);
      $report['seo_open'] = count($this->seo->openTids());
      $report['seo_checked'] = count($seo);
    }
    return [
      'total' => (int) $report['totals']['terms'],
      'processed' => (int) $report['totals']['terms'],
      'success' => (int) $report['totals']['terms'],
      'failed' => 0,
      'message' => '健康问题 ' . $report['totals']['issues'],
      'result' => [
        'totals' => $report['totals'],
        'severity' => $report['severity'],
        'threshold' => $report['threshold'],
        'trend' => $report['trend'],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function runSeo(): array {
    $rows = $this->seo->all();
    $open = $this->seo->openTids();
    return [
      'total' => count($rows),
      'processed' => count($rows),
      'success' => count($rows),
      'failed' => 0,
      'message' => 'SEO 待处理 ' . count($open),
      'result' => ['open' => count($open)],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function runAi(): array {
    if (!$this->ai->available()) {
      return [
        'total' => 0,
        'processed' => 0,
        'success' => 0,
        'failed' => 1,
        'message' => 'AI 不可用',
        'result' => ['error' => 'ai_unavailable'],
      ];
    }
    $terms = array_values($this->catalog->terms());
    usort($terms, static fn(array $a, array $b): int => ((int) $b['published_any']) <=> ((int) $a['published_any']));
    $slice = array_slice($terms, 0, 5);
    $success = 0;
    $failed = 0;
    $suggestions = 0;
    foreach ($slice as $term) {
      $result = $this->ai->analyzeTerm((int) $term['tid']);
      if ($result['ok']) {
        $success++;
        $suggestions += count($result['suggestions']);
      }
      else {
        $failed++;
      }
    }
    return [
      'total' => count($slice),
      'processed' => count($slice),
      'success' => $success,
      'failed' => $failed,
      'message' => 'AI 建议 ' . $suggestions . ' 条',
      'result' => ['suggestions' => $suggestions],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function runRecommend(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', ['photo', 'video', 'article'], 'IN')
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, 20)
      ->execute();
    $success = 0;
    $withSuggestions = 0;
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->hasField('field_tags')) {
        continue;
      }
      $input = $this->recommender->fromNode($node);
      if ($input['current']) {
        $success++;
        continue;
      }
      $suggestions = $this->recommender->suggest($input['title'], $input['body'], $input['description'], $input['current'], $input['bundle']);
      $success++;
      if ($suggestions) {
        $withSuggestions++;
      }
    }
    return [
      'total' => count($ids),
      'processed' => count($ids),
      'success' => $success,
      'failed' => 0,
      'message' => '无标签内容可推荐 ' . $withSuggestions,
      'result' => ['untagged_with_suggestions' => $withSuggestions],
    ];
  }

}
