<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\xedc_auto_translate\Translator;
use Psr\Log\LoggerInterface;

/**
 * Queues and runs interface translation without touching content translation.
 */
final class UiTranslationManager {

  private bool $processing = FALSE;

  public function __construct(
    private readonly StringCollector $collector,
    private readonly ProtectedTermGuard $guard,
    private readonly TranslationValidator $validator,
    private readonly OfficialPackImporter $official,
    private readonly LocaleTranslationWriter $writer,
    private readonly TranslationTaskRepository $repository,
    private readonly Translator $translator,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly QueueFactory $queueFactory,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return list<string>
   */
  public function enabledModules(): array {
    $names = array_keys($this->moduleHandler->getModuleList());
    sort($names);
    return $names;
  }

  /**
   * @return list<string>
   */
  public function pendingModules(): array {
    $queued = $this->state->get('xedc_ui_locale.queue', []);
    if (!is_array($queued)) {
      return [];
    }
    $modules = [];
    foreach (array_keys($queued) as $key) {
      $modules[] = explode(':', (string) $key, 2)[0];
    }
    return array_values(array_unique($modules));
  }

  /**
   * @param list<string> $modules
   */
  public function enqueueModules(array $modules, string $operation): void {
    foreach ($this->normalize($modules) as $module) {
      $this->enqueue($module, $operation, 0);
    }
    $this->state->set('xedc_ui_locale.kick', 1);
  }

  public function enqueue(string $module, string $operation, int $attempt): void {
    $operation = $this->operation($operation);
    $reset = in_array($operation, ['full', 'official'], TRUE) && $attempt === 0;
    $this->repository->ensureJob($module, $operation, $reset);
    if ($operation === 'retry' && $attempt === 0) {
      $this->repository->resetFailed($module);
    }
    $queued = $this->queuedMap();
    $key = $module . ':' . $operation;
    if (!isset($queued[$key])) {
      $this->queue()->createItem([
        'module' => $module,
        'operation' => $operation,
        'attempt' => $attempt,
      ]);
      $queued[$key] = $attempt;
      $this->state->set('xedc_ui_locale.queue', $queued);
    }
    $this->state->set('xedc_ui_locale.kick', 1);
  }

  public function clearQueued(string $module, string $operation): void {
    $queued = $this->queuedMap();
    unset($queued[$module . ':' . $this->operation($operation)]);
    $this->state->set('xedc_ui_locale.queue', $queued);
  }

  /**
   * @param array<string, mixed> $data
   */
  public function handleQueueItem(array $data): void {
    $module = trim((string) ($data['module'] ?? ''));
    $operation = $this->operation((string) ($data['operation'] ?? 'full'));
    $attempt = (int) ($data['attempt'] ?? 0);
    if ($module === '') {
      return;
    }
    $this->clearQueued($module, $operation);
    try {
      $more = $this->step($module, $operation, microtime(TRUE) + 25);
    }
    catch (\Throwable $e) {
      $message = $this->redact($e->getMessage());
      $this->record($module, 'error', $message);
      $status = $attempt + 1 >= UiLocale::MAX_ATTEMPTS ? 'failed' : 'retry';
      $this->repository->updateJob($module, [
        'status' => $status,
        'attempts' => $attempt + 1,
        'message' => $message,
      ]);
      if ($status === 'retry') {
        $this->enqueue($module, $operation, $attempt + 1);
      }
      return;
    }
    if ($more) {
      $job = $this->repository->getJob($module);
      $next = ((int) ($job['official_done'] ?? 0) === 1 && $operation !== 'official') ? 'missing' : $operation;
      $this->enqueue($module, $next, $attempt);
    }
  }

  public function processQueue(int $seconds): void {
    if ($this->processing || $seconds < 1) {
      return;
    }
    $this->processing = TRUE;
    $deadline = microtime(TRUE) + $seconds;
    try {
      while (microtime(TRUE) < $deadline) {
        $item = $this->queue()->claimItem(90);
        if (!$item) {
          break;
        }
        try {
          $this->handleQueueItem((array) $item->data);
          $this->queue()->deleteItem($item);
        }
        catch (\Throwable $e) {
          $this->queue()->releaseItem($item);
          $this->record('', 'error', $this->redact($e->getMessage()));
          break;
        }
      }
    }
    finally {
      $this->processing = FALSE;
    }
  }

  /**
   * @param list<string> $modules
   * @return array<string, mixed>
   */
  public function run(array $modules, string $operation): array {
    $operation = $this->operation($operation);
    $errors = [];
    $modules_out = [];
    foreach ($this->normalize($modules) as $module) {
      if (!$this->moduleHandler->moduleExists($module)) {
        $errors[] = sprintf('模块 %s 未启用。', $module);
        continue;
      }
      $this->repository->ensureJob($module, $operation, in_array($operation, ['full', 'official'], TRUE));
      if ($operation === 'retry') {
        $this->repository->resetFailed($module);
      }
      $guard = 0;
      try {
        while ($this->step($module, $operation, microtime(TRUE) + 90) && ++$guard < 500) {
          // The next step continues official import or the next AI batch.
        }
      }
      catch (\Throwable $e) {
        $message = $this->redact($e->getMessage());
        $errors[] = $module . ': ' . $message;
        $this->record($module, 'error', $message);
        $this->repository->updateJob($module, [
          'status' => 'failed',
          'message' => $message,
        ]);
      }
      $job = $this->repository->getJob($module);
      if (($job['status'] ?? '') === 'failed' && !empty($job['message'])) {
        $errors[] = $module . ': ' . $job['message'];
      }
      $samples = array_map(
        static fn (array $row): string => (string) $row['source'],
        $this->repository->untranslated(30, $module)
      );
      $modules_out[$module] = [
        'official' => (int) ($job['official_count'] ?? 0),
        'api' => (int) ($job['ai_count'] ?? 0),
        'remaining' => (int) ($job['remaining_count'] ?? 0),
        'samples' => $samples,
        'status' => (string) ($job['status'] ?? ''),
        'message' => (string) ($job['message'] ?? ''),
      ];
    }
    $platform = $this->platform();
    return [
      'added' => array_sum(array_column($modules_out, 'official')) + array_sum(array_column($modules_out, 'api')),
      'brand_skipped' => 0,
      'files' => [],
      'errors' => array_values(array_unique($errors)),
      'modules' => $modules_out,
      'provider' => $platform['provider_label'],
      'api_ready' => $platform['key_configured'],
    ];
  }

  /**
   * @param list<string> $modules
   * @return array<string, mixed>
   */
  public function fill(array $modules): array {
    return $this->run($modules, 'full');
  }

  /**
   * Processes one slice of a module. TRUE means the queue should continue.
   */
  public function step(string $module, string $operation, float $deadline): bool {
    $operation = $this->operation($operation);
    if (!$this->moduleHandler->moduleExists($module)) {
      $this->repository->updateJob($module, [
        'status' => 'failed',
        'message' => '模块未启用。',
      ]);
      return FALSE;
    }
    $job = $this->repository->getJob($module);
    if ($job === []) {
      $this->repository->ensureJob($module, $operation, FALSE);
      $job = $this->repository->getJob($module);
    }
    $meta = $this->collector->meta($module);
    $terms = $this->guard->termsForModule([
      $meta['label'],
      $meta['machine'],
      $meta['project'],
      $meta['package'],
    ]);
    $strings = $this->collector->collect($module);
    $official_done = (int) ($job['official_done'] ?? 0) === 1;
    if (!$official_done) {
      if ((int) ($job['official_seek'] ?? 0) === 0 && (string) ($job['official_file'] ?? '') === '') {
        $this->rememberExisting($module, $strings);
        $this->lockProtected($module, $strings, $terms, (string) $meta['path']);
      }
      $this->repository->updateJob($module, ['status' => 'official']);
      $imported = $this->official->import(
        $module,
        (int) ($job['official_seek'] ?? 0),
        (string) ($job['official_file'] ?? ''),
        $deadline,
        $terms
      );
      $message = $imported['error'] ? $this->redact((string) $imported['error']) : (string) ($job['message'] ?? '');
      $this->repository->updateJob($module, [
        'official_seek' => $imported['seek'],
        'official_file' => $imported['file'],
        'message' => $message,
      ]);
      if ($imported['error']) {
        $this->record($module, $imported['unavailable'] ? 'notice' : 'error', $message);
      }
      if (!$imported['finished']) {
        $this->markImported($module, $strings);
        return TRUE;
      }
      $this->repository->updateJob($module, [
        'official_done' => 1,
        'official_seek' => 0,
      ]);
      $this->markImported($module, $strings);
      if ($operation === 'official') {
        $this->finish($module);
        return FALSE;
      }
    }
    elseif ($operation === 'official') {
      $this->finish($module);
      return FALSE;
    }

    $pending = $this->pendingStrings($module);
    if ($pending === []) {
      $this->finish($module);
      return FALSE;
    }
    if ($this->translator->apiKey() === '') {
      $message = sprintf('未配置 %s API Key，已跳过自动翻译。', $this->translator->providerLabel());
      $this->failPending($module, $pending, $message, FALSE);
      $this->repository->updateJob($module, [
        'status' => 'failed',
        'message' => $message,
      ]);
      $this->record($module, 'error', $message);
      return FALSE;
    }
    $this->repository->updateJob($module, ['status' => 'ai']);
    $batch = array_slice($pending, 0, $this->batchSize());
    try {
      $this->translateBatch($module, $batch, $terms, (string) $meta['path']);
    }
    catch (\Throwable $e) {
      $message = $this->redact($e->getMessage());
      $retry = !$this->isPermanentApiError($e);
      $this->failPending($module, $batch, $message, $retry);
      $this->record($module, 'error', $message);
      $this->repository->recount($module);
      $this->repository->updateJob($module, [
        'status' => $retry ? 'retry' : 'failed',
        'message' => $message,
      ]);
      return $retry;
    }
    $this->refreshTranslations();
    $this->repository->recount($module);
    if ($this->repository->itemsByStatus($module, ['pending', 'retry'], 1) !== []) {
      return TRUE;
    }
    $this->finish($module);
    return FALSE;
  }

  /**
   * @return array<string, int|string|bool>
   */
  public function platform(): array {
    $config = $this->configFactory->get('xedc_auto_translate.settings');
    $provider = $this->translator->provider();
    return [
      'service' => 'xedc_auto_translate.translator',
      'method' => 'translatePlainTexts',
      'config' => 'xedc_auto_translate.settings',
      'provider' => $provider,
      'provider_label' => $this->translator->providerLabel(),
      'key_storage' => 'state:xedc_auto_translate.api_key.' . $provider,
      'key_configured' => $this->translator->apiKey() !== '',
      'deepl_endpoint' => (string) $config->get('deepl_endpoint'),
      'microsoft_endpoint' => trim((string) $config->get('microsoft_endpoint')) === '' ? 'default' : 'custom',
      'region' => $provider === 'microsoft' ? $this->translator->microsoftRegion() : '',
      'model' => '',
    ];
  }

  /**
   * @return array<string, int>
   */
  public function statistics(): array {
    return $this->repository->statistics(count($this->enabledModules()));
  }

  /**
   * @param list<array{source: string, context: string, file: string}> $strings
   */
  private function rememberExisting(string $module, array $strings): void {
    foreach ($strings as $item) {
      $current = $this->repository->findItem($module, $item['source'], $item['context']);
      $origin = (string) ($current['origin'] ?? '');
      if (!$this->writer->hasTranslation($item['source'], $item['context'])) {
        if ($origin === 'protected') {
          continue;
        }
        $this->repository->upsertItem($module, $item['source'], $item['context'], [
          'source' => $item['source'],
          'context' => $item['context'],
          'status' => 'pending',
          'origin' => '',
          'translation' => '',
          'reason' => '',
        ]);
        continue;
      }
      if (!in_array($origin, ['ai', 'official', 'protected'], TRUE)) {
        $origin = 'existing';
      }
      $this->repository->upsertItem($module, $item['source'], $item['context'], [
        'source' => $item['source'],
        'context' => $item['context'],
        'status' => $origin === 'protected' ? 'skipped' : 'completed',
        'origin' => $origin,
        'translation' => $this->writer->currentText($item['source'], $item['context']),
        'reason' => (string) ($current['reason'] ?? ''),
      ]);
    }
  }

  /**
   * @param list<array{source: string, context: string, file: string}> $strings
   * @param list<string> $terms
   */
  private function lockProtected(string $module, array $strings, array $terms, string $path): void {
    $locked = [];
    foreach ($terms as $term) {
      if ($term === '' || preg_match('/\p{Han}/u', $term)) {
        continue;
      }
      $locked[$term] = TRUE;
      if ($this->writer->hasTranslation($term, '')) {
        continue;
      }
      $result = $this->writer->apply($term, '', $term, LOCALE_CUSTOMIZED, FALSE, $path);
      if ($result === 'written') {
        $this->repository->upsertItem($module, $term, '', [
          'source' => $term,
          'context' => '',
          'translation' => $term,
          'origin' => 'protected',
          'status' => 'skipped',
          'reason' => '受保护名称保持原文',
        ]);
      }
    }
    foreach ($strings as $item) {
      if (!$this->guard->isExactTerm($item['source'], $terms) || isset($locked[$item['source']])) {
        continue;
      }
      if ($this->writer->hasTranslation($item['source'], $item['context'])) {
        continue;
      }
      $result = $this->writer->apply($item['source'], $item['context'], $item['source'], LOCALE_CUSTOMIZED, FALSE, $path . '/' . $item['file']);
      if ($result === 'written') {
        $this->repository->upsertItem($module, $item['source'], $item['context'], [
          'source' => $item['source'],
          'context' => $item['context'],
          'translation' => $item['source'],
          'origin' => 'protected',
          'status' => 'skipped',
          'reason' => '受保护名称保持原文',
        ]);
      }
    }
  }

  /**
   * @param list<array{source: string, context: string, file: string}> $strings
   */
  private function markImported(string $module, array $strings): void {
    foreach ($strings as $item) {
      if (!$this->writer->hasTranslation($item['source'], $item['context'])) {
        continue;
      }
      $current = (string) ($this->repository->findItem($module, $item['source'], $item['context'])['origin'] ?? '');
      if (in_array($current, ['existing', 'protected', 'ai', 'official'], TRUE)) {
        continue;
      }
      $this->repository->upsertItem($module, $item['source'], $item['context'], [
        'source' => $item['source'],
        'context' => $item['context'],
        'translation' => $this->writer->currentText($item['source'], $item['context']),
        'status' => 'completed',
        'origin' => 'official',
        'reason' => '',
      ]);
    }
    $this->repository->recount($module);
    $this->refreshTranslations();
  }

  /**
   * @return list<array{source: string, context: string, file: string}>
   */
  private function pendingStrings(string $module): array {
    $rows = $this->repository->itemsByStatus($module, ['pending', 'retry'], 500);
    $pending = [];
    foreach ($rows as $row) {
      if ($this->writer->hasTranslation((string) $row['source'], (string) $row['context'])) {
        $origin = (string) ($row['origin'] ?? '');
        $this->repository->upsertItem($module, (string) $row['source'], (string) $row['context'], [
          'source' => (string) $row['source'],
          'context' => (string) $row['context'],
          'status' => 'completed',
          'origin' => $origin !== '' && $origin !== 'ai' ? $origin : 'existing',
          'reason' => '',
        ]);
        continue;
      }
      $pending[] = [
        'source' => (string) $row['source'],
        'context' => (string) $row['context'],
        'file' => '',
      ];
    }
    return $pending;
  }

  /**
   * @param list<array{source: string, context: string, file: string}> $batch
   * @param list<string> $terms
   */
  private function translateBatch(string $module, array $batch, array $terms, string $path): void {
    $jobs = [];
    $flat = [];
    foreach ($batch as $item) {
      if ($this->guard->isExactTerm($item['source'], $terms)) {
        $this->writer->apply($item['source'], $item['context'], $item['source'], LOCALE_CUSTOMIZED, FALSE, $path);
        $this->repository->upsertItem($module, $item['source'], $item['context'], [
          'source' => $item['source'],
          'context' => $item['context'],
          'translation' => $item['source'],
          'origin' => 'protected',
          'status' => 'skipped',
          'reason' => '受保护名称保持原文',
        ]);
        continue;
      }
      $parts = [];
      $maps = [];
      $formats = [];
      $local = [];
      foreach ($this->guard->splitPlural($item['source']) as $part) {
        if (trim($part) === '') {
          $parts[] = '';
          $maps[] = [];
          $formats[] = 'text';
          $local[] = TRUE;
          continue;
        }
        [$shielded, $map] = $this->guard->shield($part, $terms);
        $parts[] = $shielded;
        $maps[] = $map;
        $formats[] = preg_match('/<\/?[a-z][\w:-]*(\s[^>]*)?>/i', $shielded) ? 'html' : 'text';
        $local[] = $this->guard->isTokenOnly($shielded);
      }
      $jobs[] = compact('item', 'parts', 'maps', 'formats', 'local');
    }
    foreach ($jobs as $job_index => $job) {
      foreach ($job['parts'] as $part_index => $part) {
        if ($part === '' || $job['local'][$part_index]) {
          continue;
        }
        $flat[] = [
          'job' => $job_index,
          'part' => $part_index,
          'text' => $part,
          'format' => $job['formats'][$part_index],
        ];
      }
    }
    $translated = array_fill(0, count($flat), NULL);
    foreach (['text', 'html'] as $format) {
      $indexes = [];
      $texts = [];
      foreach ($flat as $index => $row) {
        if ($row['format'] === $format) {
          $indexes[] = $index;
          $texts[] = $row['text'];
        }
      }
      if ($texts === []) {
        continue;
      }
      $out = $this->translator->translatePlainTexts($texts, $format);
      if (count($out) !== count($texts)) {
        throw new \RuntimeException('翻译 API 返回条数与请求不一致。');
      }
      foreach ($indexes as $position => $index) {
        $translated[$index] = $out[$position] ?? NULL;
      }
    }
    $cursor = 0;
    foreach ($jobs as $job) {
      $parts = [];
      $reason = NULL;
      foreach ($job['parts'] as $part_index => $part) {
        if ($part === '') {
          $parts[] = '';
          continue;
        }
        if ($job['local'][$part_index]) {
          $restored = $this->guard->unshield($part, $job['maps'][$part_index]);
        }
        else {
          $raw = $translated[$cursor] ?? NULL;
          $cursor++;
          if (!is_string($raw) || trim($raw) === '') {
            $reason = '翻译 API 没有返回这条字符串';
            break;
          }
          $restored = $this->guard->unshield($raw, $job['maps'][$part_index]);
        }
        if ($restored === NULL || trim($restored) === '') {
          $reason = '占位符没有还原';
          break;
        }
        $parts[] = $restored;
      }
      $source = $job['item']['source'];
      $context = $job['item']['context'];
      if ($reason !== NULL) {
        $this->repository->upsertItem($module, $source, $context, [
          'source' => $source,
          'context' => $context,
          'status' => 'failed',
          'reason' => $reason,
        ]);
        continue;
      }
      $translation = implode(UiLocale::PLURAL, $parts);
      $problem = $this->validator->validate($source, $translation, $terms, $this->guard);
      if ($problem !== NULL) {
        $status = $problem === '译文与原文相同' ? 'skipped' : 'failed';
        $this->repository->upsertItem($module, $source, $context, [
          'source' => $source,
          'context' => $context,
          'status' => $status,
          'reason' => $problem,
        ]);
        continue;
      }
      $written = $this->writer->apply($source, $context, $translation, LOCALE_NOT_CUSTOMIZED, FALSE, $path);
      if ($written === 'kept') {
        $this->repository->upsertItem($module, $source, $context, [
          'source' => $source,
          'context' => $context,
          'translation' => $translation,
          'origin' => 'existing',
          'status' => 'completed',
          'reason' => '已有译文，未覆盖',
        ]);
        continue;
      }
      if ($written !== 'written') {
        $this->repository->upsertItem($module, $source, $context, [
          'source' => $source,
          'context' => $context,
          'status' => 'failed',
          'reason' => '译文未通过安全检查',
        ]);
        continue;
      }
      $this->repository->upsertItem($module, $source, $context, [
        'source' => $source,
        'context' => $context,
        'translation' => $translation,
        'origin' => 'ai',
        'status' => 'completed',
        'reason' => '',
      ]);
    }
  }

  /**
   * @param list<array{source: string, context: string, file: string}> $rows
   */
  private function failPending(string $module, array $rows, string $message, bool $retry): void {
    foreach ($rows as $row) {
      $this->repository->upsertItem($module, $row['source'], $row['context'], [
        'source' => $row['source'],
        'context' => $row['context'],
        'status' => $retry ? 'retry' : 'failed',
        'reason' => $message,
      ]);
    }
    $this->repository->recount($module);
  }

  private function finish(string $module): void {
    $this->repository->recount($module);
    $job = $this->repository->getJob($module);
    $items = (int) $this->repository->browse(['module' => $module], 1, 0)['total'];
    if ($items === 0) {
      $status = 'skipped';
    }
    elseif ((int) ($job['failed_count'] ?? 0) > 0) {
      $status = 'failed';
    }
    else {
      $status = 'completed';
    }
    $this->repository->updateJob($module, ['status' => $status]);
    $this->refreshTranslations();
  }

  private function refreshTranslations(): void {
    if (function_exists('_locale_refresh_translations')) {
      _locale_refresh_translations([UiLocale::LANGCODE]);
    }
    $lookup = \Drupal::service('string_translator.locale.lookup');
    if (method_exists($lookup, 'reset')) {
      $lookup->reset();
    }
  }

  private function isPermanentApiError(\Throwable $e): bool {
    $message = $e->getMessage();
    return str_contains($message, 'API key is not configured')
      || str_contains($message, 'API Key')
      || (bool) preg_match('/\b(401|403)\b/', $message);
  }

  private function batchSize(): int {
    $size = (int) $this->configFactory->get('xedc_ui_locale.settings')->get('batch_size');
    if ($size < 5) {
      $size = 20;
    }
    return min(40, $size);
  }

  /**
   * @param list<string> $modules
   * @return list<string>
   */
  private function normalize(array $modules): array {
    $names = [];
    foreach ($modules as $module) {
      $module = trim((string) $module);
      if ($module !== '') {
        $names[$module] = $module;
      }
    }
    return array_values($names);
  }

  private function operation(string $operation): string {
    return in_array($operation, ['full', 'official', 'missing', 'retry'], TRUE) ? $operation : 'full';
  }

  /**
   * @return array<string, int>
   */
  private function queuedMap(): array {
    $queued = $this->state->get('xedc_ui_locale.queue', []);
    return is_array($queued) ? $queued : [];
  }

  private function queue(): \Drupal\Core\Queue\QueueInterface {
    $queue = $this->queueFactory->get(UiLocale::QUEUE);
    $queue->createQueue();
    return $queue;
  }

  private function record(string $module, string $level, string $message): void {
    $message = $this->redact($message);
    $this->repository->log($module, $level, $message);
    $context = ['module' => $module];
    if ($level === 'error') {
      $this->logger->error('{module}: {message}', $context + ['message' => $message]);
      return;
    }
    $this->logger->notice('{module}: {message}', $context + ['message' => $message]);
  }

  private function redact(string $message): string {
    $message = preg_replace('/(api[_-]?key|authorization|subscription-key|auth-key)\s*[:=]\s*\S+/i', '$1=[redacted]', $message) ?? $message;
    foreach (['deepl', 'microsoft'] as $provider) {
      $key = $this->translator->apiKey($provider);
      if ($key !== '' && str_contains($message, $key)) {
        $message = str_replace($key, '[redacted]', $message);
      }
    }
    return $message;
  }

}
