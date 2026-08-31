#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

# Local development machines do not always have every target-runtime tool,
# therefore the script keeps a warning-only mode by default.  CI and release
# jobs set REQUIRE_TOOLCHAIN=1 so a missing checker cannot produce a false
# green build.
require_toolchain="${REQUIRE_TOOLCHAIN:-0}"
if [[ "${CI:-}" == "true" ]]; then
  require_toolchain=1
fi

require_command() {
  local command_name="$1"
  if command -v "$command_name" >/dev/null 2>&1; then
    return 0
  fi
  if [[ "$require_toolchain" == "1" ]]; then
    echo "required command not found: $command_name" >&2
    exit 1
  fi
  echo "warning: command not found: $command_name" >&2
  return 1
}

git diff --check HEAD

if require_command php; then
  while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find themes plugins deploy -type f -name '*.php' -print0)
else
  echo "php not found; PHP lint must be run in the target PHP environment" >&2
fi

if require_command node; then
  node --check themes/koijournal/site.js
  node --check themes/koijournal/assets/mac-code.js
  node --check plugins/SuiteAdmin/admin.js
else
  echo "node not found; JavaScript checks must be run in the target environment" >&2
fi

shell_scripts=(
  deploy/monitor-collect.sh
  deploy/monitor-log-collect.sh
  deploy/monitor-incident-collect.sh
  deploy/monitor-prune.sh
  deploy/check-install.sh
  deploy/install-monitor.sh
  deploy/release-prepare.sh
  tests/static-check.sh
)

for script in "${shell_scripts[@]}"; do
  [[ -f "$script" ]] || { echo "missing shell script: $script" >&2; exit 1; }
  bash -n "$script"
done

if [[ -f composer.json ]]; then
  if require_command composer; then
    composer validate --no-check-publish --strict
  else
    echo "composer not found; dependency manifest validation must run in CI" >&2
  fi
fi

if require_command shellcheck; then
  # Existing scripts intentionally support older POSIX utilities; only actual
  # ShellCheck errors block a build while advisory warnings remain visible.
  shellcheck -x -S error --shell=bash "${shell_scripts[@]}"
else
  echo "shellcheck not found; shell checks must be run in the target environment" >&2
fi

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

if rg -n 'ips\[|JSON_TABLE\(.*top_ips|Top client IP|Top 客户端 IP' deploy/monitor-collect.sh plugins/SuiteMonitor/panel.php; then
  echo 'monitor runtime must not collect or render raw client IPs' >&2
  exit 1
fi

if rg -n 'api\.qrserver\.com|api\.qrcode' themes plugins deploy; then
  echo 'remote QR-code service detected; QR generation must remain same-origin' >&2
  exit 1
fi

if rg -n 'array_is_list|str_contains|str_starts_with|str_ends_with' themes plugins deploy; then
  echo 'PHP 8-only helpers detected; the project targets PHP 7.4+' >&2
  exit 1
fi

if rg -n 'zhzz20041117|GGJJ|8\.136\.148\.185' themes plugins deploy README*.md; then
  echo 'private credentials or production address detected' >&2
  exit 1
fi

echo 'static checks passed'
