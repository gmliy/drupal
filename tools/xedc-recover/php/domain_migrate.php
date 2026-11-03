<?php

declare(strict_types=1);

/**
 * Rewrites domains inside plain text, JSON, and PHP serialized values.
 * Serialized strings keep a correct byte length. Custom serialize payloads
 * that contain the domain are reported and left unchanged.
 */

final class DomainRewriter
{
    public static function expandPairs(string $from, string $to): array
    {
        $from = trim($from);
        $to = trim($to);
        if ($from === '' || $to === '' || $from === $to) {
            throw new InvalidArgumentException('旧域名和新域名都必须提供，且不能相同');
        }
        $pairs = [[$from, $to]];
        $fromHost = self::hostOf($from);
        $toHost = self::hostOf($to);
        if ($fromHost !== null && $toHost !== null) {
            foreach (['https://', 'http://', '//'] as $prefix) {
                $left = $prefix . $fromHost;
                $right = $prefix . $toHost;
                if ($left !== $from) {
                    $pairs[] = [$left, $right];
                }
            }
        }
        usort($pairs, static fn(array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));
        $unique = [];
        foreach ($pairs as $pair) {
            $unique[$pair[0]] = $pair[1];
        }
        $out = [];
        foreach ($unique as $left => $right) {
            $out[] = [$left, $right];
        }
        return $out;
    }

    public static function rewriteValue(string $value, array $pairs, array &$stats): string
    {
        if ($value === '' || !self::containsAny($value, $pairs)) {
            return $value;
        }
        if (self::looksSerialized($value)) {
            try {
                $pos = 0;
                $rewritten = self::consume($value, $pos, $pairs, $stats);
                if ($pos === strlen($value)) {
                    $stats['serialized_values']++;
                    return $rewritten;
                }
                $stats['unhandled'][] = 'serialized trailing bytes';
            } catch (Throwable $exception) {
                $stats['unhandled'][] = 'serialized: ' . $exception->getMessage();
            }
            return $value;
        }
        if (($value[0] === '{' || $value[0] === '[') && self::jsonOk($value)) {
            $stats['json_values']++;
            return self::replacePlain($value, $pairs, $stats);
        }
        if (preg_match('/(?:^|[^A-Za-z0-9_])(?:a|O|C|s):\d+:/', $value) === 1) {
            $embedded = self::rewriteEmbedded($value, $pairs, $stats);
            if ($embedded === null) {
                $stats['unhandled'][] = 'embedded serialized value';
                return $value;
            }
            return $embedded;
        }
        $stats['plain_values']++;
        return self::replacePlain($value, $pairs, $stats);
    }

    private static function rewriteEmbedded(string $value, array $pairs, array &$stats): ?string
    {
        $length = strlen($value);
        $out = '';
        $index = 0;
        while ($index < $length) {
            if (self::looksSerializedAt($value, $index)) {
                try {
                    $pos = $index;
                    $rewritten = self::consume($value, $pos, $pairs, $stats);
                    $out .= $rewritten;
                    $index = $pos;
                    $stats['embedded_serialized']++;
                    continue;
                } catch (Throwable $exception) {
                    return null;
                }
            }
            $out .= $value[$index];
            $index++;
        }
        $stats['plain_values']++;
        return self::replacePlain($out, $pairs, $stats);
    }

    public static function containsAny(string $value, array $pairs): bool
    {
        foreach ($pairs as [$from, $_to]) {
            if (str_contains($value, $from)) {
                return true;
            }
        }
        return false;
    }

    private static function replacePlain(string $value, array $pairs, array &$stats): string
    {
        foreach ($pairs as [$from, $to]) {
            $count = 0;
            $value = str_replace($from, $to, $value, $count);
            $stats['replacements'] += $count;
        }
        return $value;
    }

    private static function looksSerialized(string $value): bool
    {
        return self::looksSerializedAt($value, 0);
    }

