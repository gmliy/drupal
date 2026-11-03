<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\Engagement\DownloadAccess;
use Drupal\xedc_core\Engagement\DownloadRecorder;
use Drupal\xedc_core\Engagement\ZipBuilder;
use Drupal\xedc_core\Engagement\ZipPendingException;
use Drupal\xedc_core\Entity\DownloadResource;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Delivers a resource only after DownloadAccess allows it.
 */
final class DownloadDeliveryController extends ControllerBase {

  public function file(DownloadResource $xedc_download): Response {
    $decision = $this->gate()->decide($xedc_download, $this->currentUser());
    if (!$decision['allow']) {
      return $this->denied($decision);
    }
    $source = (string) $xedc_download->get('source_type')->value;
    if ($source === 'external') {
      try {
        $url = $xedc_download->externalUrl();
      }
      catch (\InvalidArgumentException) {
        return $this->denied(['status' => 404, 'message' => '下载地址无效。']);
      }
      $response = new TrustedRedirectResponse($url, 302);
      $response->headers->set('Cache-Control', 'private, no-store');
      $response->headers->set('Referrer-Policy', 'no-referrer');
      $this->log()->record((int) $this->currentUser()->id(), $xedc_download, (string) $xedc_download->get('file_extension')->value, (int) $xedc_download->get('file_size')->value, 'authorized', 'redirect');
      return $response;
    }
    if ($source !== 'upload') {
      return $this->denied(['status' => 404, 'message' => '请从下载面板选择具体文件。']);
    }
    $file = $xedc_download->get('file')->entity;
    if (!$file instanceof FileInterface) {
      return $this->denied(['status' => 404, 'message' => '文件不存在。']);
    }
    $response = $this->sendFile((string) $file->getFileUri(), (string) $file->getFilename(), (string) ($file->getMimeType() ?: 'application/octet-stream'), (int) $file->getSize());
    if ($response->getStatusCode() === 200) {
      $this->log()->record((int) $this->currentUser()->id(), $xedc_download, (string) $xedc_download->get('file_extension')->value, (int) $file->getSize(), 'authorized', 'file');
    }
    return $response;
  }

  public function original(DownloadResource $xedc_download, int $fid): Response {
    $decision = $this->gate()->decide($xedc_download, $this->currentUser(), $fid);
    if (!$decision['allow']) {
      return $this->denied($decision);
    }
    $node = $xedc_download->get('parent')->entity;
    if (!$node instanceof NodeInterface) {
      return $this->denied(['status' => 404, 'message' => '内容不存在。']);
    }
    $item = \Drupal::service('xedc_core.original_files')->one($node, $fid);
    if ($item === NULL) {
      return $this->denied(['status' => 404, 'message' => '原图不存在。']);
    }
    $response = $this->sendFile($item['uri'], $item['filename'], $item['mime'], $item['size']);
    if ($response->getStatusCode() === 200) {
      $this->log()->record((int) $this->currentUser()->id(), $xedc_download, $item['extension'], $item['size'], 'authorized', 'file');
    }
    return $response;
  }

