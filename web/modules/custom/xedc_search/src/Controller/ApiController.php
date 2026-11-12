<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\xedc_search\Query\QueryNormalizer;
use Drupal\xedc_search\Repository\HistoryRepository;
use Drupal\xedc_search\Service\SearchEngine;
use Drupal\xedc_search\Service\Suggester;
use Drupal\xedc_search\Service\Tracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API for search, suggestions, hot terms and history.
 */
final class ApiController extends ControllerBase {

  public function __construct(
    private readonly SearchEngine $engine,
    private readonly Suggester $suggester,
    private readonly Tracker $tracker,
    private readonly HistoryRepository $history,
    private readonly QueryNormalizer $normalizer,
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $searchConfig,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_search.engine'),
      $container->get('xedc_search.suggester'),
      $container->get('xedc_search.tracker'),
      $container->get('xedc_search.history'),
      $container->get('xedc_search.normalizer'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('logger.factory')->get('xedc_search'),
    );
  }

  public function keywords(Request $request): JsonResponse {
    $denied = $this->guard($request);
    if ($denied) {
      return $denied;
    }
    try {
      $q = $this->normalizer->clean($this->text($request, 'q'));
      $limit = max(1, min(8, (int) $request->query->get('limit', 5)));
      $items = $this->suggester->dynamicKeywords($q, $this->text($request, 'type'), $limit);
      $cache = $q === '' ? 30 : 0;
      return $this->ok(['q' => $q, 'items' => $items], $cache);
    }
    catch (\Throwable $exception) {
      return $this->failure($exception);
    }
  }

  public function suggest(Request $request): JsonResponse {
    $denied = $this->guard($request);
    if ($denied) {
      return $denied;
    }
    try {
      $data = $this->suggester->suggest($this->text($request, 'q'), $this->text($request, 'type'));
      return $this->ok($data, 30);
    }
    catch (\Throwable $exception) {
      return $this->failure($exception);
    }
  }

  public function complete(Request $request): JsonResponse {
    $denied = $this->guard($request);
    if ($denied) {
      return $denied;
    }
    try {
      $q = $this->normalizer->clean($this->text($request, 'q'));
      $completion = $this->suggester->complete($q, $this->text($request, 'type'));
      return $this->ok(['q' => $q, 'completion' => $completion], 30);
    }
    catch (\Throwable $exception) {
      return $this->failure($exception);
    }
  }

  public function search(Request $request): JsonResponse {
    $denied = $this->guard($request);
    if ($denied) {
      return $denied;
    }
    try {
      $q = $this->normalizer->clean($this->text($request, 'q'));
      $type = $this->normalizer->type($this->text($request, 'type'));
      $sort = $this->normalizer->sort($this->text($request, 'sort'));
      $page = $this->pageNumber($request);
      $filters = $this->filters($request);
      if (in_array($type, ['model', 'tag', 'category'], TRUE)) {
        $vids = match ($type) {
          'model' => ['models'],
          'tag' => ['tags'],
          default => ['photo_category', 'video_category', 'article_category'],
        };
        $limit = max(1, min(24, (int) ($this->setting('page_size') ?: 12)));
        $found = $this->engine->terms($q, $vids, $limit, $page * $limit);
        return $this->ok([
          'q' => $q,
          'type' => $type,
          'sort' => $sort,
          'page' => $page,
          'limit' => $limit,
          'total' => $found['total'],
          'items' => $found['items'],
        ], 30);
      }
      $found = $this->engine->page($q, $type, $sort, $page, $filters);
      return $this->ok([
        'q' => $q,
        'type' => $type,
        'sort' => $sort,
        'page' => $page,
        'limit' => $found['limit'],
        'total' => $found['total'],
        'counts' => $this->engine->counts($q, $filters),
        'items' => $this->engine->items($found['nids']),
      ], 30);
    }
    catch (\Throwable $exception) {
      return $this->failure($exception);
    }
  }

