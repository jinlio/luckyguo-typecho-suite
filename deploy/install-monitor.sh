#!/usr/bin/env bash
# Install SuiteMonitor runtime files and a cron schedule.
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo '请以 root 运行此安装脚本。' >&2
  exit 1
fi

ROOT="${TYPECHO_ROOT:-/var/www/typecho}"
PREFIX="${TYPECHO_SUITE_PREFIX:-/usr/local}"
STATE_DIR="${TYPECHO_SUITE_STATE_DIR:-/var/lib/typecho-suite/monitor}"
CRON_FILE="${TYPECHO_SUITE_CRON_FILE:-/etc/cron.d/typecho-suite-monitor}"
SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

for file in monitor-collect.sh monitor-prune.sh suite-monitor-config.php; do
  [[ -f "$SCRIPT_DIR/$file" ]] || { echo "缺少安装文件: $SCRIPT_DIR/$file" >&2; exit 1; }
done
[[ -f "$ROOT/config.inc.php" ]] || { echo "找不到 Typecho 根目录: $ROOT" >&2; exit 1; }

install -d -m 0750 "$PREFIX/sbin" "$PREFIX/libexec" "$STATE_DIR"
install -m 0750 "$SCRIPT_DIR/monitor-collect.sh" "$PREFIX/sbin/typecho-suite-monitor-collect"
install -m 0750 "$SCRIPT_DIR/monitor-prune.sh" "$PREFIX/sbin/typecho-suite-monitor-prune"
install -m 0750 "$SCRIPT_DIR/suite-monitor-config.php" "$PREFIX/libexec/typecho-suite-monitor-config.php"

install -d -m 0755 "$(dirname -- "$CRON_FILE")"
umask 022
printf '%s\n' \
  '# Managed by Typecho Suite. Configure values in the SuiteMonitor admin page.' \
  "* * * * * root TYPECHO_ROOT='$ROOT' TYPECHO_SUITE_MONITOR_EXPORTER='$PREFIX/libexec/typecho-suite-monitor-config.php' '$PREFIX/sbin/typecho-suite-monitor-collect' >> /var/log/typecho-suite-monitor.log 2>&1" \
  "17 4 * * * root TYPECHO_ROOT='$ROOT' TYPECHO_SUITE_MONITOR_EXPORTER='$PREFIX/libexec/typecho-suite-monitor-config.php' '$PREFIX/sbin/typecho-suite-monitor-prune' >> /var/log/typecho-suite-monitor.log 2>&1" \
  > "$CRON_FILE"
chmod 0644 "$CRON_FILE"

echo "SuiteMonitor 运行文件已安装。"
echo "Typecho 根目录: $ROOT"
echo "状态目录: $STATE_DIR"
echo "Cron: $CRON_FILE"
echo '下一步：执行 deploy/create-suite-monitor.sql 创建监控表，然后在后台配置 SuiteMonitor。'
