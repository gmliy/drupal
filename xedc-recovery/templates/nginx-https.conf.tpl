server {
    listen 80;
    {{ipv6_80}}
    server_name {{canonical}} {{alias}};
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
        default_type text/plain;
        allow all;
    }
    location / {
        return 301 https://{{canonical}}$request_uri;
    }
}

server {
    listen 443 ssl;
    {{ipv6_443}}
    server_name {{canonical}};
    root {{web_root}};
    index index.php;
    client_max_body_size {{client_max_body_size}};
    client_body_timeout 300s;
    ssl_certificate {{ssl_cert}};
    ssl_certificate_key {{ssl_key}};

    location = /favicon.ico { log_not_found off; access_log off; }
    location = /robots.txt { allow all; log_not_found off; access_log off; }
    location ^~ /.well-known/ { allow all; }
    location ~ \..*/.*\.php$ { return 403; }
    location ~ ^/sites/.*/private/ { return 403; }
    location ~ ^/sites/[^/]+/files/.*\.php$ { deny all; }
    location ~ (^|/)\. { return 403; }
    location / { try_files $uri /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param REQUEST_URI $request_uri;
        fastcgi_read_timeout 300s;
        fastcgi_pass unix:{{php_sock}};
    }

    location ~* \.mp4$ {
        {{mp4_directive}}
        add_header Accept-Ranges bytes always;
        add_header Cache-Control "public, max-age=86400" always;
        try_files $uri @rewrite;
    }

    location ~* \.(m3u8|ts)$ {
        add_header Cache-Control "public, max-age=60" always;
        try_files $uri @rewrite;
    }

    location ~* ^/sites/default/files/.+\.(avif|webp|jpe?g|png|svg|woff2?)$ {
        add_header Cache-Control "public, max-age=2592000" always;
        try_files $uri @rewrite;
        log_not_found off;
    }

    location @rewrite { rewrite ^ /index.php; }
}

server {
    listen 443 ssl;
    {{ipv6_443}}
    server_name {{alias}};
    ssl_certificate {{ssl_cert}};
    ssl_certificate_key {{ssl_key}};
    return 301 https://{{canonical}}$request_uri;
}
