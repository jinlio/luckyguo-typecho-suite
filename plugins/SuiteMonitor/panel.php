<?php
// Typecho 后台面板: extending.php?panel=SuiteMonitor%2Fpanel.php
// common.php 已强制登录; 此处再做管理员角色校验
if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }

$monUser = \Typecho_Widget::widget('Widget_User');
if (!$monUser->hasLogin() || !$monUser->pass('administrator', true)) {
    http_response_code(403);
    exit('Forbidden');
}

$monitorOptions = \Widget\Options::alloc();
$monitorSettings = $monitorOptions->plugin('SuiteMonitor');
$monitorValue = static function (string $name, string $fallback) use ($monitorSettings): string {
    $value = trim((string) ($monitorSettings->$name ?? ''));
    return $value !== '' ? $value : $fallback;
};
$monParseMap = static function ($raw): array {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return [];
    }
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    $map = [];
    foreach (preg_split('/\R/u', $raw) as $line) {
        $line = trim($line);
        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }
        $key = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if ($key !== '' && $value !== '') {
            $map[$key] = $value;
        }
    }
    return $map;
};
$configuredSiteLabels = $monParseMap($monitorSettings->siteLabels ?? '');
$configuredSiteUrls = $monParseMap($monitorSettings->siteUrls ?? '');
$configuredServiceLabels = $monParseMap($monitorSettings->serviceLabels ?? '');
$configuredNavItems = $monParseMap($monitorSettings->navItems ?? '');
$configuredRange = trim((string) ($monitorSettings->defaultRange ?? '24h'));
$defaultRange = in_array($configuredRange, ['24h', '7d', '30d', '1y'], true) ? $configuredRange : '24h';
$refreshSeconds = (int) ($monitorSettings->refreshSeconds ?? 30);
$refreshSeconds = in_array($refreshSeconds, [0, 30, 60, 300], true) ? $refreshSeconds : 30;
$defaultTheme = trim((string) ($monitorSettings->defaultTheme ?? 'system'));
$defaultTheme = in_array($defaultTheme, ['system', 'light', 'dark'], true) ? $defaultTheme : 'system';
define('MON_STATUS_FILE', $monitorValue('statusFile', '/var/lib/typecho-suite/monitor/status.json'));
define('MON_STATE_DIR', $monitorValue('stateDir', '/var/lib/typecho-suite/monitor'));
define('MON_LOG_HEARTBEAT', rtrim(MON_STATE_DIR, '/') . '/log-heartbeat');
define('MON_ENV_FILE', $monitorValue('envFile', '/etc/typecho-suite/monitor.env'));
define('MON_DB_DSN', $monitorValue('databaseDsn', 'mysql:host=127.0.0.1;dbname=monitor;charset=utf8mb4'));
define('MON_TYPECHO_DB', preg_replace('/[^A-Za-z0-9_]/', '', $monitorValue('typechoDatabase', 'typecho')) ?: 'typecho');
define('MON_TYPECHO_PREFIX', preg_replace('/[^A-Za-z0-9_]/', '', $monitorValue('typechoPrefix', 'typecho_')) ?: 'typecho_');
define('MON_CORES', max(1, min(512, (int) $monitorValue('cpuCores', '1'))));
define('MON_COOKIE_NAME', preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $monitorValue('cookieName', 'suite-theme')) ? $monitorValue('cookieName', 'suite-theme') : 'suite-theme');
define('MON_COOKIE_DOMAIN', preg_match('/^\.?[A-Za-z0-9.-]+$/', $monitorValue('cookieDomain', '')) ? $monitorValue('cookieDomain', '') : '');
define('MON_STATS_ENABLED', in_array('1', (array) ($monitorSettings->enableStats ?? []), true));

function mon_read_status(): array {
    $raw = @file_get_contents(MON_STATUS_FILE);
    if ($raw === false) return [[], '监控快照文件不可读'];
    try {
        $status = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        error_log('[monitor] invalid status snapshot: ' . $e->getMessage());
        return [[], '监控快照格式无效'];
    }
    if (!is_array($status)) return [[], '监控快照格式无效'];
    return [$status, null];
}

header('Cache-Control: no-store');

function mon_e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mon_fnum(float $v, int $d = 0): string { return number_format($v, $d, '.', ','); }

// ---------- 数据源 ----------
[$S, $monStatusError] = mon_read_status();

$pdo = null;
$monEnv = @parse_ini_file(MON_ENV_FILE, false, INI_SCANNER_RAW) ?: [];
$monRoUser = trim((string) ($monitorSettings->monitorRoUser ?? ''));
$monRoPass = (string) ($monitorSettings->monitorRoPass ?? '');
if ($monRoUser === '') $monRoUser = (string) ($monEnv['MONITOR_RO_USER'] ?? '');
if ($monRoPass === '') $monRoPass = (string) ($monEnv['MONITOR_RO_PASS'] ?? '');
try {
    $pdo = new PDO(
        MON_DB_DSN,
        $monRoUser,
        $monRoPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    error_log('[monitor] database connection failed: ' . $e->getMessage());
    $pdo = null;
}

function mon_db_all(?PDO $pdo, string $label, string $sql): array {
    if ($pdo === null) return [];
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        error_log('[monitor] query ' . $label . ' failed: ' . $e->getMessage());
        return [];
    }
}

function mon_db_row(?PDO $pdo, string $label, string $sql): array {
    if ($pdo === null) return [];
    try {
        return $pdo->query($sql)->fetch() ?: [];
    } catch (Throwable $e) {
        error_log('[monitor] query ' . $label . ' failed: ' . $e->getMessage());
        return [];
    }
}

$RANGES = ['24h' => '24 小时', '7d' => '7 天', '30d' => '30 天', '1y' => '1 年'];
$range = (string)($_GET['range'] ?? $defaultRange);
if (!isset($RANGES[$range])) $range = '24h';

// ---------- SVG 图表助手 ----------
function mon_nice_max(float $v): float {
    if ($v <= 0) return 1;
    $p = pow(10, floor(log10($v)));
    foreach ([1, 1.5, 2, 2.5, 4, 5, 10] as $m) { if ($v <= $m * $p) return $m * $p; }
    return 10 * $p;
}

