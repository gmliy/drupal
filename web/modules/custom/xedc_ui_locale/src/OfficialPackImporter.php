<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale;

use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\locale\File\LocaleFile;
use Drupal\locale\File\LocaleFileManager;
use Drupal\locale\LocaleFetch;
use Drupal\locale\LocaleProjectRepository;
use Drupal\locale\LocaleSource;
use Psr\Log\LoggerInterface;

/**
 * Downloads and imports official zh-hans packs through Drupal Locale.
 */
final class OfficialPackImporter {

  public function __construct(
    private readonly LocaleProjectRepository $projects,
    private readonly LocaleFetch $fetch,
    private readonly LocaleFileManager $files,
    private readonly LocaleSource $localeSource,
    private readonly ModuleExtensionList $modules,
    private readonly LocaleTranslationWriter $writer,
    private readonly TranslationTaskRepository $repository,
    private readonly ProtectedTermGuard $guard,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @param list<string> $terms
   * @return array{added: int, replaced_ai: int, file: string, seek: int, finished: bool, unavailable: bool, error: ?string}
   */
  public function import(string $module, int $seek, string $file, float $deadline, array $terms): array {
    $result = [
      'added' => 0,
      'replaced_ai' => 0,
      'file' => $file,
      'seek' => $seek,
      'finished' => TRUE,
      'unavailable' => FALSE,
      'error' => NULL,
    ];
    if ($file === '' || $seek === 0) {
      $resolved = $this->resolveFile($module);
      if ($resolved['error'] !== NULL && $resolved['file'] === '') {
        $result['unavailable'] = TRUE;
        $result['error'] = $resolved['error'];
        $local = $this->importLocalFiles($module, $terms);
        $result['added'] += $local['added'];
        $result['replaced_ai'] += $local['replaced_ai'];
        return $result;
      }
      $result['file'] = $resolved['file'];
      $result['error'] = $resolved['error'];
      $file = $resolved['file'];
    }
    if ($file !== '' && file_exists($file)) {
      $imported = $this->importUri($file, $seek, $deadline, $terms, TRUE);
      $result['added'] += $imported['added'];
      $result['replaced_ai'] += $imported['replaced_ai'];
      $result['seek'] = $imported['seek'];
      $result['finished'] = $imported['finished'];
      if ($imported['error'] !== NULL) {
        $result['error'] = $imported['error'];
      }
    }
    elseif ($result['error'] === NULL) {
      $result['unavailable'] = TRUE;
      $result['error'] = '官方语言包不存在或下载失败。';
    }
    if ($result['finished']) {
      $local = $this->importLocalFiles($module, $terms);
      $result['added'] += $local['added'];
      $result['replaced_ai'] += $local['replaced_ai'];
      $this->markCurrent($module, $file);
    }
    return $result;
  }

  /**
   * @return array{file: string, error: ?string}
   */
  private function resolveFile(string $module): array {
    $project = $this->projectName($module);
    try {
      $this->projects->buildProjects();
      $known = $this->projects->getMultiple([$project]);
      if (!isset($known[$project]) || !$known[$project]->getStatus()) {
        return ['file' => '', 'error' => '官方没有提供该模块的简体中文语言包。'];
      }
      $context = [
        'finished' => 1,
        'results' => [],
        'sandbox' => [],
        'message' => '',
      ];
      $this->fetch->batchVersionCheck($project, UiLocale::LANGCODE, $context);
      $this->fetch->batchStatusCheck($project, UiLocale::LANGCODE, [
        'finish_feedback' => FALSE,
        'use_remote' => TRUE,
      ], $context);
      $source = $this->localeSource->loadSource($project, UiLocale::LANGCODE);
      $local = $source->files[LOCALE_TRANSLATION_LOCAL] ?? NULL;
      $ready = $local instanceof LocaleFile && file_exists($local->uri);
      if (($source->type ?? '') === LOCALE_TRANSLATION_REMOTE || !$ready) {
        if (($source->type ?? '') === LOCALE_TRANSLATION_REMOTE) {
          $this->fetch->batchDownload($project, UiLocale::LANGCODE, $context);
          $source = $this->localeSource->loadSource($project, UiLocale::LANGCODE);
          $local = $source->files[LOCALE_TRANSLATION_LOCAL] ?? NULL;
          $ready = $local instanceof LocaleFile && file_exists($local->uri);
        }
        if (!$ready) {
          $remote = $source->files[LOCALE_TRANSLATION_REMOTE] ?? NULL;
          if ($remote instanceof LocaleFile && $remote->uri !== '') {
            $downloaded = $this->files->downloadTranslationSource($remote);
            if ($downloaded instanceof LocaleFile && file_exists($downloaded->uri)) {
              $this->localeSource->saveSource($project, UiLocale::LANGCODE, LOCALE_TRANSLATION_LOCAL, $downloaded);
              return ['file' => $downloaded->uri, 'error' => NULL];
            }
            $context['results']['failed_files'][] = $remote;
          }
        }
      }
      if (!$ready || !$local instanceof LocaleFile) {
        $failed = $context['results']['failed_files'] ?? [];
        $error = $failed ? '官方语言包下载失败。' : '官方没有提供该模块的简体中文语言包。';
        return ['file' => '', 'error' => $error];
      }
      return ['file' => $local->uri, 'error' => NULL];
    }
    catch (\Throwable $e) {
      $this->logger->error('检查 {project} 的官方语言包失败：{message}', [
        'project' => $project,
        'message' => $e->getMessage(),
      ]);
      return ['file' => '', 'error' => $e->getMessage()];
    }
  }

  /**
   * @param list<string> $terms
   * @return array{added: int, replaced_ai: int}
   */
  private function importLocalFiles(string $module, array $terms): array {
    $counts = ['added' => 0, 'replaced_ai' => 0];
    if (!$this->modules->exists($module)) {
      return $counts;
    }
    $project = $this->projectName($module);
    $root = dirname(DRUPAL_ROOT);
    if (!is_file($root . '/composer.json')) {
      $root = DRUPAL_ROOT;
    }
    $module_path = DRUPAL_ROOT . '/' . $this->modules->getPath($module);
    $candidates = [
      $root . '/translations/' . $module . '.zh-hans.po',
      $root . '/translations/' . $project . '.zh-hans.po',
      $module_path . '/translations/zh-hans.po',
    ];
    foreach ($candidates as $path) {
      if (!is_file($path)) {
        continue;
      }
      $imported = $this->importUri($path, 0, microtime(TRUE) + 20, $terms, FALSE);
      $counts['added'] += $imported['added'];
      $counts['replaced_ai'] += $imported['replaced_ai'];
    }
    return $counts;
  }

  /**
   * @param list<string> $terms
   * @return array{added: int, replaced_ai: int, seek: int, finished: bool, error: ?string}
   */
  private function importUri(string $uri, int $seek, float $deadline, array $terms, bool $replace_ai): array {
    $result = [
      'added' => 0,
      'replaced_ai' => 0,
      'seek' => $seek,
      'finished' => TRUE,
      'error' => NULL,
    ];
    $reader = new PoStreamReader();
    $reader->setURI($uri);
    $reader->setLangcode(UiLocale::LANGCODE);
    try {
      $reader->open();
      if (!$reader->getHeader()) {
        throw new \RuntimeException('PO 文件缺少 header：' . $uri);
      }
      if ($seek > 0) {
        $reader->setSeek($seek);
      }
      $count = 0;
      while ($item = $reader->readItem()) {
        $count++;
        $source = $this->flatten($item->getSource());
        $translation = trim($this->flatten($item->getTranslation()));
        if ($source === '' || $translation === '' || !$this->guard->keepsTerms($source, $translation, $terms)) {
          continue;
        }
        $written = $this->writer->apply($source, (string) $item->getContext(), $translation, LOCALE_NOT_CUSTOMIZED, $replace_ai);
        if ($written === 'written') {
          $result['added']++;
        }
        elseif ($written === 'replaced') {
          $result['replaced_ai']++;
          $this->repository->markBySource($source, (string) $item->getContext(), [
            'origin' => 'official',
            'status' => 'completed',
            'translation' => $translation,
            'reason' => '',
          ]);
        }
        if ($count % 40 === 0 && microtime(TRUE) > $deadline) {
          $result['finished'] = FALSE;
          $result['seek'] = (int) $reader->getSeek();
          break;
        }
      }
      if ($result['finished']) {
        $result['seek'] = 0;
      }
    }
    catch (\Throwable $e) {
      $result['error'] = $e->getMessage();
      $result['finished'] = TRUE;
      $this->logger->error('导入官方语言包 {uri} 失败：{message}', [
        'uri' => $uri,
        'message' => $e->getMessage(),
      ]);
    }
    finally {
      try {
        $reader->close();
      }
      catch (\Throwable) {
      }
    }
    return $result;
  }

  private function markCurrent(string $module, string $file): void {
    if ($file === '' || !file_exists($file)) {
      return;
    }
    try {
      $project = $this->projectName($module);
      $source = $this->localeSource->loadSource($project, UiLocale::LANGCODE);
      $local = $source->files[LOCALE_TRANSLATION_LOCAL] ?? NULL;
      if ($local instanceof LocaleFile && file_exists($local->uri)) {
        $this->localeSource->saveSource($project, UiLocale::LANGCODE, LOCALE_TRANSLATION_CURRENT, $local);
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('记录 {module} 的官方语言包状态失败：{message}', [
        'module' => $module,
        'message' => $e->getMessage(),
      ]);
    }
  }

  private function projectName(string $module): string {
    if (!$this->modules->exists($module)) {
      return $module;
    }
    $info = $this->modules->getExtensionInfo($module);
    $project = trim((string) ($info['project'] ?? $info['interface translation project'] ?? $module));
    return $project !== '' ? $project : $module;
  }

  private function flatten(mixed $value): string {
    if (is_array($value)) {
      return implode(UiLocale::PLURAL, array_map('strval', $value));
    }
    return (string) $value;
  }

}
