#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = getenv('TYPECHO_ROOT') ?: '/var/www/typecho';
$configFile = $root . '/config.inc.php';
$pluginDirectory = $root . '/usr/plugins/SuiteSearch';
$runtimeConfig = getenv('TYPECHO_SUITE_SEARCH_REBUILD_CONFIG') ?: '/etc/typecho-suite/search-rebuild.env';
if (!is_file($configFile) || !is_dir($pluginDirectory)) {
    fwrite(STDERR, "Typecho root or SuiteSearch plugin was not found\n");
    exit(78);
}
require $configFile;
foreach (['RuntimeConfig', 'MeiliClient', 'Indexer', 'RebuildStore', 'RebuildService'] as $file) {
    require_once $pluginDirectory . '/' . $file . '.php';
}

use TypechoPlugin\SuiteSearch\MeiliClient;
use TypechoPlugin\SuiteSearch\RebuildService;
use TypechoPlugin\SuiteSearch\RuntimeConfig;

try {
    $config = RuntimeConfig::fromFile($runtimeConfig);
    $taskClient = new MeiliClient($config->require('MEILI_URL'), $config->get('TASK_KEY', $config->require('REBUILD_KEY')), 1000, 30000);
    $searchClient = null;
    $searchPath = getenv('TYPECHO_SUITE_SEARCH_CONFIG') ?: '/etc/typecho-suite/search.env';
    if (is_readable($searchPath)) {
        $searchConfig = RuntimeConfig::fromFile($searchPath);
        $searchClient = new MeiliClient($searchConfig->require('MEILI_URL'), $searchConfig->require('SEARCH_KEY'), 300, 800);
    }
    exit((new RebuildService(\Typecho\Db::get(), $config, $searchClient, $taskClient))->run());
} catch (Throwable $error) {
    fwrite(STDERR, '[' . date('c') . '] rebuild failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