  public function hot(Request $request): JsonResponse {
    $denied = $this->guard($request);
    if ($denied) {
      return $denied;
    }
    if (!(bool) $this->setting('hot_enabled')) {
      return $this->ok(['window' => 'week', 'type' => 'all', 'items' => []], 30);
    }
    try {
      $window = $this->normalizer->window($this->text($request, 'window'), (string) $this->setting('hot_window'));
      $type = $this->normalizer->context($this->text($request, 'type') ?: $this->text($request, 'context'));
      $limit = max(1, min(10, (int) $request->query->get('limit', 3)));
      $items = [];
      foreach (\Drupal::service('xedc_search.stats')->hot($window, $limit, FALSE, $type) as $row) {
        $items[] = [
          'label' => $row['keyword'],
          'count' => (int) $row['searches'],
          'users' => (int) $row['users'],
          'url' => $this->hotUrl((string) $row['keyword'], $type),
        ];
      }
      $response = $this->ok(['window' => $window, 'type' => $type, 'items' => $items], 0);
      $response->headers->set('Cache-Control', 'private, no-store, must-revalidate');
      $response->headers->set('Pragma', 'no-cache');
      return $response;
    }
    catch (\Throwable $exception) {
      return $this->failure($exception);
    }
  }

  public function history(Request $request): JsonResponse {
    if (!(bool) $this->setting('history_enabled')) {
      return $this->ok(['storage' => 'off', 'items' => []], 0);
    }
    $account = $this->currentUser();
    if (!$account->isAuthenticated()) {
      return $this->ok(['storage' => 'local', 'items' => []], 0);
    }
    $type = $this->normalizer->context($this->text($request, 'type') ?: $this->text($request, 'context'));
    $limit = max(1, min(20, (int) $request->query->get('limit', 5)));
    $items = [];
    foreach ($this->history->list((int) $account->id(), $type, $limit) as $row) {
      $items[] = [
        'id' => (int) $row['id'],
        'q' => $row['keyword'],
        'type' => $row['bundle'],
        'created' => (int) $row['created'],
        'url' => $this->hotUrl((string) $row['keyword'], (string) $row['bundle']),
      ];
    }
    $response = $this->ok(['storage' => 'account', 'type' => $type, 'items' => $items], 0);
    $response->headers->set('Vary', 'Cookie');
    return $response;
  }

  public function historySave(Request $request): JsonResponse {
    if (!(bool) $this->setting('history_enabled')) {
      return $this->fail('disabled', '搜索历史已关闭', 403);
    }
    $payload = $this->json($request);
    $q = $this->normalizer->clean((string) ($payload['q'] ?? ''));
    if (mb_strlen($q) < max(1, (int) $this->setting('min_length'))) {
      return $this->fail('invalid', '关键词太短', 400);
    }
    $type = $this->normalizer->context((string) ($payload['type'] ?? 'all'));
    $id = $this->history->add((int) $this->currentUser()->id(), $q, $type);
    return $this->ok(['id' => $id, 'q' => $q, 'type' => $type], 0);
  }

  public function historyDelete(int $id): JsonResponse {
    $deleted = $this->history->delete((int) $this->currentUser()->id(), $id);
    if (!$deleted) {
      return $this->fail('not_found', '没有这条历史', 404);
    }
    return $this->ok(['id' => $id], 0);
  }

  public function historyClear(Request $request): JsonResponse {
    $type = $this->text($request, 'type') ?: $this->text($request, 'context');
    if ($type !== '') {
      $this->history->clear((int) $this->currentUser()->id(), $type);
      return $this->ok(['cleared' => TRUE, 'type' => $this->normalizer->context($type)], 0);
    }
    $this->history->clear((int) $this->currentUser()->id());
    return $this->ok(['cleared' => TRUE, 'type' => 'all'], 0);
  }

