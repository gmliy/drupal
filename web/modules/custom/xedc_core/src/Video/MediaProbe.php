<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Video;

use Symfony\Component\Process\Process;

/**
 * Reads container and stream metadata, then classifies browser playback.
 *
 * The decision lives here so controllers do not repeat codec rules.
 */
final class MediaProbe {

  /**
   * @return array<string, int|string>
   */
  public function inspect(string $path): array {
    $empty = $this->blank('unreadable', 'ffprobe could not read the file');
    if ($path === '' || !is_file($path)) {
      return $this->blank('unreadable', 'file is missing');
    }
    $binary = is_executable('/usr/bin/ffprobe') ? '/usr/bin/ffprobe' : 'ffprobe';
    $process = new Process([
      $binary,
      '-v', 'error',
      '-show_format',
      '-show_streams',
      '-of', 'json',
      $path,
    ]);
    $process->setTimeout(20);
    try {
      $process->run();
    }
    catch (\Throwable $e) {
      return $this->blank('unreadable', 'ffprobe failed');
    }
    if (!$process->isSuccessful()) {
      return $empty;
    }
    $json = json_decode($process->getOutput(), TRUE);
    if (!is_array($json)) {
      return $empty;
    }
    $video = NULL;
    $audio = NULL;
    $videoCount = 0;
    foreach ($json['streams'] ?? [] as $stream) {
      if (!is_array($stream)) {
        continue;
      }
      $type = (string) ($stream['codec_type'] ?? '');
      if ($type === 'video') {
        $videoCount++;
        $video ??= $stream;
      }
      elseif ($type === 'audio' && $audio === NULL) {
        $audio = $stream;
      }
    }
    if (!$video) {
      return $this->blank('unreadable', 'no video stream');
    }
    $format = is_array($json['format'] ?? NULL) ? $json['format'] : [];
    $codec = strtolower((string) ($video['codec_name'] ?? ''));
    $audioCodec = strtolower((string) ($audio['codec_name'] ?? ''));
    $pix = strtolower((string) ($video['pix_fmt'] ?? ''));
    $profile = strtolower((string) ($video['profile'] ?? ''));
    $container = strtolower((string) ($format['format_name'] ?? ''));
    $row = [
      'video_codec' => $codec,
      'audio_codec' => $audioCodec,
      'container' => $codec === '' ? '' : $container,
      'profile' => $profile,
      'pix_fmt' => $pix,
      'width' => (int) ($video['width'] ?? 0),
      'height' => (int) ($video['height'] ?? 0),
      'duration' => (int) round((float) ($format['duration'] ?? 0)),
      'message' => $videoCount > 1 ? 'extra video streams ignored' : '',
    ];
    $row['verdict'] = $this->verdict($codec, $audioCodec, $pix, $profile, $container);
    if ($row['verdict'] === 'transcode' && $row['message'] === '') {
      $row['message'] = 'needs an H.264 playback copy';
    }
    return $row;
  }

  public function verdict(string $codec, string $audio, string $pix, string $profile, string $container): string {
    if (str_contains($container, 'hls')) {
      return 'hls';
    }
    if (in_array($codec, ['av1', 'vp9', 'vp8'], TRUE)) {
      return 'review';
    }
    if (in_array($codec, ['hevc', 'h265'], TRUE)) {
      return 'transcode';
    }
    if ($codec !== 'h264') {
      return $codec === '' ? 'unreadable' : 'transcode';
    }
    $mp4 = str_contains($container, 'mp4') || str_contains($container, 'mov') || str_contains($container, 'isom');
    $pixels = $pix === '' || $pix === 'yuv420p';
    $audioOk = $audio === '' || in_array($audio, ['aac', 'mp3'], TRUE);
    $profileOk = $profile === '' || !str_contains($profile, '10') && !str_contains($profile, '4:2:2') && !str_contains($profile, '4:4:4');
    if ($mp4 && $pixels && $audioOk && $profileOk) {
      return 'compatible';
    }
    return 'transcode';
  }

  /**
   * @return array<string, int|string>
   */
  private function blank(string $verdict, string $message): array {
    return [
      'video_codec' => '',
      'audio_codec' => '',
      'container' => '',
      'profile' => '',
      'pix_fmt' => '',
      'width' => 0,
      'height' => 0,
      'duration' => 0,
      'verdict' => $verdict,
      'message' => $message,
    ];
  }

}
