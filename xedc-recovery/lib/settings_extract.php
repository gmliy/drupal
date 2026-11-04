<?php

declare(strict_types=1);

/**
 * 从 settings.php 提取数据库和 hash_salt。不执行该文件。
 */

function xedc_settings_code(string $source): string
{
    $tokens = token_get_all($source);
    $code = '';
    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $code .= ' ';
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
    return $code;
}

function xedc_settings_string_after(string $code, string $pattern): ?string
{
    if (preg_match($pattern, $code, $match) !== 1) {
        return null;
    }
    return stripcslashes($match[1]);
}

/**
 * @return array<string, mixed>
 */
function xedc_settings_extract(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException('找不到 settings.php');
    }
    $code = xedc_settings_code((string) file_get_contents($file));
    $database = xedc_settings_string_after($code, "/\\\$databases\\s*\\[[^\\]]+\\]\\s*\\[[^\\]]+\\]\\s*=\\s*array\\s*\\(\\s*'database'\\s*=>\\s*'((?:\\\\'|[^'])*)'/s");
    if ($database === null) {
        $database = xedc_settings_string_after($code, "/'database'\\s*=>\\s*'((?:\\\\'|[^'])*)'/");
    }
    $username = xedc_settings_string_after($code, "/'username'\\s*=>\\s*'((?:\\\\'|[^'])*)'/");
    $password = xedc_settings_string_after($code, "/'password'\\s*=>\\s*'((?:\\\\'|[^'])*)'/");
    $host = xedc_settings_string_after($code, "/'host'\\s*=>\\s*'((?:\\\\'|[^'])*)'/") ?? 'localhost';
    $port = xedc_settings_string_after($code, "/'port'\\s*=>\\s*'((?:\\\\'|[^'])*)'/");
    if ($port === null && preg_match("/'port'\\s*=>\\s*(\\d+)/", $code, $portMatch) === 1) {
        $port = $portMatch[1];
    }
    $port ??= '3306';
    $prefix = xedc_settings_string_after($code, "/'prefix'\\s*=>\\s*'((?:\\\\'|[^'])*)'/") ?? '';
    $salt = xedc_settings_string_after($code, "/\\\$settings\\s*\\[\\s*'hash_salt'\\s*\\]\\s*=\\s*'((?:\\\\'|[^'])*)'/");
    $private = xedc_settings_string_after($code, "/\\\$settings\\s*\\[\\s*'file_private_path'\\s*\\]\\s*=\\s*'((?:\\\\'|[^'])*)'/");
    $hosts = [];
    if (preg_match_all("/\\^([^\\\\\\$]+?)\\\\\\.([^\\\\\\$]+)\\$/", $code, $hostMatches, PREG_SET_ORDER)) {
        foreach ($hostMatches as $hostMatch) {
            $hosts[] = str_replace('\\.', '.', $hostMatch[0]);
        }
    }
    if (preg_match_all("/'\\^([A-Za-z0-9.-]+)\\\\\\./", $code, $simple) === 1) {
        // trusted host patterns are parsed below from quoted strings
    }
    if (preg_match("/trusted_host_patterns'\\]\\s*=\\s*\\[(.*?)\\];/s", $code, $block)) {
        if (preg_match_all("/'([^']+)'/", $block[1], $items)) {
            $hosts = $items[1];
        }
    }
    return [
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'host' => $host,
        'port' => $port,
        'prefix' => $prefix,
        'hash_salt' => $salt,
        'private_path' => $private,
        'trusted_host_patterns' => $hosts,
    ];
}

function xedc_settings_redact(string $source, array $secrets): string
{
    $out = $source;
    foreach (['password', 'hash_salt'] as $key) {
        $value = (string) ($secrets[$key] ?? '');
        if ($value === '' || strlen($value) < 6) {
            continue;
        }
        $out = str_replace($value, 'XEDC_REDACTED', $out);
    }
    return $out;
}

/**
 * @param array<int, string> $argv
 */
function xedc_settings_main(array $argv): int
{
    $opts = getopt('', ['source:', 'public:', 'secrets:', 'redacted:', 'help']);
    if ($opts === false || isset($opts['help']) || !isset($opts['source'])) {
        fwrite(STDERR, "用法: php settings_extract.php --source FILE [--public FILE] [--secrets FILE] [--redacted FILE]\n");
        return 1;
    }
    $data = xedc_settings_extract((string) $opts['source']);
    $public = $data;
    unset($public['password'], $public['hash_salt']);
    $public['password_present'] = ($data['password'] ?? '') !== '';
    $public['hash_salt_present'] = ($data['hash_salt'] ?? '') !== '';
    if (isset($opts['public'])) {
        file_put_contents((string) $opts['public'], (string) json_encode($public, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        chmod((string) $opts['public'], 0600);
    }
    if (isset($opts['secrets'])) {
        $secrets = [
            'database' => $data['database'],
            'username' => $data['username'],
            'password' => $data['password'],
            'host' => $data['host'],
            'port' => $data['port'],
            'prefix' => $data['prefix'],
            'hash_salt' => $data['hash_salt'],
        ];
        file_put_contents((string) $opts['secrets'], (string) json_encode($secrets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        chmod((string) $opts['secrets'], 0600);
    }
    if (isset($opts['redacted'])) {
        $raw = (string) file_get_contents((string) $opts['source']);
        file_put_contents((string) $opts['redacted'], xedc_settings_redact($raw, $data));
        chmod((string) $opts['redacted'], 0640);
    }
    fwrite(STDOUT, (string) json_encode([
        'database' => $public['database'],
        'password_present' => $public['password_present'],
        'hash_salt_present' => $public['hash_salt_present'],
        'private_path' => $public['private_path'],
    ], JSON_UNESCAPED_UNICODE) . "\n");
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    try {
        exit(xedc_settings_main($argv));
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
}
