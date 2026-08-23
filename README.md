# Typecho Suite

[](#typecho-suite)

> **[English](README.md)** · **[简体中文](README.zh-CN.md)**

Reusable, configurable Typecho components for personal blogs and small content sites. It contains a neutral responsive theme, optional administrator skin, sitemap, Meilisearch integration, read-only monitoring, optional anonymous statistics, deployment examples, and a separate Typecho 1.3.0 SSRF hardening patch.

This repository contains no personal domain, author identity, production database, uploads, credentials, server keys, or complete Typecho core. Configure site-specific values in Typecho or in files outside the web root.

## Components

[](#components)

| Path | Purpose | Required |
| --- | --- | --- |
| `themes/suite-default` | Responsive theme, dark mode, article TOC, code blocks, branding settings | No plugin |
| `plugins/SuiteAdmin` | Optional administrator light/dark skin | No |
| `plugins/Sitemap` | Public `/sitemap.xml` route | No |
| `plugins/SuiteSearch` | Meilisearch search, MySQL fallback, rebuild queue | No |
| `plugins/SuiteMonitor` | Authenticated read-only monitoring panel | No |
| `deploy/create-suite-monitor.sql` | Optional monitor database schema | No |
| `deploy/create-suite-stats.sql` | Optional anonymous statistics schema | No |
| `deploy/install-monitor.sh` | Installs the monitor runtime and cron schedule | No |
| `deploy/check-install.sh` | Read-only installation and runtime diagnosis | No |

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

Target: Typecho 1.3.0, PHP 7.4+, MySQL 8.0+ with the `Mysqli` adapter, and Nginx or Apache. The theme works without custom tables. Search requires `Mysqli` for its fallback and rejects unsupported adapters explicitly. The optional search queue is detected automatically; without `create-suite-search.sql`, search still works through MySQL LIKE and live Meilisearch writes remain optional.

## Minimal installation

[](#minimal-installation)

export TYPECHO\_ROOT=/var/www/typecho
git clone https://github.com/jinlio/luckyguo-typecho-suite.git /tmp/typecho-suite
rsync -a /tmp/typecho-suite/themes/suite-default/ "$TYPECHO\_ROOT/usr/themes/suite-default/"
rsync -a /tmp/typecho-suite/plugins/Sitemap/ "$TYPECHO\_ROOT/usr/plugins/Sitemap/"

Select `suite-default` in Typecho Appearance and fill in its settings. To add avatar upload and avatar URL fields to Typecho's Profile page, apply `patches/typecho-1.3.0-personal-avatar.patch` to the matching Typecho 1.3.0 source tree first. Uploaded files are stored under `usr/uploads/avatars`; the URL is stored in the current user's personal options. Comment avatars use the bundled local default by default; enable `useGravatar` in the theme settings only when third-party avatar requests are acceptable. Back up `config.inc.php`, the database, `usr/themes`, `usr/plugins`, and `usr/uploads` before upgrades.

### Quick start

1. Install Typecho 1.3.0, PHP 7.4+, and MySQL 8.0+, then finish the normal Typecho setup. Back up `config.inc.php`, the database, and `usr/` first.
2. Copy the reusable files (replace the root path as needed):
   ```sh
   export TYPECHO_ROOT=/var/www/typecho
   rsync -a themes/suite-default/ "$TYPECHO_ROOT/usr/themes/suite-default/"
   rsync -a plugins/Sitemap/ "$TYPECHO_ROOT/usr/plugins/Sitemap/"
   rsync -a plugins/SuiteSearch/ "$TYPECHO_ROOT/usr/plugins/SuiteSearch/"
   rsync -a plugins/SuiteMonitor/ "$TYPECHO_ROOT/usr/plugins/SuiteMonitor/"
   ```
3. Enable `suite-default` under Appearance and fill in the theme settings.
4. Enable only the plugins you need, then use their graphical settings pages. Sitemap controls content types; SuiteSearch controls Meilisearch, indexing, sync, and fallback; SuiteMonitor controls paths, credentials, services, probes, and retention.
5. For monitoring, run `deploy/create-suite-monitor.sql` in a dedicated database, then run `sudo TYPECHO_ROOT=/var/www/typecho deploy/install-monitor.sh`. The installer places the collector outside the web root and installs the cron schedule. Use `deploy/check-install.sh` for a read-only diagnosis before or after installation.
6. For search queues or full rebuilds, run `deploy/create-suite-search.sql` and install the supplied systemd timer. Ordinary MySQL search does not need the queue tables.
7. Check the home page, an article, comments, mobile layout, `/sitemap.xml`, search, and the administrator-only monitor panel. Search failure does not block publishing, and monitor snapshots continue when the database is temporarily unavailable.

## Theme configuration

[](#theme-configuration)

The settings page controls site name, author name and handle, tagline, biography, homepage eyebrow/signature/list labels, article author and TOC labels, avatar, comment avatar source, homepage banner, article cover, homepage/code links, preset or custom accent color, cookie name/domain, default light/dark mode, page and reading widths, reading speed, uptime start time, statistics switch, homepage auxiliary modules, table-of-contents depth, reading metadata, reading progress, search entry, code enhancements, page motion, comment RSS, excerpt length, archive limit, and counter bucket count.

On first activation, the theme imports the Typecho site title and description plus the first administrator's display name, username, homepage, personal bio, and saved avatar URL. Existing theme settings are never overwritten. All theme behavior switches are graphical settings; users do not need to edit theme files. Custom accent colors use the native color picker and take effect only when the custom-color switch is enabled; disabling it restores the preset palette.

Leave the cookie domain empty for a host-only preference. Set it only when trusted subdomains need to share it. Image fields accept HTTP(S) URLs; a missing author avatar shows a neutral theme mark, while a missing banner or article cover is omitted. Comment avatars are local by default, so visitor email hashes are not sent to an external service unless `useGravatar` is enabled. Statistics are disabled by default and require `deploy/create-suite-stats.sql`.

## Optional plugins

[](#optional-plugins)

### SuiteAdmin

[](#suiteadmin)

Copy `plugins/SuiteAdmin` to `usr/plugins/SuiteAdmin` and enable it. Use the same cookie name/domain as the theme if frontend and admin preferences should be shared.

### Sitemap

[](#sitemap)

Enable `Sitemap`, choose content types, and validate with `curl -fsS https://blog.example.com/sitemap.xml`. Add the matching URL to `robots.txt`. Password-protected content and empty taxonomies are excluded; one sitemap is capped at 50,000 URLs.

### SuiteSearch

[](#suitesearch)

Install `deploy/create-suite-search.sql`, enable SuiteSearch, and fill in the Meilisearch URL, API keys, index names, automatic sync, and LIKE fallback switches in the plugin settings page. A blank password field keeps the existing key; enter a new value only when rotating credentials, or use the clear-keys checkbox to remove saved keys. Leave the URL empty or disable Meilisearch to use MySQL search only.

New installations do not need a search `.env` file. The legacy environment file remains supported as a fallback when backend settings have never been saved:

MEILI\_URL\=http://127.0.0.1:7700
SEARCH\_KEY\=replace-with-search-only-key
WRITE\_KEY\=
MEILI\_INDEX\_LIVE\=posts\_live

Set `TYPECHO_SUITE_SEARCH_CONFIG` for another path. Without valid Meilisearch configuration, the plugin falls back to parameterized MySQL `LIKE` search. Full rebuilds use `deploy/suite-search-rebuild.php` and the supplied systemd files, which prefer backend settings; never delete the live index or queue during recovery.

### SuiteMonitor

[](#suitemonitor)

Run `deploy/create-suite-monitor.sql` in a dedicated monitoring database. Run `sudo TYPECHO_ROOT=/var/www/typecho deploy/install-monitor.sh` to install the collector, log collector, pruner, exporter, and cron schedule. Configure the status path, log path, database credentials, service units, site probes, retention periods, default range, refresh interval, default theme, and optional monitor brand name/handle/avatar URL in the SuiteMonitor settings page. Empty brand fields inherit the active theme's site name, author handle, and avatar. Use `deploy/check-install.sh` when a panel is empty or stale. The collector exports saved backend settings at runtime.

Configure monitor navigation with one `key=label|target` entry per line in `navItems`. Targets may be `admin`, `site`, a configured site key, or an HTTP(S) URL. The default navigation is Console, Home, and Landing; entries whose targets are not configured are hidden. The optional footer repository is controlled by the display checkbox and `footerRepoUrl`, and is hidden by default. Configure `logSources` as `source=/absolute/path` entries and `logJournalUnits` for journald services; the log collector writes `log_events`, which powers the 24-hour filtered exception log.

All of these controls are in Typecho Admin -> Plugins -> SuiteMonitor -> Settings. The first three fields, Monitor brand name/handle/avatar URL, are the personal identity shown on the monitor page. The navigation textarea controls Console, Home, Landing, and custom links; the footer checkbox controls whether a repository link is shown. Empty brand fields inherit the active theme's site name, author handle, and avatar.

The legacy `/etc/typecho-suite/monitor.env` remains a compatibility fallback during upgrades; new installations do not need to edit it, and the sample cron uses the backend exporter by default. Blank backend password fields preserve existing passwords; use the password-management clear option to remove them. Passwords are never written to the status snapshot.

`SITE_TARGETS` accepts space-separated `key=host:port` entries, such as `blog=blog.example.com:80 docs=docs.example.com:80`. Enter display labels, public URLs, and service labels as one `key=value` per line; JSON saved by older versions remains supported. The panel is administrator-only; monitored services, hosts, paths, retention values, cookie settings, database DSN, table prefix, and CPU core count are all editable in the backend.

For an existing monitor database created by an earlier Suite version, run `deploy/create-monitor-rollups.sql` once before the new collector. It adds `swap_total` and recomputes rollups. New installations must use only `create-suite-monitor.sql`. Enable the panel's anonymous-statistics option only after `create-suite-stats.sql` has been installed and the monitor read-only account has access to the selected Typecho database.

## Upgrade, uninstall, and rollback

[](#upgrade-uninstall-and-rollback)

1. Back up the current theme, plugins, configuration, and database before any upgrade.
2. Stage the new files in a disposable Typecho instance and complete the
   release checks below before touching production.
3. After upgrading the theme or plugins, clear page caches and re-check the
   home page, articles, comments, admin pages, sitemap, search, and monitor
   permissions.
4. When removing a plugin, disable it in the Typecho panel first, then delete
   its directory. Statistics and search tables are not dropped automatically;
   migrate or delete them only after confirming they are no longer needed.
5. To roll back, restore the backup files and database. A database restore
   overwrites everything created after the backup, so it must run inside a
   maintenance window.

## Database, privacy, and security

[](#database-privacy-and-security)

SQL examples use the `typecho_` prefix; replace it consistently for another prefix. Statistics store client IP and User-Agent for daily deduplication, so review privacy notice and retention before enabling them. Keep credentials outside Git and the web root. Do not expose Meilisearch. Review the Typecho 1.3.0 SSRF patch against the exact source revision before applying it.

## Release checks

[](#release-checks)

git diff --check
bash -n deploy/monitor-collect.sh
bash -n deploy/monitor-prune.sh
node --check themes/suite-default/site.js
node --check themes/suite-default/assets/mac-code.js

Run `php -l` on every PHP file with the target PHP version, then install into a disposable Typecho instance. Test anonymous pages, RSS, comments, uploads, admin pages, Sitemap, search fallback, optional statistics, monitor access, default/custom prefixes, upgrade, and rollback. For SuiteMonitor, verify an empty `SITE_TARGETS`, multiple targets, unavailable log files, a zero-Swap host, and the read-only database account.

## License

[](#license)

The repository uses `LICENSE`. The Sitemap plugin retains its upstream MIT notice in `plugins/Sitemap/LICENSE`.
