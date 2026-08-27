# Change Log

## 2026-08-27

- Optimized the Typecho post-editor category selector: selecting a child category now selects its parent chain, while clearing a parent category clears every descendant selection.

- Fixed theme asset handling when site-specific avatar, banner, or cover URLs point to files that are no longer present on the current deployment. Same-origin missing files now fall back to the theme default instead of rendering broken images; external user-provided URLs remain untouched.
- Restored and verified the site's configured avatar, homepage banner, and article cover on production. Home, article, About, archives, categories, category, search, 404, RSS, and Sitemap smoke checks completed without new PHP/Nginx errors; 375px light/dark checks show no horizontal overflow and all same-origin images decode successfully.

- Replaced the planned navigation bridge with the new `SuiteCore` capability router. KoiJournal now declares stable page capability IDs, exposes GUI toggles for Home/Categories/Archives/About, and renders the theme-owned `/categories/` overview through its handler and `categories.php` template.
- Added public-category sitemap integration independent of navigation visibility, filtered to visible published posts with direct category counts, and documented the SuiteCore install, route lifecycle, and navigation behavior in both README files.
- Verification: `git diff --check`, JavaScript syntax check, static checks, target-server PHP lint, production `/categories/`/navigation/Sitemap HTTP checks, and post-request Nginx error-log review.

- Added a native Typecho post-editor checkbox for pinning articles; pinned posts use the built-in contents.order field and appear before regular posts while preserving date order.
- Added a visible 置顶 marker to the homepage list and documented the editor workflow.
- Verification: PHP lint, static checks, fresh-post save/publish field handling, homepage ordering, and mobile/desktop smoke checks.

## 2026-08-26

- Reduced low-value tag archive URLs in the Sitemap plugin with a configurable minimum post count (default: 2); the existing tag checkbox remains the global include/exclude switch.
- Applied the same threshold to the homepage tag cloud and marked below-threshold tag archives `noindex,follow`, keeping discovery and indexing behavior consistent.
- Verification: `git diff --check`, PHP lint on the target server, static checks, and Sitemap/tag archive URL-count checks.

## 2026-08-24

- Improved theme SEO metadata: added configurable homepage SEO title/description, `BlogPosting`/`ProfilePage` JSON-LD, article publication/update metadata, Twitter title/description, locale, and breadcrumbs.
- Added `noindex,follow` for search results, removed custom canonical output for 404 responses, and made archive pagination canonicalize to its own URL while preserving Typecho's native single-entry canonical.
- Added optional `seoDescription` / `metaDescription` entry fields for search snippets, stable avatar dimensions, and documented the new behavior in both README files.
- Verification: `git diff --check`, PHP lint on the target server, static checks, and HTTP metadata checks for home, article, search, pagination, and 404 routes.

## 2026-08-24

- Unified the permalink workflow around Typecho's native `设置 → 永久链接` page. SuiteAdmin now appends the slug health table to that page through the admin footer hook, removes the duplicate standalone panel menu entry, and keeps the native permalink form as the only rule editor.
- Added responsive health-table styling for desktop and mobile layouts, including single-line status badges and safe wrapping for long slugs.

## 2026-08-23

- Deployed the theme UX/SEO update to the production `suite-default` theme after creating rollback archives. Pre-filled the new About/SEO settings for the site, added official Simple Icons URLs for the configurable stack cards, corrected Typecho's output-only site URL handling, and verified PHP 8.3 lint plus 200 responses for home/About/article pages and the 1200x630 cover asset.
- Expanded `themes/suite-default` with a fuller configurable About page, default tag-cloud widget with zero-count category filtering, per-entry thumbnail support, share controls, canonical/OG/favicon/Sitemap metadata, homepage JSON-LD, lazy QR imagery, and a bundled 1200x630 default social cover. Updated both README files with the new settings. Verification: `git diff --check` and JavaScript syntax check passed; PHP lint requires the target server runtime because PHP is not installed on this workstation.
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
- Documented the exact Typecho Admin -> Plugins -> SuiteMonitor -> Settings location for personal branding, navigation, and footer controls in both README files.
- Added a dedicated theme setting for the long About-page introduction, reduced chart redraw flicker by updating SVGs only after a new sample, relaxed false gap detection for normal bucket drift, matched monitor avatar corners to the homepage, and standardized package author metadata to `luckyguo`.
- Exposed the full “一些关于我的事” block as three explicit theme settings: title, subtitle, and detailed introduction.
- Fixed monitor trend lines appearing broken despite valid hover data: the SVG entrance animation now uses normalized `pathLength=1` values instead of a fixed pixel dash length, which also prevents long charts from repeating visible gaps.
- Not yet verified: PHP 7.4 runtime behavior, browser screenshots at mobile/desktop sizes, and production deployment.
