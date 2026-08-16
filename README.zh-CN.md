# Luckyguo Typecho Suite

[English](README.md) | 简体中文

这是一个可复用的 Typecho 站点组件集，包含主题、后台换肤、站点地图、搜索、监控面板，以及适用于 Typecho 1.3.0 的 SSRF 加固补丁。

它不是完整的 Typecho 发行包，也不包含数据库、上传文件、服务器配置、备份文件或任何密钥。这样做的目的，是让代码可以公开复用，同时把每个部署环境独有的配置和数据留在自己的服务器上。

## 前台预览

![Luckyguo Typecho 博客首页](docs/screenshots/blog-home-top.jpg)

![最新文章列表](docs/screenshots/blog-home-latest.jpg)

![搜索系统优化文章](docs/screenshots/search-article-top.jpg)

![搜索文章正文与目录](docs/screenshots/search-article-body.jpg)

### 浅色模式

![Luckyguo Typecho 博客浅色首页](docs/screenshots/blog-home-top-light.jpg)

![搜索文章浅色正文与目录](docs/screenshots/search-article-body-light.jpg)

## 目录

- [适用范围与边界](#适用范围与边界)
- [目录说明](#目录说明)
- [开始前的检查](#开始前的检查)
- [最小可用安装](#最小可用安装)
- [主题复用](#主题复用)
- [插件复用](#插件复用)
- [搜索系统部署](#搜索系统部署)
- [统计数据与保留策略](#统计数据与保留策略)
- [SSRF 补丁](#ssrf-补丁)
- [发布、回滚与备份](#发布回滚与备份)
- [验收与故障排查](#验收与故障排查)
- [相关文章](#相关文章)

## 适用范围与边界

### 已验证的组合

- Typecho `1.3.0`
- PHP `7.4+`，启用 `curl` 扩展
- MySQL `8.0+`
- Typecho 的 `Mysqli` 数据库适配器
- Nginx 或 Apache 作为 Web 服务器
- 可选的 Meilisearch 服务

搜索插件明确依赖 `Mysqli`，不能直接套用到 SQLite、PostgreSQL 或 PDO 适配器。主题中的统计逻辑也假设使用 MySQL 的 `ON DUPLICATE KEY UPDATE` 语义。

部署前请先在测试环境完成验证。不要将示例域名、`/etc` 路径、数据库名、Cookie 域名或 systemd 运行用户原样复制到生产。

## 目录说明

| 路径 | 用途 | 是否可独立使用 |
| --- | --- | --- |
| `themes/luckyguo` | 前台主题、响应式布局、深浅色、文章代码块和统计展示 | 可以，但统计表需先准备 |
| `plugins/LuckyguoAdmin` | Typecho 后台深浅色换肤 | 可以 |
| `plugins/Sitemap` | 动态 `/sitemap.xml` 路由 | 可以 |
| `plugins/LuckyguoSearch` | Meilisearch 搜索、降级查询和异步重建队列 | 需要数据库表、配置文件和重建任务 |
| `plugins/Monitor` | 管理员专用只读监控面板 | 需要自建采集器和监控数据库 |
| `patches/typecho-1.3.0-ssrf-hardening.patch` | Pingback/Trackback 出站请求安全加固 | 仅适用于匹配的 Typecho 1.3.0 源码 |

## 开始前的检查

1. 为现有站点建立可恢复的备份：数据库、`config.inc.php`、`usr/plugins/`、`usr/themes/` 和 `usr/uploads/`。
2. 记录当前 Typecho 版本、PHP 版本、数据库表前缀和启用的插件列表。
3. 在维护窗口或测试副本中执行首次安装。
4. 确保 Web 服务进程可读取插件、主题和只读配置文件；上传目录只授予必要的写权限。
5. 将密钥放在站点根目录以外的权限收紧文件中，不能放进 Git 仓库。

下面的命令假设 Typecho 根目录为 `/var/www/typecho`。请按实际路径替换。

```sh
export TYPECHO_ROOT=/var/www/typecho
git clone https://github.com/jinlio/luckyguo-typecho-suite.git /tmp/luckyguo-typecho-suite
rsync -a /tmp/luckyguo-typecho-suite/plugins/ "$TYPECHO_ROOT/usr/plugins/"
rsync -a /tmp/luckyguo-typecho-suite/themes/luckyguo/ "$TYPECHO_ROOT/usr/themes/luckyguo/"
```

复制后，进入 Typecho 后台的“控制台 -> 插件”，按需要启用插件；然后在“控制台 -> 外观”选择 `luckyguo` 主题。

## 最小可用安装

如果只想得到一个可用的博客前台，请按以下顺序部署：

1. 安装干净的 Typecho 1.3.0，并完成官方安装。
2. 仅复制 `themes/luckyguo`，选择主题。
3. 在主题设置中填写个人简介、个人主页地址、Gitea 地址和强调色。
4. 安装 `Sitemap`，启用后访问 `/sitemap.xml`。
5. 确认文章页、独立页面、归档、RSS、评论和上传均工作正常。
6. 在完成回归测试后，再启用统计、搜索、监控和 SSRF 补丁。

不要把所有组件一次性上线。每加一个组件就检查前台、后台和错误日志，问题会更容易定位。

## 主题复用

主题通过 `functions.php` 提供主题设置和访问统计。基础视觉不依赖搜索或监控插件，统计部分则依赖自定义表。

### 必须替换的站点信息

启用主题后，在 Typecho 主题设置中修改：

- 个人简介 `bio`
- 强调色 `accent`，可选 `rose`、`coral`、`green`
- 个人主页地址 `landingUrl`
- Gitea 地址 `giteaUrl`

此外，`header.php` 中的站点名称、头像替代文本和主题 Cookie 域名属于站点级信息。若你的博客不在 `*.luckyguo.dpdns.org` 下，必须把 Cookie 的 `Domain` 改为自己的可共享父域，或删除 `Domain` 属性只在当前主机生效。不要把原域名带到自己的站点。

### 统计表和并发语义

主题会记录文章浏览、当天 PV 与当天 UV。PV 和文章浏览使用随机桶写入，再在读取时 `SUM()` 聚合，以降低热点行锁竞争；UV 以日期和客户端 IP 作为去重语义。

发布前应在你的数据库中创建对应的统计表与唯一键。字段名和表前缀必须同主题代码保持一致，默认前缀为 `typecho_`。推荐先从一个独立测试库验证：

```sql
CREATE TABLE typecho_luckyguo_visits (
  vday DATE NOT NULL,
  bucket TINYINT UNSIGNED NOT NULL,
  pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (vday, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE typecho_luckyguo_views (
  cid INT UNSIGNED NOT NULL,
  bucket TINYINT UNSIGNED NOT NULL,
  views BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (cid, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE typecho_luckyguo_visitors (
  vday DATE NOT NULL,
  vip VARCHAR(64) NOT NULL,
  ua VARCHAR(250) NOT NULL,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  PRIMARY KEY (vday, vip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE typecho_luckyguo_visitors_daily (
  vday DATE NOT NULL,
  uv INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (vday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

这会记录 IP 和 User-Agent，部署前应自行评估隐私告知、保留期限和适用法规。若没有统计需求，建议先从主题中移除记录调用，而不是创建无人维护的表。

## 插件复用

### LuckyguoAdmin

将目录复制到 `usr/plugins/LuckyguoAdmin/` 后，在后台启用即可。它向 `admin/header.php` 注入样式和脚本，并复用前台的 `luckyguo-theme` Cookie 与 localStorage。

验证方式：打开任意后台页面，切换主题后刷新页面，确认主题状态仍保持；停用插件后后台应恢复为 Typecho 默认样式。

### Sitemap

启用后会注册 `/sitemap.xml`。后台可选择文章、独立页面、分类、标签是否进入 Sitemap，以及更新频率。

```sh
curl -fsS https://blog.example.com/sitemap.xml | head
curl -sI https://blog.example.com/sitemap.xml
```

建议同时在站点根目录的 `robots.txt` 添加：

```text
Sitemap: https://blog.example.com/sitemap.xml
```

生成器会排除密码保护的文章与页面、空分类和空标签，单文件上限为 50,000 个 URL。接近该规模时，应改成 Sitemap Index 分片。

### Monitor

该插件不是通用服务器监控产品。它只读取本机 JSON 状态文件和独立监控数据库，并只向 Typecho 管理员显示面板。使用前必须修改 `panel.php` 中的状态文件、环境文件、数据库名和 CPU 核数，使其与自己的采集器一致。

最小安全要求：

- 监控数据库账户只授予 `SELECT` 权限；
- 环境文件仅允许 root 和需要读取它的服务访问；
- 状态 JSON 必须由可信采集器写入，不能暴露在 Web 根目录；
- 监控面板不应对未登录用户提供任何数据。

没有现成采集器时，不要启用此插件。

## 搜索系统部署

`LuckyguoSearch` 使用 Meilisearch 作为主搜索引擎。搜索服务不可用时，会使用参数化 `LIKE` 查询返回结果，保证前台搜索不会整体失效。完整重建使用“构建索引 -> 补偿增量 -> 围栏 -> 交换索引”的流程，避免读到半成品索引。

### 1. 部署 Meilisearch 和最小权限密钥

Meilisearch 不应直接暴露到公网。只监听回环地址或私有网络，并分别生成搜索、实时写入和重建任务需要的密钥。将密钥分别放入两个站点外配置文件：

`/etc/luckyguo-search-search.env`：

```ini
MEILI_URL=http://127.0.0.1:7700
SEARCH_KEY=replace-with-search-key
# 可选。留空时，内容变更只写入队列，等待完整重建。
WRITE_KEY=replace-with-write-key
MEILI_INDEX_LIVE=posts_live
```

`/etc/luckyguo-search.env`：

```ini
MEILI_URL=http://127.0.0.1:7700
REBUILD_KEY=replace-with-rebuild-key
TASK_KEY=replace-with-task-key
MEILI_INDEX_LIVE=posts_live
MEILI_INDEX_BUILD=posts_build
REBUILD_FENCE_TIMEOUT=30
```

配置文件至少应限制为：

```sh
sudo chown root:nginx /etc/luckyguo-search*.env
sudo chmod 640 /etc/luckyguo-search*.env
```

`nginx` 只是示例组名。应替换为实际 PHP-FPM 运行用户或组；重建脚本的运行用户还要有读取重建配置和创建锁文件的权限。

### 2. 初始化搜索表

以下 SQL 使用默认 `typecho_` 前缀。若你的 `config.inc.php` 使用不同前缀，必须先整体替换前缀，再执行。

```sql
CREATE TABLE typecho_luckyguo_changequeue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cid INT UNSIGNED NOT NULL,
  op VARCHAR(8) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  processed_at DATETIME(6) NULL,
  rebuild_batch_id VARCHAR(32) NULL,
  PRIMARY KEY (id),
  KEY idx_cid (cid), KEY idx_processed (processed_at), KEY idx_batch (rebuild_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE typecho_luckyguo_searchmeta (
  id INT UNSIGNED NOT NULL,
  search_index_version VARCHAR(32) NOT NULL,
  rebuild_batch_id VARCHAR(32) NULL,
  build_start DATETIME(6) NULL, build_end DATETIME(6) NULL,
  document_count INT UNSIGNED NULL, swap_task_uid BIGINT UNSIGNED NULL,
  rebuild_state ENUM('UNLOCKED','LOCKED','RECOVERY') NOT NULL DEFAULT 'UNLOCKED',
  rebuild_phase ENUM('IDLE','BUILD','FENCE','SWAP','POST_SWAP','ROLLBACK') NOT NULL DEFAULT 'IDLE',
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE typecho_luckyguo_rebuildtask (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id VARCHAR(32) NOT NULL, task_uid BIGINT UNSIGNED NULL,
  operation VARCHAR(16) NOT NULL, index_uid VARCHAR(128) NOT NULL,
  status VARCHAR(16) NOT NULL, submitted_at DATETIME(6) NOT NULL,
  finished_at DATETIME(6) NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_task_uid (task_uid),
  KEY idx_batch_status (batch_id, status), KEY idx_index_time (index_uid, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO typecho_luckyguo_searchmeta
  (id, search_index_version, rebuild_state, rebuild_phase, created_at)
VALUES (1, 'initial', 'UNLOCKED', 'IDLE', NOW(6))
ON DUPLICATE KEY UPDATE id = VALUES(id);
```

`typecho_luckyguo_searchlog` 是可选的搜索分析表；当前核心检索流程不依赖它。

### 3. 启用插件与重建任务

启用 `LuckyguoSearch` 后，文章发布、保存、状态变更和删除会写入变更队列。必须准备一个定时重建入口，加载 Typecho 的 `config.inc.php` 和本插件的 `RuntimeConfig`、`MeiliClient`、`Indexer`、`RebuildStore`、`RebuildService` 类，然后调用 `RebuildService::run()`。

推荐使用 systemd timer 或等效的单实例调度器，而不是让多个 cron 任务重叠运行。重建服务必须：

- 使用独立运行时目录保存锁文件；
- 设置超时并保留标准错误日志；
- 与 MySQL、Meilisearch 的启动顺序建立依赖；
- 失败后不删除变更队列；
- 确认 `rebuild_phase` 最终回到 `IDLE`。

首次上线建议手动运行一次完整重建，在 Meilisearch 中确认 `posts_live` 有文档后，再打开前台搜索。

### 4. 搜索验收与恢复

1. 新建一篇唯一关键词文章，确认能搜到。
2. 修改标题或正文，确认实时写入或下一次重建后可搜到新内容。
3. 将文章改为私密或删除，确认索引不再返回它。
4. 临时停止 Meilisearch，确认搜索仍返回 MySQL `LIKE` 降级结果。
5. 恢复 Meilisearch 并运行重建，确认队列和索引回到正常状态。

如果重建中断，不要直接删除 `posts_live` 或清空队列表。先检查 `typecho_luckyguo_searchmeta` 的状态和重建任务日志，再重新运行同一重建入口让恢复逻辑处理未完成任务。

## 统计数据与保留策略

访问明细会持续增长。推荐每日进行两项工作：把过去日期的 PV 桶合并到 bucket 0，并在保留期后把访客明细汇总为按日 UV 后删除。

关键原则：

- 使用数据库命名锁或等效互斥，避免两个任务同时汇总；
- 将“写入历史汇总”和“删除明细”放在同一事务；
- 任务可以安全重试，不能因重试重复累计；
- 删除前先确认历史汇总已成功写入；
- 使用数据库时间 `CURDATE()`，保持应用、数据库和 cron 的时区一致。

例如，保留 180 天明细前，可先运行一次只读检查：

```sql
SELECT vday, COUNT(*) AS uv
FROM typecho_luckyguo_visitors
WHERE vday < CURDATE() - INTERVAL 180 DAY
GROUP BY vday
ORDER BY vday;
```

先在备份库验证聚合结果，再把清理任务投入生产。统计不是业务主数据，但错误的删除同样难以恢复。

## SSRF 补丁

补丁修改 Typecho 1.3.0 的 `var/Widget/Service.php` 与 `var/Typecho/Common.php`，为 Pingback 和 Trackback 涉及的出站 URL 增加 HTTP(S) 协议、域名和 IP 地址检查。

应用前务必确认源码版本和工作区干净：

```sh
cd /var/www/typecho
git apply --check /path/to/typecho-1.3.0-ssrf-hardening.patch
git apply /path/to/typecho-1.3.0-ssrf-hardening.patch
```

回退方式：

```sh
git apply --reverse /path/to/typecho-1.3.0-ssrf-hardening.patch
```

补丁会拒绝回环、私有、链路本地、CGNAT、保留、组播以及不安全的 IPv6 字面量地址，并在请求前对域名解析到的地址做校验。

### 重要限制

这不是完整的 DNS 重绑定防护。补丁先解析并校验域名，但实际 HTTP 客户端仍可能在后续连接时再次解析；当前域名解析主要覆盖 A 记录，因此不能把它表述为“已经固定 IP 的完全防护”。要获得更强的防护，需要让 HTTP 客户端使用经校验后的地址建立连接，并在 TLS 场景保持正确的 SNI/Host 校验，同时检查 A 与 AAAA 记录。

应用后至少测试：正常公网 HTTP/HTTPS 地址可用；`localhost`、`127.0.0.1`、`10.0.0.0/8`、`172.16.0.0/12`、`192.168.0.0/16`、`100.64.0.0/10`、`169.254.0.0/16`、`::1`、`fc00::/7`、`fe80::/10` 和组播地址被拒绝。测试必须在隔离环境进行，不能以生产内网地址作为探测目标。

## 发布、回滚与备份

建议把“代码发布”和“数据备份”分开处理：代码由 Git 仓库和镜像仓库管理；博客数据由受控备份保存。若 Gitea 数据已通过镜像推送到 GitHub，则不必为了代码再备份 Gitea 仓库数据，但仍应备份 Gitea 配置与运行环境中不可再生的配置。

### 发布顺序

1. 拉取指定提交，检查 `git diff`。
2. 备份当前数据库与站点配置。
3. 同步主题或插件文件，保留旧版本目录或 Git 提交作为快速回退点。
4. 执行数据库迁移和 `php -l`。
5. 重载 PHP-FPM/Nginx，检查服务状态。
6. 对匿名前台、已登录后台、搜索、Sitemap 和错误日志做回归。

示例数据库备份命令：

```sh
mysqldump --single-transaction --routines --events --triggers \
  --default-character-set=utf8mb4 typecho | gzip > typecho-$(date +%F-%H%M%S).sql.gz
```

恢复前先创建当前状态的新备份。完整导入数据库会覆盖备份之后新增的文章、评论、设置和统计数据；若问题只涉及主题或插件，优先回退代码和关闭插件，不要轻易整库恢复。

### 不应提交到 Git 的内容

- `config.inc.php`、`.env`、密钥、证书和 SSH 私钥
- MySQL 导出、上传文件、缓存和日志
- 服务器快照、监控状态 JSON、备份凭据
- 真实域名、IP、内部端口和服务账户密码

仓库中的 `.gitignore` 仅是最后一道保护，提交前仍应检查暂存区内容。

## 验收与故障排查

### 通用验收

```sh
php -l /var/www/typecho/usr/themes/luckyguo/functions.php
php -l /var/www/typecho/usr/plugins/Sitemap/Plugin.php
nginx -t
curl -fsSI https://blog.example.com/
curl -fsS https://blog.example.com/sitemap.xml | head
```

当前仓库不包含完整 Typecho 核心和真实运行配置，因此不能在仓库自身完成端到端运行；应当在匹配的 Typecho 环境中执行上述验证。

### 常见问题

| 现象 | 首先检查 |
| --- | --- |
| 启用主题后统计全为 0 | 统计表是否存在、表前缀是否一致、PHP 错误日志是否有数据库异常 |
| 搜索自动降级为 LIKE | Meilisearch 是否可达、`SEARCH_KEY` 是否可读取、PHP 是否启用 curl |
| 发布文章后索引没有更新 | 变更队列是否写入、`WRITE_KEY` 是否配置、重建 timer 是否运行 |
| 重建一直无法结束 | `searchmeta` 的 phase/state、锁文件、任务日志、Meilisearch task 状态 |
| 后台样式没有变化 | 插件是否启用、静态资源路径是否正确、浏览器是否使用了旧缓存 |
| `/sitemap.xml` 返回 404 | Sitemap 插件是否启用、固定链接与路由缓存是否刷新 |
| 打补丁失败 | Typecho 版本或本地改动不同；不要强行应用，应先比对两个目标文件 |

## 相关文章

- [Typecho 博客部署实践：架构、配置与上线记录](https://blog.luckyguo.dpdns.org/archives/6/)
- [Typecho 安全加固记录：SSRF 漏洞（CVE-2026-7025）修复实践](https://blog.luckyguo.dpdns.org/archives/8/)
- [Typecho 站点美化与体验优化记录：视觉、统计与阅读体验](https://blog.luckyguo.dpdns.org/archives/9/)
- [Typecho 统计并发优化实践：分桶计数、缓存命中与历史桶归档](https://blog.luckyguo.dpdns.org/archives/10/)
- [Typecho 搜索系统优化实践：Meilisearch、双索引重建与故障降级](https://blog.luckyguo.dpdns.org/archives/11/)

## 许可证

本组件集以 GPL-2.0-or-later 发布。`plugins/Sitemap` 保留其上游 MIT 许可证；使用或再发布时应保留相应第三方许可证与署名。
