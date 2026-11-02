<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Provider;

/**
 * Resolved model call. Debug output hides the API key.
 */
final class ModelProfile {

  public function __construct(
    public readonly int $id,
    public readonly string $providerId,
    public readonly string $driver,
    public readonly string $providerLabel,
    public readonly string $modelName,
    public readonly string $baseUrl,
    public readonly string $apiKey,
    public readonly int $timeout,
    public readonly int $maxTokens,
    public readonly string $temperature,
    public readonly string $inputPrice,
    public readonly string $outputPrice,
    public readonly bool $enabled,
  ) {}

  public function modelKey(): string {
    return $this->providerId . ':' . $this->modelName;
  }

  public function priceKnown(): bool {
    return $this->inputPrice !== '' && $this->outputPrice !== '';
  }

  /**
   * @return array<string, mixed>
   *   Debug values without the key.
   */
  public function __debugInfo(): array {
    $values = get_object_vars($this);
    $values['apiKey'] = $this->apiKey === '' ? '' : 'set';
    return $values;
  }

}
