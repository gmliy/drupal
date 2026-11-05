<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Client;

use Drupal\xedc_ai\Provider\ModelProfile;

/**
 * OpenAI-compatible chat completions client.
 *
 * Message content is sent only as text. Image and video parts are dropped.
 */
final class OpenAiChatClient {

  public function __construct(
    private readonly ChatTransportInterface $transport,
  ) {}

  /**
   * @param list<array<string, mixed>> $messages
   *   Chat messages. Non-string content is ignored.
   */
  public function once(ModelProfile $model, array $messages, ?int $maxTokens = NULL, ?string $temperature = NULL, bool $jsonObject = FALSE): ChatResponse {
    $started = (int) floor(microtime(TRUE) * 1000);
    $maxTokens = max(1, min(8192, $maxTokens ?? $model->maxTokens));
    $temperature = $temperature ?? $model->temperature;
    $payload = [
      'model' => $model->modelName,
      'messages' => $this->textMessages($messages),
      'temperature' => (float) $temperature,
      'max_tokens' => $maxTokens,
    ];
    if ($jsonObject) {
      $payload['response_format'] = ['type' => 'json_object'];
      // Thinking is on by default and shares max_tokens with the answer.
      // A 4096 budget can be spent entirely on reasoning and return no JSON.
      $payload['thinking'] = ['type' => 'disabled'];
    }
    $result = $this->send($model, $payload);
    $decoded = json_decode($result->body, TRUE);
    if ($result->status === 400 && is_array($decoded) && $this->mentions($decoded, 'max_tokens')) {
      unset($payload['max_tokens']);
      $payload['max_completion_tokens'] = $maxTokens;
      $result = $this->send($model, $payload);
      $decoded = json_decode($result->body, TRUE);
    }
    if ($result->status === 400 && is_array($decoded) && $this->mentions($decoded, 'temperature')) {
      unset($payload['temperature']);
      $result = $this->send($model, $payload);
      $decoded = json_decode($result->body, TRUE);
    }
    if ($jsonObject && $result->status === 400 && is_array($decoded) && $this->mentions($decoded, 'thinking')) {
      unset($payload['thinking']);
      $result = $this->send($model, $payload);
      $decoded = json_decode($result->body, TRUE);
    }
    if ($jsonObject && $result->status === 400 && is_array($decoded) && $this->mentions($decoded, 'response_format')) {
      unset($payload['response_format']);
      $result = $this->send($model, $payload);
    }
    return $this->interpret($model, $result, max(0, (int) floor(microtime(TRUE) * 1000) - $started));
  }

  /**
   * @param array<string, mixed> $payload
   *   JSON body.
   */
  private function send(ModelProfile $model, array $payload): TransportResult {
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    return $this->transport->post($this->endpoint($model->baseUrl), [
      'Authorization' => 'Bearer ' . $model->apiKey,
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ], $encoded === FALSE ? '{}' : $encoded, $model->timeout);
  }

  private function endpoint(string $baseUrl): string {
    $baseUrl = rtrim(trim($baseUrl), '/');
    if (str_ends_with($baseUrl, '/chat/completions')) {
      return $baseUrl;
    }
    return $baseUrl . '/chat/completions';
  }

  /**
   * @param list<array<string, mixed>> $messages
   *   Incoming messages.
   *
   * @return list<array{role: string, content: string}>
   *   Text-only messages.
   */
  private function textMessages(array $messages): array {
    $clean = [];
    foreach ($messages as $message) {
      if (isset($message['image_url']) || isset($message['images']) || isset($message['video'])) {
        continue;
      }
      $content = $message['content'] ?? '';
      if (!is_string($content)) {
        continue;
      }
      $clean[] = [
        'role' => (string) ($message['role'] ?? 'user'),
        'content' => $content,
      ];
    }
    return $clean;
  }

  private function interpret(ModelProfile $model, TransportResult $result, int $duration): ChatResponse {
    if ($result->errorCode === 'timeout' || $result->errorCode === 'network') {
      return ChatResponse::failure($model->providerId, $model->modelName, $result->errorCode, $result->errorMessage !== '' ? $result->errorMessage : '网络请求失败。', $duration, TRUE, TRUE);
    }
    $decoded = json_decode($result->body, TRUE);
    $usage = $this->usage(is_array($decoded) ? $decoded : NULL);
    $error = $this->errorMessage(is_array($decoded) ? $decoded : NULL, $result->body);
    $code = $this->errorCode($result->status, is_array($decoded) ? $decoded : NULL, $error);
    $finish = $this->finishReason(is_array($decoded) ? $decoded : NULL);
    if ($code !== '') {
      return ChatResponse::failure($model->providerId, $model->modelName, $code, $error !== '' ? $error : '模型请求失败。', $duration, $this->retryable($code), $this->fallback($code), $usage['prompt'], $usage['completion'], $finish, $usage['reported'], $usage['cache'], $result->status);
    }
    [$content, $fromReasoning] = $this->content(is_array($decoded) ? $decoded : NULL);
    if ($content === NULL) {
      $truncated = $finish === 'length';
      return ChatResponse::failure($model->providerId, $model->modelName, $truncated ? 'truncated' : 'bad_response', $truncated ? '模型输出被截断，没有可解析的正文。' : '模型返回里没有文本内容。', $duration, FALSE, FALSE, $usage['prompt'], $usage['completion'], $finish, $usage['reported'], $usage['cache'], $result->status);
    }
    return new ChatResponse(TRUE, $content, $usage['prompt'], $usage['completion'], $duration, $model->providerId, $model->modelName, '', '', FALSE, FALSE, $finish, $usage['reported'], $usage['cache'], $result->status, $fromReasoning);
  }

