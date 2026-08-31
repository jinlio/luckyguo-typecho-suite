#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Detect and (only with --apply) repair Typecho tag/category slug collisions.
 *
 * The command is deliberately independent from the web request lifecycle:
 * dry-run is the default, mappings are written before any update, and a
 * mapping can be passed to --rollback to restore the previous values.
 */

$root = getenv('TYPECHO_ROOT') ?: '/var/www/typecho';
$apply = false;
$rollback = '';
$mappingPath = '';
$jsonOutput = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--json') {
        $jsonOutput = true;
    } elseif (substr($arg, 0, 7) === '--root=') {
        $root = substr($arg, 7);
    } elseif (substr($arg, 0, 11) === '--rollback=') {
        $rollback = substr($arg, 11);
        $apply = true;
    } elseif (substr($arg, 0, 10) === '--mapping=') {
        $mappingPath = substr($arg, 10);
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: tag-slug-doctor.php [--root=/path] [--json] [--apply] [--mapping=/path]\n"
            . "       tag-slug-doctor.php [--root=/path] --rollback=/path/to/mapping.json\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(64);
    }
}

$configFile = rtrim($root, '/') . '/config.inc.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Typecho config not found: {$configFile}\n");
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
if (is_file($root . '/var/Widget/Options.php')) {
    require_once $root . '/var/Widget/Options.php';
}

if (!class_exists('Typecho\\Db')) {
    fwrite(STDERR, "Typecho database class is unavailable\n");
    exit(78);
}

/** @return array<string,mixed> */
function suite_slug_options(): array
{
    $result = [];
    foreach (array_slice($GLOBALS['argv'], 1) as $arg) {
        if (substr($arg, 0, 2) === '--') {
            $parts = explode('=', $arg, 2);
            $result[ltrim($parts[0], '-')] = $parts[1] ?? true;
        }
    }
    return $result;
}

function suite_slug_quote_table(string $prefix, string $table): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix . $table)) {
        throw new RuntimeException('Unsafe table prefix');
    }
    return '`' . $prefix . $table . '`';
}

function suite_slug_base(string $name, int $mid): string
{
    $base = '';
    if (class_exists('Typecho\\Common') && method_exists('Typecho\\Common', 'slugName')) {
        try {
            $base = (string) \Typecho\Common::slugName($name);
        } catch (Throwable $e) {
            $base = '';
        }
    }
    $base = trim($base, "-_");
    if ($base === '') {
        $base = 'tag-' . $mid;
    }
    return substr($base, 0, 120);
}

/** @param array<int,array<string,mixed>> $rows */
function suite_slug_plan(array $rows): array
{
    $used = [];
    foreach ($rows as $row) {
        $type = (string) ($row['type'] ?? '');
        $slug = (string) ($row['slug'] ?? '');
        if (!in_array($type, ['tag', 'category'], true) || $slug === '') {
            continue;
        }
        $used[$type][$slug] = true;
    }

    $groups = [];
    foreach ($rows as $row) {
        $type = (string) ($row['type'] ?? '');
        if (!in_array($type, ['tag', 'category'], true)) {
            continue;
        }
        $slug = (string) ($row['slug'] ?? '');
        $groups[$type][$slug][] = $row;
    }

    $plan = [];
    foreach ($groups as $type => $bySlug) {
        foreach ($bySlug as $oldSlug => $members) {
            usort($members, static fn (array $a, array $b): int => (int) $a['mid'] <=> (int) $b['mid']);
            $keep = true;
            foreach ($members as $row) {
                $mid = (int) $row['mid'];
                $current = (string) ($row['slug'] ?? '');
                $new = $current;
                if ($current === '' || !$keep) {
                    $keep = false;
                    $base = suite_slug_base((string) ($row['name'] ?? ''), $mid);
                    $new = $base . '-' . $mid;
                    $candidate = $new;
                    $suffix = 2;
                    while (isset($used[$type][$candidate])) {
                        $candidate = substr($base, 0, max(1, 145 - strlen((string) $suffix)))
                            . '-' . $mid . '-' . $suffix++;
                    }
                    $new = $candidate;
                    $used[$type][$new] = true;
                } else {
                    // Keep only the lowest-mid row on the legacy slug.
                    $keep = false;
                }
                if ($new !== $current) {
                    $plan[] = [
                        'mid' => $mid,
                        'type' => $type,
                        'name' => (string) ($row['name'] ?? ''),
                        'old_slug' => $current,
                        'new_slug' => $new,
                    ];
                }
            }
        }
    }
    return $plan;
}

