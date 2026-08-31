#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Populate the optional MySQL fallback document table in bounded batches. */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "suite-search-docs-backfill.php must be run from the command line.\n");
    exit(64);
}

$root = getenv('TYPECHO_ROOT') ?: '/var/www/typecho';
$batchSize = 100;
$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--root=') === 0) {
        $root = substr($arg, 7);
    } elseif (strpos($arg, '--batch=') === 0) {
        $batchSize = max(1, min(500, (int) substr($arg, 8)));
    } elseif ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: suite-search-docs-backfill.php [--root=/path] [--batch=100] [--apply]\n\n"
            . "Default mode is read-only. --apply upserts every published post and prunes stale rows.\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(64);
    }
}

$root = rtrim((string) $root, '/');
$configFile = $root . '/config.inc.php';
$pluginDirectory = $root . '/usr/plugins/SuiteSearch';
if (!is_file($configFile) || !is_dir($pluginDirectory)) {
    fwrite(STDERR, "Typecho root or SuiteSearch plugin was not found\n");
    exit(78);
}
require $configFile;
$loader = $root . '/var/Typecho/Loader.php';
if (is_file($loader)) {
    require_once $loader;
    if (method_exists('Typecho\\Loader', 'registerAutoload')) {
        \Typecho\Loader::registerAutoload();
    } elseif (method_exists('Typecho\\Loader', 'register')) {
        \Typecho\Loader::register();
    }
}
require_once $pluginDirectory . '/Indexer.php';

try {
    $db = \Typecho\Db::get();
    $prefix = (string) $db->getPrefix();
    if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
        throw new RuntimeException('Unsafe Typecho table prefix');
    }
    $connection = $db->selectDb(\Typecho\Db::WRITE);
    if (!$connection instanceof mysqli) {
        throw new RuntimeException('SuiteSearch requires the Mysqli adapter');
    }
    $table = '`' . $prefix . 'suite_search_docs`';
    $tableName = $prefix . 'suite_search_docs';
    $check = $connection->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    if (!$check) {
        throw new RuntimeException($connection->error);
    }
    $check->bind_param('s', $tableName);
    if (!$check->execute() || !$check->get_result()->fetch_row()) {
        throw new RuntimeException("Missing {$tableName}; install deploy/create-suite-search.sql first");
    }
    $check->close();

    $countResult = $connection->query(
        'SELECT COUNT(*) AS n FROM `' . $prefix . "contents` WHERE type = 'post' AND status = 'publish'"
    );
    if (!$countResult) {
        throw new RuntimeException($connection->error);
    }
    $published = (int) (($countResult->fetch_assoc()['n'] ?? 0));
    $existingResult = $connection->query('SELECT COUNT(*) AS n FROM ' . $table);
    if (!$existingResult) {
        throw new RuntimeException($connection->error);
    }
    $existing = (int) (($existingResult->fetch_assoc()['n'] ?? 0));
    printf("Published posts: %d; materialized rows before run: %d\n", $published, $existing);
    if (!$apply) {
        printf("Dry run only. Re-run with --apply to backfill in batches of %d.\n", $batchSize);
        exit(0);
    }

    $indexer = new \TypechoPlugin\SuiteSearch\Indexer($db);
    $statement = $connection->prepare(
        'INSERT INTO ' . $table . ' (cid,status,title,body,tags,categories,modified,updated_at) '
        . "VALUES (?, 'publish', ?, ?, ?, ?, ?, NOW(6)) "
        . 'ON DUPLICATE KEY UPDATE status=VALUES(status), title=VALUES(title), body=VALUES(body), '
        . 'tags=VALUES(tags), categories=VALUES(categories), modified=VALUES(modified), updated_at=NOW(6)'
    );
    if (!$statement) {
        throw new RuntimeException($connection->error);
    }
    $cursor = 0;
    $written = 0;
    do {
        $documents = $indexer->publishedBatch($cursor, $batchSize);
        if (!$documents) {
            break;
        }
        foreach ($documents as $document) {
            $cid = (int) ($document['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $title = (string) ($document['title'] ?? '');
            $body = (string) ($document['text'] ?? '');
            $tags = implode("\n", array_map('strval', (array) ($document['tags'] ?? [])));
            $categories = implode("\n", array_map('strval', (array) ($document['categories'] ?? [])));
            $modified = (int) ($document['_modified'] ?? time());
            $statement->bind_param('issssi', $cid, $title, $body, $tags, $categories, $modified);
            if (!$statement->execute()) {
                throw new RuntimeException($statement->error);
            }
            $cursor = max($cursor, $cid);
            $written++;
        }
        printf("Backfilled through cid %d (%d rows)\n", $cursor, $written);
    } while (count($documents) === $batchSize);
    $statement->close();

    $prune = $connection->query(
        'DELETE d FROM ' . $table . ' d LEFT JOIN `' . $prefix . 'contents` c ON c.cid = d.cid '
        . "WHERE c.cid IS NULL OR c.type <> 'post' OR c.status <> 'publish'"
    );
    if (!$prune) {
        throw new RuntimeException($connection->error);
    }
    $finalResult = $connection->query('SELECT COUNT(*) AS n FROM ' . $table);
    if (!$finalResult) {
        throw new RuntimeException($connection->error);
    }
    printf("Backfill complete: %d rows; pruned %d stale rows.\n", (int) (($finalResult->fetch_assoc()['n'] ?? 0)), $connection->affected_rows);
} catch (Throwable $error) {
    fwrite(STDERR, '[suite-search-docs-backfill] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
