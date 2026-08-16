<?php

namespace TypechoPlugin\LuckyguoSearch;

use Typecho\Db;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class SearchService
{
    private static ?self $instance = null;
    private Db $db;
    private RuntimeConfig $config;
    private MeiliClient $searchClient;
    private ?MeiliClient $writeClient;
    private Indexer $indexer;
    private string $liveIndex;
    private string $prefix;

    private function __construct()
    {
        $this->db = Db::get();
        $this->config = RuntimeConfig::fromFile('/etc/luckyguo-search-search.env');
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
        $this->indexer = new Indexer($this->db);
        $this->liveIndex = $this->config->get('MEILI_INDEX_LIVE', 'posts_live');
        $this->prefix = $this->db->getPrefix();
        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->prefix)) {
            throw new \RuntimeException('Unsafe Typecho table prefix');
        }
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
            $result = $this->searchClient->search($this->liveIndex, $keywords, $pageSize, $offset);
            $hits = is_array($result['hits'] ?? null) ? $result['hits'] : [];
            $rows = $this->rowsForIds(array_map(
                static fn (array $hit): int => (int) ($hit['id'] ?? 0),
                $hits
            ));
            $archive->setTotal((int) ($result['estimatedTotalHits'] ?? count($rows)));
            $this->pushInHitOrder($archive, $hits, $rows);
            return;
        } catch (\Throwable $error) {
            error_log('[LuckyguoSearch] Meilisearch unavailable, using LIKE: ' . $error->getMessage());
        }

        $this->searchLike($keywords, $archive, $page, $pageSize);
    }

    public function health(): bool
    {
        try {
            $this->searchClient->search($this->liveIndex, '健康', 1);
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    public function sync(int $cid, string $operation): void
    {
        if ($cid <= 0) {
            return;
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
            error_log('[LuckyguoSearch] live sync deferred to rebuild: ' . $error->getMessage());
        }
    }

    private function searchLike(string $keywords, \Widget\Archive $archive, int $page, int $pageSize): void
    {
        $escaped = strtr($keywords, ['=' => '==', '\\' => '=\\', '%' => '=%', '_' => '=_']);
        $pattern = '%' . $escaped . '%';

        $connection = $this->db->selectDb(Db::READ);
        if (!$connection instanceof \mysqli) {
            throw new \RuntimeException('LuckyguoSearch requires the Mysqli adapter');
        }
        $table = $this->table('contents');
        $where = " WHERE type = ? AND status = ? AND created < ?"
            . " AND (title LIKE ? ESCAPE '=' OR text LIKE ? ESCAPE '=')";
        $count = $connection->prepare('SELECT COUNT(*) AS total FROM ' . $table . $where);
        if (!$count) {
            throw new \RuntimeException($connection->error);
        }
        $type = 'post';
        $status = 'publish';
        $time = (int) Options::alloc()->time;
        $count->bind_param('ssiss', $type, $status, $time, $pattern, $pattern);
        if (!$count->execute()) {
            throw new \RuntimeException($count->error);
        }
        $total = $count->get_result()->fetch_assoc();
        $count->close();
        $archive->setTotal((int) ($total['total'] ?? 0));

        $offset = ($page - 1) * $pageSize;
        $rowsStatement = $connection->prepare(
            'SELECT * FROM ' . $table . $where . ' ORDER BY created DESC, cid DESC LIMIT ?, ?'
        );
        if (!$rowsStatement) {
            throw new \RuntimeException($connection->error);
        }
        $rowsStatement->bind_param('ssissii', $type, $status, $time, $pattern, $pattern, $offset, $pageSize);
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
        $connection = $this->db->selectDb(Db::WRITE);
        if (!$connection instanceof \mysqli) {
            throw new \RuntimeException('LuckyguoSearch requires the Mysqli adapter');
        }

        $metaTable = $this->table('luckyguo_searchmeta');
        $queueTable = $this->table('luckyguo_changequeue');
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
}
