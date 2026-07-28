#!/bin/sh
set -e

APP_PORT="${APP_PORT:-8000}"

sed "s/__APP_PORT__/${APP_PORT}/g" \
    /etc/nginx/templates/default.conf.template \
    > /etc/nginx/sites-available/default

cd /app

mkdir -p var/cache var/log config/jwt
chown -R www-data:www-data var config/jwt
chmod -R ug+rwX var config/jwt

if [ ! -f config/jwt/private.pem ] || [ ! -f config/jwt/public.pem ]; then
    if [ -z "${JWT_PASSPHRASE:-}" ]; then
        echo "JWT_PASSPHRASE is required when JWT keys are missing."
        exit 1
    fi

    openssl genpkey \
        -out config/jwt/private.pem \
        -aes256 \
        -algorithm rsa \
        -pkeyopt rsa_keygen_bits:4096 \
        -pass "pass:${JWT_PASSPHRASE}"
    openssl pkey \
        -in config/jwt/private.pem \
        -out config/jwt/public.pem \
        -pubout \
        -passin "pass:${JWT_PASSPHRASE}"
    chown www-data:www-data config/jwt/private.pem config/jwt/public.pem
    chmod 640 config/jwt/private.pem
    chmod 644 config/jwt/public.pem
fi

if [ "${APP_ENV:-prod}" = "prod" ]; then
    su -s /bin/sh www-data -c "php bin/console cache:clear --env=prod --no-debug --no-interaction" || true
fi

php-fpm -D

exec "$@"
