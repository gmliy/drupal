<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale;

/**
 * Rejects incomplete machine translations before they reach Locale.
 */
final class TranslationValidator {

  /**
   * @param list<string> $terms
   */
  public function validate(string $source, string $translation, array $terms, ProtectedTermGuard $guard): ?string {
    $translation = trim($translation);
    if ($translation === '') {
      return '译文为空';
    }
    if (preg_match('/XEDCPH\d+/u', $translation)) {
      return '占位符没有还原';
    }
    if (!$guard->keepsTerms($source, $translation, $terms)) {
      return '品牌名或项目名称丢失';
    }
    $source_parts = $guard->splitPlural($source);
    $translation_parts = $guard->splitPlural($translation);
    if (count($source_parts) !== count($translation_parts)) {
      return '复数形式不完整';
    }
    foreach ($source_parts as $index => $part) {
      $translated = $translation_parts[$index] ?? '';
      if (trim($part) !== '' && trim($translated) === '') {
        return '复数形式不完整';
      }
      $missing = $this->missingPlaceholders($part, $translated);
      if ($missing !== NULL) {
        return '占位符丢失：' . $missing;
      }
      $html = $this->htmlProblem($part, $translated);
      if ($html !== NULL) {
        return $html;
      }
    }
    if ($this->sameText($source, $translation) && preg_match('/\p{L}{3,}/u', $source) && !preg_match('/\p{Han}/u', $translation)) {
      return '译文与原文相同';
    }
    return NULL;
  }

  private function missingPlaceholders(string $source, string $translation): ?string {
    if (!preg_match_all('/(?<![\p{L}\p{N}_])[@%!:][\p{L}\p{N}_-]+|\{\{.*?\}\}/u', $source, $matches)) {
      return NULL;
    }
    foreach (array_unique($matches[0]) as $placeholder) {
      if (substr_count($translation, $placeholder) < substr_count($source, $placeholder)) {
        return $placeholder;
      }
    }
    return NULL;
  }

  private function htmlProblem(string $source, string $translation): ?string {
    if (preg_match('/<\s*script\b|<\s*iframe\b|javascript:/i', $translation)) {
      return '译文包含不安全的 HTML';
    }
    $source_tags = $this->tagCounts($source);
    $translation_tags = $this->tagCounts($translation);
    if ($source_tags === [] && $translation_tags !== []) {
      return '译文新增了 HTML 标签';
    }
    if ($source_tags != $translation_tags) {
      return 'HTML 结构不一致';
    }
    return NULL;
  }

  /**
   * @return array<string, int>
   */
  private function tagCounts(string $text): array {
    if (!preg_match_all('/<\s*(\/?)\s*([a-zA-Z][\w:-]*)\b[^>]*>/', $text, $matches, PREG_SET_ORDER)) {
      return [];
    }
    $counts = [];
    foreach ($matches as $match) {
      $name = strtolower($match[2]);
      if (in_array($name, ['br', 'hr', 'img', 'input', 'wbr'], TRUE)) {
        $counts[$name] = ($counts[$name] ?? 0) + 1;
        continue;
      }
      $key = ($match[1] === '/' ? '/' : '') . $name;
      $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
  }

  private function sameText(string $source, string $translation): bool {
    return trim(str_replace(UiLocale::PLURAL, '', $source)) === trim(str_replace(UiLocale::PLURAL, '', $translation));
  }

}
