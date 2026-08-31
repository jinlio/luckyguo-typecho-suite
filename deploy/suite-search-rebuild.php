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
if (is_file($root . '/var/Typecho/Loader.php')) {
    require_once $root . '/var/Typecho/Loader.php';
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
foreach (['RuntimeConfig', 'MeiliClient', 'Indexer', 'CircuitBreaker', 'RebuildStore', 'RebuildService'] as $file) {
    require_once $pluginDirectory . '/' . $file . '.php';
}

use TypechoPlugin\SuiteSearch\MeiliClient;
use TypechoPlugin\SuiteSearch\RebuildService;
use TypechoPlugin\SuiteSearch\RuntimeConfig;

try {
    $options = class_exists('Widget\\Options') ? \Widget\Options::alloc() : null;
    $config = $options !== null
        ? RuntimeConfig::fromOptionsOrFile($options, $runtimeConfig)
        : RuntimeConfig::fromFile($runtimeConfig);
    if (!$config->getBool('ENABLED', true)) {
        fwrite(STDOUT, "SuiteSearch is disabled in Typecho settings\n");
        exit(0);
    }
    $taskClient = new MeiliClient($config->require('MEILI_URL'), $config->get('TASK_KEY', $config->require('REBUILD_KEY')), 1000, 30000);
    $searchClient = null;
    $searchPath = getenv('TYPECHO_SUITE_SEARCH_CONFIG') ?: '/etc/typecho-suite/search.env';
    if ($options !== null) {
        $searchConfig = RuntimeConfig::fromOptionsOrFile($options, $searchPath);
    } elseif (is_readable($searchPath)) {
        $searchConfig = RuntimeConfig::fromFile($searchPath);
    } else {
        $searchConfig = null;
    }
    if ($searchConfig !== null && $searchConfig->getBool('ENABLED', true)) {
        $searchClient = new MeiliClient($searchConfig->require('MEILI_URL'), $searchConfig->require('SEARCH_KEY'), 300, 800);
    }
    exit((new RebuildService(\Typecho\Db::get(), $config, $searchClient, $taskClient))->run());
} catch (Throwable $error) {
    fwrite(STDERR, '[' . date('c') . '] rebuild failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
