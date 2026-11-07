<?php

declare(strict_types=1);

/**
 * Drupal 缓存类表只保留结构。queue 存的是待处理任务，继续导出数据。
 */
function xedc_is_ephemeral_table(string $table, string $prefix): bool
{
  $table = trim($table);
  $table = trim($table, '`');
  if (str_contains($table, '.')) {
    $table = substr($table, (int) strrpos($table, '.') + 1);
    $table = trim($table, '`');
  }
  if ($prefix !== '' && str_starts_with($table, $prefix)) {
    $base = substr($table, strlen($prefix));
  } else {
    $base = $table;
  }
  if ($base === 'cache' || str_starts_with($base, 'cache_') || $base === 'cachetags') {
    return true;
  }
  return in_array($base, ['watchdog', 'sessions', 'flood', 'semaphore'], true);
}

function xedc_sql_code(string $statement): string
{
  $sql = ltrim($statement);
  while ($sql !== '') {
    if (str_starts_with($sql, '/*')) {
      $end = strpos($sql, '*/');
      if ($end === false) {
        return '';
      }
      $sql = ltrim(substr($sql, $end + 2));
      continue;
    }
    if (str_starts_with($sql, '#')) {
      $nl = strpos($sql, "\n");
      $sql = $nl === false ? '' : ltrim(substr($sql, $nl + 1));
      continue;
    }
    if (str_starts_with($sql, '--')) {
      $third = $sql[2] ?? '';
      if ($third === ' ' || $third === "\t" || $third === "\n" || $third === "\r" || $third === '') {
        $nl = strpos($sql, "\n");
        $sql = $nl === false ? '' : ltrim(substr($sql, $nl + 1));
        continue;
      }
    }
    break;
  }
  return $sql;
}

function xedc_sql_statement_table(string $statement): string
{
  $sql = xedc_sql_code($statement);
  $into = '/^(?:INSERT|REPLACE)\b(?:\s+(?:IGNORE|LOW_PRIORITY|DELAYED))*\s+INTO\s+(?:`[^`]+`\s*\.\s*)?`([^`]+)`/i';
  $intoBare = '/^(?:INSERT|REPLACE)\b(?:\s+(?:IGNORE|LOW_PRIORITY|DELAYED))*\s+INTO\s+([A-Za-z0-9_]+)/i';
  $create = '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`[^`]+`\s*\.\s*)?`([^`]+)`/i';
  if (preg_match($into, $sql, $match) === 1 || preg_match($intoBare, $sql, $match) === 1) {
    return $match[1];
  }
  if (preg_match($create, $sql, $match) === 1) {
    return $match[1];
  }
  return '';
}

function xedc_sql_is_data_write(string $statement): bool
{
  return preg_match('/^(?:INSERT|REPLACE)\b/i', xedc_sql_code($statement)) === 1;
}

