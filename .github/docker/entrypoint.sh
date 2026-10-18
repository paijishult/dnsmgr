#!/bin/bash

set -e

if [ ! -f /app/www/public/index.php ] || [ ! -f /app/firstrun ]; then
    echo 'Copying new files'
    \cp -a /usr/src/www /app/

    if [ -d /app/www/runtime/cache ]; then
        rm -rf /app/www/runtime/*
    fi

    chown -R www:www /app/www

    touch /app/firstrun
fi

# 可选：设置环境变量 NGINX_ACCESS_LOG=off 关闭 nginx 访问日志
if [ "${NGINX_ACCESS_LOG:-on}" = "off" ]; then
    sed -i 's#access_log /dev/stdout main_timed;#access_log off;#' /etc/nginx/nginx.conf
fi

exec "$@"