<?php

declare(strict_types=1);

/**
 * 探测自定义代码，写入 custom-code.list 与 manifest，并核对暂存目录。
 *
 * inventory --site DIR [--stage DIR] [--list FILE] [--manifest FILE] [--include FILE]
 * verify-members --list FILE --members FILE
 * compare --site DIR --list FILE [--ignore-hash-kind theme]
 */

function cc_out(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function cc_warn(string $line): void
{
    fwrite(STDERR, '警告: ' . $line . "\n");
}

function cc_sh(string $command): string
{
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['bash', '-lc', $command], $spec, $pipes);
    if (!is_resource($proc)) {
        return '';
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return is_string($out) ? $out : '';
}

function cc_arg(array $argv, string $name): string
{
    $n = count($argv);
    for ($i = 0; $i < $n; $i++) {
        if ($argv[$i] === $name && isset($argv[$i + 1])) {
            return (string) $argv[$i + 1];
        }
    }
    return '';
}

function cc_flag(array $argv, string $name): bool
{
    return in_array($name, $argv, true);
}

function cc_norm(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    if (
        str_starts_with($path, 'modules/')
        || str_starts_with($path, 'themes/')
        || str_starts_with($path, 'profiles/')
        || str_starts_with($path, 'libraries/')
        || str_starts_with($path, 'core/')
    ) {
        return 'web/' . $path;
    }
    return $path;
}

function cc_is_bak(string $base): bool
{
    return preg_match('/(?:\.bak(?:[.-][^\/]+|[0-9]+)?|\.orig|\.old|~)$/', $base) === 1;
}

function cc_skip_dir(string $name): bool
{
    return $name === 'node_modules' || $name === 'vendor' || $name === '.git';
}

/**
 * @return array{files:int,bytes:int,hash:string}
 */
function cc_tree(string $root): array
{
    $rows = [];
    $bytes = 0;
    $files = 0;
    if (is_file($root)) {
        $base = basename($root);
        if (!cc_is_bak($base)) {
            $hash = hash_file('sha256', $root);
            if (is_string($hash)) {
                $rows[] = "\t" . $hash;
                $bytes += (int) filesize($root);
                $files = 1;
            }
        }
    } elseif (is_dir($root)) {
        $dir = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $dir,
            static function (SplFileInfo $current): bool {
                if ($current->isDir() && cc_skip_dir($current->getFilename())) {
                    return false;
                }
                return true;
            }
        );
        $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
        $rootLen = strlen(rtrim($root, '/')) + 1;
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $base = $file->getFilename();
            if (cc_is_bak($base)) {
                continue;
            }
            $path = $file->getPathname();
            $rel = str_replace('\\', '/', substr($path, $rootLen));
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) {
                continue;
            }
            $rows[] = $rel . "\t" . $hash;
            $bytes += (int) $file->getSize();
            $files++;
        }
    } else {
        return ['files' => 0, 'bytes' => 0, 'hash' => hash('sha256', '')];
    }
    sort($rows, SORT_STRING);
    return [
        'files' => $files,
        'bytes' => $bytes,
        'hash' => hash('sha256', implode("\n", $rows)),
    ];
}

/**
 * @return list<string>
 */
function cc_composer_roots(string $site): array
{
    $composerFile = $site . '/composer.json';
    $lockFile = $site . '/composer.lock';
    if (!is_file($composerFile) || !is_file($lockFile)) {
        return [];
    }
    $composer = json_decode((string) file_get_contents($composerFile), true);
    $lock = json_decode((string) file_get_contents($lockFile), true);
    if (!is_array($composer) || !is_array($lock)) {
        return [];
    }
    $paths = $composer['extra']['installer-paths'] ?? [];
    if (!is_array($paths)) {
        return [];
    }
    $roots = [];
    $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
    foreach ($packages as $pkg) {
        if (!is_array($pkg)) {
            continue;
        }
        $type = (string) ($pkg['type'] ?? '');
        $name = (string) ($pkg['name'] ?? '');
        $installer = (string) ($pkg['extra']['installer-name'] ?? '');
        if ($installer === '') {
            $slash = strrpos($name, '/');
            $installer = $slash === false ? $name : substr($name, $slash + 1);
        }
        foreach ($paths as $pattern => $types) {
            $matched = false;
            foreach ((array) $types as $t) {
                if ($t === 'type:' . $type) {
                    $matched = true;
                }
            }
            if (!$matched || !is_string($pattern)) {
                continue;
            }
            $rel = ltrim(str_replace('{$name}', $installer, $pattern), '/');
            if ($rel !== '') {
                $roots[] = $rel;
            }
        }
    }
    $roots[] = 'web/core';
    return array_values(array_unique($roots));
}