function xedc_filter_sql($in, $out, $map, string $prefix, int $chunk = 1048576): void
{
  $pending = '';
  $outLine = 1;
  $emit = static function (string $text, string $table) use ($out, $map, &$outLine): void {
    if ($text === '') {
      return;
    }
    $text = preg_replace('/DEFINER=`[^`]+`@`[^`]+`/', '', $text) ?? $text;
    $span = xedc_sql_span($text, $outLine);
    fwrite($map, $span[0] . "\t" . $span[1] . "\t" . $table . "\n");
    fwrite($out, $text);
    $outLine = $span[2];
  };
  $readMore = static function () use ($in, &$pending, $chunk): bool {
    $more = fread($in, $chunk);
    if (!is_string($more) || $more === '') {
      return false;
    }
    $pending .= $more;
    return true;
  };
  while ($pending !== '' || $readMore()) {
    if (preg_match('/\A(\s*DELIMITER\s+(\S+)[^\n]*)(\n|\z)/i', $pending, $match) === 1) {
      if ($match[3] === '' && $readMore()) {
        continue;
      }
      $line = $match[1] . $match[3];
      $newDelim = $match[2];
      $pending = substr($pending, strlen($line));
      $emit($line, '');
      if ($newDelim !== ';') {
        $block = $pending;
        $pending = '';
        while (true) {
          if (preg_match('/(?:\A|\n)DELIMITER\s+;[^\n]*(?:\n|\z)/i', $block, $end, PREG_OFFSET_CAPTURE) === 1) {
            $cut = $end[0][1] + strlen($end[0][0]);
            $emit(substr($block, 0, $cut), '');
            $pending = substr($block, $cut) . $pending;
            break;
          }
          if (!$readMore()) {
            $emit($block, '');
            break 2;
          }
          $block .= $pending;
          $pending = '';
        }
      }
      continue;
    }
    if (preg_match('/\A\s*(?:D(?:E(?:L(?:I(?:M(?:I(?:T(?:\s+\S*)?)?)?)?)?)?)?)?\z/i', $pending) === 1) {
      if ($readMore()) {
        continue;
      }
      $emit($pending, '');
      break;
    }
    $length = strlen($pending);
    $pos = 0;
    $state = 'normal';
    $quote = '';
    $escape = false;
    $done = false;
    $endPos = 0;
    $needMore = false;
    while ($pos < $length) {
      $char = $pending[$pos];
      $next = ($pos + 1 < $length) ? $pending[$pos + 1] : '';
      if ($state === 'normal') {
        if ($char === '\'' || $char === '"' || $char === '`') {
          $state = 'quote';
          $quote = $char;
          $escape = false;
          $pos++;
          continue;
        }
        if ($char === '#') {
          $state = 'line';
          $pos++;
          continue;
        }
        if ($char === '-' && $next === '-') {
          if ($pos + 2 >= $length) {
            $needMore = true;
            break;
          }
          $third = $pending[$pos + 2];
          if ($third === ' ' || $third === "\t" || $third === "\n" || $third === "\r") {
            $state = 'line';
            $pos += 2;
            continue;
          }
        }
        if ($char === '/' && $next === '*') {
          $state = 'block';
          $pos += 2;
          continue;
        }
        if ($char === '/' && $next === '') {
          $needMore = true;
          break;
        }
        if ($char === ';') {
          $done = true;
          $endPos = $pos + 1;
          break;
        }
        $pos++;
        continue;
      }
      if ($state === 'quote') {
        if ($escape) {
          $escape = false;
          $pos++;
          continue;
        }
        if ($char === '\\') {
          $escape = true;
          $pos++;
          continue;
        }
        if ($char === $quote) {
          if ($next === $quote) {
            $pos += 2;
            continue;
          }
          if ($next === '' && $pos + 1 >= $length) {
            $needMore = true;
            break;
          }
          $state = 'normal';
        }
        $pos++;
        continue;
      }
      if ($state === 'line') {
        if ($char === "\n") {
          $state = 'normal';
        }
        $pos++;
        continue;
      }
      if ($char === '*' && $next === '/') {
        $state = 'normal';
        $pos += 2;
        continue;
      }
      if ($char === '*' && $next === '') {
        $needMore = true;
        break;
      }
      $pos++;
    }
    if (!$done) {
      if ($readMore()) {
        continue;
      }
      if (trim($pending) !== '') {
        $emit($pending, xedc_sql_statement_table($pending));
      }
      break;
    }
    $statement = substr($pending, 0, $endPos);
    $pending = substr($pending, $endPos);
    $table = xedc_sql_statement_table($statement);
    $skip = $table !== '' && xedc_sql_is_data_write($statement) && xedc_is_ephemeral_table($table, $prefix);
    if (!$skip) {
      $emit($statement, $table);
    }
  }
}

