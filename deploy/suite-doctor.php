#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Read-only health checks for a Typecho Suite installation.
 *
 * The command deliberately performs no writes.  `--apply` is accepted only
 * together with an explicit migration identifier so a future migration cannot
 * accidentally be enabled by a typo or by a copied command.  There are no
 * migrations registered in this release; the dedicated tag-slug-doctor is
 * the only opt-in data migration and must be invoked separately.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "suite-doctor.php must be run from the command line.\n");
    exit(64);
}

$root = getenv('TYPECHO_ROOT') ?: '/var/www/typecho';
$jsonOutput = false;
$apply = false;
$migration = '';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $jsonOutput = true;
    } elseif ($arg === '--apply') {
        $apply = true;
    } elseif (strpos($arg, '--apply=') === 0) {
        $apply = true;
        $migration = substr($arg, 8);
    } elseif (strpos($arg, '--root=') === 0) {
        $root = substr($arg, 7);
    } elseif (strpos($arg, '--migration=') === 0) {
        $migration = substr($arg, 12);
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: suite-doctor.php [--root=/path] [--json]\n"
            . "       suite-doctor.php [--root=/path] --apply --migration=<id>\n\n"
            . "The default mode is read-only. No migrations are currently registered;\n"
            . "run deploy/tag-slug-doctor.php explicitly for its reviewed migration.\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(64);
    }
}

if ($apply && $migration === '') {
    fwrite(STDERR, "--apply requires an explicit --migration=<id>; no changes were made.\n");
    exit(64);
}
if ($migration !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $migration)) {
    fwrite(STDERR, "Invalid migration identifier; use letters, numbers, '.', '_' or '-'.\n");
    exit(64);
}

/** @param array<int,array<string,mixed>> $results */
function suite_doctor_add(array &$results, string $status, string $message, array $details = []): void
{
    $results[] = [
        'status' => $status,
        'message' => $message,
        'details' => $details,
    ];
}