/**
 * @param list<string> $roots
 */
function cc_owned(string $rel, array $roots): bool
{
    foreach ($roots as $root) {
        if ($rel === $root || str_starts_with($rel, $root . '/')) {
            return true;
        }
    }
    return false;
}

/**
 * @return list<string>
 */
function cc_child_dirs(string $abs): array
{
    if (!is_dir($abs)) {
        return [];
    }
    $out = [];
    foreach (scandir($abs) ?: [] as $name) {
        if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
            continue;
        }
        if (is_dir($abs . '/' . $name)) {
            $out[] = $name;
        }
    }
    sort($out);
    return $out;
}

function cc_machine_name(string $dir): string
{
    $base = basename($dir);
    $info = $dir . '/' . $base . '.info.yml';
    if (is_file($info)) {
        return $base;
    }
    foreach (glob($dir . '/*.info.yml') ?: [] as $file) {
        return basename($file, '.info.yml');
    }
    return $base;
}

function cc_kind_for(string $rel): string
{
    if (str_contains($rel, '/themes/')) {
        return 'theme';
    }
    if (str_contains($rel, '/modules/')) {
        return 'module';
    }
    if (str_contains($rel, '/profiles/')) {
        return 'profile';
    }
    return 'dir';
}

/**
 * @return array{modules:array<string,string>,themes:array<string,string>,known:bool}
 */
