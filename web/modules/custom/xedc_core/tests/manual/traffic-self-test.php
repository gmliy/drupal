<?php

/**
 * @file
 * Exercises traffic accounting against the site database, then removes zz* rows.
 */

declare(strict_types=1);

use Drupal\xedc_core\Ops\Traffic\CounterSource;
use Drupal\xedc_core\Ops\Traffic\NicGuard;
use Drupal\xedc_core\Ops\Traffic\SysfsCounterReader;

final class TrafficFakeReader implements CounterSource {

  public function __construct(
    private readonly ?array $value,
  ) {}

  public function read(string $iface): ?array {
    unset($iface);
    return $this->value;
  }

}

if (getenv('XEDC_TRAFFIC_RACE') === '1') {
  $result = \Drupal::service('xedc_core.traffic')->sampleInterface('zzlock0', 1893456000, FALSE, new TrafficFakeReader(['rx' => '5000', 'tx' => '8000']));
  echo (string) ($result['status'] ?? '') . PHP_EOL;
  return;
}

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
  echo ($condition ? 'OK  ' : 'FAIL ') . $label . PHP_EOL;
  if (!$condition) {
    $failures++;
  }
};

$zone = new DateTimeZone('Asia/Shanghai');
$stamp = static function (string $local) use ($zone): int {
  return (new DateTimeImmutable($local, $zone))->getTimestamp();
};

$database = \Drupal::database();
$collector = \Drupal::service('xedc_core.traffic');
$report = \Drupal::service('xedc_core.traffic_report');

$cleanup = static function () use ($database): void {
  $database->delete('xedc_traffic_sample')->condition('iface', 'zz%', 'LIKE')->execute();
  $database->delete('xedc_traffic_day')->condition('iface', 'zz%', 'LIKE')->execute();
};
$cleanup();

$check('ens5 is physical', NicGuard::isPhysical('ens5'));
$check('docker0 is not physical', !NicGuard::isPhysical('docker0'));
$check('only ens5 is counted', NicGuard::physical() === ['ens5']);
$check('default route is ens5', NicGuard::defaultRoute() === 'ens5');
$check('path is rejected', ($collector->sampleInterface('../etc/passwd')['status'] ?? '') === 'rejected');
$check('docker0 is rejected', ($collector->sampleInterface('docker0')['status'] ?? '') === 'rejected');
$sysfs = (new SysfsCounterReader())->read('ens5');
$check('sysfs counters are numeric', is_array($sysfs) && ctype_digit($sysfs['rx']) && ctype_digit($sysfs['tx']));
$check('reader hides virtual counters', (new SysfsCounterReader())->read('docker0') === NULL);

$iface = 'zzmonth0';
$collector->sampleInterface($iface, $stamp('2026-01-31 23:00:00'), FALSE, new TrafficFakeReader(['rx' => '100', 'tx' => '100']));
$counted = $collector->sampleInterface($iface, $stamp('2026-01-31 23:50:00'), FALSE, new TrafficFakeReader(['rx' => '300', 'tx' => '220']));
$check('january delta counted', ($counted['status'] ?? '') === 'sparse' && ($counted['rx_delta'] ?? '') === '200' && ($counted['tx_delta'] ?? '') === '120');
$cross = $collector->sampleInterface($iface, $stamp('2026-02-01 00:10:00'), FALSE, new TrafficFakeReader(['rx' => '800', 'tx' => '420']));
$check('cross month not counted', ($cross['status'] ?? '') === 'cross_day' && ($cross['counted'] ?? TRUE) === FALSE);
$february = $collector->sampleInterface($iface, $stamp('2026-02-01 01:00:00'), FALSE, new TrafficFakeReader(['rx' => '900', 'tx' => '450']));
$check('february delta counted', ($february['rx_delta'] ?? '') === '100' && ($february['tx_delta'] ?? '') === '30');

$january = $report->inspect($iface, 7, '2026-01-31');
$februaryView = $report->inspect($iface, 7, '2026-02-01');
$check('january download is 200 B', ($january['days'][0]['rx'] ?? '') === '200 B');
$check('january upload is 120 B', ($january['days'][0]['tx'] ?? '') === '120 B');
$check('february download excludes the cross-day gap', ($februaryView['days'][0]['rx'] ?? '') === '100 B');
$check('february upload is 30 B', ($februaryView['days'][0]['tx'] ?? '') === '30 B');
$check('cross-day bytes are visible but unassigned', str_contains((string) ($februaryView['days'][0]['note'] ?? ''), '未分配'));

