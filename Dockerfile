FROM mirror.gcr.io/library/php:8.2-fpm-bookworm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libaio1 \
        libnsl2 \
        nginx \
        unzip \
        wget \
    && rm -rf /var/lib/apt/lists/*

COPY oracle/instantclient-basic-linux.x64-12.1.0.2.0.zip /opt/oracle/
COPY oracle/instantclient-sdk-linux.x64-12.1.0.2.0.zip /opt/oracle/

RUN unzip /opt/oracle/instantclient-basic-linux.x64-12.1.0.2.0.zip -d /opt/oracle \
    && unzip /opt/oracle/instantclient-sdk-linux.x64-12.1.0.2.0.zip -d /opt/oracle \
    && cp -r /opt/oracle/instantclient_12_1/sdk/include /opt/oracle/instantclient_12_1/ \
    && cd /opt/oracle/instantclient_12_1 \
    && ln -sf libclntsh.so.12.1 libclntsh.so \
    && mkdir -p /usr/lib/oracle/12.1/client64 \
    && ln -sf /opt/oracle/instantclient_12_1 /usr/lib/oracle/12.1/client64 \
    && echo /opt/oracle/instantclient_12_1 > /etc/ld.so.conf.d/oracle-instantclient.conf \
    && ldconfig \
    && rm -f /opt/oracle/*.zip

ENV LD_LIBRARY_PATH="/opt/oracle/instantclient_12_1:${LD_LIBRARY_PATH}"

RUN docker-php-ext-install iconv simplexml \
    && docker-php-ext-configure pdo_oci --with-pdo-oci=instantclient,/opt/oracle/instantclient_12_1 \
    && echo 'instantclient,/opt/oracle/instantclient_12_1' | pecl install oci8-3.3.0 \
    && docker-php-ext-install pdo_oci \
    && docker-php-ext-enable oci8 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mbstring zip soap opcache \
    && rm -rf /tmp/pear

COPY --from=mirror.gcr.io/library/composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

FROM base AS vendor

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

FROM base AS runner

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_PORT=8000 \
    COMPOSER_ALLOW_SUPERUSER=1

COPY docker/nginx/default.conf /etc/nginx/templates/default.conf.template
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/app.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/zz-docker-env.conf /usr/local/etc/php-fpm.d/zz-docker-env.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /app/var/cache /app/var/log /app/config/jwt \
    && chown -R www-data:www-data /app

COPY --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data . .

RUN rm -rf oracle docker \
    && mkdir -p var/cache var/log config/jwt \
    && chown -R www-data:www-data /app \
    && chmod -R ug+rwX var config/jwt

EXPOSE ${APP_PORT}

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${APP_PORT}/" > /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
