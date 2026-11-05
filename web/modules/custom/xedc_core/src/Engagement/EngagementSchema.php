<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

/**
 * Custom tables for likes, VIP, shares and download history.
 *
 * Download resources themselves are a content entity, not one of these tables.
 */
final class EngagementSchema {

  /**
   * @return array<string, array<string, mixed>>
   *   Schema API definitions keyed by table name.
   */
  public static function tables(): array {
    return [
      'xedc_like' => [
        'description' => 'One active like per member or guest on a piece of content. Guests are stored as uid 0 plus a hash, never the cookie secret.',
        'fields' => [
          'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
          'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'actor_hash' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''],
          'entity_type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => 'node'],
          'entity_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'bundle' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => ''],
          'status' => ['type' => 'int', 'size' => 'tiny', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 1],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['id'],
        'unique keys' => [
          'user_actor' => ['uid', 'actor_hash', 'entity_type', 'entity_id'],
        ],
        'indexes' => [
          'entity' => ['entity_type', 'entity_id', 'status'],
          'bundle_created' => ['bundle', 'status', 'changed'],
          'created' => ['changed'],
          'uid_created' => ['uid', 'status', 'changed'],
        ],
      ],
      'xedc_vip' => [
        'description' => 'VIP window for a user. Access checks read this table, not the role alone.',
        'fields' => [
          'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'status' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'active'],
          'starts' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'expires' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'granted_by' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['uid'],
        'indexes' => [
          'status_expires' => ['status', 'expires'],
        ],
      ],
      'xedc_vip_log' => [
        'description' => 'Append-only VIP grant, extension, cancel and expiry log.',
        'fields' => [
          'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
          'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'action' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => ''],
          'starts' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'expires' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'note' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
          'actor' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['id'],
        'indexes' => [
          'uid_created' => ['uid', 'created'],
          'created' => ['created'],
        ],
      ],
      'xedc_share' => [
        'description' => 'Share outcomes. Opening the panel is not stored as a successful share.',
        'fields' => [
          'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
          'entity_type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => 'node'],
          'entity_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'bundle' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => ''],
          'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'anon_hash' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''],
          'method' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => ''],
          'outcome' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => ''],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['id'],
        'indexes' => [
          'entity_created' => ['entity_type', 'entity_id', 'created'],
          'method_created' => ['method', 'outcome', 'created'],
          'created' => ['created'],
          'actor' => ['uid', 'anon_hash', 'entity_id', 'method', 'created'],
        ],
      ],
      'xedc_download_log' => [
        'description' => 'Authorized download handoffs. Tokens and external URLs are never stored.',
        'fields' => [
          'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
          'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'resource_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'content_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'bundle' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => ''],
          'title' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
          'extension' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => ''],
          'bytes' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'source' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => ''],
          'tier' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => ''],
          'delivery' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => ''],
          'detail' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
          'status' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'authorized'],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['id'],
        'indexes' => [
          'uid_created' => ['uid', 'created'],
          'created' => ['created'],
          'bundle_created' => ['bundle', 'status', 'created'],
          'resource_id' => ['resource_id'],
          'content_created' => ['content_id', 'created'],
          'source_created' => ['source', 'created'],
        ],
      ],
      'xedc_zip_job' => [
        'description' => 'Latest ZIP pack state for one download resource.',
        'fields' => [
          'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
          'resource_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'content_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'status' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'queued'],
          'message' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['id'],
        'unique keys' => [
          'resource_id' => ['resource_id'],
        ],
        'indexes' => [
          'status_changed' => ['status', 'changed'],
          'content_id' => ['content_id'],
        ],
      ],
      'xedc_vip_code_batch' => [
        'description' => 'VIP redeem code batches. Plaintext codes are never stored here.',
        'fields' => [
          'batch_id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
          'batch_uuid' => ['type' => 'varchar', 'length' => 36, 'not null' => TRUE, 'default' => ''],
          'vip_days' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'quantity' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'expires_at' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'note' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
          'created_uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'status' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'active'],
        ],
        'primary key' => ['batch_id'],
        'unique keys' => [
          'batch_uuid' => ['batch_uuid'],
        ],
        'indexes' => [
          'created' => ['created'],
          'status_created' => ['status', 'created'],
        ],
      ],
      'xedc_vip_code' => [
        'description' => 'VIP redeem codes stored as hashes only.',
        'fields' => [
          'code_id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
          'batch_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'code_hash' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''],
          'code_prefix' => ['type' => 'varchar', 'length' => 8, 'not null' => TRUE, 'default' => ''],
          'status' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'unused'],
          'vip_days' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'expires_at' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'redeemed_uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'redeemed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['code_id'],
        'unique keys' => [
          'code_hash' => ['code_hash'],
        ],
        'indexes' => [
          'batch_status' => ['batch_id', 'status'],
          'status' => ['status'],
          'redeemed_uid' => ['redeemed_uid'],
          'prefix' => ['code_prefix'],
        ],
      ],
    ];
  }

}
