# Luckyguo Typecho Suite

Reusable Typecho components developed for the Luckyguo site. This repository keeps the theme, related plugins, and the Typecho 1.3.0 SSRF hardening patch in one versioned bundle because the components share theme state, database conventions, and deployment assumptions.

## Contents

- `plugins/LuckyguoAdmin`: shared light/dark theme for the Typecho administrator.
- `plugins/LuckyguoSearch`: Meilisearch-backed search with a database fallback and rebuild queue.
- `plugins/Monitor`: read-only monitoring dashboard for site and server metrics.
- `plugins/Sitemap`: public content sitemap route. The upstream MIT license is retained in the plugin directory.
- `themes/luckyguo`: the Luckyguo Typecho theme and its static assets.
- `patches/typecho-1.3.0-ssrf-hardening.patch`: hardening for Typecho 1.3.0 pingback and host validation.

## Installation

Copy the plugin directories into `usr/plugins/` and the theme into `usr/themes/luckyguo/` in a Typecho installation. Enable the plugins from the Typecho administrator and select the theme from the theme settings.

Apply the SSRF patch from the root of a matching Typecho 1.3.0 checkout:

```sh
git apply --check patches/typecho-1.3.0-ssrf-hardening.patch
git apply patches/typecho-1.3.0-ssrf-hardening.patch
```

The patch rejects loopback, private, link-local, CGNAT, reserved, multicast, and unsafe IPv6 targets before outbound pingback or trackback requests. It also validates DNS-resolved addresses to reduce DNS rebinding exposure.

## Integration Notes

`LuckyguoSearch` expects a configured Meilisearch endpoint and a rebuild worker. `Monitor` expects the site-side collector and its database schema. Those infrastructure services and credentials are intentionally not included in this repository.

Do not commit `config.inc.php`, environment files, database dumps, uploads, generated caches, server keys, or backup credentials.

## Related Write-Ups

- [Typecho blog deployment: architecture, configuration, and release notes](https://blog.luckyguo.dpdns.org/archives/6/)
- [Typecho SSRF hardening: CVE-2026-7025 remediation](https://blog.luckyguo.dpdns.org/archives/8/)
- [Theme and reading-experience improvements](https://blog.luckyguo.dpdns.org/archives/9/)
- [Statistics concurrency: sharded counters, caching, and retention](https://blog.luckyguo.dpdns.org/archives/10/)
- [Search architecture: Meilisearch, dual indexes, and graceful fallback](https://blog.luckyguo.dpdns.org/archives/11/)

## License

The bundle is distributed under GPL-2.0-or-later. Third-party notices and the original MIT license for the Sitemap plugin are kept alongside their respective sources.
