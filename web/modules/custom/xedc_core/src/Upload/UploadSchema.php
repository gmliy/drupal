<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Upload;

/**
 * Database tables for resumable upload sessions.
 */
final class UploadSchema {

  /**
   * @return array<string, array<string, mixed>>
   */
  public static function tables(): array {
    return [
      'xedc_upload_session' => [
        'description' => 'Resumable upload sessions for images and archives.',
        'fields' => [
          'session_id' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
          'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'filename' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
          'mime' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE, 'default' => ''],
          'kind' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => ''],
          'tier' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'member'],
          'total_bytes' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'chunk_bytes' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'chunk_count' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'received_bytes' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'head_sha256' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''],
          'file_sha256' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''],
          'sha_status' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'pending'],
          'status' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => 'open'],
          'nid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'title' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
          'task_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'final_uri' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
          'client_upload_id' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''],
          'reimport' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'error' => ['type' => 'text', 'not null' => FALSE],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['session_id'],
        'indexes' => [
          'uid_status' => ['uid', 'status'],
          'uid_client' => ['uid', 'client_upload_id'],
          'changed' => ['changed'],
        ],
      ],
      'xedc_blob_claim' => [
        'description' => 'One physical object claim per stored SHA-256.',
        'fields' => [
          'sha256' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
          'bytes' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'fid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'uri' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
          'kind' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'stored'],
          'status' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'pending'],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['sha256'],
      ],
      'xedc_upload_chunk' => [
        'description' => 'One successfully stored chunk of an upload session.',
        'fields' => [
          'session_id' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
          'chunk_index' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'byte_size' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'sha256' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['session_id', 'chunk_index'],
      ],
    ];
  }

}
