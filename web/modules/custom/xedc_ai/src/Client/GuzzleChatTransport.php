<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Client;

use GuzzleHttp\ClientInterface;

/**
 * Guzzle transport. HTTP errors are returned, not thrown.
 */
final class GuzzleChatTransport implements ChatTransportInterface {

  public function __construct(
    private readonly ClientInterface $http,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function post(string $url, array $headers, string $body, int $timeout): TransportResult {
    try {
      $response = $this->http->request('POST', $url, [
        'headers' => $headers,
        'body' => $body,
        'timeout' => $timeout,
        'connect_timeout' => min(10, $timeout),
        'http_errors' => FALSE,
      ]);
      return new TransportResult($response->getStatusCode(), (string) $response->getBody());
    }
    catch (\Throwable $exception) {
      $message = Redactor::text($exception->getMessage());
      $timeoutError = str_contains(strtolower($message), 'timed out') || str_contains($message, 'cURL error 28');
      return new TransportResult(0, '', $timeoutError ? 'timeout' : 'network', $message);
    }
  }

}