    private static function looksSerializedAt(string $value, int $pos): bool
    {
        if ($pos >= strlen($value)) {
            return false;
        }
        $type = $value[$pos];
        if ($type === 'N') {
            return substr($value, $pos, 2) === 'N;';
        }
        if (!in_array($type, ['a', 'b', 'i', 'd', 's', 'O', 'C', 'R', 'r'], true)) {
            return false;
        }
        return isset($value[$pos + 1]) && $value[$pos + 1] === ':';
    }

    private static function jsonOk(string $value): bool
    {
        json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private static function consume(string $in, int &$pos, array $pairs, array &$stats): string
    {
        if ($pos >= strlen($in)) {
            throw new RuntimeException('eof');
        }
        $type = $in[$pos];
        if ($type === 'N') {
            self::expect($in, $pos, 'N;');
            return 'N;';
        }
        if (in_array($type, ['b', 'i', 'd', 'R', 'r'], true)) {
            $end = strpos($in, ';', $pos);
            if ($end === false) {
                throw new RuntimeException('scalar');
            }
            $chunk = substr($in, $pos, $end - $pos + 1);
            $pos = $end + 1;
            return $chunk;
        }
        if ($type === 's') {
            self::expect($in, $pos, 's:');
            $length = self::readInt($in, $pos);
            self::expect($in, $pos, ':"');
            $data = substr($in, $pos, $length);
            $pos += $length;
            self::expect($in, $pos, '";');
            $new = self::replacePlain($data, $pairs, $stats);
            return 's:' . strlen($new) . ':"' . $new . '";';
        }
        if ($type === 'a') {
            self::expect($in, $pos, 'a:');
            $count = self::readInt($in, $pos);
            self::expect($in, $pos, ':{');
            $body = '';
            for ($i = 0; $i < $count; $i++) {
                $body .= self::consume($in, $pos, $pairs, $stats);
                $body .= self::consume($in, $pos, $pairs, $stats);
            }
            self::expect($in, $pos, '}');
            return 'a:' . $count . ':{' . $body . '}';
        }
        if ($type === 'O') {
            self::expect($in, $pos, 'O:');
            $classLength = self::readInt($in, $pos);
            self::expect($in, $pos, ':"');
            $class = substr($in, $pos, $classLength);
            $pos += $classLength;
            self::expect($in, $pos, '":');
            if (self::containsAny($class, $pairs)) {
                throw new RuntimeException('class name contains domain');
            }
            $count = self::readInt($in, $pos);
            self::expect($in, $pos, ':{');
            $body = '';
            for ($i = 0; $i < $count; $i++) {
                $body .= self::consume($in, $pos, $pairs, $stats);
                $body .= self::consume($in, $pos, $pairs, $stats);
            }
            self::expect($in, $pos, '}');
            return 'O:' . $classLength . ':"' . $class . '":' . $count . ':{' . $body . '}';
        }
        if ($type === 'C') {
            self::expect($in, $pos, 'C:');
            $classLength = self::readInt($in, $pos);
            self::expect($in, $pos, ':"');
            $class = substr($in, $pos, $classLength);
            $pos += $classLength;
            self::expect($in, $pos, '":');
            $payloadLength = self::readInt($in, $pos);
            self::expect($in, $pos, ':{');
            $payload = substr($in, $pos, $payloadLength);
            $pos += $payloadLength;
            self::expect($in, $pos, '}');
            if (self::containsAny($class, $pairs) || self::containsAny($payload, $pairs)) {
                throw new RuntimeException('custom serialize payload');
            }
            return 'C:' . $classLength . ':"' . $class . '":' . $payloadLength . ':{' . $payload . '}';
        }
        throw new RuntimeException('unknown type');
    }

    private static function expect(string $in, int &$pos, string $literal): void
    {
        $length = strlen($literal);
        if (substr($in, $pos, $length) !== $literal) {
            throw new RuntimeException('expected token at ' . $pos);
        }
        $pos += $length;
    }

    private static function readInt(string $in, int &$pos): int
    {
        $start = $pos;
        if ($pos < strlen($in) && $in[$pos] === '-') {
            $pos++;
        }
        $digits = $pos;
        while ($pos < strlen($in) && ctype_digit($in[$pos])) {
            $pos++;
        }
        if ($digits === $pos) {
            throw new RuntimeException('integer');
        }
        return (int) substr($in, $start, $pos - $start);
    }

    private static function hostOf(string $value): ?string
    {
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $host = parse_url($value, PHP_URL_HOST);
            return is_string($host) && $host !== '' ? $host : null;
        }
        return null;
    }
}

