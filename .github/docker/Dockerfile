FROM php:8.3.36-fpm-alpine3.24

# Setup document root
WORKDIR /app/www

ARG SWOOLE_VERSION=6.2.2
ARG SSH2_VERSION=1.4.1
RUN set -eux; \
    apk add --no-cache \
      bash \
      curl \
      nginx \
      ca-certificates \
      c-ares \
      brotli-libs \
      zstd-libs \
      openssl \
      pcre2 \
      zlib \
      gettext \
      libssh2 \
      libzip \
      icu-libs \
      freetype \
      libjpeg-turbo \
      libpng \
      supervisor; \
    apk add --no-cache --virtual .php-build-deps \
      $PHPIZE_DEPS \
      c-ares-dev \
      brotli-dev \
      zstd-dev \
      openssl-dev \
      curl-dev \
      pcre2-dev \
      zlib-dev \
      linux-headers \
      gettext-dev \
      libssh2-dev \
      libzip-dev \
      icu-dev \
      freetype-dev \
      libjpeg-turbo-dev \
      libpng-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd gettext intl mysqli opcache pdo_mysql sockets zip; \
    curl -fsSL \
      "https://pecl.php.net/get/swoole-${SWOOLE_VERSION}.tgz" \
      -o /tmp/swoole.tgz; \
    mkdir -p /usr/src/swoole; \
    tar -xzf /tmp/swoole.tgz -C /usr/src/swoole --strip-components=1; \
    cd /usr/src/swoole; \
    phpize; \
    ./configure \
      --with-php-config=/usr/local/bin/php-config \
      --with-openssl-dir=/usr \
      --enable-mysqlnd \
      --enable-sockets \
      --enable-swoole-curl \
      --enable-cares \
      --enable-brotli \
      --enable-zstd; \
    make -j"$(nproc)"; \
    make install; \
    pecl install "ssh2-${SSH2_VERSION}"; \
    docker-php-ext-enable swoole ssh2; \
    cd /; \
    rm -rf /usr/src/swoole /tmp/swoole.tgz; \
    apk del .php-build-deps; \
    php --ri swoole; \
    php -r 'if (PHP_VERSION_ID !== 80336) { fwrite(STDERR, "PHP 8.3.36 is required\n"); exit(1); }'; \
    php --version; \
    php -r 'foreach (["swoole", "curl", "pdo_mysql", "openssl", "ssh2", "ftp", "gd", "mbstring", "intl", "sockets"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing extension: $extension\n"); exit(1); } }'; \
    rm -rf /var/cache/apk/* /tmp/*

# Remove default server definition
RUN rm -f /etc/nginx/http.d/default.conf

# Configure nginx - http
COPY .github/docker/config/nginx.conf /etc/nginx/nginx.conf

# Configure PHP-FPM
ENV PHP_INI_DIR=/usr/local/etc/php
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
RUN composer dump-autoload -d /usr/src/www --no-dev --optimize --no-interaction \
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
CMD /usr/sbin/crond && /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf

STOPSIGNAL SIGTERM

# Configure a healthcheck to validate that everything is up&running
HEALTHCHECK --timeout=10s CMD curl --silent --fail http://127.0.0.1/fpm-ping || exit 1
