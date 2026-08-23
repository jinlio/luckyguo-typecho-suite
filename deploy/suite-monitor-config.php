#!/usr/bin/env php
<?php
declare(strict_types=1);

// Export SuiteMonitor settings as shell assignments for the root collector.
$root = getenv('TYPECHO_ROOT') ?: '/var/www/typecho';
$config = $root . '/config.inc.php';
if (!is_file($config)) {
    exit(78);
}
require $config;
$loader = $root . '/var/Typecho/Loader.php';
if (is_file($loader)) {
    require_once $loader;
    if (method_exists('Typecho\\Loader', 'registerAutoload')) {
        \Typecho\Loader::registerAutoload();
    } elseif (method_exists('Typecho\\Loader', 'register')) {
        \Typecho\Loader::register();
    }
}
if (is_file($root . '/var/Typecho/Widget.php')) {
    require_once $root . '/var/Typecho/Widget.php';
}
if (is_file($root . '/var/Widget/Options.php')) {
    require_once $root . '/var/Widget/Options.php';
}
try {
    $settings = \Widget\Options::alloc()->plugin('SuiteMonitor');
} catch (Throwable $error) {
    exit(78);
}

$fields = [
    'stateDir' => 'STATE_DIR', 'logFile' => 'LOG', 'monitorCnf' => 'CNF',
    'monitorDb' => 'MONITOR_DB', 'monitorDbHost' => 'MONITOR_DB_HOST',
    'monitorDbPort' => 'MONITOR_DB_PORT', 'monitorRwUser' => 'MONITOR_RW_USER',
    'monitorRwPass' => 'MONITOR_RW_PASS', 'serviceUnits' => 'SERVICE_UNITS',
    'siteTargets' => 'SITE_TARGETS', 'statusOwner' => 'STATUS_OWNER',
    'statusGroup' => 'STATUS_GROUP', 'statusMode' => 'STATUS_MODE',
    'monitorRoUser' => 'MONITOR_RO_USER', 'monitorRoPass' => 'MONITOR_RO_PASS',
    'logJournalUnits' => 'LOG_JOURNAL_UNITS',
    'rawRetentionDays' => 'RAW_RETENTION_DAYS', 'rollupRetentionDays' => 'ROLLUP_RETENTION_DAYS',
];
foreach ($fields as $field => $name) {
    $value = trim((string) ($settings->$field ?? ''));
    if ($value === '') {
        continue;
    }
    if (preg_match('/[\r\n]/', $value)) {
        continue;
    }
    $quoted = "'" . str_replace("'", "'\\''", $value) . "'";
    echo $name . '=' . $quoted . "\n";
}
$logSources = trim((string) ($settings->logSources ?? ''));
if ($logSources !== '' && !preg_match('/[^\x20-\x7E\r\n=:\/.@_-]/', $logSources)) {
    echo 'LOG_SOURCES_B64=' . escapeshellarg(base64_encode($logSources)) . "\n";
}
$statusFile = trim((string) ($settings->statusFile ?? ''));
if ($statusFile !== '') {
    if (preg_match('/[\r\n]/', $statusFile)) {
        exit(64);
    }
    $quoted = "'" . str_replace("'", "'\\''", $statusFile) . "'";
    echo 'STATUS_FILE=' . $quoted . "\n";
}
