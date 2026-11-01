<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\Process\Process;

/**
 * Reads mounted filesystems with a fixed df command.
 */
class DiskOverview {

  public function __construct(
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
  ) {}

  /**
   * @return array{ok: bool, error: string, rows: list<array<string, string>>, primary: array<string, string>}
   *   Partition table. Arguments to df are constants.
   */
  public function summary(bool $refresh = FALSE): array {
    $cid = 'xedc_core:disk:overview';
    if (!$refresh) {
      $cached = $this->cache->get($cid);
      if ($cached && is_array($cached->data)) {
        return $cached->data;
      }
    }
    $summary = $this->read();
    $this->cache->set($cid, $summary, $this->time->getRequestTime() + 30);
    return $summary;
  }

  /**
   * @return array{ok: bool, error: string, rows: list<array<string, string>>, primary: array<string, string>}
   *   Fresh df output.
   */
  private function read(): array {
    $env = getenv();
    if (!is_array($env)) {
      $env = [];
    }
    $env['LANG'] = 'C';
    $env['LC_ALL'] = 'C';
    $process = new Process([
      '/usr/bin/df',
      '-B1',
      '-T',
      '-P',
      '-x',
      'tmpfs',
      '-x',
      'devtmpfs',
      '-x',
      'squashfs',
      '-x',
      'overlay',
      '-x',
      'efivarfs',
    ], NULL, $env);
    $process->setTimeout(5);
    try {
      $process->run();
    }
    catch (\Throwable $exception) {
      return $this->fallback($exception->getMessage());
    }
    if (!$process->isSuccessful()) {
      return $this->fallback(trim($process->getErrorOutput()) ?: 'df 退出码 ' . $process->getExitCode());
    }
    $rows = [];
    foreach (preg_split('/\r\n|\n/', $process->getOutput()) ?: [] as $line) {
      if (!preg_match('/^(\S+)\s+(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+(.+)$/', trim($line), $matches)) {
        continue;
      }
      $rows[] = [
        'device' => $matches[1],
        'type' => $matches[2],
        'mount' => $matches[7],
        'total' => OpsFormat::bytes((float) $matches[3]),
        'used' => OpsFormat::bytes((float) $matches[4]),
        'available' => OpsFormat::bytes((float) $matches[5]),
        'percent' => $matches[6] . '%',
        'total_bytes' => (string) $matches[3],
        'used_bytes' => (string) $matches[4],
        'available_bytes' => (string) $matches[5],
      ];
    }
    if ($rows === []) {
      return $this->fallback('df 没有返回可解析的分区。');
    }
    $primary = $rows[0];
    foreach ($rows as $row) {
      if ($row['mount'] === '/') {
        $primary = $row;
        break;
      }
    }
    return [
      'ok' => TRUE,
      'error' => '',
      'rows' => $rows,
      'primary' => $primary,
    ];
  }

  /**
   * @return array{ok: bool, error: string, rows: list<array<string, string>>, primary: array<string, string>}
   *   Root filesystem only, from PHP's statvfs.
   */
  private function fallback(string $error): array {
    $total = @disk_total_space('/');
    $free = @disk_free_space('/');
    if ($total === FALSE || $free === FALSE || $total <= 0) {
      return [
        'ok' => FALSE,
        'error' => '磁盘信息暂不可用：' . $error,
        'rows' => [],
        'primary' => [],
      ];
    }
    $used = $total - $free;
    $percent = (string) (int) round(($used / $total) * 100);
    $primary = [
      'device' => '暂无数据',
      'type' => '暂无数据',
      'mount' => '/',
      'total' => OpsFormat::bytes($total),
      'used' => OpsFormat::bytes($used),
      'available' => OpsFormat::bytes($free),
      'percent' => $percent . '%',
      'total_bytes' => (string) (int) $total,
      'used_bytes' => (string) (int) $used,
      'available_bytes' => (string) (int) $free,
    ];
    return [
      'ok' => TRUE,
      'error' => '分区明细暂不可用（' . $error . '）。根分区数字来自 PHP statvfs。',
      'rows' => [$primary],
      'primary' => $primary,
    ];
  }

}
