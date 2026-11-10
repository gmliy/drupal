<?php

declare(strict_types=1);

$html = stream_get_contents(STDIN);
if (!is_string($html)) {
    exit(0);
}
if (preg_match_all('/<link\b[^>]*>/i', $html, $tags) < 1) {
    exit(0);
}
foreach ($tags[0] as $tag) {
    if (preg_match('/rel=["\']stylesheet["\']/i', $tag) !== 1) {
        continue;
    }
    if (preg_match('/href=["\']([^"\']+)["\']/', $tag, $m) === 1) {
        echo $m[1], "\n";
    }
}