function cc_enabled(string $site): array
{
    $modules = [];
    $themes = [];
    $known = false;
    $bin = '';
    foreach ([$site . '/vendor/bin/drush', $site . '/bin/drush'] as $cand) {
        if (is_executable($cand)) {
            $bin = $cand;
            break;
        }
    }
    if ($bin === '' && is_dir($site . '/web/core')) {
        $found = trim(cc_sh('command -v drush || true'));
        if ($found !== '') {
            $bin = $found;
        }
    }
    if ($bin !== '') {
        $timeout = trim(cc_sh('command -v timeout || true')) !== '' ? 'timeout 90 ' : '';
        $out = cc_sh($timeout . escapeshellarg($bin) . ' -r ' . escapeshellarg($site) . ' pml --status=enabled --format=json 2>/dev/null');
        $jsonStart = strcspn($out, '{[');
        $slice = $jsonStart < strlen($out) ? substr($out, $jsonStart) : '';
        $decoded = $slice !== '' ? json_decode($slice, true) : null;
        if (is_array($decoded) && $decoded !== []) {
            $known = true;
            $rows = array_is_list($decoded) ? $decoded : array_values($decoded);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = (string) ($row['name'] ?? '');
                $type = (string) ($row['type'] ?? 'module');
                $path = cc_norm((string) ($row['path'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if ($type === 'theme') {
                    $themes[$name] = $path;
                } elseif ($type === 'module' || $type === 'profile') {
                    $modules[$name] = $path;
                }
            }
        } else {
            foreach (preg_split("/\n/", $out) ?: [] as $line) {
                if (preg_match('/^([A-Za-z0-9_]+)\s+Enabled\b/', trim($line), $m) === 1) {
                    $modules[$m[1]] = '';
                    $known = true;
                }
            }
        }
    }
    $ext = $site . '/config/sync/core.extension.yml';
    if (is_file($ext)) {
        $section = '';
        foreach (file($ext) ?: [] as $line) {
            if (preg_match('/^(module|theme|profile):/', $line, $m) === 1) {
                $section = $m[1];
                continue;
            }
            if (preg_match('/^[A-Za-z]/', $line) === 1) {
                $section = '';
                continue;
            }
            if ($section !== '' && preg_match('/^\s+([A-Za-z0-9_]+):/', $line, $m) === 1) {
                $known = true;
                if ($section === 'theme') {
                    $themes[$m[1]] = $themes[$m[1]] ?? '';
                } else {
                    $modules[$m[1]] = $modules[$m[1]] ?? '';
                }
            }
        }
    }
    return ['modules' => $modules, 'themes' => $themes, 'known' => $known];
}

/**
 * @param list<string> $roots
 * @return list<string>
 */
function cc_scan_projects(string $site, array $roots): array
{
    $rels = [];
    foreach (['web/modules', 'web/themes'] as $parent) {
        foreach (cc_child_dirs($site . '/' . $parent) as $name) {
            if ($name === 'custom' || $name === 'contrib') {
                foreach (cc_child_dirs($site . '/' . $parent . '/' . $name) as $proj) {
                    $rel = $parent . '/' . $name . '/' . $proj;
                    if (($name === 'contrib' && cc_owned($rel, $roots)) || cc_stripped_name($proj)) {
                        continue;
                    }
                    $rels[] = $rel;
                }
                continue;
            }
            $rel = $parent . '/' . $name;
            if (!cc_owned($rel, $roots) && !cc_stripped_name($name)) {
                $rels[] = $rel;
            }
        }
    }
    foreach (cc_child_dirs($site . '/web/profiles') as $name) {
        $rel = 'web/profiles/' . $name;
        if (!cc_owned($rel, $roots) && (glob($site . '/' . $rel . '/*.info.yml') ?: []) !== []) {
            $rels[] = $rel;
        }
    }
    foreach (cc_child_dirs($site . '/web/libraries') as $name) {
        $rel = 'web/libraries/' . $name;
        if (!cc_owned($rel, $roots)) {
            $rels[] = $rel;
        }
    }
    return array_values(array_unique($rels));
}

/**
 * @return list<array{name:string,path:string,kind:string,external:string}>
 */
function cc_path_repos(string $site): array
{
    $file = $site . '/composer.json';
    if (!is_file($file)) {
        return [];
    }
    $json = json_decode((string) file_get_contents($file), true);
    if (!is_array($json)) {
        return [];
    }
    $out = [];
    foreach ($json['repositories'] ?? [] as $repo) {
        if (!is_array($repo) || ($repo['type'] ?? '') !== 'path') {
            continue;
        }
        $url = (string) ($repo['url'] ?? '');
        if ($url === '') {
            continue;
        }
        $abs = str_starts_with($url, '/') ? $url : $site . '/' . ltrim($url, './');
        $real = realpath($abs);
        if ($real === false || !is_dir($real)) {
            $out[] = [
                'name' => basename($url),
                'path' => ltrim(str_replace('\\', '/', $url), './'),
                'kind' => 'package',
                'external' => '',
                'missing' => '1',
            ];
            continue;
        }
        $siteReal = realpath($site) ?: $site;
        if (str_starts_with($real, rtrim($siteReal, '/') . '/')) {
            $rel = ltrim(substr($real, strlen(rtrim($siteReal, '/'))), '/');
            $out[] = [
                'name' => basename($real),
                'path' => str_replace('\\', '/', $rel),
                'kind' => 'package',
                'external' => '',
                'missing' => '0',
            ];
        } else {
            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($real)) ?: 'package';
            $out[] = [
                'name' => basename($real),
                'path' => 'path-packages/' . $safe,
                'kind' => 'package',
                'external' => $real,
                'missing' => '0',
                'composer_url' => $url,
            ];
        }
    }
    return $out;
}

/**
 * @return list<string>
 */
function cc_include_paths(string $file): array
{
    if ($file === '' || !is_file($file)) {
        return [];
    }
    $out = [];
    foreach (file($file) ?: [] as $line) {
        $line = preg_replace('/\s+#.*$/', '', (string) $line) ?? '';
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '/')) {
            continue;
        }
        $out[] = $line;
    }
    return $out;
}

/**
 * @return list<string>
 */
function cc_service_rule(string $rule): bool
{
    if ($rule === '' || str_contains($rule, '*') || str_contains($rule, '?')) {
        return false;
    }
    return !in_array($rule, ['docs', 'docs-archive', '_server/db.sql'], true);
}

function cc_stripped_name(string $name): bool
{
    if ($name === '') {
        return false;
    }
    $file = dirname(__DIR__, 2) . '/exclude.list';
    if (!is_file($file)) {
        return false;
    }
    foreach (file($file) ?: [] as $line) {
        $line = preg_replace('/\s+#.*$/', '', (string) $line) ?? '';
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!cc_service_rule($line)) {
            continue;
        }
        if (str_contains($name, $line)) {
            return true;
        }
    }
    return false;
}

