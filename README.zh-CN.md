# Typecho Suite

[](#typecho-suite)

> **[English](README.md)** · **[简体中文](README.zh-CN.md)**

一组可复用、可配置的 Typecho 主题和插件，适合个人博客、小型内容站点及自托管部署。它包含一套中性响应式主题、可选的后台皮肤、站点地图、Meilisearch 集成、只读监控面板、可选的匿名访问统计、部署示例，以及一份独立的 Typecho 1.3.0 SSRF 加固补丁。

本仓库不包含任何个人域名、作者身份、生产数据库、上传文件、凭据、服务器密钥或完整 Typecho 核心代码。请在 Typecho 后台或站点根目录之外的文件中配置站点专属值。

## 包含内容

[](#components)

| 路径 | 作用 | 必需 |
| --- | --- | --- |
| `themes/suite-default` | 响应式主题、深色模式、文章目录、代码块和品牌配置 | 无插件依赖 |
| `plugins/SuiteAdmin` | 可选的 Typecho 后台浅色/深色皮肤 | 否 |
| `plugins/Sitemap` | `/sitemap.xml` 站点地图 | 否 |
| `plugins/SuiteSearch` | Meilisearch 搜索、MySQL LIKE 降级和重建队列 | 否 |
| `plugins/SuiteMonitor` | 需要管理员登录的只读监控面板 | 否 |
| `deploy/create-suite-monitor.sql` | 可选监控数据库表结构 | 否 |
| `deploy/create-suite-stats.sql` | 可选匿名统计表结构 | 否 |

## 截图展示

[](#screenshots)

以下截图使用公开版组件的中性演示内容生成；监控数值为模拟数据，不代表任何生产服务器。

### 主题首页

[](#theme-home)

![Suite Default 主题首页浅色模式](docs/screenshots/blog-home-top-light.jpg)

![Suite Default 主题首页深色模式](docs/screenshots/blog-home-top-dark.jpg)

### 文章阅读与右侧目录

[](#article-reading-and-table-of-contents)

![文章阅读布局与右侧目录浅色模式](docs/screenshots/search-article-body-light.jpg)

![文章阅读布局与右侧目录深色模式](docs/screenshots/search-article-body-dark.jpg)

### SuiteMonitor 后台资源监控

[](#suitemonitor-administration-panel)

![SuiteMonitor 资源概览浅色模式](docs/screenshots/monitor-overview-light.jpg)

![SuiteMonitor 资源概览深色模式](docs/screenshots/monitor-overview-dark.jpg)

## 运行要求

[](#support)

目标环境为 Typecho 1.3.0、PHP 7.4 及以上、MySQL 8.0 及以上（`Mysqli` 适配器），以及 Nginx 或 Apache。主题不依赖自定义数据表；启用统计时才需要创建 `suite_*` 表。搜索插件的 MySQL 降级路径需要 `Mysqli`，并在不支持的适配器上显式拒绝。搜索队列表会自动探测；未创建 `create-suite-search.sql` 时，搜索仍可使用 MySQL LIKE，Meilisearch 实时写入也可以保持关闭。

## 安装

[](#minimal-installation)

```sh
export TYPECHO_ROOT=/var/www/typecho
git clone https://github.com/jinlio/luckyguo-typecho-suite.git /tmp/typecho-suite
rsync -a /tmp/typecho-suite/themes/suite-default/ "$TYPECHO_ROOT/usr/themes/suite-default/"
rsync -a /tmp/typecho-suite/plugins/Sitemap/ "$TYPECHO_ROOT/usr/plugins/Sitemap/"
```

在 Typecho 后台的“外观”中启用 `suite-default`，然后填写主题配置。若希望在“个人设置”中上传头像或填写头像地址，请先按目标 Typecho 1.3.0 源码版本应用 `patches/typecho-1.3.0-personal-avatar.patch`；上传文件会保存到 `usr/uploads/avatars`，头像地址保存在当前用户的个人选项中，留空时继续使用邮箱对应的 Gravatar。评论头像抓取失败时会回退到主题内置默认头像。升级前先备份 `config.inc.php`、数据库、`usr/themes`、`usr/plugins` 和 `usr/uploads`。

## 主题配置

[](#theme-configuration)

主题配置页可设置站点名称、作者信息、副标题、简介、关于页引导语/方向/技术栈/状态、头像、首页横幅、文章封面、站点与代码仓库链接、强调色、主题 Cookie、站点起始时间和访问统计。

Cookie 域名留空时只在当前主机生效；只有在明确需要多个可信子域共享主题状态时才填写父域。图片字段支持 HTTP(S) 地址；头像留空显示中性主题标识，横幅和文章封面留空时不显示。统计默认关闭；启用时执行 `deploy/create-suite-stats.sql`，并根据实际表前缀替换 `typecho_`。

## 插件配置

[](#optional-plugins)

### SuiteAdmin

[](#suiteadmin)

复制到 `usr/plugins/SuiteAdmin` 后启用。若希望后台与前台共享主题状态，请填写与主题相同的 Cookie 名称和域名。

### Sitemap

[](#sitemap)

启用后在插件设置中选择文章、页面、分类和标签。验证地址：`https://example.com/sitemap.xml`。在站点根目录的 `robots.txt` 中声明同一个 Sitemap 地址。密码保护内容和空分类/标签会被排除，单个 Sitemap 最多输出 50,000 个 URL。

### SuiteSearch

[](#suitesearch)

按需执行 `deploy/create-suite-search.sql`。将搜索配置放在站点根目录之外，例如 `/etc/typecho-suite/search.env`：

```ini
MEILI_URL=http://127.0.0.1:7700
SEARCH_KEY=replace-with-search-only-key
WRITE_KEY=
MEILI_INDEX_LIVE=posts_live
```

使用 `TYPECHO_SUITE_SEARCH_CONFIG` 可指定其他路径。Meilisearch 配置缺失或不可用时，插件自动降级到参数化 MySQL `LIKE` 查询。完整重建使用 `deploy/suite-search-rebuild.php` 和配套 systemd 文件，恢复时不要直接删除线上索引或队列。

### SuiteMonitor

[](#suitemonitor)

先在独立监控数据库中执行 `deploy/create-suite-monitor.sql`。将 `deploy/examples/monitor.env.example` 复制到 `/etc/typecho-suite/monitor.env`，仅授予 root 与 PHP 运行组读取权限；分别创建采集器读写账号和面板只读账号。将 `monitor-collect.sh`、`monitor-prune.sh` 安装在 Web 根目录之外，再按实际安装路径调整 `deploy/monitor.cron`。

`SITE_TARGETS` 使用空格分隔的 `key=host:port`，例如 `blog=blog.example.com:80 docs=docs.example.com:80`。在插件配置中填写同名目标的显示名称和可选公开链接。服务、主机、路径、保留天数、Cookie、监控 DSN、表前缀和 CPU 核数均由部署配置决定；监控面板仅允许管理员访问。

已存在旧版监控库时，先执行一次 `deploy/create-monitor-rollups.sql`，它会补建 `swap_total` 字段并重算汇总数据；新安装只需执行 `create-suite-monitor.sql`。只有在已安装 `create-suite-stats.sql`，且监控只读账号被授予对应 Typecho 数据库访问权限后，才开启面板里的匿名访问统计。

## 升级、卸载和回滚

[](#upgrade-uninstall-and-rollback)

1. 升级前备份当前主题、插件、配置文件和数据库。
2. 先在一次性 Typecho 实例覆盖文件并完成下方校验，再同步到生产目录。
3. 升级主题或插件后清理页面缓存，并检查首页、文章、评论、后台、Sitemap、搜索和监控权限。
4. 卸载插件时先在后台停用，再删除对应目录；统计和搜索数据表不会自动删除，确认不再需要后再手动迁移或删除。
5. 发生问题时恢复备份文件和数据库。数据库恢复会覆盖备份之后新增的内容，必须在维护窗口执行。

## 数据库、隐私与安全

[](#database-privacy-and-security)

SQL 示例使用 `typecho_` 前缀；如需更换前缀请在所有示例中保持替换一致。统计会保存每日客户端 IP 与 User-Agent 用于访客去重；启用前请补充隐私说明并设置保留策略。凭据应保存在 Git 与站点根目录之外。不要把 Meilisearch 对公网开放。应用 Typecho 1.3.0 SSRF 补丁前，请先针对确切的源码版本核对。

## 校验

[](#release-checks)

```sh
./tests/static-check.sh
git diff --check
bash -n deploy/monitor-collect.sh
bash -n deploy/monitor-prune.sh
node --check themes/suite-default/site.js
node --check themes/suite-default/assets/mac-code.js
```

目标 PHP 环境还应对每个 PHP 文件执行 `php -l`，并在一次性 Typecho 实例中验证默认表前缀、自定义表前缀、统计关闭、MySQL 搜索降级、Sitemap、监控管理员权限、升级和回滚。SuiteMonitor 还应验证空和多个 `SITE_TARGETS`、日志文件不可读、Swap 为零的主机，以及只读数据库账号。

## 许可证

[](#license)

项目使用仓库中的 `LICENSE`。Sitemap 插件保留上游 MIT 许可和署名文件。
