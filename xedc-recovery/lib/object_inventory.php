<?php

declare(strict_types=1);

/**
 * 只读清点 S3/R2 对象数量。密钥从文件读取，不写入输出。
 */

require_once __DIR__ . '/settings_extract.php';

function xedc_inventory_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function xedc_inventory_count($client, string $bucket, string $prefix): int
{
    $count = 0;
    $params = ['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 1000];
    do {
        $result = $client->listObjectsV2($params);
        $count += count($result['Contents'] ?? []);
        if (!empty($result['IsTruncated']) && !empty($result['NextContinuationToken'])) {
            $params['ContinuationToken'] = $result['NextContinuationToken'];
        } else {
            break;
        }
    } while (true);
    return $count;
}

/**
 * @param array<int, string> $argv
 */
function xedc_inventory_main(array $argv): int
{
    $opts = getopt('', ['site-root:', 'output:', 'help']);
    if ($opts === false || isset($opts['help']) || !isset($opts['site-root'], $opts['output'])) {
        fwrite(STDERR, "用法: php object_inventory.php --site-root DIR --output FILE\n");
        return 1;
    }
    $root = rtrim((string) $opts['site-root'], '/');
    $settings = xedc_settings_extract($root . '/web/sites/default/settings.php');
    $private = (string) ($settings['private_path'] ?? '');
    $active = xedc_inventory_read_json($private . '/xedc-storage-runtime/active.json');
    $mode = (string) ($active['public_mode'] ?? 'unknown');
    $provider = (string) ($active['public_provider'] ?? '');
    if ($provider !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $provider) !== 1) {
        fwrite(STDERR, "对象存储配置名不合法。\n");
        return 1;
    }
    $profileDir = $private . '/xedc-storage-keys/' . $provider;
    $profile = xedc_inventory_read_json($profileDir . '/runtime.json');
    $report = [
        'in_use' => $mode === 'cloudflare_r2',
        'mode' => $mode,
        'provider' => (string) ($active['public_provider'] ?? ''),
        'bucket' => (string) ($profile['bucket'] ?? ''),
        'region' => (string) ($profile['region'] ?? ''),
        'endpoint' => (string) ($profile['endpoint'] ?? ''),
        'public_folder' => (string) ($profile['public_folder'] ?? 'public'),
        'private_folder' => (string) ($profile['private_folder'] ?? 'private'),
        'cdn_base' => (string) ($active['cdn_base'] ?? ''),
        'prefixes' => [],
        'status' => 'SKIP',
        'detail' => '',
    ];
    if ($mode !== 'cloudflare_r2') {
        $report['status'] = 'PASS';
        $report['detail'] = '当前公开存储不是对象存储。';
        file_put_contents((string) $opts['output'], (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        chmod((string) $opts['output'], 0600);
        return 0;
    }
    $accessFile = $profileDir . '/access_key';
    $secretFile = $profileDir . '/secret_key';
    if (!is_file($accessFile) || !is_file($secretFile)) {
        $report['status'] = 'FAIL';
        $report['detail'] = '对象存储正在使用，但密钥文件缺失。';
        file_put_contents((string) $opts['output'], (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        chmod((string) $opts['output'], 0600);
        return 1;
    }
    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        $report['status'] = 'FAIL';
        $report['detail'] = '缺少 AWS SDK，无法清点对象。';
        file_put_contents((string) $opts['output'], (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        chmod((string) $opts['output'], 0600);
        return 1;
    }
    require_once $autoload;
    $client = new Aws\S3\S3Client([
        'version' => 'latest',
        'region' => $report['region'] !== '' ? $report['region'] : 'auto',
        'endpoint' => $report['endpoint'],
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key' => trim((string) file_get_contents($accessFile)),
            'secret' => trim((string) file_get_contents($secretFile)),
        ],
    ]);
    $prefixes = array_values(array_unique(array_filter([
        $report['public_folder'] . '/',
        $report['private_folder'] . '/',
    ])));
    foreach ($prefixes as $prefix) {
        $report['prefixes'][] = [
            'prefix' => $prefix,
            'count' => xedc_inventory_count($client, $report['bucket'], $prefix),
        ];
    }
    $report['status'] = 'PASS';
    $report['detail'] = '已只读列出对象数量。';
    file_put_contents((string) $opts['output'], (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    chmod((string) $opts['output'], 0600);
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    try {
        exit(xedc_inventory_main($argv));
    } catch (Throwable $exception) {
        $message = preg_replace('/(key|secret|token|signature)[^\s]*/i', '***', $exception->getMessage()) ?? '对象清点失败';
        fwrite(STDERR, "对象清点失败: " . $message . "\n");
        exit(1);
    }
}
