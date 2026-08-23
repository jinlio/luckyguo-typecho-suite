#!/bin/bash
# monitor-collect.sh — 每分钟由 cron/systemd 以 root 执行
# New installations use SuiteMonitor backend settings exported by PHP.
# The external env file remains a legacy upgrade fallback.
set -euo pipefail

CONFIG_FILE="${TYPECHO_SUITE_MONITOR_CONFIG:-/etc/typecho-suite/monitor.env}"
if [[ -r "$CONFIG_FILE" ]]; then
  # shellcheck disable=SC1090
  . "$CONFIG_FILE"
fi
# New installations configure these values in the SuiteMonitor admin page.
CONFIG_EXPORTER="${TYPECHO_SUITE_MONITOR_EXPORTER:-/usr/local/libexec/typecho-suite-monitor-config.php}"
if [[ -r "$CONFIG_EXPORTER" ]] && command -v php >/dev/null 2>&1; then
  # shellcheck disable=SC1090
  eval "$(TYPECHO_ROOT="${TYPECHO_ROOT:-/var/www/typecho}" php "$CONFIG_EXPORTER" 2>/dev/null || true)"
fi
: "${STATE_DIR:=/var/lib/typecho-suite/monitor}"
: "${STATUS_FILE:=$STATE_DIR/status.json}"
: "${LOG:=/var/log/nginx/access.log}"
: "${CNF:=/etc/typecho-suite/monitor-rw.cnf}"
: "${MONITOR_DB:=monitor}"
: "${MONITOR_DB_HOST:=127.0.0.1}"
: "${MONITOR_DB_PORT:=3306}"
: "${MONITOR_RW_USER:=}"
: "${MONITOR_RW_PASS:=}"
: "${SERVICE_UNITS:=nginx php-fpm mysqld}"
# Space-separated key=host:port entries. Keys are persisted in site_checks.
: "${SITE_TARGETS:=}"
# Configure these when the web server needs access to the JSON snapshot.
: "${STATUS_OWNER:=}"
: "${STATUS_GROUP:=}"
: "${STATUS_MODE:=0640}"

mkdir -p "$STATE_DIR"

exec 9>/run/monitor-collect.lock
flock -n 9 || exit 0

# Backend settings can provide a dedicated write account. Generate a short-lived
# client file so the password does not appear in the process list or snapshot.
TEMP_CNF=''
if [[ -n "$MONITOR_RW_USER" && -n "$MONITOR_RW_PASS" ]]; then
  TEMP_CNF="$STATE_DIR/.monitor-rw.generated.cnf"
  umask 077
  {
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
      "$MONITOR_RW_USER" "$MONITOR_RW_PASS" "$MONITOR_DB_HOST" "$MONITOR_DB_PORT"
  } > "$TEMP_CNF"
  CNF="$TEMP_CNF"
  trap 'rm -f "$TEMP_CNF"' EXIT
fi

TS=$(date '+%Y-%m-%d %H:%M:00')
NOW=$(date +%s)

