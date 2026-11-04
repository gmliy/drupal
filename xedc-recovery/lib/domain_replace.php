<?php

declare(strict_types=1);

/**
 * 域名替换。识别 PHP 序列化、JSON 和普通文本，保留序列化长度。
 * 不处理无法识别的二进制。可由命令行或测试直接调用。
 */

function xedc_domain_normalize(string $domain): string
{
    $domain = strtolower(trim($domain));
    $domain = rtrim($domain, '.');
    if ($domain === '' || str_contains($domain, '/') || str_contains($domain, ' ')) {
        throw new InvalidArgumentException('域名无效');
    }
    return $domain;
}

function xedc_domain_regex(string $domain): string
{
    $parts = [];
    $length = strlen($domain);
    for ($i = 0; $i < $length; $i++) {
        $ch = $domain[$i];
        if ($ch === '.') {
            $parts[] = '(?:\\.|%2[Ee])';
        } else {
            $parts[] = preg_quote($ch, '~');
        }
    }
    $body = implode('', $parts);
    return '~(?<![A-Za-z0-9-])' . $body . '(?![A-Za-z0-9-])(?!\\.[A-Za-z0-9])~i';
}

function xedc_replace_hosts(string $text, string $old, string $new): string
{
    $old = xedc_domain_normalize($old);
    $new = xedc_domain_normalize($new);
    if ($old === $new || $text === '') {
        return $text;
    }
    $regex = xedc_domain_regex($old);
    $replaced = preg_replace_callback(
        $regex,
        static function (array $match) use ($new): string {
            $found = $match[0];
            if (!str_contains($found, '%')) {
                return $new;
            }
            $token = str_contains($found, '%2e') && !str_contains($found, '%2E') ? '%2e' : '%2E';
            return str_replace('.', $token, $new);
        },
        $text
    );
    return $replaced ?? $text;
}

function xedc_looks_binary(string $value): bool
{
    if ($value === '') {
        return false;
    }
    return str_contains($value, "\0") || preg_match('//u', $value) !== 1;
}

function xedc_json_walk(mixed $data, string $old, string $new): mixed
{
    if (is_string($data)) {
        return xedc_transform_text($data, $old, $new);
    }
    if (!is_array($data)) {
        return $data;
    }
    $out = [];
    foreach ($data as $key => $value) {
        $newKey = is_string($key) ? xedc_replace_hosts($key, $old, $new) : $key;
        $out[$newKey] = xedc_json_walk($value, $old, $new);
    }
    return $out;
}

