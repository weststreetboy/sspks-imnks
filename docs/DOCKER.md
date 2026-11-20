# Docker 部署（群晖 Container Manager）

README 里的方案是「Nginx + PHP-FPM + 手工配路由」。这个镜像是把同一套东西塞进一个容器：
**nginx + php-fpm 8.4，supervisor 托管**，路由与 README「4. Nginx 配置」完全一致，
所以 `/_sspks_download/` 的内部下载和 `X-Accel-Redirect` 都照常工作。

## 镜像

| 项 | 值 |
|---|---|
| 镜像 | `ghcr.io/weststreetboy/sspks-imnks:latest` |
| 平台 | `linux/amd64`（DS1821+ 等 Intel/AMD 机型）、`linux/arm64` |
| 端口 | 容器内 `80` |
| 入口 | `/var/www/sspks-imnks`（`root` 指向这里） |
| 健康检查 | `wget --spider http://127.0.0.1/index.php` |

## 目录与挂载

容器里跑的是应用目录，三个需要持久化的位置对应三个 volume：

| 容器内 | 内容 | 建议宿主路径 |
|---|---|---|
| `/var/www/sspks-imnks/packages` | `.spk` 文件（索引扫描的就是这里） | `/volume6/docker/sspks-imnks/packages` |
| `/var/www/sspks-imnks/runtime` | SQLite 索引库（`sspks.sqlite3`） | `/volume6/docker/sspks-imnks/runtime` |
| `/var/www/sspks-imnks/cache` | 图标/页面缓存 | `/volume6/docker/sspks-imnks/cache` |

`conf/`、`lib/`、`themes/` 都在镜像里，改主题要重新构建镜像；只想改配置则用环境变量。

## 配置全部走环境变量（不用改镜像里的 yaml）

| 环境变量 | 对应配置 | 说明 |
|---|---|---|
| `SSPKS_BASE_URL` | `site.base_url` | **必须**和 DSM 里填的来源地址一致（含端口、末尾 `/`） |
| `SSPKS_SITE_NAME` | `site.name` | 站点标题 |
| `SSPKS_SITE_DESCRIPTION` | `site.description` | SEO / 社交分享描述 |
| `SSPKS_DEFAULT_PALETTE` | `appearance.default_palette` | 默认配色：`teal` / `ocean` / `violet` / `dark` / `aurora` |
| `SSPKS_UPDATE_TOKEN` | `management_password` | 索引管理页密码，覆盖 `conf/database.yaml` |
| `SSPKS_UPDATE_ACTION` | `update.action` | 索引刷新入口 `?action=<值>`，3–128 位 URL 安全字符 |
| `SSPKS_BROWSER_DOWNLOAD_ENABLED` | `browser_download.enabled` | 网页「下载 SPK」按钮，`true/false` |
| `SSPKS_BROWSER_OBFUSCATE_IMAGES` | `browser_url_obfuscation.package_images` | 图标走不透明地址 |
| `SSPKS_BROWSER_OBFUSCATE_DOWNLOADS` | `browser_url_obfuscation.spk_downloads` | 网页下载走不透明地址（依赖 `/_sspks_download/`） |
| `SSPKS_LANGUAGE_FIXED` | `language.fixed` | 固定语言，如 `chs`、`enu`；留空跟随浏览器 |
| `SSPKS_LANGUAGE_SHOW_SELECTOR` | `language.show_selector` | 是否显示语言切换菜单 |
| `SSPKS_DB_TYPE` | `database.type` | `sqlite`（默认）或 `mysql` |
| `SSPKS_DB_PATH` | `database.database` | SQLite 路径，默认 `runtime/sspks.sqlite3` |
| `SSPKS_DB_HOST / PORT / NAME / USER / PASSWORD` | MySQL 连接 | 用 MySQL 时配这组 |
| `SSPKS_PACKAGES_MAINTAINER / DISTRIBUTOR / SUPPORT_URL` 等 | `packages.*` | 包元数据兜底值 |
| `SSPKS_COMMIT` / `SSPKS_BRANCH` | 页脚版本信息 | 可选 |

布尔类环境变量支持 `1/true/on/yes/enabled`，其余值一律按 `false` 处理（写成 `"false"` 也确实是关）。

## 部署

```bash
mkdir -p /volume6/docker/sspks-imnks/{packages,runtime,cache}
cd /volume6/docker/sspks-imnks
# 放好 docker-compose.yml 和 .env（从 .env.example 复制后填密码）
docker compose up -d
docker compose logs -f sspks
```

然后：

1. 把 `.spk` 丢进 `/volume6/docker/sspks-imnks/packages`
2. 打开 `http://192.168.50.4:13803/?action=<你在 conf/sspks.yaml 配的 update.action>`，
   输入密码刷新索引（密码来自 `SSPKS_UPDATE_TOKEN`）
3. DSM 套件中心 → 设置 → 套件来源 → 新增 `http://192.168.50.4:13803/`

## 拉不到 ghcr.io 时

某些网络到 `ghcr.io` 会在 TLS 握手阶段被切断（报 `Error response from daemon: ... EOF`）。
Actions 每次构建都会额外产出 `sspks-imnks-amd64-image` 这个 artifact：

```bash
# 在 NAS 上，前提是把 artifact 解开后的 tar.gz 传到了该目录
gunzip -c sspks-imnks-amd64.tar.gz | docker load
docker images | grep sspks-imnks
# 然后去掉 compose 里 # pull_policy: never 的注释，再 up -d
```

## 与 README 手工部署的差异

| 项 | 手工（README） | 容器 |
|---|---|---|
| PHP | 主机装 PHP 8.4 + 扩展 | 镜像内 8.4 + `pdo_mysql / pdo_sqlite / mbstring` |
| Nginx | 主机 nginx + 站点配置 | 镜像内 nginx，配置在 `docker/nginx.conf` |
| 权限 | 手工 `chown` cache/runtime | 入口脚本每次启动自动 `chown www-data` |
| 升级 | 拉代码 + 重启 fpm | `docker compose pull && up -d` |

## 排错

| 现象 | 原因 | 处理 |
|---|---|---|
| 容器起来但页面 500 | `vendor/` 缺失或 `cache/` 不可写 | `docker compose logs`；入口脚本已自动修权限 |
| DSM 加来源报「位置无效」 | 索引返回 HTML | `SSPKS_BASE_URL` 没设或与实际地址不一致 |
| 网页下载 404，DSM 下载正常 | 混淆下载依赖 `/_sspks_download/` | 确认用的是镜像自带 nginx 配置，别替换掉 |
| 刷新索引 401 | 密码不一致 | 检查 `.env` 的 `SSPKS_UPDATE_TOKEN` |
