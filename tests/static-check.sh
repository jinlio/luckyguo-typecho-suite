#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

git diff --check HEAD

if command -v php >/dev/null 2>&1; then
  while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find themes plugins deploy -type f -name '*.php' -print0)
else
  echo "php not found; PHP lint must be run in the target PHP environment" >&2
fi

if command -v node >/dev/null 2>&1; then
  node --check themes/koijournal/site.js
  node --check themes/koijournal/assets/mac-code.js
  node --check plugins/SuiteAdmin/admin.js
fi

for script in deploy/monitor-collect.sh deploy/monitor-log-collect.sh deploy/monitor-prune.sh deploy/check-install.sh deploy/install-monitor.sh; do
  bash -n "$script"
done

if rg -n -i 'dpdns|锦鲤小果|guoc|typecho_luckyguo|/etc/luckyguo|/var/lib/monitor|/usr/plugins/Monitor|/usr/themes/luckyguo|panel=Monitor%2Fpanel' \
  themes plugins deploy README.zh-CN.md --glob '!*.png' --glob '!*.jpg' --glob '!*.webp'; then
  echo 'personal deployment coupling detected' >&2
  exit 1
fi

if find themes/koijournal -maxdepth 1 -type f \( -name 'avatar.*' -o -name 'favicon*' -o -name 'apple-touch-icon*' -o -name 'journal-banner.*' -o -name 'article-cover.*' \) | grep -q .; then
  echo 'personal theme media must not be included in the reusable package' >&2
  exit 1
fi

if rg -n 'GROUP BY v\.cid[[:space:]]+ORDER' plugins/SuiteMonitor deploy; then
  echo 'top-post query must group by the selected title under ONLY_FULL_GROUP_BY' >&2
  exit 1
fi

if rg -n 'array_is_list|str_contains|str_starts_with|str_ends_with' themes plugins deploy; then
  echo 'PHP 8-only helpers detected; the project targets PHP 7.4+' >&2
  exit 1
fi

echo 'static checks passed'