function domain_self_test(): int
{
    $pairs = DomainRewriter::expandPairs('https://gitxx.xyz', 'https://例子.测试');
    $stats = ['replacements' => 0, 'serialized_values' => 0, 'json_values' => 0, 'plain_values' => 0, 'embedded_serialized' => 0, 'unhandled' => []];
    $payload = [
        'url' => 'https://gitxx.xyz/node/1',
        'also' => 'http://gitxx.xyz/a',
        'nested' => ['cdn' => '//gitxx.xyz/files/a.jpg'],
        'object' => (object) ['link' => 'https://gitxx.xyz/user'],
    ];
    $serialized = serialize($payload);
    $rewritten = DomainRewriter::rewriteValue($serialized, $pairs, $stats);
    $back = unserialize($rewritten, ['allowed_classes' => true]);
    if (!is_array($back) || !str_contains($back['url'], '例子.测试') || !str_contains($back['also'], '例子.测试')) {
        fwrite(STDERR, "serialized rewrite failed\n");
        return 1;
    }
    if (!str_contains($back['nested']['cdn'], '例子.测试')) {
        fwrite(STDERR, "nested rewrite failed\n");
        return 1;
    }
    $json = '{"href":"https://gitxx.xyz/x","n":1}';
    $jsonOut = DomainRewriter::rewriteValue($json, $pairs, $stats);
    $decoded = json_decode($jsonOut, true);
    if (!is_array($decoded) || $decoded['href'] !== 'https://例子.测试/x' || $decoded['n'] !== 1) {
        fwrite(STDERR, "json rewrite failed\n");
        return 1;
    }
    $plain = DomainRewriter::rewriteValue('see https://gitxx.xyz/page', $pairs, $stats);
    if ($plain !== 'see https://例子.测试/page') {
        fwrite(STDERR, "plain rewrite failed\n");
        return 1;
    }
    $mixed = 'before ' . serialize(['u' => 'https://gitxx.xyz/z']) . ' after';
    $mixedOut = DomainRewriter::rewriteValue($mixed, $pairs, $stats);
    if (!str_contains($mixedOut, 'before ') || !str_contains($mixedOut, ' after') || !str_contains($mixedOut, '例子.测试')) {
        fwrite(STDERR, "embedded rewrite failed\n");
        return 1;
    }
    $again = DomainRewriter::rewriteValue($mixedOut, $pairs, $stats);
    if ($again !== $mixedOut) {
        fwrite(STDERR, "not idempotent\n");
        return 1;
    }
    $custom = 'C:3:"Foo":' . strlen('https://gitxx.xyz') . ':{https://gitxx.xyz}';
    $customStats = ['replacements' => 0, 'serialized_values' => 0, 'json_values' => 0, 'plain_values' => 0, 'embedded_serialized' => 0, 'unhandled' => []];
    $customOut = DomainRewriter::rewriteValue($custom, $pairs, $customStats);
    if ($customOut !== $custom || $customStats['unhandled'] === []) {
        fwrite(STDERR, "custom serialize should stay unchanged\n");
        return 1;
    }
    echo "PASS\n";
    return 0;
}

function domain_column_ok(string $name): bool
{
    return preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
}

