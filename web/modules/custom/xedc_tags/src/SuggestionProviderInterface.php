<?php

namespace Drupal\xedc_tags;

/**
 * A later AI provider can implement this. The current provider is local rules.
 */
interface SuggestionProviderInterface {

  /**
   * @param array{title: string, body: string, description: string, current: int[], bundle: string} $input
   * @param array<int, array<string, mixed>> $terms
   * @param array<int, array{tid: int, shared: int, jaccard: float, from: string}> $related
   * @return array<int, array{tid: int, name: string, score: int, reasons: string[]}>
   */
  public function suggest(array $input, array $terms, array $related): array;

}