$todayIface = 'zztoday0';
$now = \Drupal::time()->getRequestTime();
$collector->sampleInterface($todayIface, $now - 180, FALSE, new TrafficFakeReader(['rx' => '1000', 'tx' => '2000']));
$step = $collector->sampleInterface($todayIface, $now - 70, FALSE, new TrafficFakeReader(['rx' => '2500', 'tx' => '2600']));
$check('non-sixty interval counted', ($step['status'] ?? '') === 'ok' && ($step['rx_delta'] ?? '') === '1500' && ($step['gap'] ?? 0) !== 60);
$repeat = $collector->sampleInterface($todayIface, $now - 65, FALSE, new TrafficFakeReader(['rx' => '9999', 'tx' => '9999']));
$check('repeat inside the interval does not add', ($repeat['status'] ?? '') === 'skipped');
$today = $report->inspect($todayIface, 7, '');
$check('today download is 1.5 KB', ($today['cards'][0]['value'] ?? '') === '1.5 KB');
$check('today upload is 600 B', ($today['cards'][1]['value'] ?? '') === '600 B');
$check('month includes today', ($today['cards'][2]['value'] ?? '') === '1.5 KB' && ($today['cards'][3]['value'] ?? '') === '600 B');

$failedBefore = (string) $database->select('xedc_traffic_day', 'd')->fields('d', ['rx_bytes'])->condition('iface', $todayIface)->condition('day', (new DateTimeImmutable('@' . $now))->setTimezone($zone)->format('Y-m-d'))->execute()->fetchField();
$failed = $collector->sampleInterface($todayIface, $now - 10, FALSE, new TrafficFakeReader(NULL));
$failedAfter = (string) $database->select('xedc_traffic_day', 'd')->fields('d', ['rx_bytes'])->condition('iface', $todayIface)->condition('day', (new DateTimeImmutable('@' . $now))->setTimezone($zone)->format('Y-m-d'))->execute()->fetchField();
$check('failed read stores no traffic', ($failed['status'] ?? '') === 'failed' && $failedBefore === $failedAfter);

$resetIface = 'zzreset0';
$collector->sampleInterface($resetIface, $now - 400, FALSE, new TrafficFakeReader(['rx' => '9000', 'tx' => '100']));
$collector->sampleInterface($resetIface, $now - 300, FALSE, new TrafficFakeReader(['rx' => '9100', 'tx' => '150']));
$reset = $collector->sampleInterface($resetIface, $now - 200, FALSE, new TrafficFakeReader(['rx' => '5', 'tx' => '1']));
$check('reboot or reset does not go negative', ($reset['status'] ?? '') === 'reset' && ($reset['counted'] ?? TRUE) === FALSE);
$after = $collector->sampleInterface($resetIface, $now - 100, FALSE, new TrafficFakeReader(['rx' => '25', 'tx' => '11']));
$check('new baseline counts only the later increment', ($after['rx_delta'] ?? '') === '20' && ($after['tx_delta'] ?? '') === '10');
$resetDay = (string) $database->select('xedc_traffic_day', 'd')->fields('d', ['rx_bytes'])->condition('iface', $resetIface)->execute()->fetchField();
$check('reset day total is 120', $resetDay === '120');

$gapIface = 'zzgap0';
$collector->sampleInterface($gapIface, $stamp('2026-11-02 12:00:00'), FALSE, new TrafficFakeReader(['rx' => '10', 'tx' => '10']));
$gap = $collector->sampleInterface($gapIface, $stamp('2026-11-02 20:00:00'), FALSE, new TrafficFakeReader(['rx' => '40', 'tx' => '15']));
$check('long gap is marked and still uses the counter difference', ($gap['status'] ?? '') === 'gap' && ($gap['rx_delta'] ?? '') === '30');
$gapRow = $report->inspect($gapIface, 7, '2026-11-02');
$check('long gap is not shown as complete', ($gapRow['days'][0]['integrity'] ?? '') === '有缺口');

$sparseIface = 'zzsparse0';
$collector->sampleInterface($sparseIface, $stamp('2026-11-02 10:00:00'), FALSE, new TrafficFakeReader(['rx' => '1', 'tx' => '1']));
$sparse = $collector->sampleInterface($sparseIface, $stamp('2026-11-02 10:25:00'), FALSE, new TrafficFakeReader(['rx' => '9', 'tx' => '4']));
$check('sparse interval is partial', ($sparse['status'] ?? '') === 'sparse');
$sparseRow = $report->inspect($sparseIface, 7, '2026-11-02');
$check('sparse day is marked', ($sparseRow['days'][0]['integrity'] ?? '') === '采样偏疏');

$missing = $report->inspect($todayIface, 7, '2020-01-01');
$check('missing day is not zero', ($missing['days'][0]['rx'] ?? '') === '未采集');
$week = $report->inspect($todayIface, 7, '');
$monthTrend = $report->inspect($todayIface, 30, '');
$quarter = $report->inspect($todayIface, 90, '');
$check('trend lengths', count($week['chart']['points'] ?? []) === 7 && count($monthTrend['chart']['points'] ?? []) === 30 && count($quarter['chart']['points'] ?? []) === 90);
$check('units', $report->bytes('0') === '0 B' && $report->bytes('1536') === '1.5 KB' && $report->bytes('1048576') === '1.0 MB' && $report->bytes('1073741824') === '1.0 GB');

