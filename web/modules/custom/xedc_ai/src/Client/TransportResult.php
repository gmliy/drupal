<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Client;

/**
 * HTTP result without request headers.
 */
final class TransportResult {

  public function __construct(
    public readonly int $status,
    public readonly string $body,
    public readonly string $errorCode = '',
    public readonly string $errorMessage = '',
  ) {}

}