function domain_migrate(array $config): int
{
    $pairs = DomainRewriter::expandPairs($config['from'], $config['to']);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], (int) $config['port'], $config['database']);
    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $hostname = (string) $pdo->query('SELECT @@hostname')->fetchColumn();
    $stats = [
        'replacements' => 0,
        'serialized_values' => 0,
        'json_values' => 0,
        'plain_values' => 0,
        'embedded_serialized' => 0,
        'updated_rows' => 0,
        'tables' => 0,
        'unhandled' => [],
        'skipped_tables' => [],
        'hostname' => $hostname,
        'dry_run' => (bool) $config['dry_run'],
    ];
    $skip = '/^(cache($|_)|cachetags|watchdog|sessions|semaphore|queue|flood|batch|key_value_expire)$/';
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!domain_column_ok($table)) {
            $stats['skipped_tables'][] = $table;
            continue;
        }
        if (preg_match($skip, $table) === 1) {
            $stats['skipped_tables'][] = $table;
            continue;
        }
        $columns = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        $text = [];
        $pk = [];
        foreach ($columns as $column) {
            $name = $column['Field'];
            if (!domain_column_ok($name)) {
                continue;
            }
            $type = strtolower($column['Type']);
            if (str_contains($column['Key'], 'PRI')) {
                $pk[] = $name;
            }
            if (preg_match('/char|text|blob|json/', $type) === 1) {
                $text[] = $name;
            }
        }
        if ($text === [] || $pk === []) {
            if ($text !== [] && $pk === []) {
                $stats['unhandled'][] = $table . ': no primary key';
            }
            continue;
        }
        $stats['tables']++;
        $likes = [];
        $params = [];
        $paramIndex = 0;
        foreach ($text as $column) {
            foreach ($pairs as [$from, $_to]) {
                $name = ':p' . $paramIndex++;
                $likes[] = '`' . $column . '` LIKE ' . $name;
                $params[$name] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $from) . '%';
            }
        }
        $select = 'SELECT ' . implode(',', array_map(static fn($c) => '`' . $c . '`', array_merge($pk, $text)))
            . ' FROM `' . $table . '` WHERE ' . implode(' OR ', $likes);
        $statement = $pdo->prepare($select);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!$config['dry_run']) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($rows as $row) {
                $changes = [];
                foreach ($text as $column) {
                    $current = $row[$column];
                    if (!is_string($current) || $current === '') {
                        continue;
                    }
                    $updated = DomainRewriter::rewriteValue($current, $pairs, $stats);
                    if ($updated !== $current) {
                        $changes[$column] = $updated;
                    }
                }
                if ($changes === []) {
                    continue;
                }
                $stats['updated_rows']++;
                if ($config['dry_run']) {
                    continue;
                }
                $set = [];
                $setKeys = [];
                $bind = [];
                $i = 0;
                foreach ($changes as $column => $value) {
                    $placeholder = ':s' . $i++;
                    $set[] = '`' . $column . '`=' . $placeholder;
                    $bind[$placeholder] = $value;
                }
                foreach ($pk as $column) {
                    $placeholder = ':k' . $i++;
                    $bind[$placeholder] = $row[$column];
                    $setKeys[] = '`' . $column . '`=' . $placeholder;
                }
                $update = 'UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE ' . implode(' AND ', $setKeys);
                $write = $pdo->prepare($update);
                $write->execute($bind);
            }
            if (!$config['dry_run'] && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
    echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    return 0;
}

if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
    $mode = $argv[1] ?? '';
    if ($mode === 'self-test') {
        exit(domain_self_test());
    }
    if ($mode === 'migrate') {
        $raw = stream_get_contents(STDIN);
        $config = json_decode($raw === false ? '' : $raw, true);
        if (!is_array($config)) {
            fwrite(STDERR, "invalid config\n");
            exit(2);
        }
        try {
            exit(domain_migrate($config));
        } catch (Throwable $exception) {
            fwrite(STDERR, "migrate failed: " . $exception->getMessage() . "\n");
            exit(1);
        }
    }
    fwrite(STDERR, "usage: domain_migrate.php self-test|migrate\n");
    exit(2);
}