function cc_settings_files(string $site): array
{
    $out = [];
    $services = $site . '/web/sites/default/services.yml';
    if (is_file($services)) {
        $out[] = 'web/sites/default/services.yml';
    }
    $settings = $site . '/web/sites/default/settings.php';
    if (!is_file($settings)) {
        return array_values(array_unique($out));
    }
    $out[] = 'web/sites/default/settings.php';
    $text = (string) file_get_contents($settings);
    $found = [];
    if (preg_match_all('/(?:include|require)(?:_once)?\s*(?:\(\s*)?[\'"]([^\'"]+)[\'"]/', $text, $m)) {
        foreach ($m[1] as $p) {
            $found[] = $p;
        }
    }
    if (preg_match_all('/container_yamls[^\]]*\]\s*=\s*\[([^\]]+)\]/', $text, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match_all('/[\'"]([^\'"]+\.ya?ml)[\'"]/', $block, $ym)) {
                foreach ($ym[1] as $p) {
                    $found[] = $p;
                }
            }
        }
    }
    $base = rtrim(realpath($site) ?: $site, '/');
    foreach ($found as $p) {
        if (str_contains($p, 'example') || cc_is_bak(basename($p))) {
            continue;
        }
        $cands = str_starts_with($p, '/')
            ? [$p]
            : [$site . '/' . $p, $site . '/web/' . $p, dirname($settings) . '/' . $p];
        foreach ($cands as $cand) {
            $real = realpath($cand);
            if ($real === false || !is_file($real) || !str_starts_with($real, $base . '/')) {
                continue;
            }
            $rel = ltrim(substr($real, strlen($base)), '/');
            if (str_starts_with($rel, 'vendor/') || str_starts_with($rel, 'web/core/')) {
                continue;
            }
            $out[] = str_replace('\\', '/', $rel);
            break;
        }
    }
    return array_values(array_unique($out));
}

function cc_git_version(string $site, string $rel, string $hash): array
{
    if (!is_dir($site . '/.git')) {
        return ['hash', $hash];
    }
    $head = trim(cc_sh('git -C ' . escapeshellarg($site) . ' rev-parse HEAD 2>/dev/null'));
    if ($head === '') {
        return ['hash', $hash];
    }
    $dirty = trim(cc_sh('git -C ' . escapeshellarg($site) . ' status --porcelain -- ' . escapeshellarg($rel) . ' 2>/dev/null'));
    if ($dirty === '') {
        return ['git', $head];
    }
    return ['hash', $hash];
}

function cc_inside(string $path, array $items): bool
{
    foreach ($items as $item) {
        $p = (string) $item['path'];
        if ($path === $p || str_starts_with($path, $p . '/')) {
            return true;
        }
    }
    return false;
}

/**
 * @return array{0:list<array<string,mixed>>,1:list<string>}
 */
