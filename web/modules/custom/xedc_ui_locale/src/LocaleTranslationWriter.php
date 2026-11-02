<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale;

use Drupal\locale\StringStorageInterface;

/**
 * Writes a Locale translation without replacing human or official text.
 */
final class LocaleTranslationWriter {

  public function __construct(
    private readonly StringStorageInterface $storage,
    private readonly TranslationTaskRepository $repository,
  ) {}

  public function hasTranslation(string $source, string $context): bool {
    return $this->currentText($source, $context) !== '';
  }

  public function currentText(string $source, string $context): string {
    $existing = $this->lookup($source, $context);
    return $existing ? trim((string) ($existing->translation ?? '')) : '';
  }

  /**
   * @return string
   *   written, kept, replaced, or invalid.
   */
  public function apply(string $source, string $context, string $translation, int $customized, bool $replace_ai, string $location = ''): string {
    $translation = trim($translation);
    if ($translation === '' || (function_exists('locale_string_is_safe') && !locale_string_is_safe($translation))) {
      return 'invalid';
    }
    $existing = $this->lookup($source, $context);
    $current = $existing ? trim((string) ($existing->translation ?? '')) : '';
    $custom = $existing && (int) ($existing->customized ?? 0) === LOCALE_CUSTOMIZED;
    if ($current !== '') {
      if ($custom || !$replace_ai || !$this->repository->isAiOrigin($source, $context)) {
        return 'kept';
      }
      $existing->setString($translation);
      $existing->setCustomized(FALSE);
      $existing->save();
      return 'replaced';
    }
    if ($existing && (int) $existing->lid > 0) {
      $existing->setString($translation);
      $existing->language = UiLocale::LANGCODE;
      $existing->setCustomized($customized === LOCALE_CUSTOMIZED);
      $existing->save();
      return 'written';
    }
    $source_string = $this->storage->findString([
      'source' => $source,
      'context' => $context,
    ]);
    if (!$source_string) {
      $source_string = $this->storage->createString([
        'source' => $source,
        'context' => $context,
      ]);
      if ($location !== '') {
        $source_string->addLocation('code', substr($location, 0, 255));
      }
      $this->storage->save($source_string);
    }
    $target = $this->storage->createTranslation([
      'lid' => $source_string->getId(),
      'language' => UiLocale::LANGCODE,
      'translation' => $translation,
      'customized' => $customized,
    ]);
    $this->storage->save($target);
    return 'written';
  }

  private function lookup(string $source, string $context): ?object {
    $existing = $this->storage->findTranslation([
      'language' => UiLocale::LANGCODE,
      'source' => $source,
      'context' => $context,
    ]);
    return $existing ?: NULL;
  }

}