/** Return sampling quality information without treating missing rows as zero. */
function mon_chart_quality(array $rows): array {
    $times = [];
    foreach ($rows as $row) {
        $time = strtotime((string)($row['b'] ?? ''));
        if ($time !== false) $times[] = $time;
    }
    if (count($times) < 2) {
        return [
            'gaps' => 0,
            'latest' => $times ? date('Y-m-d H:i', end($times)) : '-',
            'gapAfter' => [],
            'minTime' => $times[0] ?? 0,
            'maxTime' => $times[0] ?? 0,
        ];
    }
    $diffs = [];
    foreach ($times as $index => $time) {
        if ($index > 0 && $time > $times[$index - 1]) $diffs[] = $time - $times[$index - 1];
    }
    sort($diffs, SORT_NUMERIC);
    $median = $diffs ? $diffs[(int) floor((count($diffs) - 1) / 2)] : 300;
    // A normal five-minute bucket can drift across a boundary during a busy
    // minute. Only mark a real outage after a materially longer interval.
    $threshold = max(900, $median * 2.5);
    $gapAfter = [];
    foreach ($times as $index => $time) {
        if ($index > 0 && ($time - $times[$index - 1]) > $threshold) $gapAfter[] = $index - 1;
    }
    return [
        'gaps' => count($gapAfter),
        'latest' => date('Y-m-d H:i', end($times)),
        'gapAfter' => $gapAfter,
        'minTime' => $times[0],
        'maxTime' => end($times),
    ];
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
        foreach ($series as $s) $leftMax = max($leftMax, (float)($r[$s['key']] ?? 0));
        foreach ($rightSeries as $s) $rightMax = max($rightMax, (float)($r[$s['key']] ?? 0));
    }
    foreach ($series as $s) if (isset($s['axisMax'])) $leftMax = max($leftMax, (float)$s['axisMax']);
    foreach ($rightSeries as $s) if (isset($s['axisMax'])) $rightMax = max($rightMax, (float)$s['axisMax']);
    $leftMax = mon_nice_max($leftMax);
    $rightMax = $rightSeries ? mon_nice_max($rightMax) : $leftMax;
    $quality = mon_chart_quality($rows);
    $timeSpan = max(1, (int)$quality['maxTime'] - (int)$quality['minTime']);
    $x = fn(int $i): float => $n <= 1 || $quality['maxTime'] === $quality['minTime']
        ? $padL + $iw / 2
        : $padL + $iw * ((strtotime((string)($rows[$i]['b'] ?? '')) - (int)$quality['minTime']) / $timeSpan);
    $axisOf = fn(array $s): string => $rightSeries && (($s['axis'] ?? 'left') === 'right') ? 'right' : 'left';
    $y = fn(float $v, string $axis): float => $padT + $ih * (1 - min($v, $axis === 'right' ? $rightMax : $leftMax) / ($axis === 'right' ? $rightMax : $leftMax));
    $segments = [];
    $segmentStart = 0;
    foreach ($quality['gapAfter'] as $gapAfter) {
        $segments[] = [$segmentStart, $gapAfter];
        $segmentStart = $gapAfter + 1;
    }
    $segments[] = [$segmentStart, $n - 1];

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
    $tipParts = array_fill(0, $n, []);
    foreach ($allSeries as $s) {
        $axis = $axisOf($s);
        foreach ($segments as [$from, $to]) {
            $pts = [];
            for ($i = $from; $i <= $to; $i++) {
                $pts[] = round($x($i), 1) . ',' . round($y((float)($rows[$i][$s['key']] ?? 0), $axis), 1);
            }
            if (!empty($s['area']) && count($pts) > 1) {
                $out .= "<polygon points=\"" . round($x($from), 1) . "," . ($padT + $ih) . " " . implode(' ', $pts) . " " . round($x($to), 1) . "," . ($padT + $ih) . "\" fill=\"{$s['color']}\" opacity=\"0.10\"/>";
            }
            if (count($pts) > 1) {
                $out .= "<polyline class=\"line\" pathLength=\"1\" points=\"" . implode(' ', $pts) . "\" stroke=\"{$s['color']}\"/>";
            } else {
                [$cx, $cy] = explode(',', $pts[0]);
                $out .= "<circle class=\"point-marker\" cx=\"$cx\" cy=\"$cy\" r=\"2\" fill=\"{$s['color']}\"/>";
            }
        }
        foreach ($rows as $i => $r) {
            $value = (float)($r[$s['key']] ?? 0);
            $precision = isset($s['precision']) ? (int)$s['precision'] : ($value < 10 ? 2 : 0);
            $unit = (string)($s['unit'] ?? '');
            $tipParts[$i][] = (string)$s['label'] . ' ' . mon_fnum($value, $precision) . $unit;
        }
    }
    foreach ($rows as $i => $r) {
        $tip = mon_e(date($labelFmt, strtotime((string)$r['b'])) . ' · ' . implode(' · ', $tipParts[$i]));
        // Adjacent hit bands meet at their midpoints. This preserves precise hover values
        // without rendering markers or allowing neighbouring data points to overlap.
        $hitLeft = $i === 0 ? $padL : ($x($i - 1) + $x($i)) / 2;
        $hitRight = $i === $n - 1 ? $padL + $iw : ($x($i) + $x($i + 1)) / 2;
        $out .= "<rect class=\"point\" pointer-events=\"all\" data-tip=\"$tip\" x=\"" . round($hitLeft, 1) . "\" y=\"$padT\" width=\"" . round($hitRight - $hitLeft, 1) . "\" height=\"$ih\" fill=\"transparent\"/>";
    }
    return $out . '</svg>';
}

