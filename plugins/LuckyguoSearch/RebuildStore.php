<?php

namespace TypechoPlugin\LuckyguoSearch;

use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class RebuildStore
{
    private Db $db;
    private \mysqli $connection;
    private \mysqli $ledgerConnection;
    private string $prefix;
    private bool $transactionActive = false;

    public function __construct(Db $db)
    {
        $prefix = $db->getPrefix();
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            throw new \RuntimeException('Unsafe Typecho table prefix');
        }
        $connection = $db->selectDb(Db::WRITE);
        if (!$connection instanceof \mysqli) {
            throw new \RuntimeException('LuckyguoSearch requires the Mysqli adapter');
        }

        $config = $db->getConfig(Db::WRITE);
        $ledger = mysqli_init();
        if (!$ledger) {
            throw new \RuntimeException('Unable to initialize task ledger connection');
        }
        $host = (string) $config->host;
        $port = empty($config->port) ? 3306 : (int) $config->port;
        $socket = null;
        if (strpos($host, '/') !== false) {
            $socket = $host;
            $host = 'localhost';
            $port = 0;
        }
        $ledger->real_connect(
            $host,
            (string) $config->user,
            (string) $config->password,
            (string) $config->database,
            $port,
            $socket
        );
        $ledger->set_charset((string) ($config->charset ?: 'utf8mb4'));

        $this->db = $db;
        $this->connection = $connection;
        $this->ledgerConnection = $ledger;
        $this->prefix = $prefix;
    }

    public function meta(bool $forUpdate = false): array
    {
        $sql = 'SELECT * FROM ' . $this->table('luckyguo_searchmeta') . ' WHERE id = 1'
            . ($forUpdate ? ' FOR UPDATE' : '');
        $result = $this->connection->query($sql);
        if (!$result) {
            throw new \RuntimeException($this->connection->error);
        }
        $row = $result->fetch_assoc();
        if (!$row) {
            throw new \RuntimeException('Missing luckyguo_searchmeta singleton row');
        }

        return $row;
    }

    public function markBuild(string $batchId, int $startEpoch): void
    {
        $this->shortTransaction(function () use ($batchId, $startEpoch): void {
            $this->meta(true);
            $statement = $this->prepare(
                'UPDATE ' . $this->table('luckyguo_searchmeta')
                . " SET rebuild_state = 'UNLOCKED', rebuild_phase = 'BUILD', rebuild_batch_id = ?,"
                . ' build_start = FROM_UNIXTIME(?), build_end = NULL, swap_task_uid = NULL WHERE id = 1'
            );
            $statement->bind_param('si', $batchId, $startEpoch);
            $this->execute($statement);
        });
    }

    public function beginFence(string $batchId): void
    {
        if ($this->transactionActive) {
            throw new \LogicException('Fence transaction is already active');
        }
        $this->connection->begin_transaction();
        $this->transactionActive = true;
        try {
            $this->meta(true);
            $statement = $this->prepare(
                'UPDATE ' . $this->table('luckyguo_searchmeta')
                . " SET rebuild_state = 'LOCKED', rebuild_phase = 'FENCE', rebuild_batch_id = ? WHERE id = 1"
            );
            $statement->bind_param('s', $batchId);
            $this->execute($statement);
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function beginRecoveryFence(string $batchId, string $phase): void
    {
        $this->assertPhase($phase);
        if ($this->transactionActive) {
            throw new \LogicException('Fence transaction is already active');
        }
        $this->connection->begin_transaction();
        $this->transactionActive = true;
        try {
            $this->meta(true);
            $statement = $this->prepare(
                'UPDATE ' . $this->table('luckyguo_searchmeta')
                . " SET rebuild_state = 'LOCKED', rebuild_phase = '" . $phase . "', rebuild_batch_id = ? WHERE id = 1"
            );
            $statement->bind_param('s', $batchId);
            $this->execute($statement);
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function setPhase(string $phase, ?int $swapTaskUid = null): void
    {
        $this->assertPhase($phase);
        if (!$this->transactionActive) {
            throw new \LogicException('Phase change requires the fence transaction');
        }
        if ($swapTaskUid === null) {
            $this->connection->query(
                'UPDATE ' . $this->table('luckyguo_searchmeta')
                . " SET rebuild_phase = '" . $phase . "' WHERE id = 1"
            );
            return;
        }

        $statement = $this->prepare(
            'UPDATE ' . $this->table('luckyguo_searchmeta')
            . " SET rebuild_phase = '" . $phase . "', swap_task_uid = ? WHERE id = 1"
        );
        $statement->bind_param('i', $swapTaskUid);
        $this->execute($statement);
    }

    public function enterRecovery(string $phase, ?int $taskUid = null): void
    {
        $this->assertPhase($phase);
        $callback = function () use ($phase, $taskUid): void {
            $statement = $this->prepare(
                'UPDATE ' . $this->table('luckyguo_searchmeta')
                . " SET rebuild_state = 'RECOVERY', rebuild_phase = '" . $phase . "', swap_task_uid = COALESCE(?, swap_task_uid)"
                . ' WHERE id = 1'
            );
            $statement->bind_param('i', $taskUid);
            $this->execute($statement);
        };

        if ($this->transactionActive) {
            $callback();
            $this->commit();
            return;
        }

        $this->shortTransaction(function () use ($callback): void {
            $this->meta(true);
            $callback();
        });
    }

    public function resetIdle(): void
    {
        $callback = function (): void {
            $this->connection->query(
                'UPDATE ' . $this->table('luckyguo_searchmeta')
                . " SET rebuild_state = 'UNLOCKED', rebuild_phase = 'IDLE' WHERE id = 1"
            );
        };
        if ($this->transactionActive) {
            $callback();
            $this->commit();
            return;
        }

        $this->shortTransaction(function () use ($callback): void {
            $this->meta(true);
            $callback();
        });
    }

    public function finishSuccess(string $version, int $documentCount): void
    {
        if (!$this->transactionActive) {
            throw new \LogicException('Successful completion requires the fence transaction');
        }
        $batchId = (string) ($this->meta(false)['rebuild_batch_id'] ?? '');
        $statement = $this->prepare(
            'UPDATE ' . $this->table('luckyguo_changequeue')
            . ' SET processed_at = NOW(6) WHERE processed_at IS NULL AND rebuild_batch_id = ?'
        );
        $statement->bind_param('s', $batchId);
        $this->execute($statement);

        $statement = $this->prepare(
            'UPDATE ' . $this->table('luckyguo_searchmeta')
            . " SET search_index_version = ?, build_end = NOW(6), document_count = ?,"
            . " rebuild_state = 'UNLOCKED', rebuild_phase = 'IDLE' WHERE id = 1"
        );
        $statement->bind_param('si', $version, $documentCount);
        $this->execute($statement);
        $this->commit();
    }

    public function finishRollback(string $batchId): void
    {
        if (!$this->transactionActive) {
            throw new \LogicException('Rollback completion requires the fence transaction');
        }
        $statement = $this->prepare(
            'UPDATE ' . $this->table('luckyguo_changequeue')
            . ' SET processed_at = NOW(6) WHERE processed_at IS NULL AND rebuild_batch_id = ?'
        );
        $statement->bind_param('s', $batchId);
        $this->execute($statement);
        $this->connection->query(
            'UPDATE ' . $this->table('luckyguo_searchmeta')
            . " SET rebuild_state = 'UNLOCKED', rebuild_phase = 'IDLE', build_end = NOW(6) WHERE id = 1"
        );
        $this->commit();
    }

    public function claimUnprocessed(string $batchId): int
    {
        $statement = $this->prepare(
            'UPDATE ' . $this->table('luckyguo_changequeue')
            . ' SET rebuild_batch_id = ? WHERE processed_at IS NULL'
        );
        $statement->bind_param('s', $batchId);
        $this->execute($statement);

        return $statement->affected_rows;
    }

    public function batchCids(string $batchId): array
    {
        $statement = $this->prepare(
            'SELECT DISTINCT cid FROM ' . $this->table('luckyguo_changequeue')
            . ' WHERE processed_at IS NULL AND rebuild_batch_id = ? ORDER BY cid'
        );
        $statement->bind_param('s', $batchId);
        $this->execute($statement);
        $result = $statement->get_result();
        $cids = [];
        while ($row = $result->fetch_assoc()) {
            $cids[] = (int) $row['cid'];
        }
        $statement->close();

        return $cids;
    }

    public function batchChanges(string $batchId, int $afterId): array
    {
        $statement = $this->prepare(
            'SELECT id, cid FROM ' . $this->table('luckyguo_changequeue')
            . ' WHERE processed_at IS NULL AND rebuild_batch_id = ? AND id > ? ORDER BY id'
        );
        $statement->bind_param('si', $batchId, $afterId);
        $this->execute($statement);
        $result = $statement->get_result();
        $cids = [];
        $lastId = $afterId;
        while ($row = $result->fetch_assoc()) {
            $lastId = max($lastId, (int) $row['id']);
            $cids[] = (int) $row['cid'];
        }
        $statement->close();

        return [
            'lastId' => $lastId,
            'cids' => array_values(array_unique($cids)),
        ];
    }

    public function unackedCount(string $batchId): int
    {
        $statement = $this->prepare(
            'SELECT COUNT(*) AS total FROM ' . $this->table('luckyguo_changequeue')
            . ' WHERE processed_at IS NULL AND rebuild_batch_id = ?'
        );
        $statement->bind_param('s', $batchId);
        $this->execute($statement);
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();

        return (int) ($row['total'] ?? 0);
    }

    public function prepareTask(string $batchId, string $operation, string $indexUid): int
    {
        $statement = $this->ledgerConnection->prepare(
            'INSERT INTO ' . $this->table('luckyguo_rebuildtask')
            . " (batch_id, task_uid, operation, index_uid, status, submitted_at)"
            . " VALUES (?, NULL, ?, ?, 'pending', NOW(6))"
        );
        if (!$statement) {
            throw new \RuntimeException($this->ledgerConnection->error);
        }
        $statement->bind_param('sss', $batchId, $operation, $indexUid);
        if (!$statement->execute()) {
            throw new \RuntimeException($statement->error);
        }
        $id = (int) $statement->insert_id;
        $statement->close();

        return $id;
    }

    public function attachTask(int $ledgerId, int $taskUid): void
    {
        $statement = $this->ledgerConnection->prepare(
            'UPDATE ' . $this->table('luckyguo_rebuildtask')
            . " SET task_uid = ?, status = 'enqueued' WHERE id = ? AND status = 'pending'"
        );
        if (!$statement) {
            throw new \RuntimeException($this->ledgerConnection->error);
        }
        $statement->bind_param('ii', $taskUid, $ledgerId);
        if (!$statement->execute() || $statement->affected_rows !== 1) {
            throw new \RuntimeException('Unable to attach Meilisearch task to ledger');
        }
        $statement->close();
    }

    public function finishTask(int $taskUid, string $status): void
    {
        if (!in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
            throw new \InvalidArgumentException('Invalid terminal task status');
        }
        $statement = $this->ledgerConnection->prepare(
            'UPDATE ' . $this->table('luckyguo_rebuildtask')
            . ' SET status = ?, finished_at = NOW(6) WHERE task_uid = ?'
        );
        if (!$statement) {
            throw new \RuntimeException($this->ledgerConnection->error);
        }
        $statement->bind_param('si', $status, $taskUid);
        if (!$statement->execute()) {
            throw new \RuntimeException($statement->error);
        }
        $statement->close();
    }

    public function abandonTask(int $ledgerId): void
    {
        $statement = $this->ledgerConnection->prepare(
            'UPDATE ' . $this->table('luckyguo_rebuildtask')
            . " SET status = 'abandoned', finished_at = NOW(6) WHERE id = ? AND task_uid IS NULL"
        );
        if (!$statement) {
            throw new \RuntimeException($this->ledgerConnection->error);
        }
        $statement->bind_param('i', $ledgerId);
        $statement->execute();
        $statement->close();
    }

    public function tasksForBatch(string $batchId): array
    {
        $statement = $this->ledgerConnection->prepare(
            'SELECT * FROM ' . $this->table('luckyguo_rebuildtask')
            . ' WHERE batch_id = ? ORDER BY id'
        );
        if (!$statement) {
            throw new \RuntimeException($this->ledgerConnection->error);
        }
        $statement->bind_param('s', $batchId);
        if (!$statement->execute()) {
            throw new \RuntimeException($statement->error);
        }
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();

        return $rows;
    }

    public function commit(): void
    {
        if (!$this->transactionActive) {
            return;
        }
        $this->connection->commit();
        $this->transactionActive = false;
    }

    public function rollback(): void
    {
        if (!$this->transactionActive) {
            return;
        }
        $this->connection->rollback();
        $this->transactionActive = false;
    }

    public function transactionActive(): bool
    {
        return $this->transactionActive;
    }

    private function shortTransaction(callable $callback): void
    {
        if ($this->transactionActive) {
            throw new \LogicException('Nested transaction is not allowed');
        }
        $this->connection->begin_transaction();
        $this->transactionActive = true;
        try {
            $callback();
            $this->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $statement = $this->connection->prepare($sql);
        if (!$statement) {
            throw new \RuntimeException($this->connection->error);
        }

        return $statement;
    }

    private function execute(\mysqli_stmt $statement): void
    {
        if (!$statement->execute()) {
            throw new \RuntimeException($statement->error);
        }
    }

    private function assertPhase(string $phase): void
    {
        if (!in_array($phase, ['IDLE', 'BUILD', 'FENCE', 'SWAP', 'POST_SWAP', 'ROLLBACK'], true)) {
            throw new \InvalidArgumentException('Invalid rebuild phase');
        }
    }

    private function table(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
    }
}
