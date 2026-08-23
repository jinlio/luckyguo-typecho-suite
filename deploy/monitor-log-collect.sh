#!/usr/bin/env bash
# Collect configured file/journald warnings and errors for SuiteMonitor.
# The script is intentionally source-agnostic: users choose paths and units in
# the SuiteMonitor settings page instead of editing this file.
set -uo pipefail

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
: "${STATE_DIR:=/var/lib/typecho-suite/monitor}"
: "${CNF:=/etc/typecho-suite/monitor-rw.cnf}"
: "${MONITOR_DB:=monitor}"
: "${MONITOR_DB_HOST:=127.0.0.1}"
: "${MONITOR_DB_PORT:=3306}"
: "${MONITOR_RW_USER:=}"
: "${MONITOR_RW_PASS:=}"
: "${LOG_JOURNAL_UNITS:=}"
: "${MAX_LOG_EVENTS:=300}"

mkdir -p "$STATE_DIR"
LOCK_FILE="${TYPECHO_SUITE_MONITOR_LOG_LOCK:-$STATE_DIR/.log-collect.lock}"
if command -v flock >/dev/null 2>&1; then
  exec 9>"$LOCK_FILE"
  flock -n 9 || exit 0
fi

TEMP_CNF=''
if [[ -n "$MONITOR_RW_USER" && -n "$MONITOR_RW_PASS" ]]; then
  TEMP_CNF="$STATE_DIR/.monitor-rw-log.generated.cnf"
  umask 077
  printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
    "$MONITOR_RW_USER" "$MONITOR_RW_PASS" "$MONITOR_DB_HOST" "$MONITOR_DB_PORT" > "$TEMP_CNF"
  CNF="$TEMP_CNF"
  trap 'rm -f "$TEMP_CNF"' EXIT
fi

SQLFILE="$STATE_DIR/.log-events.sql"
: > "$SQLFILE"

emit() {
  local ts="$1" source="$2" level="$3" message="$4"
  message=${message//$'\r'/}
  message=${message//$'\n'/ }
  message=${message//\\/\\\\}
  message=${message//\'/\\\'}
  message=${message:0:500}
  [[ -n "$message" ]] || return 0
  printf "('%s','%s','%s','%s'),\n" "$ts" "$source" "$level" "$message" >> "$SQLFILE"
}

classify() {
  local lower
  lower=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')
  if [[ "$lower" =~ (fatal|panic|critical|error|failed|failure|denied|refused|timeout|segfault|oom|invalid) ]]; then
    printf 'error\n'
  elif [[ "$lower" =~ (warn|warning|deprecated|retry) ]]; then
    printf 'warn\n'
  else
    printf 'info\n'
  fi
}

parse_file_line() {
  local source="$1" line="$2" ts="$3" message level
  ts="$3"
  message="$line"
  if [[ "$line" =~ ^([0-9]{4})/([0-9]{2})/([0-9]{2})[[:space:]]([0-9]{2}:[0-9]{2}:[0-9]{2})[[:space:]]\[([a-z]+)\][[:space:]](.*)$ ]]; then
    ts="${BASH_REMATCH[1]}-${BASH_REMATCH[2]}-${BASH_REMATCH[3]} ${BASH_REMATCH[4]}"
    message="${BASH_REMATCH[6]}"
  elif [[ "$line" =~ ^\[([0-9]{2})-([A-Za-z]{3})-([0-9]{4})[[:space:]]([0-9]{2}:[0-9]{2}:[0-9]{2})\][[:space:]](WARNING|ERROR|CRITICAL):[[:space:]]*(.*)$ ]]; then
    local month
    case "${BASH_REMATCH[2]}" in
      Jan) month=01;; Feb) month=02;; Mar) month=03;; Apr) month=04;; May) month=05;; Jun) month=06;;
      Jul) month=07;; Aug) month=08;; Sep) month=09;; Oct) month=10;; Nov) month=11;; Dec) month=12;; *) month=01;;
    esac
    ts="${BASH_REMATCH[3]}-$month-${BASH_REMATCH[1]} ${BASH_REMATCH[4]}"
    message="${BASH_REMATCH[6]}"
  elif [[ "$line" =~ ^\[?([0-9]{4}-[0-9]{2}-[0-9]{2})[T[:space:]]([0-9]{2}:[0-9]{2}:[0-9]{2}) ]]; then
    ts="${BASH_REMATCH[1]} ${BASH_REMATCH[2]}"
  fi
  level=$(classify "$line")
  [[ "$level" != info ]] && emit "$ts" "$source" "$level" "$message"
}

