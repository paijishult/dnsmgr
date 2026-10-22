FROM alpine:edge

# Setup document root
WORKDIR /app/www

ARG PHP_APK_VERSION=8.3.36-r0
ARG SWOOLE_VERSION=6.2.2
ARG SSH2_VERSION=1.4.1
ENV PHP_INI_DIR=/etc/php83

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
      php83-phar=${PHP_APK_VERSION} \
      php83-posix=${PHP_APK_VERSION} \
      php83-session=${PHP_APK_VERSION} \
      php83-simplexml=${PHP_APK_VERSION} \
      php83-sockets=${PHP_APK_VERSION} \
      php83-tokenizer=${PHP_APK_VERSION} \
      php83-xml=${PHP_APK_VERSION} \
      php83-zip=${PHP_APK_VERSION}; \
    apk add --no-cache --virtual .php-build-deps \
      autoconf \
      build-base \
      c-ares-dev \
      brotli-dev \
      zstd-dev \
      openssl-dev \
      curl-dev \
      pcre2-dev \
      zlib-dev \
      linux-headers \
      libxml2-dev \
      libssh2-dev \
      libzip-dev \
      icu-dev \
      freetype-dev \
      libjpeg-turbo-dev \
      libpng-dev \
      php83-dev=${PHP_APK_VERSION} \
      php83-pear=${PHP_APK_VERSION}; \
    ln -sf /usr/bin/php83 /usr/local/bin/php; \
    ln -sf /usr/bin/phpize83 /usr/local/bin/phpize; \
    ln -sf /usr/bin/php-config83 /usr/local/bin/php-config; \
    ln -sf /usr/sbin/php-fpm83 /usr/local/sbin/php-fpm; \
    curl -fsSL \
      "https://pecl.php.net/get/swoole-${SWOOLE_VERSION}.tgz" \
      -o /tmp/swoole.tgz; \
    mkdir -p /usr/src/swoole; \
    tar -xzf /tmp/swoole.tgz -C /usr/src/swoole --strip-components=1; \
    cd /usr/src/swoole; \
    /usr/bin/phpize83; \
    ./configure \
      --with-php-config=/usr/bin/php-config83 \
      --with-openssl-dir=/usr \
      --enable-mysqlnd \
      --enable-sockets \
      --enable-swoole-curl \
      --enable-cares \
      --enable-brotli \
      --enable-zstd; \
    make -j"$(nproc)"; \
    make install; \
    pecl83 install "ssh2-${SSH2_VERSION}"; \
    printf 'extension=swoole.so\nextension=ssh2.so\n' > /etc/php83/conf.d/50-pecl.ini; \
    cd /; \
    rm -rf /usr/src/swoole /tmp/swoole.tgz; \
    apk del .php-build-deps; \
    test "$(apk info -v php83 | sed 's/^php83-//')" = "${PHP_APK_VERSION}"; \
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
