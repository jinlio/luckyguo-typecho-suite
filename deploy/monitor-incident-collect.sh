#!/usr/bin/env bash
# Reconcile site probe samples into durable incident state.
set -euo pipefail

CONFIG_FILE="${TYPECHO_SUITE_MONITOR_CONFIG:-/etc/typecho-suite/monitor.env}"
if [[ -r "$CONFIG_FILE" ]]; then
  # shellcheck disable=SC1090
  . "$CONFIG_FILE"
fi
: "${TYPECHO_ROOT:=/var/www/typecho}"
CONFIG_EXPORTER="${TYPECHO_SUITE_MONITOR_EXPORTER:-/usr/local/libexec/typecho-suite-monitor-config.php}"
if [[ -r "$CONFIG_EXPORTER" ]] && command -v php >/dev/null 2>&1; then
  # shellcheck disable=SC1090
  eval "$(TYPECHO_ROOT="$TYPECHO_ROOT" php "$CONFIG_EXPORTER" 2>/dev/null || true)"
fi
: "${MONITOR_DB:=monitor}"
: "${MONITOR_DB_HOST:=127.0.0.1}"
: "${MONITOR_DB_PORT:=3306}"
: "${MONITOR_RW_USER:=}"
: "${MONITOR_RW_PASS:=}"
: "${CNF:=/etc/typecho-suite/monitor-rw.cnf}"
: "${MAX_INCIDENT_TARGETS:=64}"
MAX_INCIDENT_TARGETS="${MAX_INCIDENT_TARGETS//[^0-9]/}"
MAX_INCIDENT_TARGETS=$((MAX_INCIDENT_TARGETS > 0 && MAX_INCIDENT_TARGETS <= 256 ? MAX_INCIDENT_TARGETS : 64))

exec 9>/run/typecho-suite-monitor-incident.lock
flock -n 9 || exit 0

TEMP_CNF=''
if [[ -n "$MONITOR_RW_USER" && -n "$MONITOR_RW_PASS" ]]; then
  STATE_DIR="${STATE_DIR:-/var/lib/typecho-suite/monitor}"
  mkdir -p "$STATE_DIR"
  TEMP_CNF="$STATE_DIR/.monitor-rw-incident.generated.cnf"
  umask 077
  printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
    "$MONITOR_RW_USER" "$MONITOR_RW_PASS" "$MONITOR_DB_HOST" "$MONITOR_DB_PORT" > "$TEMP_CNF"
  CNF="$TEMP_CNF"
  trap 'rm -f "$TEMP_CNF"' EXIT
fi

[[ -r "$CNF" ]] || exit 0
MYSQL=(mysql --defaults-extra-file="$CNF" --batch --skip-column-names "$MONITOR_DB")
rows=$("${MYSQL[@]}" -e "SELECT s.target, s.http_code, s.ttfb_ms FROM site_checks s INNER JOIN (SELECT target, MAX(ts) AS ts FROM site_checks GROUP BY target) latest ON latest.target = s.target AND latest.ts = s.ts ORDER BY s.target LIMIT ${MAX_INCIDENT_TARGETS};" 2>/dev/null || true)
[[ -n "$rows" ]] || exit 0

sql_escape() {
  local value="$1"
  value=${value//\\/\\\\}
  value=${value//\'/\\\'}
  printf '%s' "$value"
}

while IFS=$'\t' read -r target code ttfb; do
  [[ "$target" =~ ^[A-Za-z0-9_-]{1,32}$ ]] || continue
  [[ "$code" =~ ^[0-9]+$ && "$ttfb" =~ ^[0-9]+$ ]] || continue
  provider='local'
  case "$target" in
    origin-nginx|origin-app|public-loopback) provider="$target" ;;
  esac
  failed=0
  if (( code == 0 || code >= 500 )); then failed=1; fi
  fingerprint=$(printf '%s|%s' "$target" "$code" | sha256sum | awk '{print $1}')
  target_sql=$(sql_escape "$target")
  fingerprint_sql=$(sql_escape "$fingerprint")
  "${MYSQL[@]}" -e "INSERT INTO monitor_incidents (target,provider,status,failure_streak,success_streak,last_seen,last_code,last_ttfb_ms,last_fingerprint) VALUES ('$target_sql','$provider','closed',IF($failed=1,1,0),IF($failed=1,0,1),NOW(),$code,$ttfb,'$fingerprint_sql') ON DUPLICATE KEY UPDATE failure_streak=IF($failed=1,LEAST(255,failure_streak+1),0), success_streak=IF($failed=1,0,LEAST(255,success_streak+1)), status=IF($failed=1 AND failure_streak>=3,'open',IF($failed=0 AND success_streak>=2,'closed',status)), opened_at=IF($failed=1 AND failure_streak>=3 AND status<>'open',NOW(),opened_at), closed_at=IF($failed=0 AND success_streak>=2 AND status='open',NOW(),closed_at), last_seen=NOW(), last_code=$code, last_ttfb_ms=$ttfb, last_fingerprint='$fingerprint_sql';" 2>/dev/null || true
done <<< "$rows"
