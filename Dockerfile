FROM php:8.2.0-cli

RUN apt-get update \
    && apt-get install -y git curl libxml2-dev wget unzip libaio1 libnsl-dev libfreetype6-dev libjpeg-dev libpng-dev libonig-dev libzip-dev

# Copia los ZIPs de Oracle Instant Client al contenedor
COPY oracle/instantclient-basic-linux.x64-12.1.0.2.0.zip /opt/oracle/
COPY oracle/instantclient-sdk-linux.x64-12.1.0.2.0.zip /opt/oracle/

# Descomprime ambos ZIPs y configura el entorno
RUN unzip /opt/oracle/instantclient-basic-linux.x64-12.1.0.2.0.zip -d /opt/oracle \
    && unzip /opt/oracle/instantclient-sdk-linux.x64-12.1.0.2.0.zip -d /opt/oracle \
    && cp -r /opt/oracle/instantclient_12_1/sdk/include /opt/oracle/instantclient_12_1/ \
    && cd /opt/oracle/instantclient_12_1 \
    && ln -sf libclntsh.so.12.1 libclntsh.so \
    && mkdir -p /usr/lib/oracle/12.1/client64 \
    && ln -sf /opt/oracle/instantclient_12_1 /usr/lib/oracle/12.1/client64 \
    && echo /opt/oracle/instantclient_12_1 > /etc/ld.so.conf.d/oracle-instantclient.conf \
    && ldconfig

ENV LD_LIBRARY_PATH="/opt/oracle/instantclient_12_1:${LD_LIBRARY_PATH}"

RUN docker-php-ext-install iconv simplexml \
    && docker-php-ext-configure pdo_oci --with-pdo-oci=instantclient,/opt/oracle/instantclient_12_1 \
    && echo 'instantclient,/opt/oracle/instantclient_12_1' | pecl install oci8 \
    && docker-php-ext-install pdo_oci \
    && docker-php-ext-enable oci8 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mbstring zip soap

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN wget https://get.symfony.com/cli/installer -O - | bash \
    && mv /root/.symfony5/bin/symfony /usr/local/bin/symfony

WORKDIR /app

EXPOSE 8000

CMD ["symfony", "server:start", "--no-tls", "--port=8000", "--allow-http", "--allow-all-ip"]