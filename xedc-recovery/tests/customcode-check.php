<?php

declare(strict_types=1);

/**
 * 打印备份与恢复后的对比表。参数：
 * custom-code.list  备份时的 core.extension.yml  恢复后的 config/sync  manifest.json  站点根
 */

$listFile = $argv[1] ?? '';
$beforeExt = $argv[2] ?? '';
$sync = $argv[3] ?? '';
$manifestFile = $argv[4] ?? '';
$root = $argv[5] ?? '';

$fail = false;
$manifest = json_decode((string) file_get_contents($manifestFile), true);
$fp = is_array($manifest) ? ($manifest['custom_code']['config'] ?? []) : [];
if (!is_array($fp)) {
    $fp = [];
}

$read = static function (string $file, string $key): string {
    if (!is_file($file)) {
        return '';
    }
    foreach (file($file) ?: [] as $line) {
        if (preg_match('/^' . preg_quote($key, '/') . ':\s*(.*)$/', $line, $m) === 1) {
            return trim($m[1], " \t\"'");
        }
    }
    return '';
};

$names = static function (string $file, string $section): array {
    $on = false;
    $out = [];
    foreach (file($file) ?: [] as $line) {
        if (preg_match('/^' . preg_quote($section, '/') . ':/', $line) === 1) {
            $on = true;
            continue;
        }
        if (preg_match('/^[A-Za-z]/', $line) === 1) {
            $on = false;
            continue;
        }
        if ($on && preg_match('/^\s+([A-Za-z0-9_]+):/', $line, $m) === 1) {
            $out[] = $m[1];
        }
    }
    sort($out);
    return $out;
};

$before = $names($beforeExt, 'module');
$after = $names($sync . '/core.extension.yml', 'module');
$siteName = $read($sync . '/system.site.yml', 'name');
$theme = $read($sync . '/system.theme.yml', 'default');
$admin = $read($sync . '/system.theme.yml', 'admin');
$blocks = count(glob($sync . '/block.block.*.yml') ?: []);
$views = count(glob($sync . '/views.view.*.yml') ?: []);
$fields = count(glob($sync . '/field.storage.*.yml') ?: []);

$rows = [
    ['启用模块数', (string) count($before), (string) count($after), $before === $after],
    ['站点名称', (string) ($fp['site_name'] ?? ''), $siteName, (string) ($fp['site_name'] ?? '') === $siteName],
    ['默认主题', (string) ($fp['default_theme'] ?? ''), $theme, (string) ($fp['default_theme'] ?? '') === $theme],
    ['管理主题', (string) ($fp['admin_theme'] ?? ''), $admin, (string) ($fp['admin_theme'] ?? '') === $admin],
    ['区块', (string) ($fp['blocks'] ?? ''), (string) $blocks, (int) ($fp['blocks'] ?? -1) === $blocks],
    ['视图', (string) ($fp['views'] ?? ''), (string) $views, (int) ($fp['views'] ?? -1) === $views],
    ['字段存储', (string) ($fp['field_storages'] ?? ''), (string) $fields, (int) ($fp['field_storages'] ?? -1) === $fields],
];

echo "| 项目 | 备份 | 恢复后 | 结果 |\n| --- | --- | --- | --- |\n";
foreach ($rows as $row) {
    $ok = $row[3] === true;
    if (!$ok) {
        $fail = true;
    }
    echo '| ' . $row[0] . ' | ' . $row[1] . ' | ' . $row[2] . ' | ' . ($ok ? '通过' : '失败') . " |\n";
}

echo "| 自定义代码 | 路径 | 启用 | 结果 |\n| --- | --- | --- | --- |\n";
$fh = fopen($listFile, 'rb');
$header = $fh === false ? false : fgetcsv($fh, 0, "\t");
$idx = is_array($header) ? array_flip($header) : [];
if ($fh !== false) {
    while (($cols = fgetcsv($fh, 0, "\t")) !== false) {
        $kind = (string) ($cols[$idx['kind'] ?? -1] ?? '');
        if ($kind !== 'module' && $kind !== 'theme') {
            continue;
        }
        $path = (string) ($cols[$idx['path'] ?? -1] ?? '');
        $name = (string) ($cols[$idx['name'] ?? -1] ?? '');
        $on = (string) ($cols[$idx['enabled'] ?? -1] ?? '');
        $abs = $root . '/' . $path;
        $ok = is_dir($abs) || is_file($abs);
        if ($kind === 'theme' && is_dir($abs)) {
            $hasBuild = is_dir($abs . '/build/css') || is_dir($abs . '/build/js') || is_file($abs . '/css/built.css');
            $needsBuild = is_file($abs . '/package.json');
            if ($needsBuild && !$hasBuild) {
                $ok = false;
            }
        }
        if (!$ok) {
            $fail = true;
        }
        echo '| ' . $name . ' | ' . $path . ' | ' . ($on === '1' ? '是' : '否') . ' | ' . ($ok ? '通过' : '失败') . " |\n";
    }
    fclose($fh);
}

exit($fail ? 1 : 0);