function cc_items(string $site, string $includeFile): array
{
    $errors = [];
    $roots = cc_composer_roots($site);
    $enabled = cc_enabled($site);
    if (!$enabled['known']) {
        $errors[] = '无法读取已启用模块（drush 与 config/sync/core.extension.yml 都不可用），不能确认自定义代码是否完整';
    }
    $items = [];
    foreach (cc_scan_projects($site, $roots) as $rel) {
        $abs = $site . '/' . $rel;
        $name = cc_machine_name($abs);
        $kind = cc_kind_for($rel);
        $tree = cc_tree($abs);
        [$vkind, $ver] = cc_git_version($site, $rel, $tree['hash']);
        $on = '0';
        if ($kind === 'theme') {
            $on = array_key_exists($name, $enabled['themes']) ? '1' : '0';
        } else {
            $on = array_key_exists($name, $enabled['modules']) ? '1' : '0';
            if ($on === '0') {
                foreach (glob($abs . '/*.info.yml') ?: [] as $info) {
                    $machine = basename($info, '.info.yml');
                    if (array_key_exists($machine, $enabled['modules'])) {
                        $on = '1';
                        $name = $machine;
                    }
                }
            }
        }
        $items[] = [
            'name' => $name,
            'path' => $rel,
            'kind' => $kind,
            'version' => $ver,
            'version_kind' => $vkind,
            'enabled' => $on,
            'files' => $tree['files'],
            'bytes' => $tree['bytes'],
            'hash' => $tree['hash'],
            'external' => '',
        ];
    }
    foreach (cc_path_repos($site) as $repo) {
        if (($repo['missing'] ?? '0') === '1') {
            $errors[] = 'composer path 仓库目录不存在: ' . $repo['path'];
            continue;
        }
        if (cc_inside((string) $repo['path'], $items)) {
            continue;
        }
        $abs = ($repo['external'] ?? '') !== '' ? (string) $repo['external'] : $site . '/' . $repo['path'];
        $tree = cc_tree($abs);
        [$vkind, $ver] = cc_git_version($site, (string) $repo['path'], $tree['hash']);
        $items[] = [
            'name' => (string) $repo['name'],
            'path' => (string) $repo['path'],
            'kind' => 'package',
            'version' => $ver,
            'version_kind' => $vkind,
            'enabled' => '0',
            'files' => $tree['files'],
            'bytes' => $tree['bytes'],
            'hash' => $tree['hash'],
            'external' => (string) ($repo['external'] ?? ''),
            'composer_url' => (string) ($repo['composer_url'] ?? ''),
        ];
    }
    foreach (['patches', 'scripts'] as $dir) {
        if (is_dir($site . '/' . $dir)) {
            $tree = cc_tree($site . '/' . $dir);
            [$vkind, $ver] = cc_git_version($site, $dir, $tree['hash']);
            $items[] = [
                'name' => $dir,
                'path' => $dir,
                'kind' => 'dir',
                'version' => $ver,
                'version_kind' => $vkind,
                'enabled' => '0',
                'files' => $tree['files'],
                'bytes' => $tree['bytes'],
                'hash' => $tree['hash'],
                'external' => '',
            ];
        }
    }
    foreach (cc_settings_files($site) as $rel) {
        if (cc_inside($rel, $items) || cc_is_bak(basename($rel))) {
            continue;
        }
        $tree = cc_tree($site . '/' . $rel);
        $items[] = [
            'name' => basename($rel),
            'path' => $rel,
            'kind' => 'file',
            'version' => $tree['hash'],
            'version_kind' => 'hash',
            'enabled' => '0',
            'files' => $tree['files'],
            'bytes' => $tree['bytes'],
            'hash' => $tree['hash'],
            'external' => '',
        ];
    }
    foreach (cc_include_paths($includeFile) as $line) {
        $abs = str_starts_with($line, '/') ? $line : $site . '/' . $line;
        $real = realpath($abs);
        if ($real === false) {
            $errors[] = 'include.list 路径不存在: ' . $line;
            continue;
        }
        $siteReal = realpath($site) ?: $site;
        if (str_starts_with($real, rtrim($siteReal, '/') . '/')) {
            $rel = ltrim(substr($real, strlen(rtrim($siteReal, '/'))), '/');
        } else {
            $rel = 'include-extra/' . (preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($real)) ?: 'extra');
        }
        if (cc_inside($rel, $items)) {
            continue;
        }
        $tree = cc_tree($real);
        $items[] = [
            'name' => basename($real),
            'path' => str_replace('\\', '/', $rel),
            'kind' => is_dir($real) ? 'dir' : 'file',
            'version' => $tree['hash'],
            'version_kind' => 'hash',
            'enabled' => '0',
            'files' => $tree['files'],
            'bytes' => $tree['bytes'],
            'hash' => $tree['hash'],
            'external' => str_starts_with($real, rtrim($siteReal, '/') . '/') ? '' : $real,
        ];
    }
    if ($enabled['known']) {
        foreach (['modules' => $enabled['modules'], 'themes' => $enabled['themes']] as $group => $set) {
            foreach ($set as $name => $path) {
                if (cc_stripped_name((string) $name)) {
                    continue;
                }
                $rel = $path !== '' ? cc_norm($path) : '';
                if ($rel !== '' && cc_owned($rel, $roots)) {
                    continue;
                }
                if ($rel === '') {
                    $found = false;
                    foreach ($items as $item) {
                        if ((string) $item['name'] === (string) $name) {
                            $found = true;
                        }
                    }
                    if ($found) {
                        continue;
                    }
                    $customHit = false;
                    foreach ([
                        $site . '/web/modules/custom/' . $name,
                        $site . '/web/themes/custom/' . $name,
                        $site . '/web/profiles/' . $name,
                    ] as $customDir) {
                        if (is_dir($customDir)) {
                            $customHit = true;
                        }
                    }
                    if (!$customHit) {
                        continue;
                    }
                    $errors[] = '已启用的自定义' . ($group === 'themes' ? '主题' : '模块') . ' ' . $name . ' 不在备份范围内';
                    continue;
                }
                if (cc_owned($rel, $roots)) {
                    continue;
                }
                if (!cc_inside($rel, $items) && !is_file($site . '/' . $rel) && !is_dir($site . '/' . $rel)) {
                    $errors[] = '已启用的自定义' . ($group === 'themes' ? '主题' : '模块') . ' ' . $name . ' 路径不存在: ' . $rel;
                    continue;
                }
                if (!cc_inside($rel, $items)) {
                    $errors[] = '已启用的自定义' . ($group === 'themes' ? '主题' : '模块') . ' ' . $name . ' 不在备份范围内（' . $rel . '）';
                }
            }
        }
    }
    return [$items, $errors];
}