function suite_slug_emit(array $plan, bool $json): void
{
    if ($json) {
        echo json_encode(['changes' => $plan, 'count' => count($plan)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        return;
    }
    if (!$plan) {
        echo "No tag/category slug collisions or empty slugs found.\n";
        return;
    }
    printf("%d slug change(s) planned:\n", count($plan));
    foreach ($plan as $item) {
        printf("  [%s #%d] %s: %s -> %s\n", $item['type'], $item['mid'], $item['name'],
            $item['old_slug'] === '' ? '(empty)' : $item['old_slug'], $item['new_slug']);
    }
}

/** @return array{exists:bool,unique:bool,columns:array<int,string>} */
function suite_slug_index_info(mysqli $handle, string $table, string $indexName): array
{
    $safeName = $handle->real_escape_string($indexName);
    $result = $handle->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$safeName}'");
    if (!$result) {
        throw new RuntimeException($handle->error);
    }
    $columns = [];
    $unique = false;
    while ($row = $result->fetch_assoc()) {
        $columns[(int) ($row['Seq_in_index'] ?? 0)] = (string) ($row['Column_name'] ?? '');
        $unique = ((int) ($row['Non_unique'] ?? 1)) === 0;
    }
    ksort($columns);
    return ['exists' => $columns !== [], 'unique' => $unique, 'columns' => array_values($columns)];
}

function suite_slug_validate_rollback(mysqli $handle, string $table, array $plan): void
{
    foreach ($plan as $item) {
        $mid = (int) ($item['mid'] ?? 0);
        $type = (string) ($item['type'] ?? '');
        $expected = (string) ($item['old_slug'] ?? '');
        if ($mid <= 0 || !in_array($type, ['tag', 'category'], true)) {
            throw new RuntimeException('Mapping contains an invalid mid/type pair');
        }
        $statement = $handle->prepare('SELECT slug FROM ' . $table . ' WHERE mid = ? AND type = ? LIMIT 1');
        if (!$statement) {
            throw new RuntimeException($handle->error);
        }
        $statement->bind_param('is', $mid, $type);
        if (!$statement->execute()) {
            throw new RuntimeException($statement->error);
        }
        $row = $statement->get_result()->fetch_assoc() ?: null;
        $statement->close();
        if ($row === null || (string) $row['slug'] !== $expected) {
            throw new RuntimeException("Rollback precondition failed for {$type} #{$mid}");
        }
    }
}

try {
    $db = \Typecho\Db::get();
    $prefix = $db->getPrefix();
    $rows = $db->fetchAll($db->select('mid', 'name', 'slug', 'type')
        ->from('table.metas')->where('type IN ?', ['tag', 'category'])->order('mid', \Typecho\Db::SORT_ASC));
    $plan = $rollback === '' ? suite_slug_plan($rows) : [];
    if ($rollback !== '') {
        if (!is_file($rollback)) {
            throw new RuntimeException("Mapping file not found: {$rollback}");
        }
        $decoded = json_decode((string) file_get_contents($rollback), true);
        if (!is_array($decoded) || (int) ($decoded['version'] ?? 0) !== 1) {
            throw new RuntimeException('Unsupported or invalid mapping version');
        }
        $originalPlan = is_array($decoded['changes'] ?? null) ? $decoded['changes'] : [];
        if (isset($decoded['changes_sha256'])) {
            $canonical = json_encode($originalPlan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($canonical) || !hash_equals((string) $decoded['changes_sha256'], hash('sha256', $canonical))) {
                throw new RuntimeException('Mapping checksum does not match its changes');
            }
        }
        $plan = [];
        foreach ($originalPlan as $item) {
            $plan[] = [
                'mid' => (int) ($item['mid'] ?? 0),
                'type' => (string) ($item['type'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'old_slug' => (string) ($item['new_slug'] ?? ''),
                'new_slug' => (string) ($item['old_slug'] ?? ''),
            ];
        }
    }

    suite_slug_emit($plan, $jsonOutput);
    if (!$apply || !$plan) {
        if ($plan && !$apply) {
            fwrite(STDOUT, "Dry-run only. Re-run with --apply after reviewing the mapping.\n");
        }
        exit(0);
    }

    $handle = $db->selectDb(\Typecho\Db::WRITE);
    if (!$handle instanceof mysqli) {
        throw new RuntimeException('tag-slug-doctor currently requires the Mysqli adapter');
    }
    $table = suite_slug_quote_table($prefix, 'metas');
    $indexName = 'suite_type_slug';
    $index = suite_slug_index_info($handle, $table, $indexName);
    if ($rollback !== '') {
        suite_slug_validate_rollback($handle, $table, $plan);
        // ALTER TABLE implicitly commits on MySQL, so remove the guard before
        // restoring legacy values and recreate it only when the target state is
        // compatible with uniqueness.
        if ($index['exists']) {
            if (!$handle->query('ALTER TABLE ' . $table . ' DROP INDEX `' . $indexName . '`')) {
                throw new RuntimeException($handle->error);
            }
        }
    }

    $mappingPath = $mappingPath !== '' ? $mappingPath : (getenv('TYPECHO_SUITE_STATE_DIR') ?: sys_get_temp_dir())
        . '/tag-slug-mapping-' . date('Ymd-His') . '.json';
    if ($rollback === '') {
        $canonical = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload = [
            'version' => 1,
            'created_at' => date(DATE_ATOM),
            'changes_sha256' => hash('sha256', (string) $canonical),
            'changes' => $plan,
        ];
        $parent = dirname($mappingPath);
        if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
            throw new RuntimeException("Cannot create mapping directory: {$parent}");
        }
        if (file_put_contents($mappingPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write mapping: {$mappingPath}");
        }
    }

    $handle->begin_transaction();
    try {
        $statement = $handle->prepare('UPDATE ' . $table . ' SET slug = ? WHERE mid = ? AND type = ?');
        if (!$statement) {
            throw new RuntimeException($handle->error);
        }
        foreach ($plan as $item) {
            $slug = (string) $item['new_slug'];
            $mid = (int) $item['mid'];
            $type = (string) $item['type'];
            $statement->bind_param('sis', $slug, $mid, $type);
            if (!$statement->execute()) {
                throw new RuntimeException($statement->error);
            }
        }
        $statement->close();
        $handle->commit();
    } catch (Throwable $error) {
        $handle->rollback();
        if ($rollback !== '' && $index['exists']) {
            // The DROP INDEX above commits implicitly. Best-effort restoration
            // keeps a failed rollback from leaving the guard absent.
            $check = suite_slug_index_info($handle, $table, $indexName);
            if (!$check['exists']) {
                $handle->query('ALTER TABLE ' . $table
                    . ' ADD UNIQUE KEY `' . $indexName . '` (`type`, `slug`(150))');
            }
        }
        throw $error;
    }
    // MySQL ALTER TABLE implicitly commits, so add/recreate the guard only
    // after the value transaction has completed successfully.
    if ($rollback === '' && !$index['exists']) {
        if (!$handle->query('ALTER TABLE ' . $table . ' ADD UNIQUE KEY `' . $indexName . '` (`type`, `slug`(150))')) {
            throw new RuntimeException($handle->error);
        }
    }
    if ($rollback !== '' && $index['exists']) {
        $duplicates = $handle->query('SELECT type, slug, COUNT(*) AS n FROM ' . $table
            . ' WHERE type IN (\'tag\', \'category\') GROUP BY type, slug HAVING n > 1 LIMIT 1');
        if (!$duplicates) {
            throw new RuntimeException($handle->error);
        }
        if ($duplicates->num_rows === 0 && !$handle->query('ALTER TABLE ' . $table
            . ' ADD UNIQUE KEY `' . $indexName . '` (`type`, `slug`(150))')) {
            throw new RuntimeException($handle->error);
        }
        if ($duplicates->num_rows > 0) {
            fwrite(STDERR, "Rollback restored legacy duplicate slugs; {$indexName} was not recreated.\n");
        }
    }
    if ($rollback !== '') {
        printf("Rollback applied for %d change(s).\n", count($plan));
    } else {
        printf("Applied %d change(s); mapping saved to %s\n", count($plan), $mappingPath);
    }
} catch (Throwable $error) {
    fwrite(STDERR, '[tag-slug-doctor] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