/** Decode either Typecho's legacy PHP serialization or its JSON options. */
function suite_doctor_decode($value)
{
    if (!is_string($value)) {
        return $value;
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }
    if (strpos($trimmed, 'a:') === 0 || $trimmed === 'b:0;') {
        $decoded = @unserialize($trimmed);
        return $decoded === false && $trimmed !== 'b:0;' ? null : $decoded;
    }
    $decoded = json_decode($trimmed, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
}

function suite_doctor_truthy($value): bool
{
    if (is_array($value)) {
        return in_array('1', array_map('strval', $value), true);
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function suite_doctor_table(string $prefix, string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Unsafe database table name');
    }
    return '`' . $prefix . $name . '`';
}

/** @return array<int,array<string,mixed>> */
function suite_doctor_sql_rows($handle, string $sql): array
{
    if (class_exists('mysqli') && $handle instanceof \mysqli) {
        $result = $handle->query($sql);
        if ($result === false) {
            throw new RuntimeException($handle->error);
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }
    if (class_exists('PDO') && $handle instanceof \PDO) {
        $statement = $handle->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Database query failed');
        }
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
    throw new RuntimeException('Unsupported database handle');
}

function suite_doctor_callback_label($callback): string
{
    if (is_string($callback)) {
        return $callback;
    }
    if (is_array($callback) && count($callback) >= 2) {
        $target = $callback[0];
        $class = is_object($target) ? get_class($target) : (string) $target;
        return $class . '::' . (string) $callback[1];
    }
    if ($callback instanceof \Closure) {
        return 'Closure';
    }
    return gettype($callback);
}

function suite_doctor_callback_owner($callback): string
{
    $label = suite_doctor_callback_label($callback);
    foreach (['SuiteCore', 'SuiteContent', 'SuiteSearch', 'SuiteAdmin', 'SuiteMonitor', 'Sitemap'] as $owner) {
        if (strpos($label, 'TypechoPlugin_' . $owner . '_') !== false
            || strpos($label, 'TypechoPlugin\\' . $owner . '\\') !== false) {
            return $owner;
        }
    }
    return '';
}

/** Recursively collect route URL values from Typecho's routing table. */
function suite_doctor_route_urls($value, array &$urls): void
{
    if (!is_array($value)) {
        return;
    }
    if (isset($value['url']) && is_string($value['url'])) {
        $urls[] = $value['url'];
    }
    foreach ($value as $child) {
        suite_doctor_route_urls($child, $urls);
    }
}

$results = [];
$root = rtrim((string) $root, '/');
$configFile = $root . '/config.inc.php';

if (!is_dir($root)) {
    suite_doctor_add($results, 'fail', 'Typecho 根目录不存在', ['path' => $root]);
}
if (!is_file($configFile)) {
    suite_doctor_add($results, 'fail', '找不到 Typecho 配置文件', ['path' => $configFile]);
}
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    suite_doctor_add($results, 'fail', 'PHP 版本低于项目要求 PHP 7.4', ['version' => PHP_VERSION]);
}

$db = null;
$optionValues = [];
$pluginsState = [];
$themeOptions = [];
$themeName = '';

if (is_file($configFile)) {
    try {
        require_once $configFile;
        $loader = $root . '/var/Typecho/Loader.php';
        if (is_file($loader)) {
            require_once $loader;
            if (method_exists('Typecho\\Loader', 'registerAutoload')) {
                \Typecho\Loader::registerAutoload();
            } elseif (method_exists('Typecho\\Loader', 'register')) {
                \Typecho\Loader::register();
            }
        }
        if (!class_exists('Typecho\\Db')) {
            throw new RuntimeException('Typecho database class is unavailable');
        }
        $db = \Typecho\Db::get();
        $optionRows = $db->fetchAll($db->select('name', 'value')->from('table.options')->where('user = ?', 0));
        foreach ($optionRows as $row) {
            $optionValues[(string) ($row['name'] ?? '')] = (string) ($row['value'] ?? '');
        }
        $pluginsState = suite_doctor_decode($optionValues['plugins'] ?? '') ?: [];
        if (!is_array($pluginsState)) {
            $pluginsState = [];
        }
        $themeName = (string) ($optionValues['theme'] ?? '');
        $themeOptions = suite_doctor_decode($optionValues['theme:' . $themeName] ?? '') ?: [];
        if (!is_array($themeOptions)) {
            $themeOptions = [];
        }
        suite_doctor_add($results, 'pass', 'Typecho 配置与数据库可读取');
    } catch (Throwable $error) {
        suite_doctor_add($results, 'fail', '无法读取 Typecho 配置或 options 表', ['error' => $error->getMessage()]);
    }
}

$activated = is_array($pluginsState['activated'] ?? null) ? $pluginsState['activated'] : [];
$pluginDirConstant = defined('__TYPECHO_PLUGIN_DIR__') ? (string) __TYPECHO_PLUGIN_DIR__ : '/usr/plugins';
$pluginDir = $root . '/' . ltrim($pluginDirConstant, '/');
$knownPlugins = ['SuiteCore', 'SuiteAdmin', 'SuiteContent', 'Sitemap', 'SuiteSearch', 'SuiteMonitor'];
foreach ($knownPlugins as $pluginName) {
    $directory = $pluginDir . '/' . $pluginName;
    $entry = $directory . '/Plugin.php';
    $legacyEntry = $pluginDir . '/' . $pluginName . '.php';
    $isInstalled = is_file($entry) || is_file($legacyEntry);
    $isActive = isset($activated[$pluginName]);
    if ($isActive && !$isInstalled) {
        suite_doctor_add($results, 'fail', "已启用插件 {$pluginName} 但文件不存在", ['path' => $directory]);
    } elseif ($isActive) {
        suite_doctor_add($results, 'pass', "插件 {$pluginName} 已安装且处于启用状态");
    } elseif ($isInstalled) {
        suite_doctor_add($results, 'warn', "插件 {$pluginName} 已安装但未启用");
    }
}

if ($db !== null) {
    try {
        $prefix = (string) $db->getPrefix();
        $handle = $db->selectDb(\Typecho\Db::READ);
        $tableRows = suite_doctor_sql_rows($handle, 'SHOW TABLES');
        $tableNames = [];
        foreach ($tableRows as $row) {
            $name = (string) (array_values($row)[0] ?? '');
            if ($name !== '') {
                $tableNames[$name] = true;
            }
        }

        foreach (['contents', 'metas', 'relationships', 'options'] as $name) {
            $fullName = $prefix . $name;
            if (isset($tableNames[$fullName])) {
                suite_doctor_add($results, 'pass', "核心数据表 {$name} 存在");
            } else {
                suite_doctor_add($results, 'fail', "核心数据表 {$name} 缺失");
            }
        }

        $statsEnabled = suite_doctor_truthy($themeOptions['enableStats'] ?? false);
        if ($statsEnabled) {
            foreach (['suite_visits', 'suite_views', 'suite_visitors', 'suite_visitors_daily'] as $name) {
                $fullName = $prefix . $name;
                if (isset($tableNames[$fullName])) {
                    suite_doctor_add($results, 'pass', "统计数据表 {$name} 存在");
                } else {
                    suite_doctor_add($results, 'fail', "已开启统计但数据表 {$name} 缺失");
                }
            }
        }
        if (isset($activated['SuiteSearch'])) {
            foreach (['suite_changequeue', 'suite_searchmeta', 'suite_rebuildtask'] as $name) {
                $fullName = $prefix . $name;
                if (!isset($tableNames[$fullName])) {
                    suite_doctor_add($results, 'warn', "SuiteSearch 可选数据表 {$name} 不存在，将使用降级路径");
                }
            }
            $docsName = $prefix . 'suite_search_docs';
            suite_doctor_add($results, isset($tableNames[$docsName]) ? 'pass' : 'warn',
                isset($tableNames[$docsName]) ? 'SuiteSearch 物化降级文档表存在' : 'SuiteSearch 物化降级文档表不存在，将使用原生表查询');
        }

        $metasTable = suite_doctor_table($prefix, 'metas');
        $duplicates = suite_doctor_sql_rows(
            $handle,
            'SELECT `type`, `slug`, COUNT(*) AS `n` FROM ' . $metasTable
            . " WHERE `type` IN ('tag', 'category') AND `slug` <> '' GROUP BY `type`, `slug` HAVING COUNT(*) > 1"
        );
        if (!$duplicates) {
            suite_doctor_add($results, 'pass', '标签与分类 slug 没有发现重复');
        } else {
            suite_doctor_add($results, 'fail', '发现标签或分类 slug 重复', ['groups' => $duplicates]);
        }
        $slugIndex = suite_doctor_sql_rows($handle, 'SHOW INDEX FROM ' . $metasTable . " WHERE Key_name = 'suite_type_slug'");
        $slugIndexColumns = array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['Column_name'] ?? ''), $slugIndex)));
        $slugIndexUnique = $slugIndex !== [] && count(array_filter($slugIndex, static fn(array $row): bool => (int) ($row['Non_unique'] ?? 1) === 0)) === count($slugIndex);
        if ($slugIndexUnique && count($slugIndexColumns) >= 2 && $slugIndexColumns[0] === 'type' && $slugIndexColumns[1] === 'slug') {
            suite_doctor_add($results, 'pass', '标签与分类 slug 唯一索引已启用');
        } else {
            suite_doctor_add($results, 'warn', '标签与分类 slug 唯一索引未启用，需先完成 doctor 迁移');
        }
        $emptySlugs = suite_doctor_sql_rows(
            $handle,
            'SELECT `type`, COUNT(*) AS `n` FROM ' . $metasTable
            . " WHERE `type` IN ('tag', 'category') AND (`slug` IS NULL OR `slug` = '') GROUP BY `type`"
        );
        if ($emptySlugs) {
            suite_doctor_add($results, 'fail', '发现标签或分类存在空 slug', ['groups' => $emptySlugs]);
        } else {
            suite_doctor_add($results, 'pass', '标签与分类没有空 slug');
        }
    } catch (Throwable $error) {
        suite_doctor_add($results, 'fail', '数据库结构或 slug 检查失败', ['error' => $error->getMessage()]);
    }
}