function cc_copy_tree(string $src, string $dest): void
{
    if (is_file($src)) {
        $dir = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        copy($src, $dest);
        return;
    }
    if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
        throw new RuntimeException('无法创建 ' . $dest);
    }
    $dir = new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $dir,
        static function (SplFileInfo $current): bool {
            return !($current->isDir() && cc_skip_dir($current->getFilename()));
        }
    );
    $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
    $srcLen = strlen(rtrim($src, '/')) + 1;
    foreach ($it as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }
        $rel = substr($file->getPathname(), $srcLen);
        $target = $dest . '/' . $rel;
        if ($file->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0755, true);
            }
            continue;
        }
        if (cc_is_bak($file->getFilename())) {
            continue;
        }
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        copy($file->getPathname(), $target);
    }
}

/**
 * @param list<array<string,mixed>> $items
 * @return list<string>
 */
function cc_embed_external(string $site, string $stage, array &$items): array
{
    $errors = [];
    $rewrites = [];
    foreach ($items as $i => $item) {
        $external = (string) ($item['external'] ?? '');
        if ($external === '') {
            continue;
        }
        $dest = $stage . '/' . $item['path'];
        try {
            cc_copy_tree($external, $dest);
        } catch (Throwable $e) {
            $errors[] = '无法复制 path 仓库 ' . $item['name'] . ': ' . $e->getMessage();
            continue;
        }
        $url = (string) ($item['composer_url'] ?? '');
        if ($url !== '') {
            $rewrites[$url] = './' . $item['path'];
        }
        $items[$i]['external'] = '';
    }
    if ($rewrites !== []) {
        foreach (['composer.json', 'composer.lock'] as $name) {
            $file = $stage . '/' . $name;
            if (!is_file($file)) {
                continue;
            }
            $json = json_decode((string) file_get_contents($file), true);
            if (!is_array($json)) {
                $errors[] = $name . ' 不是合法 JSON，无法改写 path 仓库';
                continue;
            }
            $changed = false;
            if (isset($json['repositories']) && is_array($json['repositories'])) {
                foreach ($json['repositories'] as $k => $repo) {
                    if (!is_array($repo)) {
                        continue;
                    }
                    $url = (string) ($repo['url'] ?? '');
                    if (isset($rewrites[$url])) {
                        $json['repositories'][$k]['url'] = $rewrites[$url];
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded === false || file_put_contents($file, $encoded . "\n") === false) {
                    $errors[] = '无法写回暂存 ' . $name;
                }
            }
        }
    }
    return $errors;
}

/**
 * @param list<array<string,mixed>> $items
 * @param list<string> $ignoreKinds
 * @return list<string>
 */
function cc_check_stage(string $stage, array $items, array $ignoreKinds = []): array
{
    $errors = [];
    if (!is_dir($stage)) {
        return ['暂存代码目录不存在'];
    }
    foreach ($items as $item) {
        $rel = (string) $item['path'];
        $abs = $stage . '/' . $rel;
        $kind = (string) $item['kind'];
        if (!is_file($abs) && !is_dir($abs)) {
            $label = ((string) $item['enabled'] === '1') ? '已启用的自定义' : '自定义';
            $errors[] = $label . $item['kind'] . ' ' . $item['name'] . ' 不在备份范围内（缺少 ' . $rel . '）';
            continue;
        }
        if (in_array($kind, $ignoreKinds, true)) {
            continue;
        }
        $tree = cc_tree($abs);
        if ((int) $tree['files'] !== (int) $item['files'] || (string) $tree['hash'] !== (string) $item['hash']) {
            $errors[] = $item['name'] . ' 与站点不一致: 文件 ' . $tree['files'] . '/' . $item['files'] . '，哈希不同（' . $rel . '）';
        }
    }
    return $errors;
}

/**
 * @return array<string,mixed>
 */
function cc_config_fingerprint(string $sync): array
{
    $fp = [
        'site_name' => '',
        'default_theme' => '',
        'admin_theme' => '',
        'blocks' => 0,
        'views' => 0,
        'field_storages' => 0,
        'sync_hash' => '',
    ];
    if (!is_dir($sync)) {
        return $fp;
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
    $fp['site_name'] = $read($sync . '/system.site.yml', 'name');
    $fp['default_theme'] = $read($sync . '/system.theme.yml', 'default');
    $fp['admin_theme'] = $read($sync . '/system.theme.yml', 'admin');
    $fp['blocks'] = count(glob($sync . '/block.block.*.yml') ?: []);
    $fp['views'] = count(glob($sync . '/views.view.*.yml') ?: []);
    $fp['field_storages'] = count(glob($sync . '/field.storage.*.yml') ?: []);
    $fp['sync_hash'] = cc_tree($sync)['hash'];
    return $fp;
}

/**
 * @param list<array<string,mixed>> $items
 */
function cc_write_list(string $file, array $items): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fh = fopen($file, 'wb');
    if ($fh === false) {
        throw new RuntimeException('无法写入 ' . $file);
    }
    fwrite($fh, "name\tpath\tkind\tversion\tversion_kind\tenabled\tfiles\tbytes\thash\n");
    foreach ($items as $item) {
        $row = [
            $item['name'], $item['path'], $item['kind'], $item['version'], $item['version_kind'],
            $item['enabled'], $item['files'], $item['bytes'], $item['hash'],
        ];
        fwrite($fh, implode("\t", array_map(static fn ($v): string => str_replace(["\t", "\n"], ' ', (string) $v), $row)) . "\n");
    }
    fclose($fh);
}

/**
 * @return list<array<string,string>>
 */
function cc_read_list(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $rows = [];
    $fh = fopen($file, 'rb');
    if ($fh === false) {
        return [];
    }
    $header = fgetcsv($fh, 0, "\t");
    if (!is_array($header)) {
        fclose($fh);
        return [];
    }
    while (($cols = fgetcsv($fh, 0, "\t")) !== false) {
        if ($cols === [null] || $cols === []) {
            continue;
        }
        $item = [];
        foreach ($header as $i => $key) {
            $item[(string) $key] = (string) ($cols[$i] ?? '');
        }
        if (($item['path'] ?? '') !== '') {
            $rows[] = $item;
        }
    }
    fclose($fh);
    return $rows;
}

/**
 * @param list<array<string,mixed>> $items
 * @param array<string,mixed> $config
 */
function cc_merge_manifest(string $file, array $items, array $config): void
{
    $raw = is_file($file) ? (string) file_get_contents($file) : '{}';
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $json = [];
    }
    $modules = [];
    $themes = [];
    $files = 0;
    $bytes = 0;
    $clean = [];
    foreach ($items as $item) {
        $files += (int) $item['files'];
        $bytes += (int) $item['bytes'];
        if ($item['kind'] === 'module') {
            $modules[] = (string) $item['name'];
        } elseif ($item['kind'] === 'theme') {
            $themes[] = (string) $item['name'];
        }
        $clean[] = [
            'name' => $item['name'],
            'path' => $item['path'],
            'kind' => $item['kind'],
            'version' => $item['version'],
            'version_kind' => $item['version_kind'],
            'enabled' => $item['enabled'] === '1',
            'files' => (int) $item['files'],
            'bytes' => (int) $item['bytes'],
            'hash' => $item['hash'],
        ];
    }
    $json['custom_code'] = [
        'modules' => count($modules),
        'themes' => count($themes),
        'files' => $files,
        'bytes' => $bytes,
        'names' => $modules,
        'theme_names' => $themes,
        'items' => $clean,
        'config' => $config,
    ];
    $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('无法编码 manifest');
    }
    if (file_put_contents($file, $encoded . "\n") === false) {
        throw new RuntimeException('无法写 manifest');
    }
}

