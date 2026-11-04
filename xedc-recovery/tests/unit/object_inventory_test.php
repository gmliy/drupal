<?php

declare(strict_types=1);

function xedc_inventory_run(string $root, string $output): int
{
    $script = dirname(__DIR__, 2) . '/lib/object_inventory.php';
    $command = 'php ' . escapeshellarg($script) . ' --site-root ' . escapeshellarg($root) . ' --output ' . escapeshellarg($output);
    exec($command, $unused, $status);
    return $status;
}

function xedc_inventory_fixture(string $mode, string $provider): string
{
    $root = sys_get_temp_dir() . '/xedc-inv-' . bin2hex(random_bytes(4));
    $private = $root . '/private';
    mkdir($root . '/web/sites/default', 0700, true);
    mkdir($private . '/xedc-storage-runtime', 0700, true);
    file_put_contents($root . '/web/sites/default/settings.php', "<?php\n\$settings['file_private_path'] = " . var_export($private, true) . ";\n");
    file_put_contents($private . '/xedc-storage-runtime/active.json', json_encode([
        'public_mode' => $mode,
        'public_provider' => $provider,
    ]));
    return $root;
}

$local = xedc_inventory_fixture('local', 'lab');
$localOut = $local . '/out.json';
$status = xedc_inventory_run($local, $localOut);
$report = json_decode((string) file_get_contents($localOut), true);
if ($status !== 0 || ($report['status'] ?? '') !== 'PASS' || ($report['mode'] ?? '') !== 'local' || ($report['in_use'] ?? true) !== false) {
    fwrite(STDERR, "FAIL local inventory\n");
    exit(1);
}
echo "PASS local inventory\n";

$missing = xedc_inventory_fixture('cloudflare_r2', 'lab');
$missingOut = $missing . '/out.json';
$status = xedc_inventory_run($missing, $missingOut);
$report = json_decode((string) file_get_contents($missingOut), true);
if ($status === 0 || ($report['status'] ?? '') !== 'FAIL') {
    fwrite(STDERR, "FAIL missing keys were accepted\n");
    exit(1);
}
echo "PASS missing keys\n";
