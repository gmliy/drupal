<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

/**
 * Writes a short H.264 MP4 that a browser can decode.
 *
 * The file is a test fixture. It is not a production video.
 */
final class PlayableMp4 {

  /**
   * Creates a two-second 64x64 video. FALSE when no encoder is available.
   */
  public static function write(string $path): bool {
    $ffmpeg = self::ffmpeg();
    if ($ffmpeg === NULL) {
      return FALSE;
    }
    $command = [
      $ffmpeg,
      '-y',
      '-hide_banner',
      '-loglevel', 'error',
      '-f', 'lavfi',
      '-i', 'color=c=blue:s=64x64:d=2:r=10',
      '-f', 'lavfi',
      '-i', 'sine=frequency=440:sample_rate=8000:duration=2',
      '-c:v', 'libx264',
      '-pix_fmt', 'yuv420p',
      '-profile:v', 'baseline',
      '-level', '3.0',
      '-g', '10',
      '-movflags', '+faststart',
      '-c:a', 'aac',
      '-shortest',
      $path,
    ];
    $spec = [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];
    $process = proc_open($command, $spec, $pipes, NULL, NULL, ['bypass_shell' => TRUE]);
    if (!is_resource($process)) {
      return FALSE;
    }
    $exit = proc_close($process);
    if ($exit !== 0 || !is_file($path) || filesize($path) < 500) {
      return FALSE;
    }
    $probe = self::ffprobe();
    if ($probe === NULL) {
      return TRUE;
    }
    $check = [
      $probe,
      '-v', 'error',
      '-select_streams', 'v:0',
      '-show_entries', 'stream=codec_name,width,height',
      '-of', 'csv=p=0',
      $path,
    ];
    $spec = [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']];
    $reader = proc_open($check, $spec, $pipes, NULL, NULL, ['bypass_shell' => TRUE]);
    if (!is_resource($reader)) {
      return FALSE;
    }
    $line = is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    if (is_resource($pipes[1])) {
      fclose($pipes[1]);
    }
    proc_close($reader);
    return is_string($line) && str_contains($line, 'h264');
  }

  private static function ffmpeg(): ?string {
    foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $path) {
      if (is_executable($path)) {
        return $path;
      }
    }
    return NULL;
  }

  private static function ffprobe(): ?string {
    foreach (['/usr/bin/ffprobe', '/usr/local/bin/ffprobe'] as $path) {
      if (is_executable($path)) {
        return $path;
      }
    }
    return NULL;
  }

}
