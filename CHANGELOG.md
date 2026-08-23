# Change Log

## 2026-08-23

- Established the repository change-record rule in `AGENTS.md`.
- Started the public-release UX hardening work; implementation entries will be added below as each area is completed.
- Fixed monitor rollups when legacy rows have `swap_total=0`: treat unavailable swap as 0 and clamp displayed swap usage to 0-100, preventing MySQL `ERROR 1264` writes to `TINYINT swapp`.
- Completed the production migration rehearsal with the reusable Suite theme and plugins while preserving the existing site configuration and retaining rollback backups. Database tables were copied into the Suite namespace, legacy tables were left intact, and the search rebuild/monitor schedules were switched to the Suite names.
- Migration verification covered HTTP status paths, RSS and sitemap responses, search empty state, PHP 8.3 syntax, the read-only installation diagnostic, service health, two clean monitor collections, desktop article TOC scrolling, mobile layout, and light/dark theme rendering. The admin login entry was reachable; authenticated setting-page clicks remain pending because no credentials were entered during the browser check.
- Fixed Typecho 1.3 output-style `pluginUrl()`/`adminUrl()` handling so SuiteAdmin assets and SuiteMonitor navigation/styles resolve to their plugin paths instead of `/admin.css` or an empty URL.
- Suppressed warnings when an optional legacy SuiteSearch env path is outside PHP `open_basedir`; configured backend settings continue to take precedence.
- Updated SuiteMonitor's header brand to read the active theme's site name, author handle, and avatar URL, with the generic Suite mark retained as a fallback.
- Added SuiteMonitor GUI fields for an optional custom monitor name, handle, and avatar URL; empty fields inherit the active theme automatically. Activation now removes legacy private panel registrations before registering `SuiteMonitor/panel.php`.
- Made SuiteMonitor activation idempotent so repeated enable/upgrade operations cannot create duplicate monitoring menu entries.
- Documented the new SuiteMonitor branding controls in both README files.
- Updated the theme empty state, context-aware article return link, mobile navigation, platform shortcut label, and comment guidance.
- Added the `useGravatar` theme setting and a local-first comment avatar renderer to avoid third-party requests by default.
- Updated SuiteMonitor charts to use real timestamp X coordinates, split on sampling gaps, expose data-quality text, and refresh chart SVGs through the admin polling endpoint.
- Added `deploy/check-install.sh` (read-only diagnosis) and `deploy/install-monitor.sh` (runtime/cron installation helper).
- Updated English and Chinese installation/configuration guidance for local-first comment avatars and the monitor installer/diagnostic flow.
- Extended the static checks to cover the new installation and diagnosis scripts.
- Added a keyboard skip link and applied the main-content landmark consistently across theme page templates.
- Added the two deployment helpers to the component tables in both README files.
- Added a SuiteMonitor configuration-check section for snapshot, database, collector freshness, and historical sampling health.
- Clarified the comment email hint so it does not imply an external avatar request when local avatars are selected.
- Added Escape-key closing and focus return for the mobile navigation menu.
- Hardened the comment avatar hook to read theme settings from the global Typecho options widget instead of relying on a protected comment-widget property.
- Preserved Typecho's already HTML-escaped Gravatar query string when the optional external avatar mode is enabled.

### Verification

- `./tests/static-check.sh` passed.
- `bash -n` passed for all monitor and installation shell scripts.
- `node --check themes/suite-default/site.js` passed.
- PHP lint passed in the server's PHP 8.3.33 environment for all PHP files under `themes`, `plugins`, and `deploy`.
- Runtime smoke tests passed for monitor gap segmentation and local/external comment-avatar modes using PHP 8.3.33.
- Chrome headless screenshots passed for the theme fixture at 375x812 and 1440x900; mobile menu-open and Escape-closed states were visually checked.
- The monitor installer passed a root smoke test on the server using isolated `/tmp` paths; no production paths were changed.
- Hardened chart rendering for the single-timestamp edge case so a valid point stays centered instead of collapsing against the Y axis.
- `git diff --check` passed.
- Restored SuiteMonitor's configurable top navigation with Console/Home/Landing defaults, optional custom links, and an opt-in footer repository link.
- Restored the 24-hour exception log with level filters, AJAX refresh, collector heartbeat status, and site-probe failures; added configurable file/journald sources, `log_events` schema, retention, installer, and cron integration.
- Production verification: created a rollback snapshot at `/var/backups/luckyguo/monitor-navigation-logs-20260823-195955`, deployed the monitor files, passed PHP 8.3.33 lint, confirmed a single `SuiteMonitor/panel.php` registration, confirmed the log heartbeat, and confirmed log rows continue to be written.
- Added the new GUI and log collector behavior to `README.md` and `README.zh-CN.md`.
- Refined the monitor log presentation with a compact toolbar, event total, clearer filters, and responsive table spacing; clarified the GUI labels for personal branding and the default Console/Home/Landing naming.
- Not yet verified: PHP 7.4 runtime behavior, browser screenshots at mobile/desktop sizes, and production deployment.
