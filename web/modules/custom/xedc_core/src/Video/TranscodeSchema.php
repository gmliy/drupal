<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Video;

/**
 * One row per source file. Node id is not the key, because several videos can share one file.
 */
final class TranscodeSchema {

  public const TABLE = 'xedc_video_transcode';

  /**
   * @return array<string, mixed>
   */
  public static function table(): array {
    return [
      'description' => 'Playback-copy jobs keyed by the source file id. Original files are not replaced.',
      'fields' => [
        'source_fid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'status' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'pending'],
        'verdict' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => ''],
        'source_uri' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
        'source_size' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'source_stamp' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'video_codec' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => ''],
        'audio_codec' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => ''],
        'container' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''],
        'profile' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''],
        'pix_fmt' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => ''],
        'width' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'height' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'output_fid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'output_size' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'attempts' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'message' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
        'started' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'finished' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'elapsed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
      ],
      'primary key' => ['source_fid'],
      'indexes' => [
        'status' => ['status'],
        'verdict' => ['verdict'],
      ],
    ];
  }

}