// Navigation is checked from persisted options, without invoking a web
// request or changing Typecho's routing table.
$allowedNavigation = ['home', 'categories', 'archives', 'about'];
$navigation = $themeOptions['navigationItems'] ?? $allowedNavigation;
if (is_string($navigation)) {
    $decodedNavigation = suite_doctor_decode($navigation);
    $navigation = is_array($decodedNavigation)
        ? $decodedNavigation
        : preg_split('/[,\s]+/', $navigation, -1, PREG_SPLIT_NO_EMPTY);
}
$navigation = array_values(array_unique(array_map('strval', (array) $navigation)));
$unknownNavigation = array_values(array_diff($navigation, $allowedNavigation));
if ($unknownNavigation) {
    suite_doctor_add($results, 'fail', '导航配置包含未知 capability ID', ['ids' => $unknownNavigation]);
} else {
    suite_doctor_add($results, 'pass', '导航配置只使用稳定 capability ID', ['ids' => $navigation]);
}

$routing = suite_doctor_decode($optionValues['routingTable'] ?? '') ?: [];
$routeUrls = [];
suite_doctor_route_urls($routing, $routeUrls);
if (in_array('categories', $navigation, true) && isset($activated['SuiteCore'])) {
    if (in_array('/categories/', $routeUrls, true)) {
        suite_doctor_add($results, 'pass', '分类导航路由 /categories/ 已注册');
    } else {
        suite_doctor_add($results, 'warn', '分类已加入导航但 /categories/ 路由尚未出现在持久化路由表');
    }
}

