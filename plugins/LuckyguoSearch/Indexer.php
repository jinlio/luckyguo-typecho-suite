<?php

namespace TypechoPlugin\LuckyguoSearch;

use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class Indexer
{
    private Db $db;
    private \mysqli $connection;
    private string $prefix;
    private string $siteUrl;

    public function __construct(Db $db)
    {
        $prefix = $db->getPrefix();
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            throw new \RuntimeException('Unsafe Typecho table prefix');
        }

        $connection = $db->selectDb(Db::READ);
        if (!$connection instanceof \mysqli) {
            throw new \RuntimeException('LuckyguoSearch requires the Mysqli adapter');
        }

        $this->db = $db;
        $this->connection = $connection;
        $this->prefix = $prefix;
        $this->siteUrl = rtrim($this->loadSiteUrl(), '/');
    }

    public function publishedBatch(int $afterCid, int $limit = 100): array
    {
        $sql = 'SELECT cid, title, slug, text, created, modified'
            . ' FROM ' . $this->table('contents')
            . " WHERE type = 'post' AND status = 'publish' AND cid > ?"
            . ' ORDER BY cid ASC LIMIT ?';
        $statement = $this->prepare($sql);
        $statement->bind_param('ii', $afterCid, $limit);

        return $this->documentsFromStatement($statement);
    }

    public function modifiedBatch(int $startEpoch, int $lastModified, int $lastCid, int $limit = 100): array
    {
        $sql = 'SELECT cid, title, slug, text, created, modified'
            . ' FROM ' . $this->table('contents')
            . " WHERE type = 'post' AND status = 'publish' AND modified >= ?"
            . ' AND (modified > ? OR (modified = ? AND cid > ?))'
            . ' ORDER BY modified ASC, cid ASC LIMIT ?';
        $statement = $this->prepare($sql);
        $statement->bind_param('iiiii', $startEpoch, $lastModified, $lastModified, $lastCid, $limit);

        return $this->documentsFromStatement($statement);
    }

    public function currentDocument(int $cid): ?array
    {
        $sql = 'SELECT cid, title, slug, text, created, modified'
            . ' FROM ' . $this->table('contents')
            . " WHERE cid = ? AND type = 'post' AND status = 'publish' LIMIT 1";
        $statement = $this->prepare($sql);
        $statement->bind_param('i', $cid);
        $documents = $this->documentsFromStatement($statement);

        return $documents[0] ?? null;
    }

    public function publishedCount(): int
    {
        $result = $this->connection->query(
            'SELECT COUNT(*) AS total FROM ' . $this->table('contents')
            . " WHERE type = 'post' AND status = 'publish'"
        );
        if (!$result) {
            throw new \RuntimeException($this->connection->error);
        }
        $row = $result->fetch_assoc();

        return (int) ($row['total'] ?? 0);
    }

    public function recentPublished(int $limit = 5): array
    {
        $sql = 'SELECT cid, title, slug, text, created, modified'
            . ' FROM ' . $this->table('contents')
            . " WHERE type = 'post' AND status = 'publish'"
            . ' ORDER BY created DESC, cid DESC LIMIT ?';
        $statement = $this->prepare($sql);
        $statement->bind_param('i', $limit);

        return $this->documentsFromStatement($statement);
    }

    private function documentsFromStatement(\mysqli_stmt $statement): array
    {
        if (!$statement->execute()) {
            throw new \RuntimeException($statement->error);
        }
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();
        if (!$rows) {
            return [];
        }

        $metas = $this->loadMetas(array_map(static fn (array $row): int => (int) $row['cid'], $rows));
        $documents = [];
        foreach ($rows as $row) {
            $cid = (int) $row['cid'];
            $documents[] = [
                'id' => $cid,
                'title' => $this->plainText((string) $row['title']),
                'slug' => (string) $row['slug'],
                'text' => $this->plainText((string) $row['text']),
                'tags' => $metas[$cid]['tags'] ?? [],
                'categories' => $metas[$cid]['categories'] ?? [],
                'date' => (int) $row['created'],
                'url' => $this->siteUrl . '/archives/' . $cid . '/',
                '_modified' => (int) $row['modified'],
            ];
        }

        return $documents;
    }

    private function loadMetas(array $cids): array
    {
        if (!$cids) {
            return [];
        }
        $cids = array_values(array_unique(array_map('intval', $cids)));
        $sql = 'SELECT r.cid, m.type, m.name FROM ' . $this->table('relationships') . ' r'
            . ' INNER JOIN ' . $this->table('metas') . ' m ON m.mid = r.mid'
            . ' WHERE r.cid IN (' . implode(',', $cids) . ") AND m.type IN ('tag', 'category')"
            . ' ORDER BY r.cid ASC, m.type ASC, m.order ASC, m.mid ASC';
        $result = $this->connection->query($sql);
        if (!$result) {
            throw new \RuntimeException($this->connection->error);
        }

        $metas = [];
        while ($row = $result->fetch_assoc()) {
            $cid = (int) $row['cid'];
            $key = $row['type'] === 'tag' ? 'tags' : 'categories';
            $metas[$cid][$key][] = $this->plainText((string) $row['name']);
        }

        foreach ($metas as &$entry) {
            $entry['tags'] = array_values(array_unique($entry['tags'] ?? []));
            $entry['categories'] = array_values(array_unique($entry['categories'] ?? []));
        }

        return $metas;
    }

    private function loadSiteUrl(): string
    {
        $result = $this->connection->query(
            'SELECT value FROM ' . $this->table('options') . " WHERE name = 'siteUrl' LIMIT 1"
        );
        if (!$result) {
            throw new \RuntimeException($this->connection->error);
        }
        $row = $result->fetch_assoc();
        $siteUrl = trim((string) ($row['value'] ?? ''));
        if (!preg_match('#^https?://#i', $siteUrl)) {
            throw new \RuntimeException('Invalid Typecho siteUrl');
        }

        return $siteUrl;
    }

    private function plainText(string $value): string
    {
        $value = str_replace('<!--markdown-->', '', $value);
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $statement = $this->connection->prepare($sql);
        if (!$statement) {
            throw new \RuntimeException($this->connection->error);
        }

        return $statement;
    }

    private function table(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
    }
}
