# Typecho Suite

[](#typecho-suite)

> **[English](README.md)** · **[简体中文](README.zh-CN.md)**

> [!TIP]
> This project is under fast iteration — APIs, file layout, and settings may change between versions. If you hit any issue, please open a [GitHub issue](https://github.com/jinlio/luckyguo-typecho-suite/issues). Feature requests and ideas from theme users are equally welcome — you can also reach me directly via the email shown on the [demo blog's about page](https://blog.luckyguo.dpdns.org/about.html).

Reusable, configurable Typecho components for personal blogs and small content sites. It contains a neutral responsive theme, an optional administrator skin, a sitemap plugin, Meilisearch integration with a MySQL fallback, an authenticated read-only monitoring panel, optional anonymous statistics, deployment scripts and systemd units, and a separate Typecho 1.3.0 SSRF hardening patch.

This repository contains no personal domain, author identity, production database, uploads, credentials, server keys, or complete Typecho core. Configure site-specific values in Typecho or in files outside the web root.

## Table of contents

[](#table-of-contents)

- [Components](#components)
- [Screenshots](#screenshots)
- [Support](#support)
- [Quick start](#quick-start)
  - [0. Prerequisites and backups](#0-prerequisites-and-backups)
  - [1. Copy the reusable files](#1-copy-the-reusable-files)
  - [2. Enable the theme](#2-enable-the-theme)
  - [3. Enable SuiteAdmin (optional)](#3-enable-suiteadmin-optional)
  - [4. Enable Sitemap (optional)](#4-enable-sitemap-optional)
  - [5. Enable SuiteSearch (optional)](#5-enable-suitesearch-optional)
  - [6. Enable SuiteMonitor (optional)](#6-enable-suitemonitor-optional)
  - [7. Optional: anonymous statistics](#7-optional-anonymous-statistics)
  - [8. Optional: Typecho source patches](#8-optional-typecho-source-patches)
  - [9. End-to-end smoke test](#9-end-to-end-smoke-test)
- [Theme configuration](#theme-configuration)
- [Optional plugins](#optional-plugins)
  - [SuiteAdmin](#suiteadmin)
  - [Sitemap](#sitemap)
  - [SuiteSearch](#suitesearch)
  - [SuiteMonitor](#suitemonitor)
- [Upgrade, uninstall, and rollback](#upgrade-uninstall-and-rollback)
- [Database, privacy, and security](#database-privacy-and-security)
- [Release checks](#release-checks)
- [License](#license)

## Components

[](#components)

| Path | Purpose | Required |
| --- | --- | --- |
| `themes/koijournal` | Responsive theme, dark mode, article TOC, code blocks, branding settings | No plugin |
| `plugins/SuiteAdmin` | Optional administrator light/dark skin, shares the theme cookie | No |
| `plugins/Sitemap` | Public `/sitemap.xml` route, content types and update frequency | No |
| `plugins/SuiteSearch` | Meilisearch search with parameterised MySQL `LIKE` fallback and a rebuild queue | No |
| `plugins/SuiteMonitor` | Authenticated read-only monitoring panel (server, sites, traffic, optional blog stats, optional 24h log) | No |
| `deploy/create-suite-monitor.sql` | Monitor database schema (raw + hourly + daily rollups + log events) | Only with SuiteMonitor |
| `deploy/create-monitor-rollups.sql` | One-time migration for legacy monitor databases (adds `swap_total`) | Legacy only |
| `deploy/create-suite-search.sql` | Search change queue, search meta, and rebuild task tables | Only for full rebuilds |
| `deploy/create-suite-stats.sql` | Anonymous statistics tables in the Typecho database | Only with anonymous stats |
| `deploy/monitor-collect.sh` | Per-minute collector for system metrics, sites, and traffic | With SuiteMonitor |
| `deploy/monitor-log-collect.sh` | Per-minute log collector (files + journald) writing `log_events` | With SuiteMonitor |
| `deploy/monitor-prune.sh` | Daily retention job for raw and rollup tables | With SuiteMonitor |
| `deploy/monitor.cron` | Sample `/etc/cron.d` entries (the installer writes an equivalent file) | Reference only |
| `deploy/suite-monitor-config.php` | Backend-settings exporter, run by the cron jobs | With SuiteMonitor |
| `deploy/install-monitor.sh` | Installs the SuiteMonitor runtime and cron schedule | With SuiteMonitor |
| `deploy/check-install.sh` | Read-only installation and runtime diagnosis | Always recommended |
| `deploy/suite-search-rebuild.php` | Nightly full Meilisearch rebuild entry point | Only for full rebuilds |
| `deploy/typecho-suite-search-rebuild.service` | systemd service for the rebuild entry point | Optional |
| `deploy/typecho-suite-search-rebuild.timer` | systemd timer (`OnCalendar=*-*-* 03:30:00`) | Optional |
| `deploy/examples/*.env.example` | Legacy env-file templates (backend settings are the new default) | Reference only |
| `patches/typecho-1.3.0-personal-avatar.patch` | Adds avatar URL + upload fields to the Typecho profile page | Optional, Typecho 1.3.0 only |
| `patches/typecho-1.3.0-ssrf-hardening.patch` | Extra SSRF protection in `Typecho\Common::safeUrl` (CVE-2026-7025) | Optional, Typecho 1.3.0 only |
| `tests/static-check.sh` | Lints PHP, JS, shell scripts and enforces repository hygiene | Release only |

## Screenshots

[](#screenshots)

These are sanitized demonstration captures from the reusable components. Monitor values are synthetic and do not represent a production host.

### Theme home

[](#theme-home)

[![Suite Default theme home in light mode](docs/screenshots/blog-home-top-light.jpg)](docs/screenshots/blog-home-top-light.jpg)

[![Suite Default theme home in dark mode](docs/screenshots/blog-home-top-dark.jpg)](docs/screenshots/blog-home-top-dark.jpg)

### Article reading and table of contents

[](#article-reading-and-table-of-contents)

[![Article reading layout and table of contents in light mode](docs/screenshots/search-article-body-light.jpg)](docs/screenshots/search-article-body-light.jpg)

[![Article reading layout and table of contents in dark mode](docs/screenshots/search-article-body-dark.jpg)](docs/screenshots/search-article-body-dark.jpg)

### SuiteMonitor administration panel

[](#suitemonitor-administration-panel)

[![SuiteMonitor resource overview in light mode](docs/screenshots/monitor-overview-light.jpg)](docs/screenshots/monitor-overview-light.jpg)

[![SuiteMonitor resource overview in dark mode](docs/screenshots/monitor-overview-dark.jpg)](docs/screenshots/monitor-overview-dark.jpg)

## Support

[](#support)

Target: Typecho 1.3.0, PHP 7.4+, MySQL 8.0+ with the `Mysqli` adapter, and Nginx or Apache. The theme works without custom tables. SuiteSearch needs `Mysqli` for its `LIKE` fallback and explicitly rejects unsupported adapters. The search change queue is auto-detected; without `create-suite-search.sql`, search still works through MySQL `LIKE` and live Meilisearch writes remain optional. The optional statistics tables are required only when the theme's `启用访问统计` switch is turned on. The Typecho 1.3.0 SSRF and personal-avatar patches are optional and must be applied to a matching Typecho 1.3.0 source tree.

## Quick start

[](#quick-start)

The nine steps below cover the theme and every optional plugin. Each step lists exactly what to copy, what to enable, how to verify it, and how to roll it back. Skip the optional steps you do not need.

### 0. Prerequisites and backups

[](#0-prerequisites-and-backups)

On the host that runs Typecho, install:

- PHP 7.4 or newer with the `mysqli` and `PDO` extensions (and `curl` for monitor site probes).
- MySQL 8.0 or newer. MariaDB 10.5+ with the `Mysqli` adapter also works.
- Nginx or Apache serving the Typecho web root.
- Optional but recommended: `jq` is not required; `bash`, `awk`, `sed`, `curl`, and `mysql` client are required for the monitor collector scripts.
- Optional: a running Meilisearch instance (1.x or 2.x) reachable from the PHP worker. Without it, SuiteSearch silently falls back to MySQL `LIKE`.

Before touching anything, back up the Typecho data so the steps below can be repeated or rolled back:

```sh
sudo cp /var/www/typecho/config.inc.php /var/www/typecho/config.inc.php.bak
sudo mysqldump typecho > /tmp/typecho-$(date +%Y%m%d).sql
sudo rsync -a /var/www/typecho/usr/ /tmp/typecho-usr-$(date +%Y%m%d)/
```

Replace `/var/www/typecho` with the actual Typecho root. The steps below assume this path; change `TYPECHO_ROOT` if it differs.

### 1. Copy the reusable files

[](#1-copy-the-reusable-files)

Clone this repository to a scratch location and copy only the directories you intend to use:

```sh
TYPECHO_ROOT=/var/www/typecho
git clone --depth 1 https://github.com/jinlio/luckyguo-typecho-suite.git /tmp/typecho-suite

# Always
rsync -a /tmp/typecho-suite/themes/koijournal/   "$TYPECHO_ROOT/usr/themes/koijournal/"

# Optional, copy only what you will enable
rsync -a /tmp/typecho-suite/plugins/SuiteAdmin/     "$TYPECHO_ROOT/usr/plugins/SuiteAdmin/"
rsync -a /tmp/typecho-suite/plugins/Sitemap/        "$TYPECHO_ROOT/usr/plugins/Sitemap/"
rsync -a /tmp/typecho-suite/plugins/SuiteSearch/    "$TYPECHO_ROOT/usr/plugins/SuiteSearch/"
rsync -a /tmp/typecho-suite/plugins/SuiteMonitor/  "$TYPECHO_ROOT/usr/plugins/SuiteMonitor/"
```

Keep `/tmp/typecho-suite` around so you can rerun `rsync` after pulling updates. The deploy scripts are intentionally not copied under the web root; they live outside `usr/` so the collector user can write files without inheriting PHP-FPM permissions.

**Verify.** Each copied directory contains the expected files:

```sh
ls "$TYPECHO_ROOT/usr/themes/koijournal/functions.php"
ls "$TYPECHO_ROOT/usr/plugins/SuiteMonitor/panel.php"
```

### 2. Enable the theme

[](#2-enable-the-theme)

1. Sign in to Typecho Admin (`/admin/`).
2. Open **控制台 → 外观 → koijournal → 启用** (Console → Appearance → koijournal → Enable).
3. Open the theme settings page and fill in the identity/copy fields, homepage SEO title and description, profile and contact fields, avatar URL, favicon URL, Gravatar endpoint, banner/share/article cover URLs, article cover display switch, repository link, accent colour, theme cookie name and domain, default theme mode, page and reading widths, reading speed, site start time, the toggles for search, reading progress, code enhancements, motion, statistics, home widgets, article TOC, comments RSS, reading metadata, and Gravatar. The About page stack cards, current direction, writing direction, detailed introduction, contact email, image URLs, and all personal links are configurable from this page. Each field has placeholder text; leave anything you do not need empty.
4. Save. On first activation the theme imports your Typecho site title and description plus the first administrator's display name, username, homepage, bio, and saved avatar URL. Subsequent saves do not overwrite values.

**Verify.** Visit the home page and an article page. The header should show the site name you entered. Toggle the dark-mode switch; the choice should persist across reloads. Open the article page on a mobile-sized window (Chrome DevTools works) and confirm the TOC and hamburger menu appear.

### 3. Enable SuiteAdmin (optional)

[](#3-enable-suiteadmin-optional)

SuiteAdmin repaints the Typecho admin pages with the same palette and provides a light/dark toggle that follows the frontend cookie.

1. In Typecho Admin, open **控制台 → 插件 → SuiteAdmin → 启用**.
2. Open the plugin settings and confirm `Cookie 名称` matches the theme's `主题偏好 Cookie 名称` (default `suite-theme`). Leave `Cookie 域名` empty for host-only, or set the parent domain when you want multiple trusted subdomains to share the toggle.
3. Pick the admin default mode (`跟随系统`, `默认浅色`, or `默认深色`).
4. Save.

**Verify.** Reload any admin page and click the theme toggle (top right). The colour scheme flips immediately and the choice survives reloads. Visit the public site and confirm both surfaces share the same cookie value:

```sh
curl -sI "$TYPECHO_ROOT/../" | grep -i set-cookie
```

### 4. Enable Sitemap (optional)

[](#4-enable-sitemap-optional)

1. In Typecho Admin, open **控制台 → 插件 → Sitemap → 启用**.
2. Open the plugin settings. Tick the content types you want exposed (`posts`, `pages`, `categories`, `tags`). Pick `更新频率` (`daily`, `weekly`, or `每月或更久`).
3. Save.
4. Add the sitemap URL to your `robots.txt`:

   ```
   Sitemap: https://yourdomain.com/sitemap.xml
   ```

**Verify.** The route is now public and returns XML. Password-protected posts and empty taxonomies are excluded automatically; the cap is 50,000 URLs per file.

```sh
curl -fsS https://yourdomain.com/sitemap.xml | head
curl -fsS https://yourdomain.com/sitemap.xml | grep -c '<loc>'
```

If you reverse-proxy through Nginx, make sure the request reaches Typecho unmodified (no `rewrite` that strips `sitemap.xml`).

### 5. Enable SuiteSearch (optional)

[](#5-enable-suitesearch-optional)

Decide whether you want a queued full rebuild. For most blogs, Meilisearch with live writes is enough and you can skip the queue tables.

1. If you want the change queue and scheduled full rebuilds, create the queue tables in the Typecho database. Replace `typecho_` with your actual prefix when it differs:

   ```sh
   mysql typecho < deploy/create-suite-search.sql
   ```

2. In Typecho Admin, open **控制台 → 插件 → SuiteSearch → 启用**.
3. Open the plugin settings:

   - `启用 Meilisearch 搜索` — turn this off to fall back to MySQL `LIKE` only.
   - `Meilisearch 地址` — `http://127.0.0.1:7700` or your remote URL. Empty to disable Meilisearch.
   - `搜索 API Key（只读）` — required for query traffic.
   - `写入 API Key` — required for live indexing after publish/save/delete.
   - `重建 API Key` — required for the full rebuild script.
   - `任务查询 API Key` — optional; leave empty to reuse the rebuild key.
   - `在线索引名称` — defaults to `posts_live`.
   - `构建索引名称` — defaults to `posts_build`; used during full rebuilds.
   - `重建切换超时` — seconds to wait for the swap task (minimum 5).
   - `实时同步` — push changes to Meilisearch on publish/save/delete (queue or direct).
   - `降级策略` — when on, Meilisearch outages fall back to MySQL `LIKE`.

   Password fields are intentionally blank. Leave them empty to keep the existing value; enter a new value only to rotate a credential; tick `清除已保存的 API Key` to wipe stored keys.

4. Save.

**Verify.** Open the search box on the public site and search for a phrase that exists in your posts. The result page renders results from Meilisearch if it is reachable, otherwise from MySQL `LIKE`. Tail the PHP error log while searching to confirm no warnings are emitted:

```sh
sudo tail -n 50 /var/log/php-fpm/www-error.log
```

Optional nightly full rebuild via systemd (only needed when you created the queue tables):

```sh
sudo cp deploy/typecho-suite-search-rebuild.service /etc/systemd/system/
sudo cp deploy/typecho-suite-search-rebuild.timer    /etc/systemd/system/
sudo cp deploy/suite-search-rebuild.php             /usr/local/bin/typecho-suite-search-rebuild
sudo chmod 0750 /usr/local/bin/typecho-suite-search-rebuild
sudo systemctl daemon-reload
sudo systemctl enable --now typecho-suite-search-rebuild.timer
systemctl list-timers | grep typecho-suite
```

The rebuild prefers backend settings; the legacy `deploy/examples/search-rebuild.env.example` is supported as a fallback only.

### 6. Enable SuiteMonitor (optional)

[](#6-enable-suitemonitor-optional)

SuiteMonitor reads from a separate monitor database. The collectors and cron schedule live outside the Typecho web root.

#### 6.1 Create the monitor database

[](#61-create-the-monitor-database)

Run from the repository root so the relative path to the SQL file resolves:

```sh
sudo mysql <<'SQL'
CREATE DATABASE monitor DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Read-only account for the admin panel
CREATE USER 'monitor_ro'@'127.0.0.1' IDENTIFIED BY 'change-me-ro';
GRANT SELECT ON monitor.* TO 'monitor_ro'@'127.0.0.1';
-- Read-write account used by the collector cron jobs
CREATE USER 'monitor_rw'@'127.0.0.1' IDENTIFIED BY 'change-me-rw';
GRANT SELECT, INSERT, UPDATE, DELETE ON monitor.* TO 'monitor_rw'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# Load the schema (path is relative to the repository root)
mysql monitor < deploy/create-suite-monitor.sql
```

If you are upgrading from an older monitor installation, run `deploy/create-monitor-rollups.sql` once before deploying the new collector. New installations only need `create-suite-monitor.sql`.

#### 6.2 Install the collectors and cron

[](#62-install-the-collectors-and-cron)

```sh
sudo TYPECHO_ROOT=/var/www/typecho deploy/install-monitor.sh
```

This copies `monitor-collect.sh`, `monitor-log-collect.sh`, `monitor-prune.sh`, and `suite-monitor-config.php` to `/usr/local/sbin` and `/usr/local/libexec`, then writes `/etc/cron.d/typecho-suite-monitor` with three entries:

- every minute: collector + log collector
- daily at 04:17: retention job

The collector user is `root` because it needs to read `/proc`, query systemd unit status, and tail privileged log files. Tighten the unit list and source paths in the plugin settings page if that is a concern.

#### 6.3 Enable and configure the plugin

[](#63-enable-and-configure-the-plugin)

1. In Typecho Admin, open **控制台 → 插件 → SuiteMonitor → 启用**. Activation is idempotent: it removes any legacy panel registrations (`Monitor`, `LuckyguoMonitor`, `LuckyguoStats`) before registering `SuiteMonitor/panel.php`.
2. Open the plugin settings. Walk through the fields in this order:

   **Branding (top of the form)**
   - `监控品牌名称` / `监控品牌标识` / `监控品牌头像地址` — leave all three empty to inherit the active theme's site name, author handle, and avatar URL. Set them when you want the monitor panel to look distinct.

   **File paths**
   - `状态 JSON 路径` — defaults to `/var/lib/typecho-suite/monitor/status.json` (what the collector writes).
   - `旧版监控环境文件路径` — defaults to `/etc/typecho-suite/monitor.env`. Leave it; the new collector does not need it.
   - `采集器状态目录` — defaults to `/var/lib/typecho-suite/monitor`.
   - `Nginx 访问日志路径` — point at the actual access log, e.g. `/var/log/nginx/access.log`.

   **Database**
   - `监控数据库名称` / `监控数据库主机` / `监控数据库端口` — match the database created in 6.1.
   - `监控数据库 DSN` — defaults to `mysql:host=127.0.0.1;dbname=monitor;charset=utf8mb4`. Used by the read-only panel.
   - `采集器写入数据库用户名` / `采集器写入数据库密码` — the `monitor_rw` account. Password fields are blank by default; the plugin keeps the stored value when you leave them empty.
   - `监控面板只读数据库用户名` / `监控面板只读数据库密码` — the `monitor_ro` account.
   - `清除已保存的数据库密码` — tick to wipe stored passwords before saving.
   - `Typecho 数据库名` / `Typecho 表前缀` — required only when you turn on `博客访问统计` below.

   **System and probes**
   - `CPU 核数` — defaults to `1`; set this to the host's actual core count so the load gauge is meaningful.
   - `需要监测的 systemd 服务` — space-separated, e.g. `nginx php-fpm mysqld`. Anything listed here appears under the "服务状态" panel.
   - `站点探测目标` — space-separated `key=host:port` entries such as `blog=blog.example.com:80 docs=docs.example.com:80`. The collector probes each entry over loopback and reports HTTP code and TTFB.
   - `状态文件 owner` / `状态文件 group` / `状态文件权限` — optional. Set the group to the web-server group (e.g. `www-data`) when the PHP worker needs to read the JSON snapshot directly.
   - `原始监控数据保留天数` — defaults to `45`.
   - `汇总监控数据保留天数` — defaults to `400`.
   - `监测目标显示名称` — one `key=名称` per line, e.g. `blog=主站`.
   - `监测目标链接` — one `key=URL` per line, e.g. `blog=https://blog.example.com`.

   **Navigation, footer, logs**
   - `监控顶部导航` — one `key=名称|目标` per line. Target may be `admin`, `site`, a configured site key, or an `https://...` URL. Default entries are `控制台|admin`, `首页|site`, `落地页|landing`. Entries whose target is not configured are hidden automatically.
   - `页脚代码仓库地址` / `页脚代码仓库名称` — optional repository link in the panel footer.
   - `页脚链接开关` — tick to actually show the footer link (default: hidden).
   - `异常日志文件来源` — one `source=绝对路径` per line, e.g. `nginx=/var/log/nginx/error.log`. The collector tails each file incrementally and writes warning-or-higher events to `log_events`.
   - `异常日志 journald 服务` — space-separated systemd unit names, e.g. `sshd nginx php-fpm mysqld`. The collector reads `journalctl --since <last-run>` for these units.
   - `服务显示名称` — one `服务名=显示名称` per line.

   **Display, blog, theme**
   - `主题偏好 Cookie 名称` / `主题偏好 Cookie 域名` — match the theme and SuiteAdmin when you want the same toggle everywhere.
   - `博客访问统计` — tick to show the blog stats panel. Requires `deploy/create-suite-stats.sql` to be loaded into the Typecho database first (see step 7).
   - `监控面板默认时间范围` — `24 小时` / `7 天` / `30 天` / `1 年`.
   - `监控面板自动刷新` — `关闭自动刷新` / `每 30 秒` / `每 1 分钟` / `每 5 分钟`. AJAX log refresh always runs at 60 seconds.
   - `监控面板默认主题` — `跟随系统` / `默认浅色` / `默认深色`.

3. Save. The plugin keeps existing passwords when their fields are left blank; tick the clear option only when you want to wipe them.

**Verify.** Wait about two minutes, then visit **控制台 → 站点监控** (Console → Site Monitor). The configuration check card at the top should report `OK` for every row, the gauge cards should show non-zero values, and the trends chart should show at least one bucket. From the host, run the read-only diagnostic to confirm the rest of the wiring:

```sh
sudo TYPECHO_ROOT=/var/www/typecho deploy/check-install.sh
```

Each line should be `[OK]` or `[WARN]`. `[FAIL]` indicates a missing file, missing extension, or wrong permissions that you need to fix. Tail the collector log to confirm cron actually runs:

```sh
sudo tail -n 50 /var/log/typecho-suite-monitor.log
```

### 7. Optional: anonymous statistics

[](#7-optional-anonymous-statistics)

The theme can record anonymous page views and visitor counts. Statistics are off by default and require four tables in the Typecho database.

1. Replace `typecho_` in `deploy/create-suite-stats.sql` with your actual prefix when it differs:

   ```sh
   sed 's/typecho_/your_prefix_/g' deploy/create-suite-stats.sql | mysql your_typecho_db
   ```

2. In **外观 → koijournal → 设置**, tick `启用访问统计`.
3. In **控制台 → SuiteMonitor → 设置**, tick `博客访问统计` if you want the numbers shown on the monitor panel. The `monitor_ro` user needs `SELECT` on the Typecho database for this to work.

**Verify.** Visit the home page a couple of times. Then check the counters:

```sh
mysql typecho -e "SELECT vday, SUM(pv) pv, COUNT(DISTINCT vip) uv FROM typecho_suite_visits JOIN typecho_suite_visitors USING (vday) GROUP BY vday ORDER BY vday DESC LIMIT 7;"
```

The theme footer also shows today / total UV after a few visits.

### 8. Optional: Typecho source patches

[](#8-optional-typecho-source-patches)

Two patches target a vanilla Typecho 1.3.0 source tree. Apply them only when the corresponding feature matters; both are independent.

#### 8.1 Personal avatar patch

[](#81-personal-avatar-patch)

`patches/typecho-1.3.0-personal-avatar.patch` adds an avatar URL field and an avatar upload control to **个人设置** (Profile), plus a `<img onerror>` fallback for the Gravatar rendering path. Apply it once against a clean Typecho 1.3.0 source tree:

```sh
cd /path/to/typecho-1.3.0
git apply /tmp/typecho-suite/patches/typecho-1.3.0-personal-avatar.patch
```

Uploaded files land under `usr/uploads/avatars/<uid>/` and the URL is stored in the user's personal options. The theme reads `avatarUrl` from the global options widget on first activation.

#### 8.2 SSRF hardening patch

[](#82-ssrf-hardening-patch)

`patches/typecho-1.3.0-ssrf-hardening.patch` extends `Typecho\Common::safeUrl` with extra checks for CGNAT ranges (including `100.100.100.200`), reserved/multicast IPv4 segments, and IPv6 loopback / unspecified / ULA / link-local / multicast addresses (CVE-2026-7025). Apply it against the exact Typecho 1.3.0 source revision you run, then re-test plugins that fetch remote URLs (the Sitemap generator and SuiteSearch):

```sh
cd /path/to/typecho-1.3.0
git apply /tmp/typecho-suite/patches/typecho-1.3.0-ssrf-hardening.patch
```

### 9. End-to-end smoke test

[](#9-end-to-end-smoke-test)

After steps 1–7, run through this checklist from a fresh browser session. Any failure points to the section above that owns the feature.

| Check | Expected |
| --- | --- |
| Home page (light + dark) | Site name, author, tagline, banner or default mark render correctly. |
| Article page (desktop + mobile) | TOC appears on desktop; hamburger menu works on mobile and closes with Escape. |
| Comment form | Email field hint does not imply a third-party request when Gravatar is disabled. |
| RSS feed | `/feed/` returns XML with the expected items. |
| `/sitemap.xml` | Returns XML; URL count > 0; password posts absent. |
| Search box | Returns results; no PHP warnings in the error log. Search failure does not block publishing. |
| Anonymous statistics | PV / UV counters increment for fresh visits. |
| Monitor panel | Configuration check all `[OK]`, gauges and charts populated, log column shows recent events. When the monitor database is temporarily unavailable, the collector still writes the resource snapshot; historical charts and database-backed panels may be empty until the database recovers. |
| Backups | `config.inc.php`, the Typecho dump, and `usr/` snapshot all exist. |

## Theme configuration

[](#theme-configuration)

The settings page exposes the following groups. Every switch is a graphical option; you do not need to edit theme files.

**Identity and copy**
- Site name, author name and handle, tagline, plus the homepage SEO title and description. Empty SEO fields fall back to the site name and tagline.
- About page: leading sentence, focus area, stack, status, module title, module subtitle, stack cards (name/icon/description), current direction, writing direction, contact email, and the long detailed introduction. The detailed introduction takes precedence over the page body when set.
- Homepage eyebrow, signature, signature note, post list title and label.
- Article author label and TOC label.

**Profile, links, and visuals**
- Personal bio (textarea).
- Avatar URL, favicon URL, banner URL, site sharing cover URL, article cover URL, landing URL, code repository URL, and Gravatar endpoint. Each accepts an HTTP(S) URL; empty values fall back to the neutral theme mark or hide the slot. The article-top cover switch is off by default; article-level `thumbnail` / `cover` / `image` fields remain available for per-article covers.
- Accent: `rose` (default), `coral`, or `green`. A custom hex colour picker is available; it takes effect only when `使用自定义主题色` is ticked.
- Banner and article cover alternative text.

**Layout and reading**
- Counter bucket count (`4`–`64`), default theme mode (`跟随系统`, `默认浅色`, `默认深色`).
- Homepage excerpt length, recent comments count, archive limit.
- Page width (`960` / `1120` / `1280`), reading width (`640` / `740` / `840`).
- Reading speed (`300` / `480` / `600` words per minute), table-of-contents depth (`h2` or `h2-h3`).
- Search entry, reading progress, code enhancements (highlight + copy + line numbers), page motion.
- Comment RSS, reading metadata (comment count, view count, ETA).
- Home widgets (categories, archive, recent comments).

**Site**
- Site start time (used for the uptime display).
- Article pages emit `BlogPosting` JSON-LD with author, image, publication/update times and breadcrumbs. Search result pages use `noindex,follow`; paginated pages use their own canonical URL. To override an individual article's search snippet, add a Typecho custom field named `seoDescription` or `metaDescription`.
- Anonymous statistics toggle (requires the `suite_*` tables — see step 7).
- Gravatar switch and endpoint for comment avatars. Off by default; comment avatars are rendered from the bundled local default SVG so visitor email hashes never leave your server. When enabled, use a reachable endpoint such as `https://gravatar.loli.net/avatar/` if the official endpoint is inaccessible.

**Cookie**
- Cookie name (validated as `[A-Za-z][A-Za-z0-9_-]{0,63}`).
- Cookie domain (validated as `\.?[A-Za-z0-9.-]+`). Leave empty for host-only preference.

On first activation, the theme imports the Typecho site title and description, the first administrator's display name / username / homepage / bio, and any saved avatar URL into the form. Existing theme settings are never overwritten. The theme persists its own settings through `themeConfigHandle` so both first activation and later saves are reliable.

## Optional plugins

[](#optional-plugins)

### SuiteAdmin

[](#suiteadmin)

Install path: `usr/plugins/SuiteAdmin`. Activating it injects the matching `admin.css` and `admin.js` into every Typecho admin page through the `admin/header.php` filter. The plugin normalises Typecho 1.3's `pluginUrl()` output so the asset URLs resolve correctly under the public namespace.

**Settings**

| Field | Default | Notes |
| --- | --- | --- |
| `主题偏好 Cookie 名称` | `suite-theme` | Required; share with the theme and SuiteMonitor for a unified toggle. |
| `主题偏好 Cookie 域名` | empty | Set only when multiple trusted subdomains should share the cookie. |
| `后台默认主题模式` | `跟随系统` | Used when the visitor has no saved preference. |

The plugin does not depend on SuiteMonitor or the frontend theme. It only renders the admin shell and toggles its own `data-theme` attribute.

### Sitemap

[](#sitemap)

Install path: `usr/plugins/Sitemap`. Activation registers a public `/sitemap.xml` route. Deactivation removes it.

**Settings**

| Field | Options | Default |
| --- | --- | --- |
| `站点地图显示` | `生成文章链接`, `生成独立页面链接`, `生成分类链接`, `生成标签链接` | All four ticked |
| `更新频率` | `每天`, `每周`, `每月或更久` | `每天` |

The generator is based on `joyqi/typecho-plugin-sitemap` v1.0.0 (MIT, retained in `plugins/Sitemap/LICENSE`). Password-protected posts and empty taxonomies are skipped; a single sitemap is capped at 50,000 URLs. The XML response is served with `Cache-Control: public, max-age=300, stale-while-revalidate=60`.

### SuiteSearch

[](#suitesearch)

Install path: `usr/plugins/SuiteSearch`. Activation wires four Typecho hooks (`Widget\Archive::search` and the `Post\Edit::finish*` hooks) so publishing, saving, marking, and deleting a post can synchronise the index.

**Settings**

| Field | Default | Notes |
| --- | --- | --- |
| `启用 Meilisearch 搜索` | on | Master switch. Off forces MySQL `LIKE` only. |
| `Meilisearch 地址` | `http://127.0.0.1:7700` | Empty disables Meilisearch. Validated as an HTTP(S) URL. |
| `搜索 API Key` (只读) | empty | Required for queries. Leave blank to keep the stored value. |
| `写入 API Key` | empty | Required for live writes; missing keys skip direct sync. |
| `重建 API Key` | empty | Required for `suite-search-rebuild.php`. |
| `任务查询 API Key` | empty | Optional. Defaults to the rebuild key. |
| `清除已保存的 API Key` | off | Tick to wipe stored keys before saving. |
| `在线索引名称` | `posts_live` | The index served on the public site. |
| `构建索引名称` | `posts_build` | Used during full rebuilds; never query this directly. |
| `重建切换超时（秒）` | `30` | Minimum 5 seconds. |
| `实时同步` | on | Queue or direct-write on publish / save / delete. |
| `降级策略` | on | Fall back to MySQL `LIKE` when Meilisearch is unreachable. |

The plugin reads configuration in this order: backend settings → optional `/etc/typecho-suite/search.env` (path overridable through `TYPECHO_SUITE_SEARCH_CONFIG`). When neither is present, the plugin throws on every search call and falls back to MySQL `LIKE` automatically; the search box keeps working.

The change-queue tables (`typecho_suite_changequeue`, `typecho_suite_searchmeta`, `typecho_suite_rebuildtask`) are required only when you run nightly full rebuilds through the systemd timer. They are detected automatically; without them, live writes still work and the rebuild script is a no-op.

`deploy/examples/search.env.example` and `deploy/examples/search-rebuild.env.example` are kept only for legacy upgrades; new installations do not need either file.

### SuiteMonitor

[](#suitemonitor)

Install path: `usr/plugins/SuiteMonitor`. Activation adds a top-level admin panel entry `SuiteMonitor/panel.php` under the `administrator` group, removing any legacy `Monitor`, `LuckyguoMonitor`, or `LuckyguoStats` registrations first. The panel itself re-checks `pass('administrator', true)` for every request.

The data path is:

```
collector scripts (cron, root)
    │
    ├─► status.json (atomic write, every minute)
    │       └─► panel render (top metrics)
    │
    └─► monitor MySQL database (raw + hourly + daily rollups + log_events)
            └─► panel render (charts, sites, traffic, blog stats, 24h log)
```

The `suite-monitor-config.php` exporter reads the plugin settings from Typecho and prints shell assignments. The cron jobs `eval` its output before running, so you should not edit `/etc/typecho-suite/monitor.env` on new installations; the example file documents the format only.

**Settings**

| Field | Default | Notes |
| --- | --- | --- |
| `监控品牌名称` | empty | Inherits the active theme's site name. |
| `监控品牌标识` | empty | Inherits the active theme's author handle. |
| `监控品牌头像地址` | empty | Inherits the active theme's avatar URL. Must be HTTP(S). |
| `状态 JSON 路径` | `/var/lib/typecho-suite/monitor/status.json` | Written by the collector. |
| `旧版监控环境文件路径` | `/etc/typecho-suite/monitor.env` | Legacy fallback only. |
| `采集器状态目录` | `/var/lib/typecho-suite/monitor` | Holds `.cpustate`, `.netstate`, `.logpos-*`, and `log-heartbeat`. |
| `Nginx 访问日志路径` | `/var/log/nginx/access.log` | Source for the traffic panel. |
| `监控数据库写入凭据文件路径（旧版兼容）` | `/etc/typecho-suite/monitor-rw.cnf` | Legacy `mysql --defaults-extra-file` target. New installs can leave it; the collector writes a short-lived `*.generated.cnf` instead. |
| `监控数据库名称` / `主机` / `端口` | `monitor` / `127.0.0.1` / `3306` | |
| `采集器写入数据库用户名` / `密码` | empty | Stored encrypted-at-rest in the plugin options; leave blank to keep. |
| `监控数据库 DSN` | `mysql:host=127.0.0.1;dbname=monitor;charset=utf8mb4` | Used by the read-only panel connection. |
| `监控面板只读数据库用户名` / `密码` | empty | The `monitor_ro` account. |
| `清除已保存的数据库密码` | off | Tick before saving to wipe both passwords. |
| `Typecho 数据库名` / `表前缀` | `typecho` / `typecho_` | Required when `博客访问统计` is on. |
| `CPU 核数` | `1` | Set to the actual core count for accurate load scaling. |
| `需要监测的 systemd 服务` | `nginx php-fpm mysqld` | Space-separated unit names. |
| `站点探测目标` | empty | Space-separated `key=host:port`. Keys are persisted in `site_checks`. |
| `状态文件 owner` / `group` / `权限` | `0640` | Apply when the PHP worker needs to read `status.json` directly. |
| `原始监控数据保留天数` / `汇总监控数据保留天数` | `45` / `400` | Used by `monitor-prune.sh`. |
| `监测目标显示名称` | `blog=主站` etc. | One `key=名称` per line. |
| `监测目标链接` | one `key=URL` per line | Must be HTTP(S). |
| `监控顶部导航` | `控制台\|admin`, `首页\|site`, `落地页\|landing` | One `key=名称\|目标` per line. Targets: `admin`, `site`, a configured site key, or an HTTP(S) URL. |
| `页脚代码仓库地址` / `名称` | empty / `代码仓库` | Footer link target and label. |
| `页脚链接开关` | off | Must be ticked to actually render the footer link. |
| `异常日志文件来源` | empty | One `source=绝对路径` per line. Tailed incrementally. |
| `异常日志 journald 服务` | empty | Space-separated systemd unit names. |
| `服务显示名称` | `nginx=Nginx` etc. | One `服务名=显示名称` per line. |
| `主题偏好 Cookie 名称` / `Cookie 域名` | `suite-theme` / empty | Match the theme for a unified toggle. |
| `博客访问统计` | off | Tick to render the blog stats panel; requires `create-suite-stats.sql` and read access. |
| `监控面板默认时间范围` | `24 小时` | One of `24 小时` / `7 天` / `30 天` / `1 年`. |
| `监控面板自动刷新` | `每 30 秒` | One of `关闭自动刷新` / `每 30 秒` / `每 1 分钟` / `每 5 分钟`. The 24h log column always polls every 60 seconds. |
| `监控面板默认主题` | `跟随系统` | One of `跟随系统` / `默认浅色` / `默认深色`. |

**Panel structure**

The panel renders (top to bottom):

1. **Configuration check** — four pass/fail rows for the snapshot file, monitor database connection, collector freshness (≤ 180 s), and historical sampling health. Use it to triage an empty or stale panel.
2. **Server overview** — gauge cards for CPU, memory, disk, load (scaled to `CPU 核数`).
3. **Services** — one dot per `需要监测的 systemd 服务`.
4. **Uptime** — last-24-hour strip plus 30-day availability per configured `站点探测目标`.
5. **Trends** — four charts (CPU + load, memory + swap, network, traffic) for the selected range. Trend lines split on real sampling gaps; data-quality text (`最后采集 ... · N 处数据缺口`) reflects the latest collector run.
6. **Traffic** — last-24-hour totals, status-code donut, and top client IPs.
7. **Blog** (only when `博客访问统计` is on) — today / total PV/UV and the top five posts.
8. **24h exception log** — combined view of `log_events` and recent site probe failures, with level filters and a 60-second AJAX refresh. A `采集可能异常` badge appears when the heartbeat file is older than 150 seconds.

## Upgrade, uninstall, and rollback

[](#upgrade-uninstall-and-rollback)

1. Back up the current theme, plugins, configuration, and database before any upgrade.
2. Stage the new files in a disposable Typecho instance and complete the release checks below before touching production.
3. After upgrading the theme or plugins, clear page caches and re-check the home page, articles, comments, admin pages, sitemap, search, and monitor permissions.
4. When removing a plugin, disable it in the Typecho panel first, then delete its directory. Statistics and search queue tables are not dropped automatically; migrate or delete them only after confirming they are no longer needed.
5. To roll back, restore the backup files and database. A database restore overwrites everything created after the backup, so it must run inside a maintenance window.
6. When removing SuiteMonitor, also remove the cron file (`/etc/cron.d/typecho-suite-monitor`), the binaries under `/usr/local/sbin/`, the exporter under `/usr/local/libexec/`, and optionally the `monitor` database. The plugin `deactivate` action removes only the admin panel entry.

## Database, privacy, and security

[](#database-privacy-and-security)

SQL examples use the `typecho_` prefix; replace it consistently for another prefix. Statistics store client IP and User-Agent for daily deduplication, so review the privacy notice and retention before enabling them. The collector itself only sees what is reachable from the PHP worker and from the host that runs cron; service unit names, site probe targets, and log source paths are validated before they reach systemd or `journalctl`. Keep credentials outside Git and the web root. Do not expose Meilisearch. Review the Typecho 1.3.0 SSRF patch against the exact source revision before applying it.

## Release checks

[](#release-checks)

Run from the repository root:

```sh
./tests/static-check.sh
git diff --check
bash -n deploy/monitor-collect.sh
bash -n deploy/monitor-log-collect.sh
bash -n deploy/monitor-prune.sh
bash -n deploy/install-monitor.sh
bash -n deploy/check-install.sh
node --check themes/koijournal/site.js
node --check themes/koijournal/assets/mac-code.js
node --check plugins/SuiteAdmin/admin.js
```

`tests/static-check.sh` lints every PHP file under `themes`, `plugins`, and `deploy` (when `php` is available), runs the JavaScript and shell checks above, scans for personal-deployment coupling (specific domains, author paths, legacy private panel keys, third-party remote remotes, and any non-public author handle), and rejects PHP 8-only helpers such as `array_is_list`, `str_contains`, `str_starts_with`, or `str_ends_with`.

Then run `php -l` on every PHP file with the target PHP version, and install into a disposable Typecho instance. Test anonymous pages, RSS, comments, uploads, admin pages, Sitemap, search fallback, optional statistics, monitor access, default and custom prefixes, upgrade, and rollback. For SuiteMonitor, verify an empty `SITE_TARGETS`, multiple targets, unavailable log files, a zero-Swap host, the read-only database account, and the configuration-check panel on a stale snapshot.

## License

[](#license)

The repository uses `LICENSE`. The Sitemap plugin retains its upstream MIT notice in `plugins/Sitemap/LICENSE`.