$themeDirConstant = defined('__TYPECHO_THEME_DIR__') ? (string) __TYPECHO_THEME_DIR__ : '/usr/themes';
$themePath = trim($themeName, './');
$themeFunctions = $root . '/' . ltrim($themeDirConstant, '/') . '/' . $themePath . '/functions.php';
if ($themeName !== '' && is_file($themeFunctions)) {
    require_once $themeFunctions;
    if (function_exists('suite_capabilities')) {
        try {
            $capabilities = (array) suite_capabilities(null);
            $capabilityIds = [];
            foreach ($capabilities as $capability) {
                if (isset($capability['id'])) {
                    $capabilityIds[] = (string) $capability['id'];
                }
            }
            $missingCapabilities = array_values(array_diff($navigation, $capabilityIds));
            if ($missingCapabilities) {
                suite_doctor_add($results, 'fail', '导航项目没有对应的主题 capability', ['ids' => $missingCapabilities]);
            } else {
                suite_doctor_add($results, 'pass', '主题 capability 与导航配置一致');
            }
        } catch (Throwable $error) {
            suite_doctor_add($results, 'warn', '无法读取主题 capability 定义', ['error' => $error->getMessage()]);
        }
    } else {
        suite_doctor_add($results, 'warn', '当前主题未提供 suite_capabilities()');
    }
} elseif ($themeName !== '') {
    suite_doctor_add($results, 'fail', '当前主题 functions.php 不存在', ['theme' => $themeName]);
}

