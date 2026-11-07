<?php

declare(strict_types=1);

/**
 * 让 80 端口能回答 ACME http-01。
 * Certbot 生成的旧配置会用 server 级 if 把所有请求跳到 https，挑战文件到不了。
 */
function xedc_nginx_prepare_http(string $text): string
{
    $text = preg_replace(
        '/\n[ \t]*if\s*\(\$host\s*=\s*[^)]+\)\s*\{\s*return\s+301\s+https:\/\/\$host\$request_uri;\s*\}[^\n]*\n/',
        "\n",
        $text
    ) ?? $text;
    $text = preg_replace(
        '/\n[ \t]*return\s+404;[ \t]*# managed by Certbot\n/',
        "\n",
        $text
    ) ?? $text;

    $snippet = <<<'NGINX'

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
        default_type text/plain;
        allow all;
    }
NGINX;

    $out = '';
    $len = strlen($text);
    $i = 0;
    while ($i < $len) {
        if (preg_match('/\Gserver\s*\{/', $text, $match, 0, $i) === 1) {
            $start = $i;
            $i += strlen($match[0]);
            $depth = 1;
            $bodyStart = $i;
            while ($i < $len && $depth > 0) {
                $char = $text[$i];
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                }
                $i++;
            }
            $body = substr($text, $bodyStart, $i - $bodyStart - 1);
            $listens80 = preg_match('/listen\s+\[::\]:80\b|listen\s+80\b/', $body) === 1;
            if ($listens80 && !str_contains($body, 'acme-challenge')) {
                if (preg_match('/server_name\s+[^;]+;/', $body, $name, PREG_OFFSET_CAPTURE) === 1) {
                    $pos = $name[0][1] + strlen($name[0][0]);
                    $body = substr($body, 0, $pos) . $snippet . substr($body, $pos);
                } else {
                    $body = $snippet . $body;
                }
                if (preg_match('/location\s+\/\s*\{/', $body) !== 1 && preg_match('/return\s+301/', $body) !== 1) {
                    $body .= "\n    location / {\n        return 301 https://\$host\$request_uri;\n    }\n";
                }
            }
            $out .= 'server {' . $body . '}';
            continue;
        }
        $out .= $text[$i];
        $i++;
    }
    return $out;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $file = $argv[1] ?? '';
    if ($file === '' || !is_file($file)) {
        fwrite(STDERR, "用法: nginx_acme.php 配置文件\n");
        exit(2);
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        fwrite(STDERR, "无法读取 {$file}\n");
        exit(1);
    }
    $next = xedc_nginx_prepare_http($raw);
    if ($next !== $raw && file_put_contents($file, $next) === false) {
        fwrite(STDERR, "无法写入 {$file}\n");
        exit(1);
    }
}