/**
 * @param list<array<string,mixed>> $items
 */
function cc_summary(array $items): string
{
    $modules = [];
    $themes = [];
    $files = 0;
    $bytes = 0;
    foreach ($items as $item) {
        $files += (int) $item['files'];
        $bytes += (int) $item['bytes'];
        if ($item['kind'] === 'module') {
            $modules[] = (string) $item['name'];
        } elseif ($item['kind'] === 'theme') {
            $themes[] = (string) $item['name'];
        }
    }
    return 'SUMMARY modules=' . count($modules)
        . ' themes=' . count($themes)
        . ' files=' . $files
        . ' bytes=' . $bytes
        . ' names=' . implode(',', $modules)
        . ' theme_names=' . implode(',', $themes);
}

/**
 * @param list<string> $errors
 */
function cc_fail(array $errors): never
{
    cc_warn('自定义代码清单与备份不一致');
    $shown = 0;
    foreach ($errors as $error) {
        if ($shown >= 30) {
            cc_warn('其余 ' . (count($errors) - $shown) . ' 项省略');
            break;
        }
        cc_warn($error);
        $shown++;
    }
    exit(1);
}

/**
 * @param list<array<string,string>> $items
 * @return list<string>
 */
function cc_verify_members(array $items, string $membersFile): array
{
    $lines = file($membersFile, FILE_IGNORE_NEW_LINES) ?: [];
    $errors = [];
    foreach ($items as $item) {
        $rel = (string) ($item['path'] ?? '');
        if ($rel === '') {
            continue;
        }
        $prefix = 'code/' . $rel;
        $found = false;
        foreach ($lines as $line) {
            $line = ltrim((string) $line, './');
            if ($line === $prefix || str_starts_with($line, $prefix . '/')) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $errors[] = '备份包缺少自定义代码 ' . ($item['name'] ?? $rel) . '（' . $rel . '）';
        }
    }
    return $errors;
}