// Rehydrate the persisted hook registry and look for missing/duplicated
// callbacks.  This remains read-only; Plugin::init only populates memory.
$hooks = [];
if (class_exists('Typecho\\Plugin') && is_array($pluginsState)) {
    try {
        \Typecho\Plugin::init($pluginsState);
        $exported = \Typecho\Plugin::export();
        $hooks = is_array($exported['handles'] ?? null) ? $exported['handles'] : [];
    } catch (Throwable $error) {
        suite_doctor_add($results, 'warn', '无法读取 Typecho 插件钩子注册表', ['error' => $error->getMessage()]);
    }
}
$expectedHooks = [
    'SuiteCore' => ['Widget_Archive:handle' => 'SuiteCore'],
    'SuiteContent' => [
        'admin/write-post.php:option' => 'SuiteContent',
        'Widget_Contents_Post_Edit:write' => 'SuiteContent',
        'Widget_Archive:handleInit' => 'SuiteContent',
    ],
    'SuiteSearch' => [
        'Widget_Archive:search' => 'SuiteSearch',
        'Widget_Contents_Post_Edit:finishPublish' => 'SuiteSearch',
        'Widget_Contents_Post_Edit:finishSave' => 'SuiteSearch',
        'Widget_Contents_Post_Edit:finishMark' => 'SuiteSearch',
        'Widget_Contents_Post_Edit:finishDelete' => 'SuiteSearch',
    ],
    'SuiteAdmin' => ['admin/header.php:header' => 'SuiteAdmin'],
];
foreach ($expectedHooks as $pluginName => $components) {
    if (!isset($activated[$pluginName])) {
        continue;
    }
    foreach ($components as $component => $owner) {
        $callbacks = (array) ($hooks[$component] ?? []);
        $matching = [];
        foreach ($callbacks as $callback) {
            $label = suite_doctor_callback_label($callback);
            if (suite_doctor_callback_owner($callback) === $owner) {
                $matching[] = $label;
            }
        }
        if (count($matching) === 1) {
            suite_doctor_add($results, 'pass', "钩子 {$component} 由 {$owner} 注册");
        } elseif (count($matching) === 0) {
            suite_doctor_add($results, 'fail', "已启用 {$owner} 但钩子 {$component} 未注册");
        } else {
            suite_doctor_add($results, 'fail', "钩子 {$component} 存在重复 {$owner} 回调", ['callbacks' => $matching]);
        }
    }
}
if (isset($activated['SuiteAdmin'], $activated['SuiteContent'])) {
    foreach (['admin/write-post.php:option', 'Widget_Contents_Post_Edit:write', 'Widget_Archive:handleInit'] as $component) {
        foreach ((array) ($hooks[$component] ?? []) as $callback) {
            if (suite_doctor_callback_owner($callback) === 'SuiteAdmin') {
                suite_doctor_add($results, 'fail', "SuiteAdmin 仍注册内容钩子 {$component}，会与 SuiteContent 重复");
            }
        }
    }
}

if ($apply) {
    suite_doctor_add($results, 'warn', '未执行任何迁移：当前没有注册的 migration', ['requested' => $migration]);
}

$failures = 0;
foreach ($results as $result) {
    if ($result['status'] === 'fail') {
        $failures++;
    }
}

if ($jsonOutput) {
    echo json_encode([
        'root' => $root,
        'read_only' => !$apply,
        'migration_requested' => $migration !== '' ? $migration : null,
        'failures' => $failures,
        'checks' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    printf("Typecho Suite doctor (root: %s)\n\n", $root);
    foreach ($results as $result) {
        $label = strtoupper((string) $result['status']);
        printf('[%-5s] %s\n', $label, $result['message']);
        if (!empty($result['details']) && $result['status'] === 'fail') {
            printf("        %s\n", json_encode($result['details'], JSON_UNESCAPED_UNICODE));
        }
    }
    printf("\n%d blocking check(s). %s\n", $failures, $apply ? 'No migration was executed.' : 'Read-only mode; no files or rows were changed.');
}

if ($apply && $migration !== '') {
    // A future implementation must add an explicit branch above before this
    // command is allowed to mutate anything.
    exit($failures > 0 ? 1 : 2);
}
exit($failures > 0 ? 1 : 0);