  /**
   * OpenAI-compatible finish_reason from the first choice, if present.
   */
  private function finishReason(?array $decoded): string {
    $reason = $decoded['choices'][0]['finish_reason'] ?? '';
    return is_string($reason) ? $reason : '';
  }

  /**
   * @return array{reported: bool, prompt: int, completion: int, cache: int}
   *   Usage taken only from the provider payload.
   */
  private function usage(?array $decoded): array {
    $empty = ['reported' => FALSE, 'prompt' => 0, 'completion' => 0, 'cache' => 0];
    $usage = is_array($decoded['usage'] ?? NULL) ? $decoded['usage'] : NULL;
    if ($usage === NULL) {
      return $empty;
    }
    $prompt = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? NULL;
    $completion = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? NULL;
    $total = $usage['total_tokens'] ?? NULL;
    if ($prompt === NULL && $completion === NULL && $total === NULL) {
      return $empty;
    }
    if ($prompt === NULL && $completion === NULL) {
      $prompt = $total;
      $completion = 0;
    }
    $cache = $usage['prompt_cache_hit_tokens'] ?? NULL;
    if ($cache === NULL && is_array($usage['prompt_tokens_details'] ?? NULL)) {
      $cache = $usage['prompt_tokens_details']['cached_tokens'] ?? 0;
    }
    return [
      'reported' => TRUE,
      'prompt' => max(0, (int) $prompt),
      'completion' => max(0, (int) $completion),
      'cache' => max(0, (int) $cache),
    ];
  }

  /**
   * @return array{0: ?string, 1: bool}
   *   Text and whether it was recovered from reasoning content.
   */
  private function content(?array $decoded): array {
    $message = $decoded['choices'][0]['message'] ?? NULL;
    $text = $this->messageText(is_array($message) ? ($message['content'] ?? NULL) : $message);
    if ($text !== NULL) {
      return [$text, FALSE];
    }
    $finish = $this->finishReason($decoded);
    $reasoning = is_array($message) ? ($message['reasoning_content'] ?? NULL) : NULL;
    if ($finish !== 'length' && is_string($reasoning) && str_contains($reasoning, '{')) {
      return [$reasoning, TRUE];
    }
    return [NULL, FALSE];
  }

  private function messageText(mixed $message): ?string {
    if (is_string($message) && trim($message) !== '') {
      return $message;
    }
    if (!is_array($message)) {
      return NULL;
    }
    $text = '';
    foreach ($message as $part) {
      if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
        $text .= $part['text'];
      }
    }
    return trim($text) === '' ? NULL : $text;
  }

  private function errorCode(int $status, ?array $decoded, string $message): string {
    $lower = strtolower($message . ' ' . strtolower((string) ($decoded['error']['code'] ?? $decoded['error']['type'] ?? '')));
    if ($status === 401 || $status === 403 || str_contains($lower, 'invalid api key') || str_contains($lower, 'authentication')) {
      return 'auth';
    }
    if ($status === 402 || str_contains($lower, 'insufficient_quota') || str_contains($lower, 'insufficient quota') || str_contains($lower, 'balance') || str_contains($message, '余额') || str_contains($lower, 'billing')) {
      return 'insufficient_quota';
    }
    if ($status === 429 || str_contains($lower, 'rate limit') || str_contains($lower, 'rate_limit')) {
      return 'rate_limit';
    }
    if ($status === 408) {
      return 'timeout';
    }
    if ($status === 404 || str_contains($lower, 'model_not_found') || str_contains($lower, 'model not found') || str_contains($lower, 'does not exist') || str_contains($message, '模型不存在')) {
      return 'model_not_found';
    }
    if (str_contains($lower, 'context_length') || str_contains($lower, 'maximum context') || str_contains($lower, 'token limit') || str_contains($message, '上下文')) {
      return 'token_limit';
    }
    if ($status >= 500) {
      return 'unavailable';
    }
    if ($status >= 400) {
      return 'http';
    }
    if (!is_array($decoded)) {
      return 'bad_response';
    }
    return '';
  }

  private function errorMessage(?array $decoded, string $body): string {
    $message = '';
    if (is_array($decoded)) {
      $raw = $decoded['error']['message'] ?? $decoded['message'] ?? '';
      $message = is_string($raw) ? $raw : '';
    }
    if ($message === '') {
      $message = trim($body);
    }
    return Redactor::text($message);
  }

  private function mentions(array $decoded, string $needle): bool {
    $encoded = json_encode($decoded);
    return is_string($encoded) && str_contains(strtolower($encoded), strtolower($needle));
  }

  private function retryable(string $code): bool {
    return in_array($code, ['timeout', 'network', 'rate_limit', 'unavailable'], TRUE);
  }

  private function fallback(string $code): bool {
    return in_array($code, ['timeout', 'network', 'rate_limit', 'unavailable', 'model_not_found', 'token_limit', 'auth', 'insufficient_quota'], TRUE);
  }

}
