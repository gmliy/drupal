<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Video;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Builds one local H.264 copy when the source picture is HEVC.
 *
 * The original file stays in place. The copy is only for browsers that do
 * not decode HEVC. It is not a quality ladder.
 */
final class PlaybackTranscoder {

  public function __construct(
    private readonly StreamWrapperManagerInterface $streams,
    private readonly StateInterface $state,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Site-relative URL of an existing H.264 copy, or an empty string.
   */
  public function localPath(MediaInterface|FileInterface|null $entity): string {
    $file = $this->file($entity);
    if (!$file instanceof FileInterface) {
      return '';
    }
    $fid = (int) $file->id();
    $path = $this->absolutePath($fid);
    if ($path === '' || !is_file($path) || filesize($path) < 1) {
      return '';
    }
    return '/sites/default/files/xedc-playback/' . $fid . '.mp4';
  }

  public function readableSource(FileInterface $file): string {
    return $this->sourcePath($file);
  }

  public function playbackFile(int $fid): string {
    return $this->absolutePath($fid);
  }

  /**
   * Writes one H.264 file. The caller validates it before it becomes the playback copy.
   */
  public function writeCopy(string $source, string $destination, bool $audio, int $width, int $height): bool {
    $binary = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : 'ffmpeg';
    $args = [$binary, '-y', '-threads', '2', '-i', $source, '-map', '0:v:0'];
    if ($audio) {
      $args[] = '-map';
      $args[] = '0:a:0';
    }
    $long = max($width, $height);
    if ($long > 1920 && $width > 0 && $height > 0) {
      $args[] = '-vf';
      $args[] = $width >= $height ? 'scale=1920:-2' : 'scale=-2:1920';
    }
    array_push($args, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p');
    if ($audio) {
      array_push($args, '-c:a', 'aac', '-ac', '2', '-b:a', '128k');
    }
    array_push($args, '-movflags', '+faststart', $destination);
    $process = new Process($args);
    $process->setTimeout(180);
    try {
      $process->run();
    }
    catch (\Throwable $e) {
      $this->logger->error('播放副本转码失败：@message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
    if (!$process->isSuccessful()) {
      $this->logger->error('播放副本转码未完成，退出码 @code。', ['@code' => (string) $process->getExitCode()]);
      return FALSE;
    }
    return is_file($destination) && filesize($destination) > 0;
  }

  /**
   * Creates the H.264 copy when the source video codec is HEVC.
   */
  public function ensure(FileInterface $file): bool {
    $fid = (int) $file->id();
    if ($fid < 1 || $this->localPath($file) !== '') {
      return $this->localPath($file) !== '';
    }
    $codec = (string) $this->state->get('xedc_video.codec.' . $fid, '');
    $path = $this->sourcePath($file);
    if ($path === '') {
      return FALSE;
    }
    if ($codec === '') {
      $codec = $this->codec($path);
      if ($codec !== '') {
        $this->state->set('xedc_video.codec.' . $fid, $codec);
      }
    }
    if (!in_array($codec, ['hevc', 'h265'], TRUE)) {
      return FALSE;
    }
    $directory = dirname($this->absolutePath($fid));
    if ($directory === '' || $directory === '.' || (!is_dir($directory) && !@mkdir($directory, 0775, TRUE) && !is_dir($directory))) {
      $this->logger->error('无法创建视频播放副本目录。');
      return FALSE;
    }
    $target = $this->absolutePath($fid);
    $binary = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : 'ffmpeg';
    $process = new Process([
      $binary,
      '-y',
      '-i', $path,
      '-map', '0:v:0',
      '-map', '0:a:0?',
      '-c:v', 'libx264',
      '-pix_fmt', 'yuv420p',
      '-preset', 'veryfast',
      '-crf', '23',
      '-c:a', 'aac',
      '-ac', '2',
      '-b:a', '128k',
      '-movflags', '+faststart',
      $target,
    ]);
    $process->setTimeout(120);
    try {
      $process->run();
    }
    catch (ProcessTimedOutException $e) {
      $this->logger->error('HEVC 转 H.264 超时：文件 @fid', ['@fid' => $fid]);
      return FALSE;
    }
    catch (\Throwable $e) {
      $this->logger->error('HEVC 转 H.264 失败：@message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
    if (!$process->isSuccessful() || !is_file($target) || filesize($target) < 1) {
      $detail = trim($process->getErrorOutput());
      if (strlen($detail) > 240) {
        $detail = substr($detail, 0, 240);
      }
      $this->logger->error('HEVC 转 H.264 未生成文件 @fid：@error', [
        '@fid' => $fid,
        '@error' => $detail !== '' ? $detail : 'exit ' . $process->getExitCode(),
      ]);
      if (is_file($target)) {
        @unlink($target);
      }
      return FALSE;
    }
    $this->logger->info('已为文件 @fid 生成 H.264 播放副本，原文件保留。', ['@fid' => $fid]);
    return TRUE;
  }

  private function file(MediaInterface|FileInterface|null $entity): ?FileInterface {
    if ($entity instanceof FileInterface) {
      return $entity;
    }
    if (!$entity instanceof MediaInterface) {
      return NULL;
    }
    $fid = (int) $entity->getSource()->getSourceFieldValue($entity);
    $file = $fid > 0 ? \Drupal::entityTypeManager()->getStorage('file')->load($fid) : NULL;
    return $file instanceof FileInterface ? $file : NULL;
  }

  private function sourcePath(FileInterface $file): string {
    $uri = (string) $file->getFileUri();
    $scheme = (string) $this->streams->getScheme($uri);
    if (!in_array($scheme, ['public', 'private'], TRUE)) {
      return '';
    }
    $target = StreamWrapperManager::getTarget($uri);
    if (!is_string($target) || $target === '' || str_contains($target, '..')) {
      return '';
    }
    $base = $scheme === 'private' ? PrivateStream::basePath() : PublicStream::basePath();
    if (!is_string($base) || $base === '') {
      return '';
    }
    $root = DRUPAL_ROOT . '/' . $base;
    $path = $root . '/' . $target;
    $realRoot = realpath($root);
    $real = realpath($path);
    if ($real === FALSE || $realRoot === FALSE || !is_file($real)) {
      return '';
    }
    $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($real, $prefix)) {
      return '';
    }
    return $real;
  }

  private function absolutePath(int $fid): string {
    $base = PublicStream::basePath();
    if (!is_string($base) || $base === '') {
      return '';
    }
    return DRUPAL_ROOT . '/' . $base . '/xedc-playback/' . $fid . '.mp4';
  }

  private function codec(string $path): string {
    $binary = is_executable('/usr/bin/ffprobe') ? '/usr/bin/ffprobe' : 'ffprobe';
    $process = new Process([
      $binary,
      '-v', 'error',
      '-select_streams', 'v:0',
      '-show_entries', 'stream=codec_name',
      '-of', 'default=noprint_wrappers=1:nokey=1',
      $path,
    ]);
    $process->setTimeout(20);
    try {
      $process->run();
    }
    catch (\Throwable $e) {
      $this->logger->error('读取视频编码失败：@message', ['@message' => $e->getMessage()]);
      return '';
    }
    if (!$process->isSuccessful()) {
      return '';
    }
    return strtolower(trim($process->getOutput()));
  }

}