function xedc_try_json(string $value, string $old, string $new): ?string
{
    if ($value === '' || ($value[0] !== '{' && $value[0] !== '[')) {
        return null;
    }
    try {
        $data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($data)) {
        return null;
    }
    $encoded = json_encode(
        xedc_json_walk($data, $old, $new),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    return $encoded === false ? null : $encoded;
}

function xedc_ser_read(string $raw, int &$pos, string $old, string $new): string
{
    $length = strlen($raw);
    if ($pos >= $length) {
        throw new RuntimeException('序列化数据不完整');
    }
    $type = $raw[$pos];
    if ($type === 'N') {
        if (substr($raw, $pos, 2) !== 'N;') {
            throw new RuntimeException('无法识别的 NULL');
        }
        $pos += 2;
        return 'N;';
    }
    if ($type === 'b' || $type === 'i' || $type === 'd' || $type === 'R' || $type === 'r') {
        if (!preg_match('/\G' . $type . ':[^;]*;/', $raw, $match, 0, $pos)) {
            throw new RuntimeException('无法识别的标量');
        }
        $pos += strlen($match[0]);
        return $match[0];
    }
    if ($type === 's') {
        if (!preg_match('/\Gs:(\d+):"/', $raw, $match, 0, $pos)) {
            throw new RuntimeException('无法识别的字符串');
        }
        $declared = (int) $match[1];
        $pos += strlen($match[0]);
        if ($pos + $declared > $length) {
            throw new RuntimeException('字符串长度超出数据');
        }
        $body = substr($raw, $pos, $declared);
        $pos += $declared;
        if (substr($raw, $pos, 2) !== '";') {
            throw new RuntimeException('字符串没有正常结束');
        }
        $pos += 2;
        $updated = xedc_transform_text($body, $old, $new);
        return 's:' . strlen($updated) . ':"' . $updated . '";';
    }
    if ($type === 'a' || $type === 'O') {
        if ($type === 'a') {
            if (!preg_match('/\Ga:(\d+):\{/', $raw, $match, 0, $pos)) {
                throw new RuntimeException('无法识别的数组');
            }
            $count = (int) $match[1];
            $pos += strlen($match[0]);
            $prefix = $match[0];
        } else {
            if (!preg_match('/\GO:(\d+):"/', $raw, $match, 0, $pos)) {
                throw new RuntimeException('无法识别的对象');
            }
            $nameLength = (int) $match[1];
            $pos += strlen($match[0]);
            $name = substr($raw, $pos, $nameLength);
            $pos += $nameLength;
            if (!preg_match('/\G":(\d+):\{/', $raw, $countMatch, 0, $pos)) {
                throw new RuntimeException('对象属性数量无法识别');
            }
            $count = (int) $countMatch[1];
            $pos += strlen($countMatch[0]);
            $prefix = 'O:' . strlen($name) . ':"' . $name . '":' . $count . ':{';
        }
        $body = '';
        for ($i = 0; $i < $count; $i++) {
            $body .= xedc_ser_read($raw, $pos, $old, $new);
            $body .= xedc_ser_read($raw, $pos, $old, $new);
        }
        if (($raw[$pos] ?? '') !== '}') {
            throw new RuntimeException('结构没有正常结束');
        }
        $pos++;
        return $prefix . $body . '}';
    }
    if ($type === 'C') {
        if (!preg_match('/\GC:(\d+):"/', $raw, $match, 0, $pos)) {
            throw new RuntimeException('无法识别的自定义对象');
        }
        $nameLength = (int) $match[1];
        $start = $pos;
        $pos += strlen($match[0]);
        $pos += $nameLength;
        if (!preg_match('/\G":(\d+):\{/', $raw, $sizeMatch, 0, $pos)) {
            throw new RuntimeException('自定义对象长度无法识别');
        }
        $blob = (int) $sizeMatch[1];
        $pos += strlen($sizeMatch[0]);
        $pos += $blob;
        if (($raw[$pos] ?? '') !== '}') {
            throw new RuntimeException('自定义对象没有正常结束');
        }
        $pos++;
        return substr($raw, $start, $pos - $start);
    }
    throw new RuntimeException('不支持的序列化类型');
}

function xedc_ser_complete(string $raw): bool
{
    try {
        $pos = 0;
        xedc_ser_read($raw, $pos, 'invalid.invalid', 'invalid.invalid');
        return $pos === strlen($raw);
    } catch (Throwable) {
        return false;
    }
}

function xedc_transform_text(string $value, string $old, string $new): string
{
    if ($value === '') {
        return $value;
    }
    if (xedc_ser_complete($value)) {
        $pos = 0;
        $parsed = xedc_ser_read($value, $pos, $old, $new);
        if ($pos === strlen($value)) {
            return $parsed;
        }
    }
    $json = xedc_try_json($value, $old, $new);
    if ($json !== null) {
        return $json;
    }
    if (xedc_looks_binary($value)) {
        return $value;
    }
    return xedc_replace_hosts($value, $old, $new);
}

function xedc_php_accepts(string $raw): bool
{
    if (!xedc_ser_complete($raw)) {
        return false;
    }
    $warned = false;
    set_error_handler(static function () use (&$warned): bool {
        $warned = true;
        return true;
    });
    try {
        unserialize($raw, ['allowed_classes' => true]);
    } catch (Throwable) {
        restore_error_handler();
        return false;
    }
    restore_error_handler();
    return !$warned;
}

/**
 * @return array{value: string, changed: bool, unsupported: bool}
 */
function xedc_transform_column(string $value, string $old, string $new): array
{
    $binary = xedc_looks_binary($value) && !xedc_ser_complete($value);
    if ($binary && xedc_try_json($value, $old, $new) === null) {
        return ['value' => $value, 'changed' => false, 'unsupported' => true];
    }
    $updated = xedc_transform_text($value, $old, $new);
    if (xedc_ser_complete($value) && !xedc_php_accepts($updated)) {
        throw new RuntimeException('替换后的序列化数据无法重新解析');
    }
    return [
        'value' => $updated,
        'changed' => $updated !== $value,
        'unsupported' => false,
    ];
}

/**
 * @param array<int, string> $argv
 */
function xedc_domain_main(array $argv): int
{
    $opts = getopt('', [
        'defaults-file:',
        'database:',
        'old:',
        'new:',
        'dry-run',
        'report:',
        'help',
    ]);
    if ($opts === false || isset($opts['help']) || !isset($opts['old'], $opts['new'], $opts['database'], $opts['defaults-file'])) {
        fwrite(STDERR, "用法: php domain_replace.php --defaults-file FILE --database NAME --old DOMAIN --new DOMAIN [--dry-run] [--report FILE]\n");
        return isset($opts['help']) ? 0 : 1;
    }
    $old = xedc_domain_normalize((string) $opts['old']);
    $new = xedc_domain_normalize((string) $opts['new']);
    $dry = isset($opts['dry-run']);
    $settings = parse_ini_file((string) $opts['defaults-file'], false, INI_SCANNER_RAW);
    if (!is_array($settings)) {
        fwrite(STDERR, "无法读取数据库凭据文件\n");
        return 1;
    }
    $user = (string) ($settings['user'] ?? '');
    $pass = (string) ($settings['password'] ?? '');
    $host = (string) ($settings['host'] ?? 'localhost');
    $port = (string) ($settings['port'] ?? '3306');
    $socket = (string) ($settings['socket'] ?? '');
    $dsn = $socket !== ''
        ? 'mysql:unix_socket=' . $socket . ';dbname=' . $opts['database'] . ';charset=utf8mb4'
        : 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $opts['database'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
    $skip = ['cache%', 'watchdog', 'sessions', 'search_%', 'flood', 'key_value_expire'];
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $stats = ['tables' => 0, 'columns' => 0, 'rows' => 0, 'unsupported' => 0, 'skipped' => []];
    foreach ($tables as $table) {
        $table = (string) $table;
        $skipped = false;
        foreach ($skip as $pattern) {
            if (fnmatch($pattern, $table)) {
                $stats['skipped'][] = $table;
                $skipped = true;
                break;
            }
        }
        if ($skipped) {
            continue;
        }
        $columns = $pdo->query('SHOW COLUMNS FROM ' . xedc_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        $keys = [];
        $textColumns = [];
        foreach ($columns as $column) {
            $name = (string) $column['Field'];
            $type = strtolower((string) $column['Type']);
            if (($column['Key'] ?? '') === 'PRI') {
                $keys[] = $name;
            }
            if (preg_match('/char|text|blob|json|enum|set/', $type) === 1) {
                $textColumns[] = $name;
            }
        }
        if ($textColumns === []) {
            continue;
        }
        if ($keys === []) {
            $stats['unsupported']++;
            $stats['skipped'][] = $table . ' (无主键)';
            continue;
        }
        $stats['tables']++;
        $selectList = array_merge($keys, $textColumns);
        $sql = 'SELECT ' . implode(', ', array_map('xedc_quote_ident', $selectList))
            . ' FROM ' . xedc_quote_ident($table);
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $updates = [];
            foreach ($textColumns as $column) {
                $current = $row[$column];
                if ($current === null) {
                    continue;
                }
                $stats['columns']++;
                $result = xedc_transform_column((string) $current, $old, $new);
                if ($result['unsupported']) {
                    $stats['unsupported']++;
                    continue;
                }
                if ($result['changed']) {
                    $updates[$column] = $result['value'];
                }
            }
            if ($updates === []) {
                continue;
            }
            $stats['rows']++;
            if ($dry) {
                continue;
            }
            $sets = [];
            $params = [];
            foreach ($updates as $column => $value) {
                $sets[] = xedc_quote_ident($column) . ' = ?';
                $params[] = $value;
            }
            $where = [];
            foreach ($keys as $key) {
                $where[] = xedc_quote_ident($key) . ' = ?';
                $params[] = $row[$key];
            }
            $update = 'UPDATE ' . xedc_quote_ident($table)
                . ' SET ' . implode(', ', $sets)
                . ' WHERE ' . implode(' AND ', $where);
            $pdo->prepare($update)->execute($params);
            $check = $pdo->prepare(
                'SELECT ' . implode(', ', array_map('xedc_quote_ident', array_keys($updates)))
                . ' FROM ' . xedc_quote_ident($table)
                . ' WHERE ' . implode(' AND ', $where)
            );
            $check->execute(array_slice($params, count($updates)));
            $after = $check->fetch(PDO::FETCH_ASSOC);
            if (!is_array($after)) {
                throw new RuntimeException('替换后无法读回行');
            }
            foreach ($updates as $column => $value) {
                if ((string) $after[$column] !== $value) {
                    throw new RuntimeException('替换后的值与写回结果不一致');
                }
                if (xedc_ser_complete($value) && !xedc_php_accepts((string) $after[$column])) {
                    throw new RuntimeException('写回后的序列化数据无法解析');
                }
            }
        }
    }
    $report = [
        'old' => $old,
        'new' => $new,
        'dry_run' => $dry,
        'tables' => $stats['tables'],
        'cells' => $stats['columns'],
        'rows' => $stats['rows'],
        'unsupported' => $stats['unsupported'],
        'skipped' => $stats['skipped'],
    ];
    $encoded = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (isset($opts['report'])) {
        file_put_contents((string) $opts['report'], (string) $encoded);
        chmod((string) $opts['report'], 0600);
    }
    fwrite(STDOUT, (string) $encoded . "\n");
    return 0;
}

function xedc_quote_ident(string $name): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
        throw new RuntimeException('非法标识符');
    }
    return '`' . $name . '`';
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    try {
        exit(xedc_domain_main($argv));
    } catch (Throwable $exception) {
        fwrite(STDERR, "域名替换失败: " . $exception->getMessage() . "\n");
        exit(2);
    }
}
