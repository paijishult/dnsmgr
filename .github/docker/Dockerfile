FROM alpine:3.24@sha256:71e9b7bae160ba167fa17414401b94cbcdaf3c32feeab9298bd794a2a0614c35

# Setup document root
WORKDIR /app/www

ARG PHP_APK_VERSION=8.3.36-r0
ARG SWOOLE_APK_VERSION=6.2.2-r0
ARG SSH2_APK_VERSION=1.5.0-r0
ENV PHP_INI_DIR=/etc/php83

RUN set -eux; \
    apk add --no-cache \
      bash \
      curl \
      nginx \
      ca-certificates \
      openssl \
      supervisor \
      php83=${PHP_APK_VERSION} \
      php83-fpm=${PHP_APK_VERSION} \
      php83-ctype=${PHP_APK_VERSION} \
      php83-curl=${PHP_APK_VERSION} \
      php83-dom=${PHP_APK_VERSION} \
      php83-fileinfo=${PHP_APK_VERSION} \
      php83-ftp=${PHP_APK_VERSION} \
      php83-gd=${PHP_APK_VERSION} \
      php83-gettext=${PHP_APK_VERSION} \
      php83-iconv=${PHP_APK_VERSION} \
      php83-intl=${PHP_APK_VERSION} \
      php83-mbstring=${PHP_APK_VERSION} \
      php83-mysqli=${PHP_APK_VERSION} \
      php83-opcache=${PHP_APK_VERSION} \
      php83-openssl=${PHP_APK_VERSION} \
      php83-pcntl=${PHP_APK_VERSION} \
      php83-pdo=${PHP_APK_VERSION} \
      php83-pdo_mysql=${PHP_APK_VERSION} \
      php83-pdo_sqlite=${PHP_APK_VERSION} \
      php83-phar=${PHP_APK_VERSION} \
      php83-posix=${PHP_APK_VERSION} \
      php83-session=${PHP_APK_VERSION} \
      php83-simplexml=${PHP_APK_VERSION} \
      php83-sockets=${PHP_APK_VERSION} \
      php83-sodium=${PHP_APK_VERSION} \
      php83-tokenizer=${PHP_APK_VERSION} \
      php83-xml=${PHP_APK_VERSION} \
      php83-xmlreader=${PHP_APK_VERSION} \
      php83-xmlwriter=${PHP_APK_VERSION} \
      php83-zip=${PHP_APK_VERSION} \
      php83-pecl-swoole=${SWOOLE_APK_VERSION} \
      php83-pecl-ssh2=${SSH2_APK_VERSION}; \
    ln -sf /usr/bin/php83 /usr/local/bin/php; \
    php --ri swoole; \
    php --version; \
    rm -rf /var/cache/apk/* /tmp/*

# Remove default server definition
RUN rm -f /etc/nginx/http.d/default.conf

# Configure nginx - http
COPY .github/docker/config/nginx.conf /etc/nginx/nginx.conf

# Configure PHP-FPM
COPY .github/docker/config/fpm-pool.conf ${PHP_INI_DIR}/php-fpm.d/www.conf
COPY .github/docker/config/php.ini ${PHP_INI_DIR}/conf.d/custom.ini

# Configure supervisord
COPY .github/docker/config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Install composer
RUN wget https://getcomposer.org/download/latest-stable/composer.phar -O /usr/local/bin/composer && chmod +x /usr/local/bin/composer

# 先单独安装依赖，源码变动时可复用依赖层缓存
COPY composer.json composer.lock /usr/src/www/
RUN composer install -d /usr/src/www --no-interaction --no-dev --no-autoloader --no-scripts --no-cache

# Add application（使用本仓库源码构建）
COPY . /usr/src/www
COPY .github/docker/verify-runtime.php /tmp/dnsmgr-verify-runtime.php
RUN composer dump-autoload -d /usr/src/www --no-dev --optimize --no-interaction \
    && DNSMGR_RUNTIME_APP_ROOT=/usr/src/www php /tmp/dnsmgr-verify-runtime.php \
    && rm /tmp/dnsmgr-verify-runtime.php \
    && rm -rf /usr/src/www/.github /usr/src/www/.git /usr/src/www/tests /usr/local/bin/composer

RUN adduser -D -s /sbin/nologin -g www www && chown -R www:www /usr/src/www /var/lib/nginx /var/log/nginx

# crontab
RUN echo "* * * * * cd /app/www && /usr/local/bin/php think certtask" | crontab -u www -

COPY .github/docker/config/run_tasks.sh /app/run_tasks.sh
RUN chmod +x /app/run_tasks.sh

# copy entrypoint script
COPY .github/docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["sh", "/entrypoint.sh"]

# Expose the port nginx is reachable on
EXPOSE 80

# Let supervisord start nginx & php-fpm
CMD ["/bin/sh", "-c", "/usr/sbin/crond && exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf"]

STOPSIGNAL SIGTERM

# Configure a healthcheck to validate that everything is up&running
HEALTHCHECK --timeout=10s CMD curl --silent --fail http://127.0.0.1/fpm-ping || exit 1