/** 柱状图 (可选异常叠段) */
function mon_bar_chart(array $rows, string $key, ?string $errKey, string $labelFmt, string $valueLabel, ?string $errLabel = null, int $w = 720, int $h = 168): string {
    $n = count($rows);
    if ($n === 0) return '<div class="chart-empty">暂无数据</div>';
    $padL = 40; $padR = 10; $padT = 12; $padB = 22;
    $iw = $w - $padL - $padR; $ih = $h - $padT - $padB;
    $max = 0.0;
    foreach ($rows as $r) $max = max($max, (float)$r[$key]);
    $max = mon_nice_max($max);
    // Keep dense traffic bars readable while preventing short series from becoming columns.
    $bw = min(10.0, max(0.8, $iw / $n * 0.5));

    $out = "<svg class=\"trend-chart bar-chart\" viewBox=\"0 0 $w $h\" role=\"img\" aria-label=\"" . mon_e($valueLabel) . "趋势图\">";
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
        $barDelay = min($i, 36) * 0.012;
        $tip = mon_e(date($labelFmt, $t) . ' · ' . $valueLabel . ' ' . mon_fnum($v)
            . ($errKey !== null ? ' · ' . ($errLabel ?? '异常') . ' ' . mon_fnum((float)$r[$errKey]) : ''));
        $out .= "<rect class=\"bar-fill\" style=\"animation-delay:{$barDelay}s\" x=\"" . round($bx, 1) . "\" y=\"" . round($by, 1) . "\" width=\"" . round($bw, 1) . "\" height=\"" . round(max($bh, $v > 0 ? 1.5 : 0), 1) . "\" rx=\"1\" fill=\"var(--accent)\" opacity=\"0.8\"/>";
        if ($errKey !== null && (float)$r[$errKey] > 0) {
            $eh = $ih * (float)$r[$errKey] / $max;
            $out .= "<rect class=\"bar-fill bar-error\" style=\"animation-delay:{$barDelay}s\" x=\"" . round($bx, 1) . "\" y=\"" . round($by, 1) . "\" width=\"" . round($bw, 1) . "\" height=\"" . round(max($eh, 1.5), 1) . "\" rx=\"1\" fill=\"var(--accent-strong)\"/>";
        }
    }
    foreach ([0, intdiv($n - 1, 2), $n - 1] as $i) {
        $t = strtotime((string)$rows[$i]['b']);
        $out .= "<text class=\"axis\" x=\"" . ($padL + $iw * ($i + 0.5) / $n) . "\" y=\"" . ($h - 6) . "\" text-anchor=\"middle\">" . date($labelFmt, $t) . "</text>";
    }
    foreach ($rows as $i => $r) {
        $v = (float)$r[$key];
        $tip = mon_e(date($labelFmt, strtotime((string)$r['b'])) . ' · ' . $valueLabel . ' ' . mon_fnum($v)
            . ($errKey !== null ? ' · ' . ($errLabel ?? '异常') . ' ' . mon_fnum((float)$r[$errKey]) : ''));
        $hitLeft = $padL + $iw * $i / $n;
        $hitRight = $padL + $iw * ($i + 1) / $n;
        $out .= "<rect class=\"point\" pointer-events=\"all\" data-tip=\"$tip\" x=\"" . round($hitLeft, 1) . "\" y=\"$padT\" width=\"" . round($hitRight - $hitLeft, 1) . "\" height=\"$ih\" fill=\"transparent\"/>";
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

$rollup = ['24h' => null, '7d' => 'hourly', '30d' => 'daily', '1y' => 'daily'][$range];
if ($rollup === null) {
    $metrics = mon_db_all($pdo, 'raw metrics',
        "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/$bucket)*$bucket) AS b,
                MAX(cpu_pct) cpu, ROUND(AVG(load1),2) l1,
                ROUND(AVG(mem_used*100.0/GREATEST(mem_total,1))) memp,
                LEAST(100, GREATEST(0, ROUND(AVG(CASE WHEN swap_total > 0 THEN swap_used*100.0/swap_total ELSE 0 END)))) swapp,
                ROUND(AVG(net_rx_kbps)) rx, ROUND(AVG(net_tx_kbps)) tx
         FROM metrics WHERE ts >= NOW() - INTERVAL $interval GROUP BY b ORDER BY b");
    $traffic = mon_db_all($pdo, 'raw traffic',
        "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/$bucket)*$bucket) AS b,
                SUM(requests) req, SUM(s4xx+s5xx) err
         FROM traffic_min WHERE ts >= NOW() - INTERVAL $interval GROUP BY b ORDER BY b");
} else {
    $metrics = mon_db_all($pdo, $rollup . ' metrics',
        "SELECT bucket AS b, cpu, l1, memp, swapp, rx, tx
         FROM metrics_$rollup WHERE bucket >= NOW() - INTERVAL $interval ORDER BY bucket");
    $traffic = mon_db_all($pdo, $rollup . ' traffic',
        "SELECT bucket AS b, requests AS req, s4xx + s5xx AS err
         FROM traffic_$rollup WHERE bucket >= NOW() - INTERVAL $interval ORDER BY bucket");
}

$sites24h = mon_db_all($pdo, 'site checks',
    "SELECT target, FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/900)*900) AS b,
            SUM(http_code=0 OR http_code>=500) bad, COUNT(*) n, ROUND(AVG(ttfb_ms)) ttfb
     FROM site_checks WHERE ts >= NOW() - INTERVAL 1 DAY GROUP BY target, b ORDER BY b");
$uptime30 = mon_db_all($pdo, 'uptime summary',
    "SELECT target, ROUND(100*SUM(http_code BETWEEN 200 AND 399)/COUNT(*),2) up
     FROM site_checks WHERE ts >= NOW() - INTERVAL 30 DAY GROUP BY target");
$codes = mon_db_row($pdo, 'traffic totals',
    "SELECT SUM(s2xx) s2, SUM(s3xx) s3, SUM(s4xx) s4, SUM(s5xx) s5, SUM(requests) req, SUM(bytes_kb) kb
     FROM traffic_min WHERE ts >= NOW() - INTERVAL 1 DAY");
$topIps = mon_db_all($pdo, 'top IPs',
    "SELECT jt.ip, SUM(jt.c) n FROM traffic_min,
            JSON_TABLE(CASE WHEN JSON_VALID(top_ips) THEN top_ips ELSE JSON_ARRAY() END,
                       '$[*]' COLUMNS (ip VARCHAR(45) PATH '$[0]', c INT PATH '$[1]')) jt
     WHERE ts >= NOW() - INTERVAL 1 DAY GROUP BY jt.ip ORDER BY n DESC LIMIT 8");
$typechoDb = '`' . MON_TYPECHO_DB . '`';
$typechoTable = static function (string $name) use ($typechoDb): string {
    return $typechoDb . '.`' . MON_TYPECHO_PREFIX . $name . '`';
};
if (MON_STATS_ENABLED) {
    $visitorsTable = $typechoTable('suite_visitors');
    $visitsTable = $typechoTable('suite_visits');
    $visitorsDailyTable = $typechoTable('suite_visitors_daily');
    $viewsTable = $typechoTable('suite_views');
    $uvDaily = mon_db_all($pdo, 'daily visitors',
        "SELECT vday AS b, COUNT(*) uv FROM {$visitorsTable}
         WHERE vday >= CURDATE() - INTERVAL 29 DAY GROUP BY vday ORDER BY vday");
    $blogStats = mon_db_row($pdo, 'blog totals',
        "SELECT
            (SELECT COUNT(*) FROM {$visitorsTable} WHERE vday=CURDATE()) today_uv,
            (SELECT COALESCE(SUM(pv),0) FROM {$visitsTable} WHERE vday=CURDATE()) today_pv,
            (SELECT COALESCE(SUM(uv),0) FROM {$visitorsDailyTable})
              + (SELECT COUNT(DISTINCT vip) FROM {$visitorsTable}) total_uv,
            (SELECT COALESCE(SUM(pv),0) FROM {$visitsTable}) total_pv");
    $todayUv = (int)($blogStats['today_uv'] ?? 0);
    $todayPv = (int)($blogStats['today_pv'] ?? 0);
    $totalUv = (int)($blogStats['total_uv'] ?? 0);
    $totalPv = (int)($blogStats['total_pv'] ?? 0);
    $topPosts = mon_db_all($pdo, 'top posts',
        "SELECT c.title, SUM(v.views) vv
         FROM {$viewsTable} v
         JOIN {$typechoDb}.`" . MON_TYPECHO_PREFIX . "contents` c ON c.cid = v.cid AND c.status='publish'
         GROUP BY v.cid, c.title ORDER BY vv DESC LIMIT 5");
}

// ---------- 24h 异常日志 ----------
// log_events is optional: the dashboard remains usable when no collector has been installed.
$logs = [];
$logEvents = mon_db_all($pdo, 'log events',
    "SELECT ts, source, level, message FROM log_events
     WHERE ts >= NOW() - INTERVAL 1 DAY ORDER BY ts DESC LIMIT 300");
$siteFails = mon_db_all($pdo, 'site failures',
    "SELECT ts, 'site' AS source, 'error' AS level,
            CONCAT('站点探测失败: ', target, ' HTTP ', http_code,
                   CASE WHEN http_code=0 THEN '（连接超时或拒绝）' ELSE '' END) AS message
     FROM site_checks WHERE ts >= NOW() - INTERVAL 1 DAY
       AND (http_code = 0 OR http_code >= 500)
     ORDER BY ts DESC LIMIT 50");
foreach (array_merge($logEvents, $siteFails) as $logEvent) {
    $logs[] = [
        'ts' => (string)($logEvent['ts'] ?? ''),
        'source' => (string)($logEvent['source'] ?? 'unknown'),
        'level' => (string)($logEvent['level'] ?? 'warn'),
        'message' => (string)($logEvent['message'] ?? ''),
    ];
}
usort($logs, static fn(array $a, array $b): int => strcmp($b['ts'], $a['ts']));
$logs = array_slice($logs, 0, 300);
$logCounts = ['all' => count($logs), 'error' => 0, 'warn' => 0, 'info' => 0];
foreach ($logs as $logEvent) {
    if (isset($logCounts[$logEvent['level']])) {
        $logCounts[$logEvent['level']]++;
    }
}
$logHeartbeat = '';
$heartbeatRaw = @file_get_contents(MON_LOG_HEARTBEAT);
if ($heartbeatRaw !== false && strtotime(trim($heartbeatRaw)) !== false) {
    $logHeartbeat = trim($heartbeatRaw);
}
$logHeartbeatTime = $logHeartbeat !== '' ? strtotime($logHeartbeat) : false;
$logCollectionEnabled = trim((string)($monitorSettings->logSources ?? '')) !== ''
    || trim((string)($monitorSettings->logJournalUnits ?? '')) !== '';
$logFreshStale = $logCollectionEnabled
    && ($logHeartbeatTime === false || (time() - $logHeartbeatTime > 150));

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
$services = is_array($S['services'] ?? null) ? $S['services'] : [];
$sites = is_array($S['sites'] ?? null) ? $S['sites'] : [];
$ts = $S['ts'] ?? '-';

$svcLabel = [];
foreach ($configuredServiceLabels as $key => $label) {
    $key = (string) $key;
    $label = trim((string) $label);
    if (preg_match('/^[A-Za-z0-9_.@-]+$/', $key) && $label !== '') {
        $svcLabel[$key] = $label;
    }
}
foreach (array_keys($services) as $key) {
    if (!isset($svcLabel[$key])) {
        $svcLabel[$key] = ucwords(str_replace(['-', '_'], ' ', (string) $key));
    }
}
$siteLabel = [];
foreach ($configuredSiteLabels as $key => $label) {
    $key = (string) $key;
    $label = trim((string) $label);
    if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key) && $label !== '') {
        $siteLabel[$key] = $label;
    }
}
foreach (array_keys($sites) as $key) {
    $key = (string) $key;
    if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key) && !isset($siteLabel[$key])) {
        $siteLabel[$key] = ucfirst(str_replace(['-', '_'], ' ', $key));
    }
}
$siteUrl = [];
foreach ($configuredSiteUrls as $key => $url) {
    $key = (string) $key;
    $url = trim((string) $url);
    if (isset($siteLabel[$key]) && preg_match('#^https?://#i', $url)) {
        $siteUrl[$key] = $url;
    }
}

$stripRows = [];
foreach ($sites24h as $r) $stripRows[$r['target']][strtotime((string)$r['b'])] = $r;
$uptimeMap = array_column($uptime30, 'up', 'target');

if (isset($_GET['ajaxlog'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'rows' => $logs,
        'collected_at' => $logHeartbeat,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $charts = [
        'cpu' => mon_line_chart($metrics, [
            ['key' => 'cpu', 'label' => 'CPU', 'unit' => '%', 'precision' => 0, 'color' => 'var(--accent)', 'area' => true],
        ], $labelFmt, 720, 168, [
            ['key' => 'l1', 'label' => '负载 1min', 'precision' => 2, 'color' => 'var(--sage)', 'axis' => 'right'],
        ]),
        'memory' => mon_line_chart($metrics, [
            ['key' => 'memp', 'label' => '内存 %', 'color' => 'var(--accent)', 'area' => true, 'axisMax' => 100],
            ['key' => 'swapp', 'label' => 'Swap %', 'color' => 'var(--sage)', 'axisMax' => 100],
        ], $labelFmt),
        'network' => mon_line_chart($metrics, [
            ['key' => 'rx', 'label' => '接收 KB/s', 'color' => 'var(--accent)', 'area' => true],
            ['key' => 'tx', 'label' => '发送 KB/s', 'color' => 'var(--sage)'],
        ], $labelFmt),
        'traffic' => mon_bar_chart($traffic, 'req', 'err', $labelFmt, '请求量', '4xx+5xx'),
    ];
    if (MON_STATS_ENABLED) {
        $charts['visitors'] = mon_bar_chart($uvDaily, 'uv', null, 'm-d', '访客');
    }
    echo json_encode([
        'snapshot' => $S,
        'charts' => $charts,
        'quality' => mon_chart_quality($metrics),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mon_site_dot($code): string { return ($code >= 200 && $code < 400) ? 'ok' : 'bad'; }

$monSelf = 'extending.php?panel=SuiteMonitor%2Fpanel.php';
$adminUrl = '';
ob_start();
$monitorOptions->adminUrl();
$adminUrl = (string) ob_get_clean();
$blogUrl = (string) $monitorOptions->siteUrl;
ob_start();
$monitorOptions->pluginUrl('SuiteMonitor');
$monitorPluginUrl = rtrim((string) ob_get_clean(), '/');
$navItems = [];
$navConfig = $configuredNavItems ?: [
    'console' => '控制台|admin',
    'home' => '首页|site',
    'landing' => '落地页|landing',
];
foreach ($navConfig as $key => $rawItem) {
    $key = (string) $key;
    $rawItem = trim((string) $rawItem);
    $parts = explode('|', $rawItem, 2);
    $label = trim((string)($parts[0] ?? ''));
    $target = trim((string)($parts[1] ?? ''));
    if ($label === '' || $target === '') {
        continue;
    }
    if ($target === 'admin') {
        $url = $adminUrl;
    } elseif ($target === 'site') {
        $url = $blogUrl;
    } elseif (isset($siteUrl[$target])) {
        $url = $siteUrl[$target];
    } elseif (preg_match('#^https?://#i', $target)) {
        $url = $target;
    } else {
        continue;
    }
    if ($url !== '') {
        $navItems[] = ['label' => $label, 'url' => $url, 'key' => $key];
    }
}
$footerRepoUrl = trim((string)($monitorSettings->footerRepoUrl ?? ''));
if (!preg_match('#^https?://#i', $footerRepoUrl)) {
    $footerRepoUrl = '';
}
$footerRepoSetting = $monitorSettings->showFooterRepo ?? null;
$showFooterRepo = $footerRepoSetting === null
    ? $footerRepoUrl !== ''
    : in_array('1', array_map('strval', (array)$footerRepoSetting), true);
if (!$showFooterRepo) {
    $footerRepoUrl = '';
}
$footerRepoLabel = trim((string)($monitorSettings->footerRepoLabel ?? '')) ?: '代码仓库';
$monitorBrandName = trim((string) ($monitorSettings->brandName ?? ''))
    ?: trim((string) ($monitorOptions->siteName ?? $monitorOptions->title ?? 'Typecho Suite'));
$monitorBrandName = $monitorBrandName ?: 'Typecho Suite';
$monitorBrandHandle = trim((string) ($monitorSettings->brandHandle ?? ''))
    ?: trim((string) ($monitorOptions->authorHandle ?? 'status'));
$monitorBrandHandle = $monitorBrandHandle ?: 'status';
$monitorBrandAvatar = trim((string) ($monitorSettings->brandAvatarUrl ?? ''))
    ?: trim((string) ($monitorOptions->avatarUrl ?? ''));
if (!preg_match('#^https?://#i', $monitorBrandAvatar)) {
    $monitorBrandAvatar = '';
}
$memoryLabel = (int)($S['mem_total_mb'] ?? 0) > 0 ? mon_fnum((float)$S['mem_total_mb']) . 'MB RAM' : 'RAM';
$diskLabel = (int)($S['disk_total_mb'] ?? 0) > 0 ? mon_fnum((float)$S['disk_total_mb'] / 1024, 1) . 'GB 盘' : '磁盘';
$metricsQuality = mon_chart_quality($metrics);
$metricsQualityText = $metricsQuality['latest'] === '-'
    ? '暂无采样数据'
    : ('最后采集 ' . $metricsQuality['latest'] . ($metricsQuality['gaps'] > 0 ? ' · ' . $metricsQuality['gaps'] . ' 处数据缺口' : ''));
$snapshotTime = isset($S['ts']) ? strtotime((string)$S['ts']) : false;
$snapshotAge = $snapshotTime === false ? PHP_INT_MAX : max(0, time() - $snapshotTime);
$monitorChecks = [
    ['label' => '状态快照', 'ok' => $monStatusError === null, 'detail' => $monStatusError === null ? '文件可读' : $monStatusError],
    ['label' => '监控数据库', 'ok' => $pdo instanceof PDO, 'detail' => $pdo instanceof PDO ? '连接正常' : '无法连接或凭据未配置'],
    ['label' => '采集器新鲜度', 'ok' => $snapshotAge <= 180, 'detail' => $snapshotAge === PHP_INT_MAX ? '尚未生成快照' : ($snapshotAge . ' 秒前更新')],
    ['label' => '资源采样', 'ok' => count($metrics) > 0, 'detail' => count($metrics) > 0 ? count($metrics) . ' 个数据点' : '暂无历史数据'],
];
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Typecho Suite 站点状态监控">
<meta name="theme-color" content="<?= (($_COOKIE[MON_COOKIE_NAME] ?? '') === 'dark') ? '#1c191d' : '#fcfafb' ?>">
<meta name="robots" content="noindex, nofollow">
<title>站点状态 · <?= mon_e($monitorBrandName) ?></title>
<script>window.SuiteMonitorThemeConfig=<?= json_encode(['name' => MON_COOKIE_NAME, 'domain' => MON_COOKIE_DOMAIN, 'defaultTheme' => $defaultTheme], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;(function(){var c=window.SuiteMonitorThemeConfig,m=document.cookie.match(new RegExp('(?:^|;\\s*)'+c.name+'=(dark|light)(?:;|$)')),saved='';try{saved=localStorage.getItem(c.name)||'';}catch(e){}var t=m?m[1]:saved||((matchMedia('(prefers-color-scheme:dark)').matches)?'dark':'light');if(!m&&!saved&&(c.defaultTheme==='dark'||c.defaultTheme==='light'))t=c.defaultTheme;document.documentElement.dataset.theme=t;})();</script>
<link rel="stylesheet" href="<?= mon_e($monitorPluginUrl . '/style.css?v=2.1.0') ?>">
</head>
<body>
<header class="site-header">
    <a class="brand" href="<?= $monSelf ?>">
        <?php if ($monitorBrandAvatar !== ''): ?>
        <img class="suite-monitor-mark" src="<?= mon_e($monitorBrandAvatar) ?>" alt="<?= mon_e($monitorBrandName) ?>">
        <?php else: ?>
        <span class="suite-monitor-mark" aria-hidden="true">TS</span>
        <?php endif; ?>
        <span><strong><?= mon_e($monitorBrandName) ?></strong><small><?= mon_e($monitorBrandHandle) ?></small></span>
    </a>
    <nav class="site-nav"><?php foreach ($navItems as $navItem): ?><a href="<?= mon_e($navItem['url']) ?>"><?= mon_e($navItem['label']) ?></a><?php endforeach; ?></nav>
    <span class="updated" title="采集器每分钟更新">更新于 <span data-f="ts"><?= mon_e($ts) ?></span></span>
    <button class="theme-toggle" type="button" aria-label="切换深浅色主题" title="切换主题">◐</button>
</header>

<main>
    <section class="config-check" aria-labelledby="config-check-title">
        <header><div><p class="eyebrow">CHECK</p><h2 id="config-check-title">配置检查</h2></div><span class="hint">帮助定位空面板和数据延迟</span></header>
        <div class="check-list">
            <?php foreach ($monitorChecks as $check): ?>
            <div class="check-item <?= $check['ok'] ? 'ok' : 'bad' ?>"><span class="check-dot" aria-hidden="true"></span><strong><?= mon_e($check['label']) ?></strong><small><?= mon_e($check['detail']) ?></small></div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- 概览 -->
    <section>
        <header><div><p class="eyebrow">OVERVIEW</p><h2>服务器概览</h2></div><span class="hint<?= $monStatusError !== null ? ' status-warning' : '' ?>"><?= $monStatusError !== null ? mon_e($monStatusError) : mon_e($memoryLabel . ' · ' . $diskLabel . ' · 已运行 ' . $upStr) ?></span></header>
        <div class="cards">
            <?= mon_gauge_card('cpu', 'CPU', $cpu, $cpu . '%', '负载 ' . mon_e((string)($load[0] ?? 0)) . ' / ' . mon_e((string)($load[1] ?? 0)) . ' / ' . mon_e((string)($load[2] ?? 0))) ?>
            <?= mon_gauge_card('mem', '内存', $memPct, mon_fnum((float)$memU) . 'M', '共 ' . mon_fnum((float)$memT) . 'M · swap ' . mon_fnum((float)$swapU) . 'M') ?>
            <?= mon_gauge_card('disk', '磁盘', $diskPct, mon_fnum($diskU / 1024, 1) . 'G', '共 ' . mon_fnum($diskT / 1024, 0) . 'G') ?>
            <?= mon_gauge_card('load', '负载', min($loadPct, 100), mon_e((string)($load[0] ?? 0)), '按 ' . MON_CORES . ' 核折算 · 进程 ' . (int)($S['procs'] ?? 0)) ?>
        </div>
    </section>

    <!-- 服务状态 -->
    <section>
        <header><div><p class="eyebrow">SERVICES</p><h2>服务状态</h2></div><span class="hint">由采集器配置的 systemd 服务</span></header>
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
                <div class="strip">
                    <?php
                    $now = time();
                    for ($i = 95; $i >= 0; $i--):
                        $slotStart = $now - $now % 900 - $i * 900;
                        $r = $rows[$slotStart] ?? null;
                        if ($r === null || (int)$r['n'] === 0) { $cls = 'none'; $tip = '无数据'; }
                        elseif ((int)$r['bad'] > 0) { $cls = 'down'; $tip = '异常'; }
                        else { $cls = 'ok'; $tip = '正常'; }
                        $tip = $name . ' · ' . date('H:i', $slotStart) . ' · ' . $tip
                            . ($r ? ' · TTFB ' . (int)$r['ttfb'] . ' ms' : '');
                    ?>
                    <i class="<?= $cls ?> uptime-point" data-tip="<?= mon_e($tip) ?>" aria-label="<?= mon_e($tip) ?>"></i>
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
            <span class="hint<?= $metricsQuality['gaps'] > 0 ? ' status-warning' : '' ?>" data-chart-quality><?= mon_e($metricsQualityText) ?></span>
            <nav class="range-nav">
                <?php foreach ($RANGES as $rk => $rn): ?>
                <a href="<?= $monSelf ?>&range=<?= mon_e($rk) ?>" class="<?= $rk === $range ? 'cur' : '' ?>"><?= mon_e($rn) ?></a>
                <?php endforeach; ?>
            </nav>
        </header>
        <div class="grid-2">
            <div class="panel chart" data-chart-key="cpu">
                <?= mon_line_chart($metrics, [
                    ['key' => 'cpu', 'label' => 'CPU', 'unit' => '%', 'precision' => 0, 'color' => 'var(--accent)', 'area' => true],
                ], $labelFmt, 720, 168, [
                    ['key' => 'l1', 'label' => '负载 1min', 'precision' => 2, 'color' => 'var(--sage)', 'axis' => 'right'],
                ]) ?>
                <div class="legend"><span><i style="background:var(--accent)"></i>CPU %</span><span><i style="background:var(--sage)"></i>负载 1min</span></div>
            </div>
            <div class="panel chart" data-chart-key="memory">
                <?= mon_line_chart($metrics, [
                    ['key' => 'memp', 'label' => '内存 %', 'color' => 'var(--accent)', 'area' => true, 'axisMax' => 100],
                    ['key' => 'swapp', 'label' => 'Swap %', 'color' => 'var(--sage)', 'axisMax' => 100],
                ], $labelFmt) ?>
                <div class="legend"><span><i style="background:var(--accent)"></i>内存 %</span><span><i style="background:var(--sage)"></i>Swap %</span></div>
            </div>
            <div class="panel chart" data-chart-key="network">
                <?= mon_line_chart($metrics, [
                    ['key' => 'rx', 'label' => '接收 KB/s', 'color' => 'var(--accent)', 'area' => true],
                    ['key' => 'tx', 'label' => '发送 KB/s', 'color' => 'var(--sage)'],
                ], $labelFmt) ?>
                <div class="legend"><span><i style="background:var(--accent)"></i>接收 KB/s</span><span><i style="background:var(--sage)"></i>发送 KB/s</span></div>
            </div>
            <div class="panel chart" data-chart-key="traffic">
                <?= mon_bar_chart($traffic, 'req', 'err', $labelFmt, '请求量', '4xx+5xx') ?>
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

    <?php if (MON_STATS_ENABLED): ?>
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
            <div class="panel chart" data-chart-key="visitors">
                <?= mon_bar_chart($uvDaily, 'uv', null, 'm-d', '访客') ?>
                <div class="legend"><span><i style="background:var(--accent)"></i>每日访客 · 近 30 天</span></div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- 异常日志 -->
    <section>
        <header>
            <div><p class="eyebrow">LOGS</p><h2>24 小时异常日志</h2></div>
            <span class="hint">文件日志与站点探测失败<?php if ($logFreshStale): ?> · <span id="log-fresh" class="log-fresh stale">采集可能异常</span><?php else: ?><span id="log-fresh" class="log-fresh"></span><?php endif; ?></span>
        </header>
        <div class="panel log-panel">
            <div class="log-toolbar">
                <span class="log-total"><b><?= $logCounts['all'] ?></b> 条事件</span>
                <div class="log-filters" role="tablist" aria-label="日志级别筛选">
                <button type="button" class="log-filter cur" data-lf="all" role="tab" aria-selected="true">全部 <b><?= $logCounts['all'] ?></b></button>
                <button type="button" class="log-filter" data-lf="error" role="tab" aria-selected="false">错误 <b><?= $logCounts['error'] ?></b></button>
                <button type="button" class="log-filter" data-lf="warn" role="tab" aria-selected="false">警告 <b><?= $logCounts['warn'] ?></b></button>
                <button type="button" class="log-filter" data-lf="info" role="tab" aria-selected="false">信息 <b><?= $logCounts['info'] ?></b></button>
                </div>
                <span class="log-window">按时间倒序 · 自动刷新 60 秒</span>
            </div>
            <div class="log-table">
                <div class="log-row log-head"><span>时间</span><span>来源</span><span>级别</span><span>消息</span></div>
                <div id="log-rows">
                    <?php if (!$logs): ?>
                    <div class="log-row log-empty"><span>近 24 小时暂无日志事件</span></div>
                    <?php else: foreach ($logs as $logEvent):
                        $level = in_array($logEvent['level'], ['error', 'warn', 'info'], true) ? $logEvent['level'] : 'warn';
                        $levelName = ['error' => '错误', 'warn' => '警告', 'info' => '信息'][$level];
                        $sourceClass = preg_match('/^[a-zA-Z0-9-]+$/', $logEvent['source']) ? $logEvent['source'] : 'other';
                        $eventTime = strtotime($logEvent['ts']);
                    ?>
                    <div class="log-row" data-lv="<?= mon_e($level) ?>">
                        <span class="log-t"><?= mon_e($eventTime ? date('m-d H:i:s', $eventTime) : $logEvent['ts']) ?></span>
                        <span class="log-src src-<?= mon_e($sourceClass) ?>"><?= mon_e($logEvent['source']) ?></span>
                        <span class="log-badge <?= mon_e($level) ?>"><?= mon_e($levelName) ?></span>
                        <span class="log-msg" title="<?= mon_e($logEvent['message']) ?>"><?= mon_e($logEvent['message']) ?></span>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </section>
</main>

<div class="chart-tooltip" role="status" aria-live="polite"></div>

<footer><span><?= mon_e($monitorBrandName) ?> · <?= mon_e($monitorBrandHandle) ?></span><span>STATUS / <?= date('Y') ?><?php if ($footerRepoUrl !== ''): ?> · <a href="<?= mon_e($footerRepoUrl) ?>" rel="noopener noreferrer" target="_blank"><?= mon_e($footerRepoLabel) ?></a><?php endif; ?></span></footer>

<script>
(function(){
  var root=document.documentElement,toggle=document.querySelector('.theme-toggle');
  var tc=window.SuiteMonitorThemeConfig||{name:'suite-theme',domain:''};
  var save=function(t){try{localStorage.setItem(tc.name,t);}catch(e){}document.cookie=tc.name+'='+t+'; Max-Age=31536000; Path=/'+(tc.domain?'; Domain='+tc.domain:'')+'; SameSite=Lax; Secure';};
  var color=function(){var m=document.querySelector('meta[name="theme-color"]');if(m)m.setAttribute('content',root.dataset.theme==='dark'?'#1c191d':'#fcfafb');};
  var styleTimer=0,animationTimer=0;
  if(toggle)toggle.addEventListener('click',function(){var t=root.dataset.theme==='dark'?'light':'dark';root.classList.add('theme-switching');void root.offsetWidth;toggle.classList.remove('is-rotating');void toggle.offsetWidth;toggle.classList.add('is-rotating');root.dataset.theme=t;save(t);color();clearTimeout(styleTimer);clearTimeout(animationTimer);styleTimer=setTimeout(function(){root.classList.remove('theme-switching');},280);animationTimer=setTimeout(function(){toggle.classList.remove('is-rotating');},700);});
  var tip=document.querySelector('.chart-tooltip');
  var hideTip=function(){if(tip)tip.classList.remove('is-visible');};
  var showTip=function(point,x,y){if(!tip)return;var text=point.getAttribute('data-tip')||'';if(!text)return;tip.textContent=text;tip.classList.add('is-visible');var tw=tip.offsetWidth,th=tip.offsetHeight,left=x+14,top=point.classList.contains('uptime-point')?y-th-10:y+14;if(left+tw>window.innerWidth-12)left=x-tw-14;if(top+th>window.innerHeight-12)top=y-th-14;if(top<12)top=y+14;tip.style.left=Math.max(12,Math.min(left,window.innerWidth-tw-12))+'px';tip.style.top=Math.max(12,Math.min(top,window.innerHeight-th-12))+'px';};
  document.addEventListener('pointermove',function(e){var point=e.target.closest&&e.target.closest('.point,.uptime-point');if(point)showTip(point,e.clientX,e.clientY);else hideTip();});
  document.addEventListener('pointerleave',hideTip);window.addEventListener('blur',hideTip);window.addEventListener('scroll',hideTip,{passive:true});
  // 30s 局部刷新快照数据 (图表随页面刷新)
  var C=2*Math.PI*30,chartStamp=<?= json_encode($metricsQuality['latest']) ?>;
  var setG=function(k,pct,v){pct=Math.max(0,Math.min(100,Math.round(pct)));var a=document.querySelector('[data-ga="'+k+'"]');if(a)a.style.strokeDashoffset=(C*(1-pct/100)).toFixed(1);var t=document.querySelector('[data-gv="'+k+'"]');if(t)t.textContent=pct+'%';var g=a&&a.closest('.gauge');if(g){g.classList.remove('warn','crit');if(pct>=85)g.classList.add('crit');else if(pct>=70)g.classList.add('warn');}var el=document.querySelector('[data-f="'+k+'_v"]');if(el&&v!==undefined)el.textContent=v;};
  var poll=function(){fetch('<?= $monSelf ?>&ajax=1&range=<?= mon_e($range) ?>',{cache:'no-store'}).then(function(r){return r.json();}).then(function(payload){
    var d=payload.snapshot||payload;
    var t=document.querySelector('[data-f="ts"]');if(t)t.textContent=d.ts;
    setG('cpu',d.cpu_pct,d.cpu_pct+'%');
    setG('mem',d.mem_total_mb?d.mem_used_mb*100/d.mem_total_mb:0,d.mem_used_mb.toLocaleString()+'M');
    setG('disk',d.disk_total_mb?d.disk_used_mb*100/d.disk_total_mb:0,(d.disk_used_mb/1024).toFixed(1)+'G');
    setG('load',d.load?d.load[0]/<?= MON_CORES ?>*100:0,d.load?d.load[0]:'-');
    var mr=document.querySelector('[data-f="min_req"]');if(mr&&d.traffic)mr.textContent=d.traffic.requests;
    if(payload.charts&&payload.quality&&payload.quality.latest!==chartStamp){for(var chartKey in payload.charts){var panel=document.querySelector('[data-chart-key="'+chartKey+'"]');if(!panel)continue;var old=panel.querySelector('.trend-chart,.chart-empty');if(old)old.outerHTML=payload.charts[chartKey];}chartStamp=payload.quality.latest;}
    if(payload.quality){var quality=document.querySelector('[data-chart-quality]');if(quality){quality.textContent=payload.quality.latest==='-'?'暂无采样数据':('最后采集 '+payload.quality.latest+(payload.quality.gaps>0?' · '+payload.quality.gaps+' 处数据缺口':''));quality.classList.toggle('status-warning',payload.quality.gaps>0);}}
    if(d.services)for(var k in d.services){var dot=document.querySelector('[data-svc="'+k+'"]');var st=document.querySelector('[data-svc-t="'+k+'"]');var ok=d.services[k]==='active';if(dot){dot.classList.remove('ok','bad');dot.classList.add(ok?'ok':'bad');}if(st)st.textContent=d.services[k];}
    if(d.sites)for(var s in d.sites){var c=d.sites[s].code,ok2=c>=200&&c<400;var cd=document.querySelector('[data-site-code="'+s+'"]');if(cd){cd.textContent=c||'—';cd.classList.remove('ok','bad');cd.classList.add(ok2?'ok':'bad');}var tt=document.querySelector('[data-site-ttfb="'+s+'"]');if(tt)tt.textContent=d.sites[s].ttfb_ms;var sd=document.querySelector('[data-site-dot="'+s+'"]');if(sd){sd.classList.remove('ok','bad');sd.classList.add(ok2?'ok':'bad');}}
  }).catch(function(){});};
  <?php if ($refreshSeconds > 0): ?>setInterval(poll,<?= $refreshSeconds * 1000 ?>);<?php endif; ?>
  var logFilter='all',rowsBox=document.getElementById('log-rows'),filters=document.querySelectorAll('.log-filter'),freshEl=document.getElementById('log-fresh'),logTotal=document.querySelector('.log-total b');
  var esc=function(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');};
  var levelNames={error:'错误',warn:'警告',info:'信息'};
  var logRows=<?= json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var renderLogs=function(list){
    var filtered=logFilter==='all'?list:list.filter(function(r){return r.level===logFilter;});
    var html='';
    if(!list.length) html='<div class="log-row log-empty"><span>近 24 小时暂无日志事件</span></div>';
    else if(!filtered.length) html='<div class="log-row log-empty"><span>当前筛选下无记录</span></div>';
    else filtered.forEach(function(r){
      var lv=levelNames[r.level]?r.level:'warn',ts=String(r.ts||''),t=ts.length>=19?ts.slice(5,19):ts;
      var source=/^[a-zA-Z0-9-]+$/.test(String(r.source||''))?String(r.source):'other';
      html+='<div class="log-row" data-lv="'+esc(lv)+'"><span class="log-t">'+esc(t)+'</span><span class="log-src src-'+esc(source)+'">'+esc(r.source||'unknown')+'</span><span class="log-badge '+lv+'">'+levelNames[lv]+'</span><span class="log-msg" title="'+esc(r.message||'')+'">'+esc(r.message||'')+'</span></div>';
    });
    if(rowsBox) rowsBox.innerHTML=html;
  };
  filters.forEach(function(button){button.addEventListener('click',function(){
    logFilter=button.getAttribute('data-lf')||'all';
    filters.forEach(function(item){item.classList.remove('cur');item.setAttribute('aria-selected','false');});
    button.classList.add('cur');button.setAttribute('aria-selected','true');renderLogs(logRows);
  });});
  var pollLogs=function(){fetch('<?= $monSelf ?>&ajaxlog=1',{cache:'no-store'}).then(function(r){return r.json();}).then(function(payload){
    logRows=payload&&Array.isArray(payload.rows)?payload.rows:[];
    if(logTotal)logTotal.textContent=logRows.length;
    var counts={all:logRows.length,error:0,warn:0,info:0};logRows.forEach(function(r){if(counts[r.level]!==undefined)counts[r.level]++;});
    filters.forEach(function(button){var n=button.querySelector('b'),k=button.getAttribute('data-lf');if(n)n.textContent=counts[k]||0;});
    if(freshEl&&payload&&payload.collected_at){var ft=Date.parse(String(payload.collected_at).replace(' ','T')+'+08:00'),stale=!isNaN(ft)&&Date.now()-ft>150000;freshEl.textContent=stale?'采集可能异常':'';freshEl.classList.toggle('stale',stale);}
    renderLogs(logRows);
  }).catch(function(){});};
  setInterval(pollLogs,60000);
})();
</script>
</body>
</html>