  public function archive(DownloadResource $xedc_download): Response {
    $decision = $this->gate()->decide($xedc_download, $this->currentUser());
    if (\Drupal::request()->query->get('status') === '1') {
      if (!$decision['allow'] || (string) $xedc_download->get('source_type')->value !== 'zip') {
        return new JsonResponse([
          'ok' => FALSE,
          'state' => 'denied',
          'message' => (string) ($decision['message'] ?: '不能下载这个压缩包。'),
        ], (int) ($decision['status'] ?? 403));
      }
      return new JsonResponse($this->zipState($xedc_download));
    }
    if (!$decision['allow']) {
      return $this->denied($decision);
    }
    if ((string) $xedc_download->get('source_type')->value !== 'zip') {
      return $this->denied(['status' => 404, 'message' => '这不是压缩包资源。']);
    }
    try {
      $this->zip()->note($xedc_download, 'running', '正在生成压缩包。');
      $uri = $this->zip()->build($xedc_download);
    }
    catch (ZipPendingException $e) {
      $this->zip()->enqueue($xedc_download);
      $this->zip()->note($xedc_download, 'queued', $e->getMessage());
      $this->log()->record((int) $this->currentUser()->id(), $xedc_download, 'zip', 0, 'zip_pending', 'zip', $e->getMessage());
      return new Response($this->messagePage($e->getMessage()), 202, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
    catch (\Throwable $e) {
      $this->zip()->note($xedc_download, 'failed', $e->getMessage());
      $this->log()->record((int) $this->currentUser()->id(), $xedc_download, 'zip', 0, 'zip_failed', 'zip', $e->getMessage());
      \Drupal::logger('xedc_core')->error('下载压缩包失败：@message', ['@message' => $e->getMessage()]);
      return new Response($this->messagePage('压缩包生成失败，请稍后再试。'), 500, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
    $size = is_file($uri) ? (int) filesize($uri) : 0;
    $filename = $this->safeName((string) $xedc_download->label()) . '.zip';
    $response = $this->sendFile($uri, $filename, 'application/zip', $size);
    if ($response->getStatusCode() === 200) {
      $this->log()->record((int) $this->currentUser()->id(), $xedc_download, 'zip', $size, 'authorized', 'zip');
    }
    else {
      $this->zip()->note($xedc_download, 'failed', '压缩包已生成，但无法交付。');
      $this->log()->record((int) $this->currentUser()->id(), $xedc_download, 'zip', $size, 'failed', 'zip', '压缩包已生成，但无法交付。');
    }
    return $response;
  }

  /**
   * @return array{ok: bool, state: string, message: string}
   *   ZIP state without building a new archive.
   */
  protected function zipState(DownloadResource $resource): array {
    $state = $this->zip()->inspect($resource);
    if ($state['state'] === 'queued') {
      $this->zip()->enqueue($resource);
      $this->zip()->note($resource, 'queued', $state['message']);
    }
    if ($state['state'] === 'failed' && \Drupal::database()->schema()->tableExists('xedc_zip_job')) {
      $job = \Drupal::database()->select('xedc_zip_job', 'z')
        ->fields('z', ['status', 'message'])
        ->condition('resource_id', (int) $resource->id())
        ->execute()
        ->fetchObject();
      if ($job && (string) $job->status === 'failed' && (string) $job->message !== '') {
        $state['message'] = (string) $job->message;
      }
    }
    return [
      'ok' => $state['state'] !== 'failed',
      'state' => $state['state'],
      'message' => $state['message'],
      'count' => $state['count'],
      'bytes' => $state['bytes'],
      'cached' => $state['cached'],
    ];
  }

  public function toggle(DownloadResource $xedc_download, Request $request): RedirectResponse {
    $token = (string) $request->query->get('token');
    if (!\Drupal::csrfToken()->validate($token, 'xedc-download-toggle:' . $xedc_download->id())) {
      return $this->redirect('entity.xedc_download.collection');
    }
    if ($xedc_download->isPublished()) {
      $xedc_download->setUnpublished();
    }
    else {
      $xedc_download->setPublished();
    }
    $xedc_download->save();
    $this->messenger()->addStatus($xedc_download->isPublished() ? $this->t('已启用下载资源。') : $this->t('已停用下载资源。'));
    return $this->redirect('entity.xedc_download.collection');
  }

  /**
   * @param array{status?: int, message?: string} $decision
   *   Denial from the gate.
   */
  protected function denied(array $decision): Response {
    $status = (int) ($decision['status'] ?? 403);
    return new Response($this->messagePage((string) ($decision['message'] ?? '不能下载。')), $status, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'private, no-store',
    ]);
  }

  protected function sendFile(string $uri, string $filename, string $mime, int $length): Response {
    $real = \Drupal::service('file_system')->realpath($uri);
    $filename = $this->safeName($filename);
    if (is_string($real) && is_file($real) && !is_link($real)) {
      $response = new BinaryFileResponse($real);
      $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
      $response->headers->set('Content-Type', $mime);
      $response->headers->set('X-Content-Type-Options', 'nosniff');
      $response->headers->set('Cache-Control', 'private, no-store');
      return $response;
    }
    if (!is_file($uri)) {
      return $this->denied(['status' => 404, 'message' => '文件不存在。']);
    }
    $response = new StreamedResponse(static function () use ($uri): void {
      $in = fopen($uri, 'rb');
      if ($in === FALSE) {
        return;
      }
      while (!feof($in)) {
        $chunk = fread($in, 1048576);
        if ($chunk === FALSE || $chunk === '') {
          break;
        }
        echo $chunk;
        flush();
      }
      fclose($in);
    });
    $response->headers->set('Content-Type', $mime);
    $response->headers->set('Content-Disposition', $this->disposition($filename));
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Cache-Control', 'private, no-store');
    if ($length > 0) {
      $response->headers->set('Content-Length', (string) $length);
    }
    return $response;
  }

  protected function disposition(string $filename): string {
    $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';
    return 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
  }

  protected function safeName(string $filename): string {
    $filename = basename(str_replace('\\', '/', $filename));
    $filename = preg_replace('/[\r\n"]+/', '', $filename) ?: 'download';
    return $filename;
  }

  protected function messagePage(string $message): string {
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    return '<!DOCTYPE html><meta charset="utf-8"><title>下载</title><p>' . $safe . '</p>';
  }

  protected function gate(): DownloadAccess {
    return \Drupal::service('xedc_core.download_access');
  }

  protected function log(): DownloadRecorder {
    return \Drupal::service('xedc_core.download_recorder');
  }

  protected function zip(): ZipBuilder {
    return \Drupal::service('xedc_core.zip_builder');
  }

}
