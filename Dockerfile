# SSPKS-IMNKS —— 群晖 DSM 7 套件源（容器化）
# 单容器内跑 nginx + php-fpm 8.4，由 supervisor 托管
# 路由与 README 的 Nginx 方案一致（含 /_sspks_download/ 内部下载位置）
FROM php:8.4-fpm-alpine

RUN set -eux; \
    apk add --no-cache nginx supervisor tzdata oniguruma sqlite-libs; \
    apk add --no-cache --virtual .build-deps oniguruma-dev sqlite-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite mbstring; \
    apk del --no-network .build-deps; \
    rm -rf /var/cache/apk/* /tmp/pear

WORKDIR /var/www/sspks-imnks

# 应用本体（vendor/ 已在仓库里，运行时不需要 composer）
COPY . /var/www/sspks-imnks

# 容器化配置
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# 构建期就把配置和 PHP 语法校验掉，免得等到运行才炸
RUN set -eux; \
    chmod +x /usr/local/bin/entrypoint.sh; \
    mkdir -p /var/www/sspks-imnks/cache /var/www/sspks-imnks/runtime /var/www/sspks-imnks/packages; \
    chown -R www-data:www-data /var/www/sspks-imnks/cache /var/www/sspks-imnks/runtime; \
    nginx -t; \
    find /var/www/sspks-imnks -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 php -l > /dev/null; \
    php -v

EXPOSE 80

HEALTHCHECK --interval=60s --timeout=5s --start-period=15s --retries=3 \
  CMD wget -q --spider http://127.0.0.1/index.php || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
