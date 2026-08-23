#!/bin/bash
# Prune monitor history. Keep configuration outside the repository.
set -euo pipefail

CONFIG_FILE="${TYPECHO_SUITE_MONITOR_CONFIG:-/etc/typecho-suite/monitor.env}"
if [[ -r "$CONFIG_FILE" ]]; then
  # shellcheck disable=SC1090
  . "$CONFIG_FILE"
fi
CONFIG_EXPORTER="${TYPECHO_SUITE_MONITOR_EXPORTER:-/usr/local/libexec/typecho-suite-monitor-config.php}"
if [[ -r "$CONFIG_EXPORTER" ]] && command -v php >/dev/null 2>&1; then
  # shellcheck disable=SC1090
  eval "$(TYPECHO_ROOT="${TYPECHO_ROOT:-/var/www/typecho}" php "$CONFIG_EXPORTER" 2>/dev/null || true)"
fi
: "${CNF:=/etc/typecho-suite/monitor-rw.cnf}"
: "${MONITOR_DB:=monitor}"
: "${MONITOR_DB_HOST:=127.0.0.1}"
: "${MONITOR_DB_PORT:=3306}"
: "${MONITOR_RW_USER:=}"
: "${MONITOR_RW_PASS:=}"
: "${RAW_RETENTION_DAYS:=45}"
: "${ROLLUP_RETENTION_DAYS:=400}"

TEMP_CNF=''
if [[ -n "$MONITOR_RW_USER" && -n "$MONITOR_RW_PASS" ]]; then
  TEMP_CNF="${STATE_DIR:-/var/lib/typecho-suite/monitor}/.monitor-rw.generated.cnf"
  mkdir -p "$(dirname "$TEMP_CNF")"
  umask 077
  printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
    "$MONITOR_RW_USER" "$MONITOR_RW_PASS" "$MONITOR_DB_HOST" "$MONITOR_DB_PORT" > "$TEMP_CNF"
  CNF="$TEMP_CNF"
  trap 'rm -f "$TEMP_CNF"' EXIT
fi

if ! [[ "$RAW_RETENTION_DAYS" =~ ^[1-9][0-9]*$ && "$ROLLUP_RETENTION_DAYS" =~ ^[1-9][0-9]*$ ]]; then
  echo 'retention values must be positive integers' >&2
  exit 64
fi

mysql --defaults-extra-file="$CNF" "$MONITOR_DB" <<SQL
DELETE FROM metrics WHERE ts < NOW() - INTERVAL $RAW_RETENTION_DAYS DAY;
DELETE FROM site_checks WHERE ts < NOW() - INTERVAL $RAW_RETENTION_DAYS DAY;
DELETE FROM traffic_min WHERE ts < NOW() - INTERVAL $RAW_RETENTION_DAYS DAY;
DELETE FROM metrics_hourly WHERE bucket < NOW() - INTERVAL $RAW_RETENTION_DAYS DAY;
DELETE FROM traffic_hourly WHERE bucket < NOW() - INTERVAL $RAW_RETENTION_DAYS DAY;
DELETE FROM metrics_daily WHERE bucket < NOW() - INTERVAL $ROLLUP_RETENTION_DAYS DAY;
DELETE FROM traffic_daily WHERE bucket < NOW() - INTERVAL $ROLLUP_RETENTION_DAYS DAY;
SQL