function xedc_sql_span(string $statement, int $line): array
{
  $start = $line;
  $content = $line;
  $seen = false;
  $length = strlen($statement);
  for ($i = 0; $i < $length; $i++) {
    $char = $statement[$i];
    if (!$seen && $char !== "\n") {
      $content = $line;
      $seen = true;
    }
    if ($char === "\n") {
      $line++;
    }
  }
  if (!$seen) {
    $content = $start;
  }
  if ($length > 0 && $statement[$length - 1] === "\n") {
    $end = max($content, $line - 1);
    $next = $line;
  } else {
    $end = max($content, $line);
    $next = $line;
  }
  return [$content, $end, $next];
}

function xedc_sql_table_at_line(string $mapPath, int $line): string
{
  $found = '';
  $foundStart = -1;
  foreach (file($mapPath, FILE_IGNORE_NEW_LINES) ?: [] as $row) {
    $parts = explode("\t", $row, 3);
    if (count($parts) !== 3) {
      continue;
    }
    $start = (int) $parts[0];
    $end = (int) $parts[1];
    if ($line < $start || $line > $end || $parts[2] === '') {
      continue;
    }
    if ($start >= $foundStart) {
      $foundStart = $start;
      $found = $parts[2];
    }
  }
  return $found;
}

function xedc_explain_sql_error(string $mapPath, string $errorText): string
{
  $useful = $errorText;
  if (preg_match('/^ERROR[^\n]*/m', $errorText, $errorLine) === 1) {
    $useful = $errorLine[0];
  }
  $flat = trim(preg_replace('/\s+/', ' ', $useful) ?? $useful);
  if (strlen($flat) > 160) {
    $flat = substr($flat, 0, 160) . '...';
  }
  $line = 0;
  if (preg_match('/\bat line (\d+)/i', $errorText, $match) === 1) {
    $line = (int) $match[1];
  }
  $table = $line > 0 ? xedc_sql_table_at_line($mapPath, $line) : '';
  if ($table === '') {
    $table = '未知表';
  }
  if ($line > 0) {
    return "导入失败: 表 {$table}，第 {$line} 行: {$flat}";
  }
  return "导入失败: 表 {$table}: {$flat}";
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
  $mode = '';
  $prefix = '';
  $mapPath = '';
  $errPath = '';
  for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--classify') {
      $mode = 'classify';
    } elseif ($argv[$i] === '--filter') {
      $mode = 'filter';
    } elseif ($argv[$i] === '--explain-error') {
      $mode = 'explain';
    } elseif ($argv[$i] === '--prefix') {
      $prefix = (string) ($argv[++$i] ?? '');
    } elseif ($argv[$i] === '--map') {
      $mapPath = (string) ($argv[++$i] ?? '');
    } elseif ($argv[$i] === '--err') {
      $errPath = (string) ($argv[++$i] ?? '');
    }
  }
  if ($prefix !== '' && preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
    fwrite(STDERR, "表前缀含有非法字符\n");
    exit(1);
  }
  if ($mode === 'classify') {
    while (($line = fgets(STDIN)) !== false) {
      $table = trim($line);
      if ($table !== '' && xedc_is_ephemeral_table($table, $prefix)) {
        echo $table, "\n";
      }
    }
    exit(0);
  }
  if ($mode === 'filter') {
    if ($mapPath === '') {
      fwrite(STDERR, "缺少 --map\n");
      exit(2);
    }
    $map = fopen($mapPath, 'wb');
    if ($map === false) {
      fwrite(STDERR, "无法写入语句索引\n");
      exit(1);
    }
    xedc_filter_sql(STDIN, STDOUT, $map, $prefix);
    fclose($map);
    exit(0);
  }
  if ($mode === 'explain') {
    $text = $errPath !== '' ? (string) file_get_contents($errPath) : (string) stream_get_contents(STDIN);
    fwrite(STDERR, xedc_explain_sql_error($mapPath, $text) . "\n");
    exit(0);
  }
  fwrite(STDERR, "用法: sql_filter.php --classify|--filter|--explain-error [--prefix 前缀] [--map 文件] [--err 文件]\n");
  exit(2);
}