$old = 'zzpurge0';
$oldDay = (new DateTimeImmutable('@' . $now))->setTimezone($zone)->modify('-100 days');
$collector->sampleInterface($old, $oldDay->getTimestamp(), FALSE, new TrafficFakeReader(['rx' => '10', 'tx' => '10']));
$collector->sampleInterface($old, $oldDay->modify('+2 minutes')->getTimestamp(), FALSE, new TrafficFakeReader(['rx' => '70', 'tx' => '40']));
$purge = $collector->purge($now);
$oldSamples = (int) $database->select('xedc_traffic_sample', 's')->condition('iface', $old)->countQuery()->execute()->fetchField();
$oldDayRow = $database->select('xedc_traffic_day', 'd')->fields('d', ['rx_bytes'])->condition('iface', $old)->execute()->fetchField();
$check('matched snapshots can be removed', $oldSamples === 0 && $oldDayRow === '60' && ($purge['removed'] ?? 0) >= 2);

$kept = 'zzpurge1';
$collector->sampleInterface($kept, $oldDay->getTimestamp() + 10, FALSE, new TrafficFakeReader(['rx' => '10', 'tx' => '10']));
$collector->sampleInterface($kept, $oldDay->modify('+4 minutes')->getTimestamp(), FALSE, new TrafficFakeReader(['rx' => '15', 'tx' => '12']));
$database->update('xedc_traffic_day')->fields(['rx_bytes' => 1])->condition('iface', $kept)->execute();
$collector->purge($now);
$keptSamples = (int) $database->select('xedc_traffic_sample', 's')->condition('iface', $kept)->countQuery()->execute()->fetchField();
$check('mismatched snapshots stay', $keptSamples === 2);

$collector->sampleInterface('zzlock0', 1893455900, FALSE, new TrafficFakeReader(['rx' => '100', 'tx' => '100']));
$script = 'web/modules/custom/xedc_core/tests/manual/traffic-self-test.php';
$env = ['XEDC_TRAFFIC_RACE' => '1', 'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'];
foreach (['HOME', 'USER', 'LANG', 'LC_ALL'] as $envKey) {
  $envValue = getenv($envKey);
  if (is_string($envValue) && $envValue !== '') {
    $env[$envKey] = $envValue;
  }
}
$start = static function () use ($script, $env): array {
  $pipes = [];
  $process = proc_open(['vendor/bin/drush', 'php:script', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/var/www/drupal', $env);
  return [$process, $pipes];
};
[$first, $firstPipes] = $start();
[$second, $secondPipes] = $start();
$firstOut = stream_get_contents($firstPipes[1]);
$secondOut = stream_get_contents($secondPipes[1]);
fclose($firstPipes[1]);
fclose($firstPipes[2]);
fclose($secondPipes[1]);
fclose($secondPipes[2]);
proc_close($first);
proc_close($second);
$raceOut = trim((string) $firstOut . "\n" . (string) $secondOut);
echo 'RACE ' . str_replace("\n", ' | ', $raceOut) . PHP_EOL;
$lockRx = (string) $database->select('xedc_traffic_day', 'd')->fields('d', ['rx_bytes'])->condition('iface', 'zzlock0')->execute()->fetchField();
$check('concurrent samples do not double count', $lockRx === '4900' && str_contains($raceOut, 'skipped') && str_contains($raceOut, 'ok'));

$ensBefore = (int) $database->select('xedc_traffic_sample', 's')->condition('iface', 'ens5')->countQuery()->execute()->fetchField();
$encoded = json_encode($report->report('../etc/passwd', 999, 'not-a-day'), JSON_UNESCAPED_UNICODE);
$ensAfter = (int) $database->select('xedc_traffic_sample', 's')->condition('iface', 'ens5')->countQuery()->execute()->fetchField();
$check('invalid nic and span stay on a physical nic', is_string($encoded) && str_contains($encoded, '"iface":"ens5"') && !str_contains($encoded, '/sys/') && !str_contains($encoded, 'passwd'));
$check('report does not sample', $ensBefore === $ensAfter);

$live = $collector->samplePhysical();
$check('live sample is ens5 only', count($live) === 1 && ($live[0]['iface'] ?? '') === 'ens5' && in_array($live[0]['status'] ?? '', ['baseline', 'ok', 'sparse', 'skipped'], TRUE));

$cleanup();
echo $failures === 0 ? "PASS $failures failures\n" : "FAIL $failures failures\n";
return $failures === 0 ? 0 : 1;
