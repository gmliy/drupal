<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/lib/object_mirror.php';

$cases = [
    ['public/2026-11/a.webp', 'public/', '2026-11/a.webp'],
    ['public/../secret', 'public/', null],
    ['public//a.webp', 'public/', null],
    ['/etc/passwd', 'public/', null],
    ['public/dir/', 'public/', null],
    ['private/xedc/a.zip', 'private/', 'xedc/a.zip'],
];
foreach ($cases as [$key, $prefix, $expected]) {
    $actual = xedc_mirror_relative($key, $prefix);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$key}\n");
        exit(1);
    }
}
echo "PASS object mirror paths\n";
