<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Client;

/**
 * One model call. The API key is never part of this object.
 */
final class ChatResponse {

  public function __construct(
    public readonly bool $ok,
    public readonly string $content,
    public readonly int $promptTokens,
    public readonly int $completionTokens,
    public readonly int $durationMs,
    public readonly string $providerId,
    public readonly string $modelName,
    public readonly string $errorCode,
    public readonly string $errorMessage,
    public readonly bool $retryable,
    public readonly bool $fallbackEligible,
    public readonly string $finishReason = '',
    public readonly bool $usageReported = FALSE,
    public readonly int $cacheReadTokens = 0,
    public readonly int $httpStatus = 0,
    public readonly bool $usedReasoning = FALSE,
  ) {}

  public static function failure(string $providerId, string $modelName, string $code, string $message, int $durationMs, bool $retryable, bool $fallbackEligible, int $promptTokens = 0, int $completionTokens = 0, string $finishReason = '', bool $usageReported = FALSE, int $cacheReadTokens = 0, int $httpStatus = 0): self {
    return new self(FALSE, '', $promptTokens, $completionTokens, $durationMs, $providerId, $modelName, $code, Redactor::text($message), $retryable, $fallbackEligible, $finishReason, $usageReported, $cacheReadTokens, $httpStatus, FALSE);
  }

}
