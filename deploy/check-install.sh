#!/usr/bin/env bash
# Diagnose a Typecho Suite installation without changing the host.
set -u

ROOT="${TYPECHO_ROOT:-/var/www/typecho}"
STATE_DIR="${TYPECHO_SUITE_STATE_DIR:-/var/lib/typecho-suite/monitor}"
errors=0

ok() { printf '[OK]   %s\n' "$1"; }
warn() { printf '[WARN] %s\n' "$1"; }
fail() { printf '[FAIL] %s\n' "$1"; errors=$((errors + 1)); }
need_cmd() { command -v "$1" >/dev/null 2>&1 && ok "命令可用: $1" || fail "缺少命令: $1"; }

printf 'Typecho Suite installation check\nroot: %s\n\n' "$ROOT"
for cmd in php mysql curl awk sed; do
  need_cmd "$cmd"
done

if [[ -f "$ROOT/config.inc.php" ]]; then
  ok "Typecho 配置存在"
else
  fail "找不到 $ROOT/config.inc.php"
fi

if [[ -d "$ROOT/usr/themes/koijournal" ]]; then
  ok "Suite Default 主题已安装"
else
  warn "Suite Default 主题目录不存在"
fi

if [[ -d "$ROOT/usr/plugins/SuiteMonitor" ]]; then
  ok "SuiteMonitor 插件已安装"
else
  warn "SuiteMonitor 插件未安装"
fi

if [[ -d "$STATE_DIR" && -w "$STATE_DIR" ]]; then
  ok "监控状态目录可写: $STATE_DIR"
elif [[ -d "$STATE_DIR" ]]; then
  fail "监控状态目录不可写: $STATE_DIR"
else
  warn "监控状态目录不存在: $STATE_DIR"
fi

if command -v php >/dev/null 2>&1; then
  php -m 2>/dev/null | grep -qx 'mysqli' && ok 'PHP mysqli 扩展可用' || fail 'PHP 缺少 mysqli 扩展'
  php -m 2>/dev/null | grep -qx 'PDO' && ok 'PHP PDO 扩展可用' || fail 'PHP 缺少 PDO 扩展'
fi

for file in \
  "$ROOT/usr/themes/koijournal/functions.php" \
  "$ROOT/usr/themes/koijournal/site.js" \
  "$ROOT/usr/plugins/SuiteMonitor/Plugin.php" \
  "$ROOT/usr/plugins/SuiteMonitor/panel.php"; do
  [[ -f "$file" ]] || continue
  case "$file" in
    *.php) php -l "$file" >/dev/null 2>&1 && ok "PHP 语法: $file" || fail "PHP 语法错误: $file" ;;
  esac
done

if [[ -f /etc/cron.d/typecho-suite-monitor || -f /etc/cron.d/suite-monitor ]]; then
  ok '监控定时任务文件存在'
else
  warn '未发现 SuiteMonitor cron 文件'
fi

if (( errors > 0 )); then
  printf '\n检查完成：%d 项必须修复的问题。\n' "$errors"
  exit 1
fi
printf '\n检查完成：未发现阻断性问题。\n'
