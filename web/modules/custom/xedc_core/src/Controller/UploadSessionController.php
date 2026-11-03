<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\xedc_core\Upload\UploadSessionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API for resumable image and archive uploads.
 */
final class UploadSessionController extends ControllerBase {

  public function __construct(
    protected UploadSessionManager $uploads,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.upload_session'),
    );
  }

  public function createSession(Request $request): JsonResponse {
    return $this->respond(function () use ($request): array {
      $payload = json_decode($request->getContent(), TRUE);
      if (!is_array($payload)) {
        return ['http' => 400, 'data' => ['ok' => FALSE, 'error' => '请求不是 JSON。', 'message' => '请求不是 JSON。']];
      }
      return $this->uploads->open($payload);
    });
  }

  public function listSessions(): JsonResponse {
    return $this->respond(fn (): array => $this->uploads->list());
  }

  public function show(string $upload_session): JsonResponse {
    return $this->respond(fn (): array => $this->uploads->status($upload_session));
  }

  public function chunk(Request $request, string $upload_session, string $chunk): JsonResponse {
    return $this->respond(function () use ($request, $upload_session, $chunk): array {
      if (!ctype_digit($chunk)) {
        return ['http' => 400, 'data' => ['ok' => FALSE, 'error' => '分块编号无效。', 'message' => '分块编号无效。']];
      }
      $input = fopen('php://input', 'rb');
      if ($input === FALSE) {
        return ['http' => 400, 'data' => ['ok' => FALSE, 'error' => '没有读到分块内容。', 'message' => '没有读到分块内容。']];
      }
      try {
        return $this->uploads->receive($upload_session, (int) $chunk, $input, (string) $request->headers->get('X-Chunk-Sha256', ''));
      }
      finally {
        fclose($input);
      }
    });
  }

  public function complete(Request $request, string $upload_session): JsonResponse {
    return $this->respond(function () use ($request, $upload_session): array {
      $payload = json_decode($request->getContent(), TRUE);
      if (!is_array($payload)) {
        $payload = [];
      }
      return $this->uploads->complete($upload_session, $payload);
    });
  }

  public function cancel(string $upload_session): JsonResponse {
    return $this->respond(fn (): array => $this->uploads->cancel($upload_session));
  }

  /**
   * @param callable(): array{http: int, data: array<string, mixed>} $callback
   */
  private function respond(callable $callback): JsonResponse {
    try {
      $result = $callback();
    }
    catch (\Throwable $e) {
      $this->getLogger('xedc_core')->error('上传接口异常：@message', ['@message' => $e->getMessage()]);
      $result = ['http' => 500, 'data' => ['ok' => FALSE, 'error' => '上传请求失败。', 'message' => '上传请求失败。']];
    }
    $response = new JsonResponse($result['data'], (int) $result['http']);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
