#!/bin/bash
# Prune monitor history. Keep configuration outside the repository.
set -euo pipefail

CONFIG_FILE="${TYPECHO_SUITE_MONITOR_CONFIG:-/etc/typecho-suite/monitor.env}"
if [[ -r "$CONFIG_FILE" ]]; then
  # shellcheck disable=SC1090
  . "$CONFIG_FILE"
fi
: "${CNF:=/etc/typecho-suite/monitor-rw.cnf}"
: "${MONITOR_DB:=monitor}"
: "${RAW_RETENTION_DAYS:=45}"
: "${ROLLUP_RETENTION_DAYS:=400}"

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