  public function track(Request $request): JsonResponse {
    $site = strtolower((string) $request->headers->get('sec-fetch-site', ''));
    if ($site !== '' && !in_array($site, ['same-origin', 'same-site', 'none'], TRUE)) {
      return $this->fail('forbidden', '拒绝跨站统计', 403);
    }
    $payload = $this->json($request);
    $visitor = isset($payload['visitor']) && is_string($payload['visitor']) ? $payload['visitor'] : NULL;
    try {
      $result = $this->tracker->record(
        (string) ($payload['q'] ?? ''),
        (string) ($payload['type'] ?? 'all'),
        $visitor,
        !empty($payload['history']),
      );
      return $this->ok($result, 0);
    }
    catch (\Throwable $exception) {
      return $this->failure($exception);
    }
  }

  private function guard(Request $request): ?JsonResponse {
    if (!(bool) $this->setting('enabled')) {
      return $this->fail('disabled', '搜索已关闭', 403);
    }
    $limit = max(1, (int) $this->setting('flood_limit') ?: 180);
    $window = max(1, (int) $this->setting('flood_window') ?: 60);
    if (!$this->flood->isAllowed('xedc_search.api', $limit, $window)) {
      return $this->fail('rate_limit', '请求过于频繁', 429);
    }
    $this->flood->register('xedc_search.api', $window);
    $q = $request->query->get('q');
    if ($q !== NULL && !is_string($q)) {
      return $this->fail('invalid', '关键词格式不正确', 400);
    }
    if (is_string($q) && mb_strlen($q) > 100) {
      return $this->fail('invalid', '关键词过长', 400);
    }
    return NULL;
  }

  private function text(Request $request, string $key): string {
    $value = $request->query->get($key, '');
    return is_string($value) ? $value : '';
  }

  private function pageNumber(Request $request): int {
    $page = $request->query->get('page', 0);
    if (is_array($page)) {
      $page = end($page);
    }
    $page = is_numeric($page) ? (int) $page : 0;
    return max(0, min(1000, $page));
  }

  /**
   * @return array<string, mixed>
   */
  private function filters(Request $request): array {
    $int = static function (Request $request, string $key): int {
      $value = $request->query->get($key);
      if (!is_scalar($value)) {
        return 0;
      }
      $value = (int) $value;
      return $value > 0 && $value < 100000000 ? $value : 0;
    };
    $day = static function (Request $request, string $key): string {
      $value = $request->query->get($key, '');
      return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    };
    return [
      'category' => $int($request, 'category'),
      'tag' => $int($request, 'tag'),
      'model' => $int($request, 'model'),
      'from' => $day($request, 'from'),
      'to' => $day($request, 'to'),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function json(Request $request): array {
    $data = json_decode($request->getContent(), TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * @param array<string, mixed> $data
   */
  private function ok(array $data, int $maxAge): JsonResponse {
    $response = new JsonResponse(['ok' => TRUE, 'data' => $data, 'error' => NULL]);
    $response->headers->set('Cache-Control', $maxAge > 0 ? 'private, max-age=' . $maxAge : 'private, no-store');
    return $response;
  }

  private function fail(string $code, string $message, int $status): JsonResponse {
    $response = new JsonResponse([
      'ok' => FALSE,
      'data' => NULL,
      'error' => ['code' => $code, 'message' => $message],
    ], $status);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  private function failure(\Throwable $exception): JsonResponse {
    $this->logger->error('Search API failed: @message', ['@message' => $exception->getMessage()]);
    return $this->fail('error', '搜索暂时不可用', 500);
  }

  private function setting(string $key): mixed {
    return $this->searchConfig->get('xedc_search.settings')->get($key);
  }

  private function hotUrl(string $keyword, string $type): string {
    $type = $this->normalizer->context($type);
    $query = ['q' => $keyword];
    if ($type !== 'all') {
      $query['type'] = $type;
    }
    return \Drupal\Core\Url::fromUserInput('/search', ['query' => $query])->toString();
  }

}
