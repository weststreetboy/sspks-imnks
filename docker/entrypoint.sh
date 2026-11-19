#!/bin/sh
# 容器入口：把挂载进来的 cache/ runtime/ 权限交给 www-data，然后交给 supervisor
set -e

APP=/var/www/sspks-imnks

mkdir -p "$APP/cache" "$APP/runtime" "$APP/packages"

# 群晖上挂载目录的属主通常不是容器内的 www-data(82)，这里统一纠正
# 失败不致命（只读挂载或权限已正确时会返回非 0）
chown -R www-data:www-data "$APP/cache" "$APP/runtime" 2>/dev/null || true
chmod -R u+rwX,g+rwX "$APP/cache" "$APP/runtime" 2>/dev/null || true

exec "$@"