tail_incremental() {
  local source="$1" file="$2" posfile="$STATE_DIR/.logpos-${source//[^A-Za-z0-9_.-]/_}" current previous
  [[ -r "$file" ]] || return 0
  current=$(stat -c%s "$file" 2>/dev/null || stat -f%z "$file" 2>/dev/null || echo 0)
  previous=0
  [[ -r "$posfile" ]] && previous=$(cat "$posfile" 2>/dev/null || echo 0)
  [[ "$previous" =~ ^[0-9]+$ ]] || previous=0
  (( previous > current )) && previous=0
  if (( current > previous )); then
    while IFS= read -r line; do
      parse_file_line "$source" "$line" "$(date '+%Y-%m-%d %H:%M:%S')"
    done < <(tail -c +$((previous + 1)) "$file")
  fi
  printf '%s\n' "$current" > "$posfile"
}

# LOG_SOURCES_B64 is exported by the PHP settings exporter because a textarea
# contains newlines. Each line is source=/absolute/path.
if [[ -n "${LOG_SOURCES_B64:-}" ]] && command -v base64 >/dev/null 2>&1; then
  while IFS='=' read -r source file; do
    source="${source//[^A-Za-z0-9_.-]/}"
    file="${file# }"
    [[ -n "$source" && "$file" = /* ]] || continue
    tail_incremental "$source" "$file"
  done < <(printf '%s' "$LOG_SOURCES_B64" | base64 -d 2>/dev/null; printf '\n')
fi

# Journald is optional and only reads warning priority or higher.
LASTPOS="$STATE_DIR/.journal-log-pos"
LAST='1 minute ago'
[[ -r "$LASTPOS" ]] && LAST=$(cat "$LASTPOS" 2>/dev/null || echo '1 minute ago')
if command -v journalctl >/dev/null 2>&1 && [[ -n "$LOG_JOURNAL_UNITS" ]]; then
  SINCE=$(date -d "$LAST" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -d '1 minute ago' '+%Y-%m-%d %H:%M:%S')
  # Unit names are validated before being passed to journalctl.
  units=()
  for unit in $LOG_JOURNAL_UNITS; do
    [[ "$unit" =~ ^[A-Za-z0-9_.@-]+$ ]] && units+=("-u" "$unit")
  done
  if ((${#units[@]})); then
    while IFS= read -r line; do
      [[ "$line" =~ ^([0-9]{4}-[0-9]{2}-[0-9]{2})T([0-9]{2}:[0-9]{2}:[0-9]{2})[^[:space:]]*[[:space:]]+[^[:space:]]+[[:space:]]+([^:]+):[[:space:]]?(.*)$ ]] || continue
      ts="${BASH_REMATCH[1]} ${BASH_REMATCH[2]}"
      source="${BASH_REMATCH[3]%%[*}"
      message="${BASH_REMATCH[4]}"
      [[ "$source" =~ ^[A-Za-z0-9_.@-]+$ ]] || source=journald
      level=$(classify "$message")
      [[ "$level" != info ]] && emit "$ts" "$source" "$level" "$message"
    done < <(journalctl --since "$SINCE" --no-pager -o short-iso -p warning "${units[@]}" 2>/dev/null)
  fi
fi
date '+%Y-%m-%d %H:%M:%S' > "$LASTPOS"

if [[ -s "$SQLFILE" ]] && [[ -r "$CNF" ]]; then
  # Keep one minute bounded even when a log rotates or floods the host.
  head -n "$MAX_LOG_EVENTS" "$SQLFILE" | sed '$ s/,$//' | {
    read -r first || true
    [[ -n "${first:-}" ]] || exit 0
    {
      printf 'INSERT IGNORE INTO log_events (ts, source, level, message) VALUES\n%s\n' "$first"
      cat
    } | mysql --defaults-extra-file="$CNF" "$MONITOR_DB" 2>/dev/null || true
  }
fi
rm -f "$SQLFILE"
printf '%s\n' "$(date '+%Y-%m-%d %H:%M:%S')" > "$STATE_DIR/log-heartbeat"
chmod 0640 "$STATE_DIR/log-heartbeat" 2>/dev/null || true
exit 0