$argv = $_SERVER['argv'] ?? [];
$mode = (string) ($argv[1] ?? '');
if ($mode === '' || $mode === '-h' || $mode === '--help') {
    fwrite(STDERR, "用法: custom_code.php inventory|verify-members|compare ...\n");
    exit($mode === '' ? 2 : 0);
}

try {
    if ($mode === 'verify-members') {
        $list = cc_arg($argv, '--list');
        $members = cc_arg($argv, '--members');
        if ($list === '' || $members === '' || !is_file($list) || !is_file($members)) {
            fwrite(STDERR, "需要 --list 与 --members\n");
            exit(2);
        }
        $errors = cc_verify_members(cc_read_list($list), $members);
        if ($errors !== []) {
            cc_fail($errors);
        }
        cc_out('自定义代码预检通过');
        exit(0);
    }
    if ($mode === 'compare') {
        $site = cc_arg($argv, '--site');
        $list = cc_arg($argv, '--list');
        $ignore = array_filter(explode(',', cc_arg($argv, '--ignore-hash-kind')));
        if ($site === '' || !is_file($list)) {
            fwrite(STDERR, "需要 --site 与 --list\n");
            exit(2);
        }
        $items = cc_read_list($list);
        $errors = cc_check_stage($site, $items, $ignore);
        $enabled = cc_enabled($site);
        if ($enabled['known']) {
            foreach ($items as $item) {
                if (($item['enabled'] ?? '') !== '1') {
                    continue;
                }
                $name = (string) $item['name'];
                $kind = (string) $item['kind'];
                $set = $kind === 'theme' ? $enabled['themes'] : $enabled['modules'];
                if (!array_key_exists($name, $set)) {
                    $errors[] = '恢复后未启用 ' . $kind . ' ' . $name;
                }
            }
        } else {
            $errors[] = '恢复后无法确认模块启用状态';
        }
        if ($errors !== []) {
            cc_fail($errors);
        }
        cc_out('自定义代码与清单一致');
        exit(0);
    }
    if ($mode !== 'inventory') {
        fwrite(STDERR, "未知模式: {$mode}\n");
        exit(2);
    }
    $site = cc_arg($argv, '--site');
    if ($site === '' || !is_dir($site)) {
        fwrite(STDERR, "需要 --site 目录\n");
        exit(2);
    }
    $stage = cc_arg($argv, '--stage');
    $list = cc_arg($argv, '--list');
    $manifest = cc_arg($argv, '--manifest');
    $include = cc_arg($argv, '--include');
    [$items, $errors] = cc_items($site, $include);
    if ($stage !== '') {
        $errors = array_merge($errors, cc_embed_external($site, $stage, $items));
        $errors = array_merge($errors, cc_check_stage($stage, $items));
    }
    if ($errors !== []) {
        cc_fail($errors);
    }
    if ($list !== '') {
        cc_write_list($list, $items);
    }
    if ($manifest !== '') {
        $sync = $stage !== '' && is_dir($stage . '/config/sync') ? $stage . '/config/sync' : $site . '/config/sync';
        cc_merge_manifest($manifest, $items, cc_config_fingerprint($sync));
    }
    cc_out(cc_summary($items));
    exit(0);
} catch (Throwable $e) {
    cc_warn($e->getMessage());
    exit(1);
}
