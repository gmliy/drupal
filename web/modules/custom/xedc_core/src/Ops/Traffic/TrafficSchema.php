<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Traffic;

/**
 * Tables for physical NIC counters and daily totals.
 */
final class TrafficSchema {

  /**
   * @return array<string, array<string, mixed>>
   *   Schema API definitions.
   */
  public static function tables(): array {
    return [
      'xedc_traffic_sample' => [
        'description' => 'Physical NIC counter snapshots. Increments are the difference between adjacent successful reads.',
        'fields' => [
          'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
          'iface' => ['type' => 'varchar', 'length' => 15, 'not null' => TRUE, 'default' => ''],
          'sampled' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'rx_bytes' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => FALSE],
          'tx_bytes' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => FALSE],
          'rx_delta' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => FALSE],
          'tx_delta' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => FALSE],
          'gap_seconds' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'status' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => ''],
          'counted' => ['type' => 'int', 'size' => 'tiny', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'detail' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['id'],
        'unique keys' => [
          'iface_sampled' => ['iface', 'sampled'],
        ],
        'indexes' => [
          'sampled' => ['sampled'],
        ],
      ],
      'xedc_traffic_day' => [
        'description' => 'Daily allocated traffic. Month totals are summed from these rows.',
        'fields' => [
          'iface' => ['type' => 'varchar', 'length' => 15, 'not null' => TRUE, 'default' => ''],
          'day' => ['type' => 'varchar', 'length' => 10, 'not null' => TRUE, 'default' => ''],
          'rx_bytes' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'tx_bytes' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'unallocated_rx' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'unallocated_tx' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'samples' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'counted' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'sparse' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'gaps' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'crosses' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'failures' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'resets' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'integrity' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'baseline'],
          'updated' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['iface', 'day'],
        'indexes' => [
          'day' => ['day'],
        ],
      ],
    ];
  }

}
