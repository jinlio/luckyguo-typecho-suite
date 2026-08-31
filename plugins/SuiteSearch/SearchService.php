<?php

namespace TypechoPlugin\SuiteSearch;

use Typecho\Db;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class SearchService
{
    private static ?self $instance = null;
    private Db $db;
    private ?RuntimeConfig $config = null;
    private ?MeiliClient $searchClient = null;
    private ?MeiliClient $writeClient;
    private Indexer $indexer;
    private string $liveIndex;
    private string $prefix;
    private bool $queueAvailable = false;
    private bool $docsAvailable = false;
    private bool $mysqlFallback = true;
    private CircuitBreaker $circuit;
    private string $fallbackReason = '';

    private function __construct()
    {
        $this->db = Db::get();
        $configPath = getenv('TYPECHO_SUITE_SEARCH_CONFIG') ?: '/etc/typecho-suite/search.env';
        try {
            $this->config = RuntimeConfig::fromOptionsOrFile(Options::alloc(), $configPath);
            if (!$this->config->getBool('ENABLED', true)) {
                throw new \RuntimeException('disabled in SuiteSearch settings');
            }
            $this->searchClient = new MeiliClient(
                $this->config->require('MEILI_URL'),
                $this->config->require('SEARCH_KEY'),
                300,
                800
            );
            $writeKey = $this->config->get('WRITE_KEY');
            $this->writeClient = $writeKey === '' ? null : new MeiliClient(
                $this->config->require('MEILI_URL'),
                $writeKey,
                500,
                5000
            );
        } catch (\Throwable $error) {
            error_log('[SuiteSearch] Meilisearch disabled: ' . $error->getMessage());
            $this->writeClient = null;
        }
        $this->indexer = new Indexer($this->db);
        $this->liveIndex = $this->config !== null
            ? $this->config->get('MEILI_INDEX_LIVE', 'posts_live')
            : 'posts_live';
        $this->mysqlFallback = $this->config === null || $this->config->getBool('MYSQL_FALLBACK', true);
        $this->prefix = $this->db->getPrefix();
        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->prefix)) {
            throw new \RuntimeException('Unsafe Typecho table prefix');
        }
        $this->queueAvailable = $this->hasQueueSchema();
        $this->docsAvailable = $this->hasTable('suite_search_docs');
        $this->circuit = new CircuitBreaker($this->circuitPath(), 30);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function search(string $keywords, \Widget\Archive $archive): void
    {
        $keywords = trim($keywords);
        if ($keywords === '') {
            $archive->setTotal(0);
            return;
        }

        $pageSize = max(1, (int) ($archive->parameter->pageSize ?? 10));
        $page = max(1, $archive->getCurrentPage());
        $offset = ($page - 1) * $pageSize;

        try {
            if ($this->searchClient === null) {
                throw new \RuntimeException('Meilisearch is not configured');
            }
            if ($this->circuit->isOpen()) {
                throw new \RuntimeException('Meilisearch circuit breaker is open');
            }
            $result = $this->searchClient->search($this->liveIndex, $keywords, $pageSize, $offset);
            $hits = is_array($result['hits'] ?? null) ? $result['hits'] : [];
            $rows = $this->rowsForIds(array_map(
                static fn (array $hit): int => (int) ($hit['id'] ?? 0),
                $hits
            ));
            $archive->setTotal((int) ($result['estimatedTotalHits'] ?? count($rows)));
            $this->pushInHitOrder($archive, $hits, $rows);
            $this->circuit->clear();
            $this->fallbackReason = '';
            return;
        } catch (\Throwable $error) {
            $this->circuit->trip();
            $this->fallbackReason = $error->getMessage();
            error_log('[SuiteSearch] Meilisearch unavailable, using LIKE: ' . $error->getMessage());
        }

        if ($this->mysqlFallback) {
            if (!headers_sent()) {
                header('X-SuiteSearch-Backend: mysql');
            }
            $this->searchLike($keywords, $archive, $page, $pageSize);
        } else {
            $archive->setTotal(0);
        }
    }

    public function health(): bool
    {
        try {
            if ($this->searchClient === null) {
                return false;
            }
            $this->searchClient->search($this->liveIndex, 'health', 1);
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    /** @return array{backend:string,published:?int,pending:?int,rebuild:string,docs:bool,docCount:?int,circuit:bool} */
    public function diagnostics(): array
    {
        $published = null;
        try {
            $published = $this->indexer->publishedCount();
        } catch (\Throwable $error) {
        }
        $pending = null;
        $rebuild = '未安装';
        $docCount = null;
        if ($this->docsAvailable) {
            $connection = $this->db->selectDb(Db::READ);
            if ($connection instanceof \mysqli) {
                $result = $connection->query('SELECT COUNT(*) AS n FROM ' . $this->table('suite_search_docs'));
                if ($result) {
                    $docCount = (int) (($result->fetch_assoc()['n'] ?? 0));
                }
            }
        }
        if ($this->queueAvailable) {
            $connection = $this->db->selectDb(Db::READ);
            if ($connection instanceof \mysqli) {
                $result = $connection->query(
                    'SELECT COUNT(*) AS n FROM ' . $this->table('suite_changequeue') . ' WHERE processed_at IS NULL'
                );
                if ($result) {
                    $pending = (int) (($result->fetch_assoc()['n'] ?? 0));
                }
                $result = $connection->query(
                    'SELECT rebuild_state, rebuild_phase FROM ' . $this->table('suite_searchmeta') . ' WHERE id = 1 LIMIT 1'
                );
                if ($result && ($row = $result->fetch_assoc())) {
                    $rebuild = (string) ($row['rebuild_state'] ?? 'UNLOCKED') . '/' . (string) ($row['rebuild_phase'] ?? 'IDLE');
                }
            }
        }
        return [
            'backend' => $this->searchClient !== null && !$this->circuit->isOpen() ? 'meilisearch' : 'mysql',
            'published' => $published,
            'pending' => $pending,
            'rebuild' => $rebuild,
            'docs' => $this->docsAvailable,
            'docCount' => $docCount,
            'circuit' => $this->circuit->isOpen(),
        ];
    }

    public function sync(int $cid, string $operation): void
    {
        if ($cid <= 0) {
            return;
        }

        if ($this->config !== null && !$this->config->getBool('AUTO_SYNC', true)) {
            return;
        }

        if ($this->docsAvailable) {
            try {
                $this->syncMaterializedDoc($cid, $operation);
            } catch (\Throwable $error) {
                error_log('[SuiteSearch] materialized fallback sync failed: ' . $error->getMessage());
            }
        }

        $batchId = $this->enqueue($cid, $operation);
        if ($batchId !== null || $this->writeClient === null) {
            return;
        }

        try {
            $document = $this->indexer->currentDocument($cid);
            if ($document === null) {
                $taskUid = $this->writeClient->deleteDocuments($this->liveIndex, [$cid]);
            } else {
                unset($document['_modified']);
                $taskUid = $this->writeClient->addDocuments($this->liveIndex, [$document]);
            }
            $this->writeClient->waitForTask($taskUid, microtime(true) + 4.5);
        } catch (\Throwable $error) {
            error_log('[SuiteSearch] live sync deferred to rebuild: ' . $error->getMessage());
        }
    }

    private function searchLike(string $keywords, \Widget\Archive $archive, int $page, int $pageSize): void
    {
        $escaped = strtr($keywords, ['=' => '==', '\\' => '=\\', '%' => '=%', '_' => '=_']);
        $pattern = '%' . $escaped . '%';

        $connection = $this->db->selectDb(Db::READ);
        if (!$connection instanceof \mysqli) {
            throw new \RuntimeException('SuiteSearch requires the Mysqli adapter');
        }
        $table = $this->table('contents');
        $where = " WHERE c.type = ? AND c.status = ? AND c.created < ?"
            . " AND (c.title LIKE ? ESCAPE '=' OR c.text LIKE ? ESCAPE '='"
            . " OR EXISTS (SELECT 1 FROM " . $this->table('relationships') . " r"
            . " INNER JOIN " . $this->table('metas') . " m ON m.mid = r.mid"
            . " WHERE r.cid = c.cid AND m.type = 'tag' AND m.name LIKE ? ESCAPE '=')"
            . " OR EXISTS (SELECT 1 FROM " . $this->table('relationships') . " r"
            . " INNER JOIN " . $this->table('metas') . " m ON m.mid = r.mid"
            . " WHERE r.cid = c.cid AND m.type = 'category' AND m.name LIKE ? ESCAPE '='))";
        $count = $connection->prepare('SELECT COUNT(*) AS total FROM ' . $table . ' c' . $where);
        if (!$count) {
            throw new \RuntimeException($connection->error);
        }
        $type = 'post';
        $status = 'publish';
        $time = (int) Options::alloc()->time;
        $count->bind_param('ssissss', $type, $status, $time, $pattern, $pattern, $pattern, $pattern);
        if (!$count->execute()) {
            throw new \RuntimeException($count->error);
        }
        $total = $count->get_result()->fetch_assoc();
        $count->close();
        $archive->setTotal((int) ($total['total'] ?? 0));

        $offset = ($page - 1) * $pageSize;
        $rowsStatement = $connection->prepare(
            'SELECT c.* FROM ' . $table . ' c' . $where . ' ORDER BY c.created DESC, c.cid DESC LIMIT ?, ?'
        );
        if (!$rowsStatement) {
            throw new \RuntimeException($connection->error);
        }
        $rowsStatement->bind_param('ssissssii', $type, $status, $time, $pattern, $pattern, $pattern, $pattern, $offset, $pageSize);
        if (!$rowsStatement->execute()) {
            throw new \RuntimeException($rowsStatement->error);
        }
        $result = $rowsStatement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $rowsStatement->close();
        foreach ($rows as $row) {
            $archive->push($row);
        }
    }

    private function rowsForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }

        $rows = $this->db->fetchAll(
            $this->db->select('table.contents.*')->from('table.contents')
                ->where('table.contents.cid IN ?', $ids)
                ->where('table.contents.type = ?', 'post')
                ->where('table.contents.status = ?', 'publish')
                ->where('table.contents.created < ?', Options::alloc()->time)
        );
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[(int) $row['cid']] = $row;
        }
        return $mapped;
    }

    private function pushInHitOrder(\Widget\Archive $archive, array $hits, array $rows): void
    {
        foreach ($hits as $hit) {
            $cid = (int) ($hit['id'] ?? 0);
            if (isset($rows[$cid])) {
                $archive->push($rows[$cid]);
            }
        }
    }

    private function enqueue(int $cid, string $operation): ?string
    {
        if (!$this->queueAvailable) {
            return null;
        }
        $connection = $this->db->selectDb(Db::WRITE);
        if (!$connection instanceof \mysqli) {
            throw new \RuntimeException('SuiteSearch requires the Mysqli adapter');
        }

        $metaTable = $this->table('suite_searchmeta');
        $queueTable = $this->table('suite_changequeue');
        $connection->begin_transaction();
        try {
            $meta = $connection->query(
                'SELECT rebuild_state, rebuild_batch_id FROM ' . $metaTable . ' WHERE id = 1 FOR UPDATE'
            );
            if (!$meta) {
                throw new \RuntimeException($connection->error);
            }
            $state = $meta->fetch_assoc() ?: [];
            $batchId = $state['rebuild_state'] === 'LOCKED' || $state['rebuild_state'] === 'RECOVERY'
                ? (string) ($state['rebuild_batch_id'] ?? '')
                : null;
            $statement = $connection->prepare(
                'INSERT INTO ' . $queueTable
                . ' (cid, op, created_at, rebuild_batch_id) VALUES (?, ?, NOW(6), ?)'
            );
            if (!$statement) {
                throw new \RuntimeException($connection->error);
            }
            $statement->bind_param('iss', $cid, $operation, $batchId);
            if (!$statement->execute()) {
                throw new \RuntimeException($statement->error);
            }
            $statement->close();
            $connection->commit();
            return $batchId !== '' ? $batchId : null;
        } catch (\Throwable $error) {
            $connection->rollback();
            throw $error;
        }
    }

    private function table(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
    }

    private function circuitPath(): string
    {
        $path = getenv('TYPECHO_SUITE_SEARCH_CIRCUIT_FILE') ?: sys_get_temp_dir() . '/typecho-suite-search-circuit';
        return preg_match('#^/[A-Za-z0-9_./-]+$#', $path) ? $path : sys_get_temp_dir() . '/typecho-suite-search-circuit';
    }

    private function hasQueueSchema(): bool
    {
        $connection = $this->db->selectDb(Db::WRITE);
        if (!$connection instanceof \mysqli) {
            return false;
        }

        foreach (['suite_searchmeta', 'suite_changequeue', 'suite_rebuildtask'] as $name) {
            $statement = $connection->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            if (!$statement) {
                return false;
            }
            $table = $this->prefix . $name;
            $statement->bind_param('s', $table);
            if (!$statement->execute() || !$statement->get_result()->fetch_row()) {
                $statement->close();
                return false;
            }
            $statement->close();
        }

        return true;
    }

    private function hasTable(string $name): bool
    {
        $connection = $this->db->selectDb(Db::READ);
        if (!$connection instanceof \mysqli || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return false;
        }
        $statement = $connection->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        if (!$statement) {
            return false;
        }
        $table = $this->prefix . $name;
        $statement->bind_param('s', $table);
        $ok = $statement->execute() && (bool) $statement->get_result()->fetch_row();
        $statement->close();
        return $ok;
    }

    private function syncMaterializedDoc(int $cid, string $operation): void
    {
        $connection = $this->db->selectDb(Db::WRITE);
        if (!$connection instanceof \mysqli) {
            throw new \RuntimeException('SuiteSearch requires the Mysqli adapter');
        }
        $table = $this->table('suite_search_docs');
        if ($operation === 'delete') {
            $statement = $connection->prepare('DELETE FROM ' . $table . ' WHERE cid = ?');
            if (!$statement) {
                throw new \RuntimeException($connection->error);
            }
            $statement->bind_param('i', $cid);
            if (!$statement->execute()) {
                throw new \RuntimeException($statement->error);
            }
            $statement->close();
            return;
        }
        $document = $this->indexer->currentDocument($cid);
        if ($document === null) {
            $this->syncMaterializedDoc($cid, 'delete');
            return;
        }
        $title = (string) ($document['title'] ?? '');
        $body = (string) ($document['text'] ?? '');
        $tags = implode("\n", array_map('strval', (array) ($document['tags'] ?? [])));
        $categories = implode("\n", array_map('strval', (array) ($document['categories'] ?? [])));
        $modified = (int) ($document['_modified'] ?? time());
        $statement = $connection->prepare(
            'INSERT INTO ' . $table . ' (cid,status,title,body,tags,categories,modified,updated_at) '
            . "VALUES (?, 'publish', ?, ?, ?, ?, ?, NOW(6)) "
            . 'ON DUPLICATE KEY UPDATE status=VALUES(status), title=VALUES(title), body=VALUES(body), '
            . 'tags=VALUES(tags), categories=VALUES(categories), modified=VALUES(modified), updated_at=NOW(6)'
        );
        if (!$statement) {
            throw new \RuntimeException($connection->error);
        }
        $statement->bind_param('issssi', $cid, $title, $body, $tags, $categories, $modified);
        if (!$statement->execute()) {
            throw new \RuntimeException($statement->error);
        }
        $statement->close();
    }
}