# ---------- 系统指标 ----------
read -r LOAD1 LOAD5 LOAD15 PROCS _ < /proc/loadavg
TOTAL_PROCS=${PROCS#*/}
UP_MIN=$(( $(cut -d. -f1 /proc/uptime) / 60 ))

read -r _ U1 U2 U3 U4 U5 U6 U7 U8 _ < /proc/stat
CPU_TOT=$((U1+U2+U3+U4+U5+U6+U7+U8))
CPU_IDLE=$((U4+U5))
CPU_PCT=0
if [[ -f "$STATE_DIR/.cpustate" ]]; then
  read -r PT PI < "$STATE_DIR/.cpustate" || true
  DT=$((CPU_TOT-PT)); DI=$((CPU_IDLE-PI))
  if (( DT > 0 && DI >= 0 )); then CPU_PCT=$(( (100*(DT-DI))/DT )); fi
fi
echo "$CPU_TOT $CPU_IDLE" > "$STATE_DIR/.cpustate"

MEM_TOTAL=$(awk '/^MemTotal:/{print int($2/1024)}' /proc/meminfo)
MEM_AVAIL=$(awk '/^MemAvailable:/{print int($2/1024)}' /proc/meminfo)
MEM_USED=$((MEM_TOTAL-MEM_AVAIL))
SWAP_TOTAL=$(awk '/^SwapTotal:/{print int($2/1024)}' /proc/meminfo)
SWAP_FREE=$(awk '/^SwapFree:/{print int($2/1024)}' /proc/meminfo)
SWAP_USED=$((SWAP_TOTAL-SWAP_FREE))

read -r DISK_TOTAL DISK_USED <<< "$(df -m / | awk 'NR==2{print $2, $3}')"

read -r RX TX <<< "$(awk -F'[: ]+' 'NF>11 && $2!="lo"{rx+=$3; tx+=$11} END{print rx+0, tx+0}' /proc/net/dev)"
NET_RX=0; NET_TX=0
if [[ -f "$STATE_DIR/.netstate" ]]; then
  read -r PN PRX PTX < "$STATE_DIR/.netstate" || true
  DT=$((NOW-PN))
  if (( DT > 0 )); then
    NET_RX=$(( (RX-PRX)/DT/1024 ))
    NET_TX=$(( (TX-PTX)/DT/1024 ))
    if (( NET_RX < 0 )); then NET_RX=0; fi
    if (( NET_TX < 0 )); then NET_TX=0; fi
  fi
fi
echo "$NOW $RX $TX" > "$STATE_DIR/.netstate"

# ---------- 服务状态 ----------
SVC=""
for S in $SERVICE_UNITS; do
  if [[ ! "$S" =~ ^[A-Za-z0-9_.@-]+$ ]]; then
    continue
  fi
  A=$(systemctl is-active "$S" 2>/dev/null || true)
  if [[ -z "$A" ]]; then A="unknown"; fi
  SVC+="\"$S\":\"$A\","
done
SVC="{${SVC%,}}"

# ---------- Site probes (local HTTP with a configurable Host header) ----------
probe_local() {
  local OUT CODE TMS
  if [[ -z "$1" ]]; then echo "0 0"; return; fi
  OUT=$(curl -o /dev/null -s -m 5 -H "Host: $1" -w '%{http_code} %{time_total}' "http://127.0.0.1:$2/" || echo "000 5.000")
  CODE=${OUT% *}; TMS=${OUT#* }
  if ! [[ "$CODE" =~ ^[0-9]+$ ]]; then CODE=0; fi
  awk -v t="$TMS" -v c="$CODE" 'BEGIN{printf "%d %d\n", c, t*1000}'
}
SITE_ROWS=''
SITE_JSON=''
for TARGET in $SITE_TARGETS; do
  KEY=${TARGET%%=*}
  ADDRESS=${TARGET#*=}
  if [[ "$KEY" == "$TARGET" || ! "$KEY" =~ ^[A-Za-z0-9_-]{1,32}$ || ! "$ADDRESS" =~ ^[^:[:space:]]+:[0-9]{1,5}$ ]]; then
    echo "invalid SITE_TARGETS entry: $TARGET" >&2
    continue
  fi
  HOST=${ADDRESS%:*}
  PORT=${ADDRESS##*:}
  read -r SITE_CODE SITE_TTFB <<< "$(probe_local "$HOST" "$PORT")"
  SITE_ROWS+="('$TS','$KEY',$SITE_CODE,$SITE_TTFB),"
  SITE_JSON+="\"$KEY\":{\"code\":$SITE_CODE,\"ttfb_ms\":$SITE_TTFB},"
done
SITE_ROWS=${SITE_ROWS%,}
SITE_JSON="{${SITE_JSON%,}}"

# ---------- nginx 流量增量聚合 ----------
REQ=0; BYTES=0; S2=0; S3=0; S4=0; S5=0; TOPJSON="[]"
CURSIZE=0
if [[ -r "$LOG" ]]; then CURSIZE=$(stat -c%s "$LOG"); fi
PREV=0
if [[ -f "$STATE_DIR/.logpos" ]]; then read -r PREV < "$STATE_DIR/.logpos" || true; fi
if (( PREV > CURSIZE )); then PREV=0; fi
if (( CURSIZE > PREV )) && [[ -r "$LOG" ]]; then
  AGG=$(tail -c +$((PREV+1)) "$LOG" | awk '
    {
      if ($1 == "-") next
      req++
      ip=$1; if (ip=="-") ip="127.0.0.1"
      s=0
      if (match($0, /" [0-9][0-9][0-9] /)) {
        s=substr($0, RSTART+2, 3)+0
        rest=substr($0, RSTART+RLENGTH)
        split(rest, b, " ")
        bytes+=b[1]+0
      }
      if (s>=200 && s<300) s2++
      else if (s>=300 && s<400) s3++
      else if (s>=400 && s<500) s4++
      else if (s>=500) s5++
      ips[ip]++
    }
    END {
      printf "TOTALS %d %d %d %d %d %d\n", req+0, bytes+0, s2+0, s3+0, s4+0, s5+0
      for (i in ips) printf "IP %s %d\n", i, ips[i]
    }')
  read -r _ REQ BYTES S2 S3 S4 S5 <<< "$(echo "$AGG" | grep '^TOTALS ')"
  TOPJSON=$(echo "$AGG" | grep '^IP ' | grep -E '^IP [0-9a-fA-F.:]+ [0-9]+$' | sort -k3 -nr | head -5 | awk 'BEGIN{printf "["}{printf "%s[\"%s\",%d]", (NR>1?",":""), $2, $3}END{print "]"}') || TOPJSON="[]"
fi
echo "$CURSIZE" > "$STATE_DIR/.logpos"
BYTES_KB=$((BYTES/1024))

# ---------- 写 MySQL (失败不阻断 JSON 快照) ----------
mysql --defaults-extra-file="$CNF" "$MONITOR_DB" <<SQL || echo "mysql write failed at $TS" >&2
INSERT IGNORE INTO metrics (ts, load1, load5, load15, cpu_pct, mem_total, mem_used, swap_total, swap_used, disk_total, disk_used, net_rx_kbps, net_tx_kbps, procs, uptime_min)
VALUES ('$TS', $LOAD1, $LOAD5, $LOAD15, $CPU_PCT, $MEM_TOTAL, $MEM_USED, $SWAP_TOTAL, $SWAP_USED, $DISK_TOTAL, $DISK_USED, $NET_RX, $NET_TX, $TOTAL_PROCS, $UP_MIN);
$(if [[ -n "$SITE_ROWS" ]]; then printf 'INSERT IGNORE INTO site_checks (ts, target, http_code, ttfb_ms) VALUES %s;\n' "$SITE_ROWS"; fi)
INSERT IGNORE INTO traffic_min (ts, requests, bytes_kb, s2xx, s3xx, s4xx, s5xx, top_ips)
VALUES ('$TS', $REQ, $BYTES_KB, $S2, $S3, $S4, $S5, '$TOPJSON');
INSERT INTO metrics_hourly (bucket, samples, cpu, l1, memp, swapp, rx, tx)
SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00:00'), COUNT(*), MAX(cpu_pct), ROUND(AVG(load1), 2),
       ROUND(AVG(mem_used * 100.0 / GREATEST(mem_total, 1))),
       LEAST(100, GREATEST(0, ROUND(AVG(CASE WHEN swap_total > 0 THEN swap_used * 100.0 / swap_total ELSE 0 END)))),
       ROUND(AVG(net_rx_kbps)), ROUND(AVG(net_tx_kbps))
FROM metrics WHERE ts >= DATE_FORMAT('$TS', '%Y-%m-%d %H:00:00') AND ts <= '$TS'
GROUP BY DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')
ON DUPLICATE KEY UPDATE samples=VALUES(samples), cpu=VALUES(cpu), l1=VALUES(l1), memp=VALUES(memp),
    swapp=VALUES(swapp), rx=VALUES(rx), tx=VALUES(tx);
INSERT INTO metrics_daily (bucket, samples, cpu, l1, memp, swapp, rx, tx)
SELECT DATE(ts), COUNT(*), MAX(cpu_pct), ROUND(AVG(load1), 2),
       ROUND(AVG(mem_used * 100.0 / GREATEST(mem_total, 1))),
       LEAST(100, GREATEST(0, ROUND(AVG(CASE WHEN swap_total > 0 THEN swap_used * 100.0 / swap_total ELSE 0 END)))),
       ROUND(AVG(net_rx_kbps)), ROUND(AVG(net_tx_kbps))
FROM metrics WHERE ts >= DATE('$TS') AND ts <= '$TS'
GROUP BY DATE(ts)
ON DUPLICATE KEY UPDATE samples=VALUES(samples), cpu=VALUES(cpu), l1=VALUES(l1), memp=VALUES(memp),
    swapp=VALUES(swapp), rx=VALUES(rx), tx=VALUES(tx);
INSERT INTO traffic_hourly (bucket, requests, bytes_kb, s2xx, s3xx, s4xx, s5xx)
SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00:00'), SUM(requests), SUM(bytes_kb), SUM(s2xx), SUM(s3xx), SUM(s4xx), SUM(s5xx)
FROM traffic_min WHERE ts >= DATE_FORMAT('$TS', '%Y-%m-%d %H:00:00') AND ts <= '$TS'
GROUP BY DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')
ON DUPLICATE KEY UPDATE requests=VALUES(requests), bytes_kb=VALUES(bytes_kb), s2xx=VALUES(s2xx),
    s3xx=VALUES(s3xx), s4xx=VALUES(s4xx), s5xx=VALUES(s5xx);
INSERT INTO traffic_daily (bucket, requests, bytes_kb, s2xx, s3xx, s4xx, s5xx)
SELECT DATE(ts), SUM(requests), SUM(bytes_kb), SUM(s2xx), SUM(s3xx), SUM(s4xx), SUM(s5xx)
FROM traffic_min WHERE ts >= DATE('$TS') AND ts <= '$TS'
GROUP BY DATE(ts)
ON DUPLICATE KEY UPDATE requests=VALUES(requests), bytes_kb=VALUES(bytes_kb), s2xx=VALUES(s2xx),
    s3xx=VALUES(s3xx), s4xx=VALUES(s4xx), s5xx=VALUES(s5xx);
SQL

# ---------- 原子写 JSON 快照 ----------
cat > "$STATE_DIR/.status.json.tmp" <<EOF
{"ts":"$TS","uptime_min":$UP_MIN,"load":[$LOAD1,$LOAD5,$LOAD15],"cpu_pct":$CPU_PCT,"mem_total_mb":$MEM_TOTAL,"mem_used_mb":$MEM_USED,"swap_total_mb":$SWAP_TOTAL,"swap_used_mb":$SWAP_USED,"disk_total_mb":$DISK_TOTAL,"disk_used_mb":$DISK_USED,"net_rx_kbps":$NET_RX,"net_tx_kbps":$NET_TX,"procs":$TOTAL_PROCS,"services":$SVC,"sites":$SITE_JSON,"traffic":{"requests":$REQ,"bytes_kb":$BYTES_KB,"s2xx":$S2,"s3xx":$S3,"s4xx":$S4,"s5xx":$S5,"top_ips":$TOPJSON}}
EOF
mkdir -p "$(dirname "$STATUS_FILE")"
mv "$STATE_DIR/.status.json.tmp" "$STATUS_FILE"
if [[ -n "$STATUS_OWNER" || -n "$STATUS_GROUP" ]]; then
  chown "${STATUS_OWNER:-root}${STATUS_GROUP:+:$STATUS_GROUP}" "$STATUS_FILE"
fi
chmod "$STATUS_MODE" "$STATUS_FILE"
