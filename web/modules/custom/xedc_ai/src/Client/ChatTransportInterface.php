<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Client;

/**
 * POST helper used by the chat client. Implementations must not log headers.
 */
interface ChatTransportInterface {

  /**
   * @param array<string, string> $headers
   *   Request headers. The caller may include Authorization.
   */
  public function post(string $url, array $headers, string $body, int $timeout): TransportResult;

}
