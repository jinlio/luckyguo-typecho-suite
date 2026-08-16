<?php
// Typecho 后台面板: extending.php?panel=Monitor%2Fpanel.php
// common.php 已强制登录; 此处再做管理员角色校验
if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }

$monUser = \Typecho_Widget::widget('Widget_User');
if (!$monUser->hasLogin() || !$monUser->pass('administrator', true)) {
    http_response_code(403);
    exit('Forbidden');
}

const MON_STATUS_FILE = '/var/lib/monitor/status.json';
const MON_ENV_FILE = '/etc/luckyguo-monitor.env';
const MON_CORES = 2; // 2 vCPU, 负载折算用

// ajax 分支: 30s 局部刷新的数据源
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $monJson = @file_get_contents(MON_STATUS_FILE);
    if ($monJson === false) { http_response_code(503); echo '{"error":"status unavailable"}'; exit; }
    echo $monJson;
    exit;
}

header('Cache-Control: no-store');

function mon_e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mon_fnum(float $v, int $d = 0): string { return number_format($v, $d, '.', ','); }

// ---------- 数据源 ----------
$S = json_decode((string)@file_get_contents(MON_STATUS_FILE), true) ?: [];

$pdo = null;
$monEnv = @parse_ini_file(MON_ENV_FILE, false, INI_SCANNER_RAW) ?: [];
try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;dbname=luckyguo_monitor;charset=utf8mb4',
        (string)($monEnv['MONITOR_RO_USER'] ?? ''),
        (string)($monEnv['MONITOR_RO_PASS'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $ignored) { $pdo = null; }

$RANGES = ['24h' => '24 小时', '7d' => '7 天', '30d' => '30 天', '1y' => '1 年'];
$range = (string)($_GET['range'] ?? '24h');
if (!isset($RANGES[$range])) $range = '24h';

// ---------- SVG 图表助手 ----------
function mon_nice_max(float $v): float {
    if ($v <= 0) return 1;
    $p = pow(10, floor(log10($v)));
    foreach ([1, 1.5, 2, 2.5, 4, 5, 10] as $m) { if ($v <= $m * $p) return $m * $p; }
    return 10 * $p;
}

/** 多序列折线/面积图，支持可选右侧纵轴. $rows: 按时间升序 */
function mon_line_chart(array $rows, array $series, string $labelFmt, int $w = 720, int $h = 168, array $rightSeries = []): string {
    $n = count($rows);
    if ($n === 0) return '<div class="chart-empty">暂无数据 · 采集器运行后即显示</div>';
    $padL = 40; $padR = $rightSeries ? 42 : 10; $padT = 12; $padB = 22;
    $iw = $w - $padL - $padR; $ih = $h - $padT - $padB;
    $allSeries = array_merge($series, $rightSeries);
    $leftMax = 0.0; $rightMax = 0.0;
    foreach ($rows as $r) {
        foreach ($series as $s) $leftMax = max($leftMax, (float)$r[$s['key']]);
        foreach ($rightSeries as $s) $rightMax = max($rightMax, (float)$r[$s['key']]);
    }
    $leftMax = mon_nice_max($leftMax);
    $rightMax = $rightSeries ? mon_nice_max($rightMax) : $leftMax;
    $x = fn(int $i): float => $padL + ($n <= 1 ? $iw / 2 : $iw * $i / ($n - 1));
    $axisOf = fn(array $s): string => $rightSeries && (($s['axis'] ?? 'left') === 'right') ? 'right' : 'left';
    $y = fn(float $v, string $axis): float => $padT + $ih * (1 - min($v, $axis === 'right' ? $rightMax : $leftMax) / ($axis === 'right' ? $rightMax : $leftMax));

    $out = "<svg class=\"trend-chart\" viewBox=\"0 0 $w $h\" role=\"img\" aria-label=\"资源趋势图\">";
    foreach ([0, 0.5, 1] as $g) {
        $gy = $padT + $ih * (1 - $g);
        $out .= "<line class=\"grid\" x1=\"$padL\" y1=\"$gy\" x2=\"" . ($w - $padR) . "\" y2=\"$gy\"/>";
        $out .= "<text class=\"axis\" x=\"4\" y=\"" . ($gy + 3) . "\">" . mon_fnum($leftMax * $g, $leftMax < 10 ? 1 : 0) . "</text>";
        if ($rightSeries) {
            $out .= "<text class=\"axis axis-right\" x=\"" . ($w - 4) . "\" y=\"" . ($gy + 3) . "\" text-anchor=\"end\">" . mon_fnum($rightMax * $g, $rightMax < 10 ? 1 : 0) . "</text>";
        }
    }
    foreach ([0, intdiv($n - 1, 2), $n - 1] as $i) {
        $t = strtotime((string)$rows[$i]['b']);
        $out .= "<text class=\"axis\" x=\"" . $x($i) . "\" y=\"" . ($h - 6) . "\" text-anchor=\"middle\">" . date($labelFmt, $t) . "</text>";
    }
    foreach ($allSeries as $s) {
        $axis = $axisOf($s);
        $pts = [];
        foreach ($rows as $i => $r) $pts[] = round($x($i), 1) . ',' . round($y((float)$r[$s['key']], $axis), 1);
        if (!empty($s['area']) && $n > 1) {
            $out .= "<polygon points=\"{$padL}," . ($padT + $ih) . " " . implode(' ', $pts) . " " . round($x($n - 1), 1) . "," . ($padT + $ih) . "\" fill=\"{$s['color']}\" opacity=\"0.10\"/>";
        }
        $out .= "<polyline class=\"line\" points=\"" . implode(' ', $pts) . "\" stroke=\"{$s['color']}\"/>";
        foreach ($rows as $i => $r) {
            $value = (float)$r[$s['key']];
            $precision = isset($s['precision']) ? (int)$s['precision'] : ($value < 10 ? 2 : 0);
            $unit = (string)($s['unit'] ?? '');
            $tip = mon_e(date($labelFmt, strtotime((string)$r['b'])) . ' · ' . (string)$s['label'] . ' ' . mon_fnum($value, $precision) . $unit);
            $out .= "<circle class=\"point\" tabindex=\"0\" pointer-events=\"all\" data-tip=\"$tip\" cx=\"" . round($x($i), 1) . "\" cy=\"" . round($y($value, $axis), 1) . "\" r=\"2.4\" fill=\"{$s['color']}\" stroke=\"transparent\" stroke-width=\"12\"><title>$tip</title></circle>";
        }
    }
    return $out . '</svg><div class="chart-tooltip" role="status" aria-live="polite"></div>';
}

/** 柱状图 (可选异常叠段) */
function mon_bar_chart(array $rows, string $key, ?string $errKey, string $labelFmt, int $w = 720, int $h = 150): string {
    $n = count($rows);
    if ($n === 0) return '<div class="chart-empty">暂无数据</div>';
    $padL = 40; $padR = 10; $padT = 12; $padB = 22;
    $iw = $w - $padL - $padR; $ih = $h - $padT - $padB;
    $max = 0.0;
    foreach ($rows as $r) $max = max($max, (float)$r[$key]);
    $max = mon_nice_max($max);
    $bw = max(1.0, $iw / $n - 2);

    $out = "<svg viewBox=\"0 0 $w $h\" role=\"img\" aria-hidden=\"true\">";
    foreach ([0, 1] as $g) {
        $gy = $padT + $ih * (1 - $g);
        $out .= "<line class=\"grid\" x1=\"$padL\" y1=\"$gy\" x2=\"" . ($w - $padR) . "\" y2=\"$gy\"/>";
        $out .= "<text class=\"axis\" x=\"4\" y=\"" . ($gy + 3) . "\">" . mon_fnum($max * $g, $max < 10 ? 1 : 0) . "</text>";
    }
    foreach ($rows as $i => $r) {
        $v = (float)$r[$key];
        $bh = $ih * $v / $max;
        $bx = $padL + $iw * ($i + 0.5) / $n - $bw / 2;
        $by = $padT + $ih - $bh;
        $t = strtotime((string)$r['b']);
        $tip = mon_e(date($labelFmt, $t) . ' · ' . mon_fnum($v) . ($errKey !== null ? ' · 异常 ' . mon_fnum((float)$r[$errKey]) : ''));
        $out .= "<rect x=\"" . round($bx, 1) . "\" y=\"" . round($by, 1) . "\" width=\"" . round($bw, 1) . "\" height=\"" . round(max($bh, $v > 0 ? 1.5 : 0), 1) . "\" rx=\"1.5\" fill=\"var(--accent)\" opacity=\"0.8\"><title>$tip</title></rect>";
        if ($errKey !== null && (float)$r[$errKey] > 0) {
            $eh = $ih * (float)$r[$errKey] / $max;
            $out .= "<rect x=\"" . round($bx, 1) . "\" y=\"" . round($by, 1) . "\" width=\"" . round($bw, 1) . "\" height=\"" . round(max($eh, 1.5), 1) . "\" rx=\"1.5\" fill=\"var(--accent-strong)\"><title>$tip</title></rect>";
        }
    }
    foreach ([0, intdiv($n - 1, 2), $n - 1] as $i) {
        $t = strtotime((string)$rows[$i]['b']);
        $out .= "<text class=\"axis\" x=\"" . ($padL + $iw * ($i + 0.5) / $n) . "\" y=\"" . ($h - 6) . "\" text-anchor=\"middle\">" . date($labelFmt, $t) . "</text>";
    }
    return $out . '</svg>';
}

/** 状态码 donut. $parts: [label, value, color] */
function mon_donut_svg(array $parts): string {
    $total = array_sum(array_column($parts, 1));
    if ($total <= 0) return '<div class="chart-empty">24h 内暂无请求</div>';
    $r = 44; $c = 2 * M_PI * $r; $acc = 0.0;
    $out = "<svg viewBox=\"0 0 120 120\" class=\"donut\" role=\"img\" aria-hidden=\"true\">";
    $out .= "<circle cx=\"60\" cy=\"60\" r=\"$r\" fill=\"none\" stroke=\"var(--line)\" stroke-width=\"14\"/>";
    foreach ($parts as [$label, $val, $color]) {
        if ($val <= 0) continue;
        $frac = $val / $total;
        $out .= "<circle cx=\"60\" cy=\"60\" r=\"$r\" fill=\"none\" stroke=\"$color\" stroke-width=\"14\" stroke-dasharray=\"" . round($frac * $c, 1) . " " . round($c, 1) . "\" stroke-dashoffset=\"" . round(-$acc * $c, 1) . "\" transform=\"rotate(-90 60 60)\"/>";
        $acc += $frac;
    }
    $out .= "<text x=\"60\" y=\"58\" text-anchor=\"middle\" font-size=\"16\">" . mon_fnum((float)$total) . "</text>";
    $out .= "<text x=\"60\" y=\"74\" text-anchor=\"middle\" font-size=\"9\" fill=\"var(--ink-soft)\">请求/24h</text></svg>";
    return $out;
}

/** 环形 gauge 卡片 */
function mon_gauge_card(string $key, string $label, int $pct, string $value, string $sub): string {
    $r = 30; $c = round(2 * M_PI * $r, 1);
    $pct = max(0, min(100, $pct));
    $off = round($c * (1 - $pct / 100), 1);
    $cls = $pct >= 85 ? 'crit' : ($pct >= 70 ? 'warn' : '');
    return "<div class=\"card\"><div class=\"gauge $cls\"><svg viewBox=\"0 0 76 76\" width=\"76\" height=\"76\">"
        . "<circle class=\"track\" cx=\"38\" cy=\"38\" r=\"$r\" fill=\"none\" stroke-width=\"7\"/>"
        . "<circle class=\"bar\" data-ga=\"$key\" cx=\"38\" cy=\"38\" r=\"$r\" fill=\"none\" stroke-width=\"7\" stroke-linecap=\"round\" stroke-dasharray=\"$c\" stroke-dashoffset=\"$off\" transform=\"rotate(-90 38 38)\"/>"
        . "<text x=\"38\" y=\"43\" text-anchor=\"middle\" data-gv=\"$key\">$pct%</text>"
        . "</svg></div><div class=\"info\"><div class=\"label\">$label</div><div class=\"value\" data-f=\"{$key}_v\">$value</div><div class=\"sub\">$sub</div></div></div>";
}

// ---------- 历史数据查询 ----------
$bucket = ['24h' => 300, '7d' => 3600, '30d' => 86400, '1y' => 86400][$range];
$interval = ['24h' => '1 DAY', '7d' => '7 DAY', '30d' => '30 DAY', '1y' => '370 DAY'][$range];
$labelFmt = ['24h' => 'H:i', '7d' => 'm-d H:i', '30d' => 'm-d', '1y' => 'm-d'][$range];

$metrics = $traffic = $sites24h = $uptime30 = $codes = $topIps = [];
$uvDaily = $topPosts = [];
$todayUv = $todayPv = $totalUv = $totalPv = 0;

if ($pdo !== null) {
    try {
        $metrics = $pdo->query(
            "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/$bucket)*$bucket) AS b,
                    MAX(cpu_pct) cpu, ROUND(AVG(load1),2) l1,
                    ROUND(AVG(mem_used*100.0/GREATEST(mem_total,1))) memp,
                    ROUND(AVG(swap_used*100.0/4095.0)) swapp,
                    ROUND(AVG(net_rx_kbps)) rx, ROUND(AVG(net_tx_kbps)) tx
             FROM metrics WHERE ts >= NOW() - INTERVAL $interval GROUP BY b ORDER BY b"
        )->fetchAll();

        $traffic = $pdo->query(
            "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/$bucket)*$bucket) AS b,
                    SUM(requests) req, SUM(s4xx+s5xx) err
             FROM traffic_min WHERE ts >= NOW() - INTERVAL $interval GROUP BY b ORDER BY b"
        )->fetchAll();

        $sites24h = $pdo->query(
            "SELECT target, FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/900)*900) AS b,
                    SUM(http_code=0 OR http_code>=500) bad, COUNT(*) n, ROUND(AVG(ttfb_ms)) ttfb
             FROM site_checks WHERE ts >= NOW() - INTERVAL 1 DAY GROUP BY target, b ORDER BY b"
        )->fetchAll();

        $uptime30 = $pdo->query(
            "SELECT target, ROUND(100*SUM(http_code BETWEEN 200 AND 399)/COUNT(*),2) up
             FROM site_checks WHERE ts >= NOW() - INTERVAL 30 DAY GROUP BY target"
        )->fetchAll();

        $codes = $pdo->query(
            "SELECT SUM(s2xx) s2, SUM(s3xx) s3, SUM(s4xx) s4, SUM(s5xx) s5, SUM(requests) req, SUM(bytes_kb) kb
             FROM traffic_min WHERE ts >= NOW() - INTERVAL 1 DAY"
        )->fetch() ?: [];

        $topIps = $pdo->query(
            "SELECT jt.ip, SUM(jt.c) n FROM traffic_min,
                    JSON_TABLE(top_ips, '$[*]' COLUMNS (ip VARCHAR(45) PATH '$[0]', c INT PATH '$[1]')) jt
             WHERE ts >= NOW() - INTERVAL 1 DAY GROUP BY jt.ip ORDER BY n DESC LIMIT 8"
        )->fetchAll();

        $uvDaily = $pdo->query(
            "SELECT vday AS b, COUNT(*) uv FROM luckyguo_typecho.typecho_luckyguo_visitors
             WHERE vday >= CURDATE() - INTERVAL 29 DAY GROUP BY vday ORDER BY vday"
        )->fetchAll();

        $pvDaily = $pdo->query(
            "SELECT vday, SUM(pv) pv FROM luckyguo_typecho.typecho_luckyguo_visits
             WHERE vday >= CURDATE() - INTERVAL 29 DAY GROUP BY vday"
        )->fetchAll();
        $pvMap = array_column($pvDaily, 'pv', 'vday');
        foreach ($uvDaily as &$row) $row['pv'] = (int)($pvMap[$row['b']] ?? 0);
        unset($row);

        $todayUv = (int)$pdo->query("SELECT COUNT(*) c FROM luckyguo_typecho.typecho_luckyguo_visitors WHERE vday=CURDATE()")->fetch()['c'];
        $todayPv = (int)$pdo->query("SELECT COALESCE(SUM(pv),0) c FROM luckyguo_typecho.typecho_luckyguo_visits WHERE vday=CURDATE()")->fetch()['c'];
        $totalUv = (int)$pdo->query("SELECT COUNT(DISTINCT vip) c FROM luckyguo_typecho.typecho_luckyguo_visitors")->fetch()['c'];
        $totalPv = (int)$pdo->query("SELECT COALESCE(SUM(pv),0) c FROM luckyguo_typecho.typecho_luckyguo_visits")->fetch()['c'];

        $topPosts = $pdo->query(
            "SELECT c.title, SUM(v.views) vv
             FROM luckyguo_typecho.typecho_luckyguo_views v
             JOIN luckyguo_typecho.typecho_contents c ON c.cid = v.cid AND c.status='publish'
             GROUP BY v.cid ORDER BY vv DESC LIMIT 5"
        )->fetchAll();
    } catch (Throwable $ignored) { /* 历史区降级显示空 */ }
}

// ---------- 快照取值 ----------
$cpu = (int)($S['cpu_pct'] ?? 0);
$load = $S['load'] ?? [0, 0, 0];
$loadPct = (int)round(((float)($load[0] ?? 0)) / MON_CORES * 100);
$memT = (int)($S['mem_total_mb'] ?? 0); $memU = (int)($S['mem_used_mb'] ?? 0);
$memPct = $memT > 0 ? (int)round($memU * 100 / $memT) : 0;
$diskT = (int)($S['disk_total_mb'] ?? 0); $diskU = (int)($S['disk_used_mb'] ?? 0);
$diskPct = $diskT > 0 ? (int)round($diskU * 100 / $diskT) : 0;
$swapU = (int)($S['swap_used_mb'] ?? 0);
$upMin = (int)($S['uptime_min'] ?? 0);
$upStr = intdiv($upMin, 1440) . ' 天 ' . intdiv($upMin % 1440, 60) . ' 小时 ' . ($upMin % 60) . ' 分';
$services = $S['services'] ?? [];
$sites = $S['sites'] ?? [];
$tunnel = (int)($S['tunnel_conn'] ?? 0);
$ts = $S['ts'] ?? '-';

$svcLabel = ['nginx' => 'Nginx', 'php-fpm' => 'PHP-FPM', 'mysqld' => 'MySQL', 'gitea' => 'Gitea', 'cloudflared' => 'Cloudflared'];
$siteLabel = ['blog' => 'Blog', 'git' => 'Gitea', 'landing' => '落地页'];
$siteUrl = ['blog' => 'blog.luckyguo.dpdns.org', 'git' => 'git.luckyguo.dpdns.org', 'landing' => 'luckyguo.dpdns.org'];

$stripRows = [];
foreach ($sites24h as $r) $stripRows[$r['target']][strtotime((string)$r['b'])] = $r;
$uptimeMap = array_column($uptime30, 'up', 'target');

function mon_site_dot($code): string { return ($code >= 200 && $code < 400) ? 'ok' : 'bad'; }

$monSelf = 'extending.php?panel=Monitor%2Fpanel.php';
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="luckyguo 站点状态监控">
<meta name="theme-color" content="<?= (($_COOKIE['luckyguo-theme'] ?? '') === 'dark') ? '#1c191d' : '#fcfafb' ?>">
<meta name="robots" content="noindex, nofollow">
<title>站点状态 · 锦鲤小果</title>
<script>(function(){var m=document.cookie.match(/(?:^|;\s*)luckyguo-theme=(dark|light)(?:;|$)/),t=m?m[1]:localStorage.getItem('luckyguo-theme')||((matchMedia('(prefers-color-scheme:dark)').matches)?'dark':'light');if(!m)document.cookie='luckyguo-theme='+t+'; Max-Age=31536000; Path=/; Domain=.luckyguo.dpdns.org; SameSite=Lax; Secure';document.documentElement.dataset.theme=t;})();</script>
<link rel="stylesheet" href="/usr/plugins/Monitor/style.css?v=1.3.3">
<link rel="icon" type="image/png" sizes="32x32" href="/usr/themes/luckyguo/favicon-32-v3.png">
<link rel="icon" type="image/png" sizes="16x16" href="/usr/themes/luckyguo/favicon-16-v3.png">
<link rel="apple-touch-icon" sizes="180x180" href="/usr/themes/luckyguo/apple-touch-icon-v3.png">
<link rel="shortcut icon" type="image/x-icon" href="/usr/themes/luckyguo/favicon-v3.ico">
</head>
<body>
<header class="site-header">
    <a class="brand" href="<?= $monSelf ?>">
        <img src="/usr/themes/luckyguo/avatar.jpg" alt="锦鲤小果头像">
        <span><strong>锦鲤小果</strong><small>status</small></span>
    </a>
    <nav class="site-nav"><a href="/admin/">控制台</a><a href="/">Blog</a><a href="https://git.luckyguo.dpdns.org">Gitea</a></nav>
    <span class="updated" title="采集器每分钟更新">更新于 <span data-f="ts"><?= mon_e($ts) ?></span></span>
    <button class="theme-toggle" type="button" aria-label="切换深浅色主题" title="切换主题">◐</button>
</header>

<main>
    <!-- 概览 -->
    <section>
        <header><div><p class="eyebrow">OVERVIEW</p><h2>服务器概览</h2></div><span class="hint">2 vCPU · 1.9GB RAM · 40GB 盘 · 已运行 <?= mon_e($upStr) ?></span></header>
        <div class="cards">
            <?= mon_gauge_card('cpu', 'CPU', $cpu, $cpu . '%', '负载 ' . mon_e((string)($load[0] ?? 0)) . ' / ' . mon_e((string)($load[1] ?? 0)) . ' / ' . mon_e((string)($load[2] ?? 0))) ?>
            <?= mon_gauge_card('mem', '内存', $memPct, mon_fnum((float)$memU) . 'M', '共 ' . mon_fnum((float)$memT) . 'M · swap ' . mon_fnum((float)$swapU) . 'M') ?>
            <?= mon_gauge_card('disk', '磁盘', $diskPct, mon_fnum($diskU / 1024, 1) . 'G', '共 ' . mon_fnum($diskT / 1024, 0) . 'G') ?>
            <?= mon_gauge_card('load', '负载', min($loadPct, 100), mon_e((string)($load[0] ?? 0)), '按 ' . MON_CORES . ' 核折算 · 进程 ' . (int)($S['procs'] ?? 0)) ?>
        </div>
    </section>

    <!-- 服务状态 -->
    <section>
        <header><div><p class="eyebrow">SERVICES</p><h2>服务状态</h2></div><span class="hint">隧道连接 <b data-f="tunnel_conn"><?= $tunnel ?></b> 条</span></header>
        <div class="panel"><div class="services">
            <?php foreach ($svcLabel as $k => $name): $st = $services[$k] ?? 'unknown'; $ok = $st === 'active'; ?>
            <div class="svc"><span class="dot <?= $ok ? 'ok' : 'bad' ?>" data-svc="<?= mon_e($k) ?>"></span><b><?= mon_e($name) ?></b><small data-svc-t="<?= mon_e($k) ?>"><?= mon_e($st) ?></small></div>
            <?php endforeach; ?>
        </div></div>
    </section>

    <!-- 站点可用性 -->
    <section>
        <header><div><p class="eyebrow">UPTIME</p><h2>站点可用性</h2></div><span class="hint">24h 每格 15 分钟 · 本机链路探测</span></header>
        <div class="panel">
            <?php foreach ($siteLabel as $k => $name):
                $code = (int)($sites[$k]['code'] ?? 0); $ttfb = (int)($sites[$k]['ttfb_ms'] ?? 0);
                $ok = mon_site_dot($code);
                $rows = $stripRows[$k] ?? [];
            ?>
            <div class="site-row">
                <div class="site-name"><span class="dot <?= $ok ?>" data-site-dot="<?= mon_e($k) ?>"></span><?= mon_e($name) ?></div>
                <div class="site-meta">
                    <span class="code-pill <?= $ok ?>" data-site-code="<?= mon_e($k) ?>"><?= $code ?: '—' ?></span>
                    <span><?= mon_e($siteUrl[$k]) ?></span>
                    <span>TTFB <b data-site-ttfb="<?= mon_e($k) ?>"><?= $ttfb ?></b> ms</span>
                    <span>30 天可用率 <b><?= mon_e((string)($uptimeMap[$k] ?? '—')) ?></b>%</span>
                </div>
                <div class="strip" aria-hidden="true">
                    <?php
                    $now = time();
                    for ($i = 95; $i >= 0; $i--):
                        $slotStart = $now - $now % 900 - $i * 900;
                        $r = $rows[$slotStart] ?? null;
                        if ($r === null || (int)$r['n'] === 0) { $cls = 'none'; $tip = '无数据'; }
                        elseif ((int)$r['bad'] > 0) { $cls = 'down'; $tip = '异常'; }
                        else { $cls = 'ok'; $tip = '正常'; }
                        $tip = date('H:i', $slotStart) . ' ' . $tip . ($r ? ' · ' . (int)$r['ttfb'] . 'ms' : '');
                    ?>
                    <i class="<?= $cls ?>" title="<?= mon_e($tip) ?>"></i>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- 趋势图 -->
    <section>
        <header>
            <div><p class="eyebrow">TRENDS</p><h2>资源趋势</h2></div>
            <nav class="range-nav">
                <?php foreach ($RANGES as $rk => $rn): ?>
                <a href="<?= $monSelf ?>&range=<?= mon_e($rk) ?>" class="<?= $rk === $range ? 'cur' : '' ?>"><?= mon_e($rn) ?></a>
                <?php endforeach; ?>
            </nav>
        </header>
        <div class="grid-2">
            <div class="panel chart">
                <?= mon_line_chart($metrics, [
                    ['key' => 'cpu', 'label' => 'CPU', 'unit' => '%', 'precision' => 0, 'color' => 'var(--accent)', 'area' => true],
                ], $labelFmt, 720, 168, [
                    ['key' => 'l1', 'label' => '负载 1min', 'precision' => 2, 'color' => 'var(--sage)', 'axis' => 'right'],
                ]) ?>
                <div class="legend"><span><i style="background:var(--accent)"></i>CPU %</span><span><i style="background:var(--sage)"></i>负载 1min</span></div>
            </div>
            <div class="panel chart">
                <?= mon_line_chart($metrics, [
                    ['key' => 'memp', 'label' => '内存 %', 'color' => 'var(--accent)', 'area' => true],
                    ['key' => 'swapp', 'label' => 'Swap %', 'color' => 'var(--sage)'],
                ], $labelFmt) ?>
                <div class="legend"><span><i style="background:var(--accent)"></i>内存 %</span><span><i style="background:var(--sage)"></i>Swap %</span></div>
            </div>
            <div class="panel chart">
                <?= mon_line_chart($metrics, [
                    ['key' => 'rx', 'label' => '接收 KB/s', 'color' => 'var(--accent)', 'area' => true],
                    ['key' => 'tx', 'label' => '发送 KB/s', 'color' => 'var(--sage)'],
                ], $labelFmt) ?>
                <div class="legend"><span><i style="background:var(--accent)"></i>接收 KB/s</span><span><i style="background:var(--sage)"></i>发送 KB/s</span></div>
            </div>
            <div class="panel chart">
                <?= mon_bar_chart($traffic, 'req', 'err', $labelFmt) ?>
                <div class="legend"><span><i style="background:var(--accent)"></i>请求量/<?= $range === '24h' ? '5 分钟' : ($range === '7d' ? '小时' : '天') ?></span><span><i style="background:var(--accent-strong)"></i>4xx+5xx</span></div>
            </div>
        </div>
    </section>

    <!-- 流量 -->
    <section>
        <header><div><p class="eyebrow">TRAFFIC</p><h2>流量统计</h2></div><span class="hint">来源 nginx access log · 近 24 小时</span></header>
        <div class="panel">
            <div class="statline">
                <div class="kv"><b><?= mon_fnum((float)($codes['req'] ?? 0)) ?></b><small>请求 / 24h</small></div>
                <div class="kv"><b><?= mon_fnum(((float)($codes['kb'] ?? 0)) / 1024, 1) ?>M</b><small>流量 / 24h</small></div>
                <div class="kv"><b data-f="min_req"><?= (int)($S['traffic']['requests'] ?? 0) ?></b><small>请求 / 当前分钟</small></div>
            </div>
            <div class="donut-wrap">
                <?= mon_donut_svg([
                    ['2xx', (int)($codes['s2'] ?? 0), 'var(--sage)'],
                    ['3xx', (int)($codes['s3'] ?? 0), 'var(--ink-soft)'],
                    ['4xx', (int)($codes['s4'] ?? 0), 'var(--accent)'],
                    ['5xx', (int)($codes['s5'] ?? 0), 'var(--accent-strong)'],
                ]) ?>
                <div class="legend" style="flex-direction:column;gap:6px">
                    <span><i style="background:var(--sage)"></i>2xx <?= mon_fnum((float)($codes['s2'] ?? 0)) ?></span>
                    <span><i style="background:var(--ink-soft)"></i>3xx <?= mon_fnum((float)($codes['s3'] ?? 0)) ?></span>
                    <span><i style="background:var(--accent)"></i>4xx <?= mon_fnum((float)($codes['s4'] ?? 0)) ?></span>
                    <span><i style="background:var(--accent-strong)"></i>5xx <?= mon_fnum((float)($codes['s5'] ?? 0)) ?></span>
                </div>
                <div class="toplist">
                    <?php $maxN = max(1, (int)($topIps[0]['n'] ?? 1)); foreach ($topIps as $ip): ?>
                    <div class="row"><span class="ip"><?= mon_e($ip['ip']) ?></span><span class="bar" style="width:<?= (int)round($ip['n'] * 100 / $maxN) ?>%"></span><span class="n"><?= (int)$ip['n'] ?></span></div>
                    <?php endforeach; if (!$topIps): ?><span class="hint">暂无 IP 数据</span><?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- 博客统计 -->
    <section>
        <header><div><p class="eyebrow">BLOG</p><h2>博客访问</h2></div><span class="hint">来源 Typecho 站点统计</span></header>
        <div class="grid-2">
            <div class="panel">
                <div class="statline">
                    <div class="kv"><b><?= mon_fnum((float)$todayUv) ?></b><small>今日访客</small></div>
                    <div class="kv"><b><?= mon_fnum((float)$todayPv) ?></b><small>今日浏览</small></div>
                    <div class="kv"><b><?= mon_fnum((float)$totalUv) ?></b><small>累计访客</small></div>
                    <div class="kv"><b><?= mon_fnum((float)$totalPv) ?></b><small>累计浏览</small></div>
                </div>
                <?php if ($topPosts): ?>
                <div class="toplist">
                    <?php $maxV = max(1, (int)$topPosts[0]['vv']); foreach ($topPosts as $p): ?>
                    <div class="row"><span class="ip" title="<?= mon_e($p['title']) ?>"><?= mon_e($p['title']) ?></span><span class="bar" style="width:<?= (int)round($p['vv'] * 100 / $maxV) ?>%"></span><span class="n"><?= (int)$p['vv'] ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="panel chart">
                <?= mon_bar_chart($uvDaily, 'uv', null, 'm-d') ?>
                <div class="legend"><span><i style="background:var(--accent)"></i>每日访客 · 近 30 天</span></div>
            </div>
        </div>
    </section>
</main>

<footer><span>锦鲤小果 · luckyguo</span><span>STATUS / 2026</span></footer>

<script>
(function(){
  var root=document.documentElement,toggle=document.querySelector('.theme-toggle');
  var save=function(t){localStorage.setItem('luckyguo-theme',t);document.cookie='luckyguo-theme='+t+'; Max-Age=31536000; Path=/; Domain=.luckyguo.dpdns.org; SameSite=Lax; Secure';};
  var color=function(){var m=document.querySelector('meta[name="theme-color"]');if(m)m.setAttribute('content',root.dataset.theme==='dark'?'#1c191d':'#fcfafb');};
  var styleTimer=0,animationTimer=0;
  if(toggle)toggle.addEventListener('click',function(){var t=root.dataset.theme==='dark'?'light':'dark';root.classList.add('theme-switching');void root.offsetWidth;toggle.classList.remove('is-rotating');void toggle.offsetWidth;toggle.classList.add('is-rotating');root.dataset.theme=t;save(t);color();clearTimeout(styleTimer);clearTimeout(animationTimer);styleTimer=setTimeout(function(){root.classList.remove('theme-switching');},280);animationTimer=setTimeout(function(){toggle.classList.remove('is-rotating');},700);});
  document.querySelectorAll('.trend-chart').forEach(function(svg){
    var tip=svg.parentElement.querySelector('.chart-tooltip');if(!tip)return;document.body.appendChild(tip);
    var hide=function(){tip.classList.remove('is-visible');};
    var show=function(point,x,y){var text=point.getAttribute('data-tip')||'';if(!text)return;tip.textContent=text;tip.classList.add('is-visible');var left=Math.min(x+14,window.innerWidth-tip.offsetWidth-12),top=Math.min(y+14,window.innerHeight-tip.offsetHeight-12);tip.style.left=Math.max(12,left)+'px';tip.style.top=Math.max(12,top)+'px';};
    svg.addEventListener('pointermove',function(e){var point=e.target.closest&&e.target.closest('.point');if(point)show(point,e.clientX,e.clientY);else hide();});
    svg.addEventListener('pointerleave',hide);svg.addEventListener('focusin',function(e){var point=e.target.closest&&e.target.closest('.point');if(point){var r=point.getBoundingClientRect();show(point,r.left+r.width/2,r.top);}});svg.addEventListener('focusout',hide);
  });
  // 30s 局部刷新快照数据 (图表随页面刷新)
  var C=2*Math.PI*30;
  var setG=function(k,pct,v){pct=Math.max(0,Math.min(100,Math.round(pct)));var a=document.querySelector('[data-ga="'+k+'"]');if(a)a.style.strokeDashoffset=(C*(1-pct/100)).toFixed(1);var t=document.querySelector('[data-gv="'+k+'"]');if(t)t.textContent=pct+'%';var g=a&&a.closest('.gauge');if(g){g.classList.remove('warn','crit');if(pct>=85)g.classList.add('crit');else if(pct>=70)g.classList.add('warn');}var el=document.querySelector('[data-f="'+k+'_v"]');if(el&&v!==undefined)el.textContent=v;};
  var poll=function(){fetch('<?= $monSelf ?>&ajax=1',{cache:'no-store'}).then(function(r){return r.json();}).then(function(d){
    var t=document.querySelector('[data-f="ts"]');if(t)t.textContent=d.ts;
    setG('cpu',d.cpu_pct,d.cpu_pct+'%');
    setG('mem',d.mem_total_mb?d.mem_used_mb*100/d.mem_total_mb:0,d.mem_used_mb.toLocaleString()+'M');
    setG('disk',d.disk_total_mb?d.disk_used_mb*100/d.disk_total_mb:0,(d.disk_used_mb/1024).toFixed(1)+'G');
    setG('load',d.load?d.load[0]/<?= MON_CORES ?>*100:0,d.load?d.load[0]:'-');
    var tc=document.querySelector('[data-f="tunnel_conn"]');if(tc)tc.textContent=d.tunnel_conn;
    var mr=document.querySelector('[data-f="min_req"]');if(mr&&d.traffic)mr.textContent=d.traffic.requests;
    if(d.services)for(var k in d.services){var dot=document.querySelector('[data-svc="'+k+'"]');var st=document.querySelector('[data-svc-t="'+k+'"]');var ok=d.services[k]==='active';if(dot){dot.classList.remove('ok','bad');dot.classList.add(ok?'ok':'bad');}if(st)st.textContent=d.services[k];}
    if(d.sites)for(var s in d.sites){var c=d.sites[s].code,ok2=c>=200&&c<400;var cd=document.querySelector('[data-site-code="'+s+'"]');if(cd){cd.textContent=c||'—';cd.classList.remove('ok','bad');cd.classList.add(ok2?'ok':'bad');}var tt=document.querySelector('[data-site-ttfb="'+s+'"]');if(tt)tt.textContent=d.sites[s].ttfb_ms;var sd=document.querySelector('[data-site-dot="'+s+'"]');if(sd){sd.classList.remove('ok','bad');sd.classList.add(ok2?'ok':'bad');}}
  }).catch(function(){});};
  setInterval(poll,30000);
})();
</script>
</body>
</html>
