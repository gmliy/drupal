<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Keeps brand names, project names and technical identifiers intact.
 */
final class ProtectedTermGuard {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Configured terms plus the built-in brand names.
   *
   * @return list<string>
   */
  public function configuredTerms(): array {
    $configured = $this->configFactory->get('xedc_ui_locale.settings')->get('protected_terms');
    $terms = is_array($configured) ? $configured : [];
    $terms[] = UiLocale::BRAND;
    $terms[] = 'Drupal';
    $terms[] = 'XEDC';
    return $this->cleanTerms($terms);
  }

  /**
   * @param list<string> $extra
   * @return list<string>
   */
  public function termsForModule(array $extra): array {
    return $this->cleanTerms(array_merge($this->configuredTerms(), $extra));
  }

  /**
   * The whole string is a protected name, so the module around it can still
   * be translated.
   *
   * @param list<string> $terms
   */
  public function isExactTerm(string $source, array $terms): bool {
    $parts = $this->splitPlural($source);
    if ($parts === []) {
      return FALSE;
    }
    foreach ($parts as $part) {
      $part = trim($part);
      if ($part === '') {
        continue;
      }
      $matched = FALSE;
      foreach ($terms as $term) {
        if ($this->sameTerm($part, $term)) {
          $matched = TRUE;
          break;
        }
      }
      if (!$matched) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * @param list<string> $terms
   * @return array{0: string, 1: array<string, string>}
   */
  public function shield(string $text, array $terms): array {
    $map = [];
    $n = 0;
    $terms = $this->cleanTerms($terms);
    usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    foreach ($terms as $term) {
      $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u';
      if ($this->isBrand($term)) {
        $pattern .= 'i';
      }
      $text = preg_replace_callback($pattern, static function (array $matches) use (&$map, &$n): string {
        $token = 'XEDCPH' . $n;
        $map[$token] = $matches[0];
        $n++;
        return $token;
      }, $text) ?? $text;
    }
    if (preg_match_all('/(?<![\p{L}\p{N}_])[@%!:][\p{L}\p{N}_-]+|\{\{.*?\}\}/u', $text, $matches)) {
      $placeholders = array_unique($matches[0]);
      usort($placeholders, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
      foreach ($placeholders as $placeholder) {
        if ($placeholder === '' || !str_contains($text, $placeholder)) {
          continue;
        }
        $token = 'XEDCPH' . $n;
        $map[$token] = $placeholder;
        $text = str_replace($placeholder, $token, $text);
        $n++;
      }
    }
    return [$text, $map];
  }

  /**
   * @param array<string, string> $map
   */
  public function unshield(string $text, array $map): ?string {
    $text = preg_replace('/XEDCPH\s*(\d+)/iu', 'XEDCPH$1', $text) ?? $text;
    $tokens = array_keys($map);
    usort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    foreach ($tokens as $token) {
      if (!str_contains($text, $token)) {
        return NULL;
      }
      $text = str_replace($token, $map[$token], $text);
    }
    if (preg_match('/XEDCPH\d+/u', $text)) {
      return NULL;
    }
    return $text;
  }

  public function isTokenOnly(string $text): bool {
    $stripped = preg_replace('/XEDCPH\d+/u', '', $text);
    $stripped = trim((string) $stripped);
    return $stripped === '' || !preg_match('/\p{L}/u', $stripped);
  }

  /**
   * @param list<string> $terms
   */
  public function keepsTerms(string $source, string $translation, array $terms): bool {
    foreach ($this->cleanTerms($terms) as $term) {
      $needed = $this->countTerm($source, $term);
      if ($needed === 0) {
        continue;
      }
      if ($this->countTerm($translation, $term) < $needed) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * @return list<string>
   */
  public function splitPlural(string $source): array {
    if (str_contains($source, UiLocale::PLURAL)) {
      return explode(UiLocale::PLURAL, $source);
    }
    if (str_contains($source, "\0")) {
      return explode("\0", $source);
    }
    return [$source];
  }

  /**
   * @param list<string> $terms
   * @return list<string>
   */
  private function cleanTerms(array $terms): array {
    $clean = [];
    foreach ($terms as $term) {
      $term = trim((string) $term);
      if (mb_strlen($term) < 2 || mb_strlen($term) > 180) {
        continue;
      }
      $clean[$term] = $term;
    }
    return array_values($clean);
  }

  private function isBrand(string $term): bool {
    return in_array(mb_strtolower($term), ['dclili', 'drupal', 'xedc'], TRUE);
  }

  private function sameTerm(string $source, string $term): bool {
    return $this->isBrand($term) ? strcasecmp($source, $term) === 0 : $source === $term;
  }

  private function countTerm(string $haystack, string $term): int {
    $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u';
    if ($this->isBrand($term)) {
      $pattern .= 'i';
    }
    $count = preg_match_all($pattern, $haystack);
    return $count === FALSE ? 0 : $count;
  }

}
