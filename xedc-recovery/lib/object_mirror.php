<?php

declare(strict_types=1);

/**
 * 只读下载当前正在使用的 R2 对象。不上传、不删除、不覆盖远端。
 */

require_once __DIR__ . '/settings_extract.php';

function xedc_mirror_relative(string $key, string $prefix): ?string
{
    if ($prefix === '' || !str_starts_with($key, $prefix)) {
        return null;
    }
    $relative = substr($key, strlen($prefix));
    if ($relative === '' || str_ends_with($key, '/')) {
        return null;
    }
    if (str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
        return null;
    }
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return null;
        }
    }
    return $relative;
}

function xedc_mirror_disk_ok(string $directory): bool
{
    $free = disk_free_space($directory);
    return $free === false || $free >= 8 * 1024 * 1024 * 1024;
}

/**
 * @param array<string, mixed> $report
 */
function xedc_mirror_write(string $path, array $report): void
{
    file_put_contents($path, (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    chmod($path, 0600);
}

/**
 * @param array<int, string> $argv
 */
function xedc_mirror_main(array $argv): int
{
    $opts = getopt('', ['site-root:', 'inventory:', 'output:', 'help']);
    if ($opts === false || isset($opts['help']) || !isset($opts['site-root'], $opts['inventory'], $opts['output'])) {
        fwrite(STDERR, "用法: php object_mirror.php --site-root DIR --inventory FILE --output DIR\n");
        return 1;
    }
    $inventoryPath = (string) $opts['inventory'];
    $report = json_decode((string) file_get_contents($inventoryPath), true);
    if (!is_array($report)) {
        fwrite(STDERR, "对象清点结果无法读取。\n");
        return 1;
    }
    $output = rtrim((string) $opts['output'], '/');
    if (!is_dir($output) && !mkdir($output, 0700, true) && !is_dir($output)) {
        fwrite(STDERR, "无法创建镜像目录。\n");
        return 1;
    }
    $report['mirror'] = [
        'status' => 'SKIP',
        'public_count' => 0,
        'public_bytes' => 0,
        'private_count' => 0,
        'private_bytes' => 0,
    ];
    if (($report['in_use'] ?? false) !== true) {
        $report['mirror']['detail'] = '当前公开存储不是对象存储，不下载远端对象。';
        xedc_mirror_write($inventoryPath, $report);
        return 0;
    }
    if (!xedc_mirror_disk_ok($output)) {
        fwrite(STDERR, "磁盘剩余低于 8GB，停止下载对象。没有删除已有备份。\n");
        return 1;
    }
    $root = rtrim((string) $opts['site-root'], '/');
    $settings = xedc_settings_extract($root . '/web/sites/default/settings.php');
    $private = (string) ($settings['private_path'] ?? '');
    $provider = (string) ($report['provider'] ?? '');
    if ($provider === '' || preg_match('/^[A-Za-z0-9_-]+$/', $provider) !== 1) {
        fwrite(STDERR, "对象存储配置名不合法。\n");
        return 1;
    }
    $profileDir = $private . '/xedc-storage-keys/' . $provider;
    $accessFile = $profileDir . '/access_key';
    $secretFile = $profileDir . '/secret_key';
    if (!is_file($accessFile) || !is_file($secretFile)) {
        fwrite(STDERR, "对象存储正在使用，但密钥文件缺失，无法镜像。\n");
        return 1;
    }
    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        fwrite(STDERR, "缺少 AWS SDK，无法镜像对象。\n");
        return 1;
    }
    require_once $autoload;
    $client = new Aws\S3\S3Client([
        'version' => 'latest',
        'region' => ($report['region'] ?? '') !== '' ? $report['region'] : 'auto',
        'endpoint' => $report['endpoint'] ?? '',
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key' => trim((string) file_get_contents($accessFile)),
            'secret' => trim((string) file_get_contents($secretFile)),
        ],
    ]);
    $bucket = (string) ($report['bucket'] ?? '');
    $prefixes = [
        'public' => ((string) ($report['public_folder'] ?? 'public')) . '/',
        'private' => ((string) ($report['private_folder'] ?? 'private')) . '/',
    ];
    $warnings = is_array($report['warnings'] ?? null) ? $report['warnings'] : [];
    $warnings[] = 'R2 对象是列出后逐个下载的副本，与数据库导出不是同一个冻结时间点。下载过程没有上传或删除远端对象。';
    $report['warnings'] = $warnings;
    foreach ($prefixes as $name => $prefix) {
        $directory = $output . '/' . $name;
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            fwrite(STDERR, "无法创建镜像子目录。\n");
            return 1;
        }
        $checksum = fopen($output . '/' . $name . '.sha256', 'wb');
        if ($checksum === false) {
            fwrite(STDERR, "无法写入校验文件。\n");
            return 1;
        }
        $count = 0;
        $bytes = 0;
        $token = null;
        do {
            if (!xedc_mirror_disk_ok($output)) {
                fclose($checksum);
                fwrite(STDERR, "下载过程中磁盘剩余低于 8GB，已停止。没有上传或删除远端对象。\n");
                return 1;
            }
            $params = ['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 1000];
            if (is_string($token) && $token !== '') {
                $params['ContinuationToken'] = $token;
            }
            $result = $client->listObjectsV2($params);
            foreach ($result['Contents'] ?? [] as $object) {
                $key = (string) ($object['Key'] ?? '');
                $relative = xedc_mirror_relative($key, $prefix);
                if ($relative === null) {
                    continue;
                }
                $destination = $directory . '/' . $relative;
                $parent = dirname($destination);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    fclose($checksum);
                    fwrite(STDERR, "无法创建对象目录。\n");
                    return 1;
                }
                $client->getObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'SaveAs' => $destination,
                ]);
                $size = filesize($destination);
                $expected = (int) ($object['Size'] ?? -1);
                if ($size === false || ($expected >= 0 && $size !== $expected)) {
                    fclose($checksum);
                    fwrite(STDERR, "对象大小与列表不一致，镜像停止。\n");
                    return 1;
                }
                $hash = hash_file('sha256', $destination);
                fwrite($checksum, $hash . '  ' . $relative . "\n");
                $count++;
                $bytes += $size;
                if ($count % 100 === 0) {
                    fwrite(STDERR, "已下载 {$name} {$count} 个对象。\n");
                }
            }
            $token = (!empty($result['IsTruncated']) && !empty($result['NextContinuationToken']))
                ? (string) $result['NextContinuationToken']
                : null;
        } while ($token !== null);
        fclose($checksum);
        chmod($output . '/' . $name . '.sha256', 0600);
        $report['mirror'][$name . '_count'] = $count;
        $report['mirror'][$name . '_bytes'] = $bytes;
    }
    $report['mirror']['status'] = 'PASS';
    $report['mirror']['detail'] = '已只读下载对象并计算 SHA-256。没有上传或删除远端对象。';
    $report['detail'] = '已只读列出并下载对象。';
    xedc_mirror_write($inventoryPath, $report);
    fwrite(STDERR, "镜像完成。public {$report['mirror']['public_count']}，private {$report['mirror']['private_count']}。\n");
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    try {
        exit(xedc_mirror_main($argv));
    } catch (Throwable $exception) {
        $message = preg_replace('/(key|secret|token|signature)[^\s]*/i', '***', $exception->getMessage()) ?? '对象镜像失败';
        fwrite(STDERR, "对象镜像失败: " . $message . "\n");
        exit(1);
    }
}
