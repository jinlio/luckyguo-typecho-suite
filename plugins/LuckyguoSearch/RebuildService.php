<?php

namespace TypechoPlugin\LuckyguoSearch;

use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class RebuildService
{
    public const SETTINGS = [
        'searchableAttributes' => ['title', 'tags', 'categories', 'text'],
        'prefixSearch' => 'indexingTime',
    ];

    private RebuildStore $store;
    private Indexer $indexer;
    private MeiliClient $client;
    private MeiliClient $taskClient;
    private ?MeiliClient $searchClient;
    private string $liveIndex;
    private string $buildIndex;
    private int $fenceTimeout;
    private string $lockPath;
    private string $batchId = '';
    private string $phase = 'IDLE';

    public function __construct(
        Db $db,
        RuntimeConfig $config,
        ?MeiliClient $searchClient = null,
        ?MeiliClient $taskClient = null,
        string $lockPath = '/run/luckyguo-search/rebuild.lock'
    ) {
        $this->store = new RebuildStore($db);
        $this->indexer = new Indexer($db);
        $this->client = new MeiliClient(
            $config->require('MEILI_URL'),
            $config->require('REBUILD_KEY'),
            1000,
            30000
        );
        $this->taskClient = $taskClient ?? $this->client;
        $this->searchClient = $searchClient;
        $this->liveIndex = $config->get('MEILI_INDEX_LIVE', 'posts_live');
        $this->buildIndex = $config->get('MEILI_INDEX_BUILD', 'posts_build');
        $this->fenceTimeout = max(5, $config->getInt('REBUILD_FENCE_TIMEOUT', 30));
        $this->lockPath = $lockPath;
    }

    public function run(): int
    {
        $lockDirectory = dirname($this->lockPath);
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0750, true) && !is_dir($lockDirectory)) {
            throw new \RuntimeException('Unable to create rebuild lock directory');
        }
        $lock = fopen($this->lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open rebuild lock');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $this->log('Another rebuild is already running');
            fclose($lock);
            return 75;
        }

        try {
            $meta = $this->store->meta();
            if (($meta['rebuild_state'] ?? '') === 'RECOVERY' || ($meta['rebuild_phase'] ?? 'IDLE') !== 'IDLE') {
                $this->recover($meta);
            } else {
                $this->rebuild();
            }
            return 0;
        } finally {
            if ($this->store->transactionActive()) {
                $this->store->rollback();
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function rebuild(): void
    {
        $startEpoch = time();
        $this->batchId = date('Ymd-His');
        $this->phase = 'BUILD';
        $this->store->markBuild($this->batchId, $startEpoch);
        $this->log('Starting rebuild batch ' . $this->batchId);

        $queueCursor = 0;
        $lastModified = $startEpoch;
        $lastModifiedCid = 0;
        try {
            $buildDeadline = microtime(true) + 300;
            $this->runTask(
                'build',
                $this->buildIndex,
                fn (): int => $this->client->deleteAllDocuments($this->buildIndex),
                $buildDeadline
            );
            $indexed = $this->indexPublished($buildDeadline);
            $this->runTask(
                'settings',
                $this->buildIndex,
                fn (): int => $this->client->updateSettings($this->buildIndex, self::SETTINGS),
                $buildDeadline
            );

            $this->store->claimUnprocessed($this->batchId);
            $changes = $this->store->batchChanges($this->batchId, $queueCursor);
            $queueCursor = $changes['lastId'];
            if ($changes['cids']) {
                $this->applyCids($this->buildIndex, $changes['cids'], 'mutation', $buildDeadline);
            }

            $settled = false;
            for ($round = 1; $round <= 5; $round++) {
                [$lastModified, $lastModifiedCid, $modified] = $this->drainModified(
                    $startEpoch,
                    $lastModified,
                    $lastModifiedCid,
                    $buildDeadline
                );
                $this->store->claimUnprocessed($this->batchId);
                $changes = $this->store->batchChanges($this->batchId, $queueCursor);
                $queueCursor = $changes['lastId'];
                if ($changes['cids']) {
                    $this->applyCids($this->buildIndex, $changes['cids'], 'mutation', $buildDeadline);
                }
                if (!$modified && !$changes['cids']) {
                    $settled = true;
                    break;
                }
            }
            if (!$settled) {
                throw new \RuntimeException('Build did not settle within five compensation rounds');
            }
            $this->assertSettingsEqual();
            $this->log('Build prepared documents=' . $indexed);
        } catch (\Throwable $error) {
            $this->cancelAndDrainKnownTasks($this->batchId, microtime(true) + 30);
            if ($this->hasOpenTasks($this->batchId)) {
                $this->store->enterRecovery('BUILD', $this->taskUidFrom($error));
            } else {
                $this->store->resetIdle();
            }
            throw $error;
        }

        $deadline = microtime(true) + $this->fenceTimeout;
        $swapSucceeded = false;
        try {
            $this->phase = 'FENCE';
            $this->store->beginFence($this->batchId);
            $this->store->claimUnprocessed($this->batchId);
            $changes = $this->store->batchChanges($this->batchId, $queueCursor);
            $queueCursor = $changes['lastId'];
            if ($changes['cids']) {
                $this->applyCids($this->buildIndex, $changes['cids'], 'mutation', $deadline);
            }
            [$lastModified, $lastModifiedCid] = $this->drainModified(
                $startEpoch,
                $lastModified,
                $lastModifiedCid,
                $deadline
            );

            $this->phase = 'SWAP';
            $this->store->setPhase('SWAP');
            $swapUid = $this->runTask(
                'swap',
                $this->liveIndex . '|' . $this->buildIndex,
                fn (): int => $this->client->swapIndexes($this->liveIndex, $this->buildIndex),
                $deadline
            );
            $swapSucceeded = true;
            $this->store->setPhase('SWAP', $swapUid);
            $this->verifyLive();

            $this->phase = 'POST_SWAP';
            $this->store->setPhase('POST_SWAP');
            $this->applyCids(
                $this->liveIndex,
                $this->store->batchCids($this->batchId),
                'replay',
                $deadline
            );
            if (microtime(true) >= $deadline) {
                throw new MeiliTaskTimeout($swapUid);
            }

            $documentCount = $this->indexer->publishedCount();
            $this->store->finishSuccess($this->batchId, $documentCount);
            $this->log('Rebuild succeeded documents=' . $documentCount);
        } catch (\Throwable $error) {
            if ($swapSucceeded) {
                $this->rollbackAfterSwap($error, $deadline);
            } elseif ($this->store->transactionActive()) {
                if ($this->hasOpenTasks($this->batchId)) {
                    $this->store->enterRecovery($this->phase, $this->taskUidFrom($error));
                } else {
                    $this->store->rollback();
                    $this->store->resetIdle();
                }
            }
            throw $error;
        }
    }

    private function recover(array $meta): void
    {
        $this->batchId = (string) ($meta['rebuild_batch_id'] ?? '');
        $this->phase = (string) ($meta['rebuild_phase'] ?? 'IDLE');
        if ($this->batchId === '' || $this->phase === 'IDLE') {
            throw new \RuntimeException('Recovery metadata is incomplete');
        }
        if (($meta['rebuild_state'] ?? '') !== 'RECOVERY') {
            $this->store->enterRecovery($this->phase);
        }
        $this->log('Recovering batch ' . $this->batchId . ' phase=' . $this->phase);
        $this->refreshBatchTasks($this->batchId, microtime(true) + 120);
        if ($this->hasOpenTasks($this->batchId)) {
            throw new \RuntimeException('Recovery still has unknown or non-terminal tasks');
        }

        $tasks = $this->store->tasksForBatch($this->batchId);
        $swap = $this->latestTask($tasks, 'swap');
        $rollback = $this->latestTask($tasks, 'rollback');
        if (in_array($this->phase, ['BUILD', 'FENCE'], true)) {
            $this->store->resetIdle();
            $this->log('Recovered pre-swap batch without changing live index');
            return;
        }
        if ($this->phase === 'SWAP' && (($swap['status'] ?? '') === 'failed' || ($swap['status'] ?? '') === 'canceled')) {
            $this->store->resetIdle();
            $this->log('Recovered terminal no-swap failure');
            return;
        }

        $swapSucceeded = ($swap['status'] ?? '') === 'succeeded';
        $rollbackSucceeded = ($rollback['status'] ?? '') === 'succeeded';
        if ($rollback !== null && !$rollbackSucceeded) {
            throw new \RuntimeException('The reverse swap did not succeed; recovery remains locked');
        }
        if (!$swapSucceeded && !$rollbackSucceeded) {
            throw new \RuntimeException('Recovery cannot prove the live index state');
        }
        $deadline = microtime(true) + $this->fenceTimeout;
        $this->store->beginRecoveryFence($this->batchId, 'ROLLBACK');
        try {
            if (!$rollbackSucceeded) {
                $this->runTask(
                    'rollback',
                    $this->liveIndex . '|' . $this->buildIndex,
                    fn (): int => $this->client->swapIndexes($this->liveIndex, $this->buildIndex),
                    $deadline
                );
            }
            $this->applyCids(
                $this->liveIndex,
                $this->store->batchCids($this->batchId),
                'replay',
                $deadline
            );
            $this->store->finishRollback($this->batchId);
            $this->log('Recovery restored the old live index and replayed the batch');
        } catch (\Throwable $error) {
            if ($this->store->transactionActive()) {
                $this->store->enterRecovery('ROLLBACK', $this->taskUidFrom($error));
            }
            throw $error;
        }
    }

    private function rollbackAfterSwap(\Throwable $original, float $deadline): void
    {
        try {
            $this->phase = 'ROLLBACK';
            $this->store->setPhase('ROLLBACK');
            $rollbackUid = $this->runTask(
                'rollback',
                $this->liveIndex . '|' . $this->buildIndex,
                fn (): int => $this->client->swapIndexes($this->liveIndex, $this->buildIndex),
                $deadline
            );
            $this->store->setPhase('ROLLBACK', $rollbackUid);
            $this->applyCids(
                $this->liveIndex,
                $this->store->batchCids($this->batchId),
                'replay',
                $deadline
            );
            $this->store->finishRollback($this->batchId);
            $this->log('Swap was reversed after failure: ' . $original->getMessage());
        } catch (\Throwable $rollbackError) {
            if ($this->store->transactionActive()) {
                $this->store->enterRecovery('ROLLBACK', $this->taskUidFrom($rollbackError));
            }
            throw new \RuntimeException(
                'Post-swap failure could not be rolled back: ' . $rollbackError->getMessage(),
                0,
                $original
            );
        }
    }

    private function indexPublished(float $deadline): int
    {
        $afterCid = 0;
        $count = 0;
        do {
            $documents = $this->indexer->publishedBatch($afterCid, 100);
            if (!$documents) {
                break;
            }
            $afterCid = (int) end($documents)['id'];
            $count += count($documents);
            $payload = $this->stripInternal($documents);
            $this->runTask(
                'build',
                $this->buildIndex,
                fn (): int => $this->client->addDocuments($this->buildIndex, $payload),
                $deadline
            );
        } while (count($documents) === 100);

        return $count;
    }

    private function drainModified(
        int $startEpoch,
        int $lastModified,
        int $lastCid,
        float $deadline
    ): array {
        $changed = false;
        do {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Modified compensation exceeded its deadline');
            }
            $documents = $this->indexer->modifiedBatch($startEpoch, $lastModified, $lastCid, 100);
            if (!$documents) {
                break;
            }
            $changed = true;
            $last = end($documents);
            $lastModified = (int) $last['_modified'];
            $lastCid = (int) $last['id'];
            $payload = $this->stripInternal($documents);
            $this->runTask(
                'mutation',
                $this->buildIndex,
                fn (): int => $this->client->addDocuments($this->buildIndex, $payload),
                $deadline
            );
        } while (count($documents) === 100);

        return [$lastModified, $lastCid, $changed];
    }

    private function applyCids(string $index, array $cids, string $operation, float $deadline): void
    {
        $cids = array_values(array_unique(array_filter(array_map('intval', $cids), static fn (int $cid): bool => $cid > 0)));
        foreach (array_chunk($cids, 100) as $chunk) {
            $upserts = [];
            $deletes = [];
            foreach ($chunk as $cid) {
                $document = $this->indexer->currentDocument($cid);
                if ($document === null) {
                    $deletes[] = $cid;
                } else {
                    unset($document['_modified']);
                    $upserts[] = $document;
                }
            }
            if ($upserts) {
                $this->runTask(
                    $operation,
                    $index,
                    fn (): int => $this->client->addDocuments($index, $upserts),
                    $deadline
                );
            }
            if ($deletes) {
                $this->runTask(
                    $operation,
                    $index,
                    fn (): int => $this->client->deleteDocuments($index, $deletes),
                    $deadline
                );
            }
        }
    }

    private function runTask(string $operation, string $indexUid, callable $submit, float $deadline): int
    {
        if (microtime(true) >= $deadline) {
            throw new \RuntimeException('No time remains to submit Meilisearch task');
        }
        $ledgerId = $this->store->prepareTask($this->batchId, $operation, $indexUid);
        try {
            $taskUid = $submit();
        } catch (MeiliException $error) {
            if ($error->statusCode() >= 400) {
                $this->store->abandonTask($ledgerId);
            }
            throw $error;
        }
        $this->store->attachTask($ledgerId, $taskUid);
        try {
            $this->taskClient->waitForTask($taskUid, $deadline);
            $this->store->finishTask($taskUid, 'succeeded');
            return $taskUid;
        } catch (MeiliTaskTimeout $error) {
            throw $error;
        } catch (MeiliException $error) {
            try {
                $task = $this->taskClient->getTask($taskUid);
                $status = (string) ($task['status'] ?? '');
                if (in_array($status, ['failed', 'canceled'], true)) {
                    $this->store->finishTask($taskUid, $status);
                }
            } catch (\Throwable $ignored) {
            }
            throw $error;
        }
    }

    private function verifyLive(): void
    {
        $stats = $this->client->getStats($this->liveIndex);
        $expected = $this->indexer->publishedCount();
        $actual = (int) ($stats['numberOfDocuments'] ?? -1);
        if ($actual !== $expected) {
            throw new \RuntimeException('Live document count mismatch: expected ' . $expected . ', got ' . $actual);
        }
        $this->assertSettingsEqual();

        if ($this->searchClient !== null && $expected > 0) {
            $document = $this->indexer->recentPublished(1)[0] ?? null;
            if ($document !== null) {
                $query = mb_substr((string) $document['title'], 0, 6, 'UTF-8');
                $result = $this->searchClient->search($this->liveIndex, $query, 10);
                $ids = array_map(static fn (array $hit): int => (int) ($hit['id'] ?? 0), $result['hits'] ?? []);
                if (!in_array((int) $document['id'], $ids, true)) {
                    throw new \RuntimeException('Live sample search did not return the expected document');
                }
            }
        }
    }

    private function assertSettingsEqual(): void
    {
        $live = $this->client->getSettings($this->liveIndex);
        $build = $this->client->getSettings($this->buildIndex);
        $this->normalize($live);
        $this->normalize($build);
        if ($live !== $build) {
            throw new \RuntimeException('Live and build settings differ');
        }
    }

    private function refreshBatchTasks(string $batchId, float $deadline): void
    {
        $rows = $this->store->tasksForBatch($batchId);
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (in_array($status, ['succeeded', 'failed', 'canceled', 'abandoned'], true)) {
                continue;
            }
            if ($row['task_uid'] === null) {
                $this->resolvePendingTask($row);
                continue;
            }
            $taskUid = (int) $row['task_uid'];
            try {
                $task = $this->taskClient->waitForTask($taskUid, $deadline);
                $this->store->finishTask($taskUid, (string) $task['status']);
            } catch (MeiliException $error) {
                try {
                    $task = $this->taskClient->getTask($taskUid);
                    $terminal = (string) ($task['status'] ?? '');
                    if (in_array($terminal, ['failed', 'canceled'], true)) {
                        $this->store->finishTask($taskUid, $terminal);
                    }
                } catch (\Throwable $ignored) {
                }
            }
        }
    }

    private function resolvePendingTask(array $row): void
    {
        $operation = (string) $row['operation'];
        $types = [
            'settings' => 'settingsUpdate',
            'swap' => 'indexSwap',
            'rollback' => 'indexSwap',
        ];
        $query = [
            'afterEnqueuedAt' => gmdate('c', strtotime((string) $row['submitted_at']) - 2),
            'limit' => 20,
        ];
        if (isset($types[$operation])) {
            $query['types'] = $types[$operation];
        }
        $indexUid = (string) $row['index_uid'];
        if (strpos($indexUid, '|') === false) {
            $query['indexUids'] = $indexUid;
        }
        $result = $this->taskClient->getTasks($query);
        $candidates = $result['results'] ?? [];
        if (count($candidates) !== 1 || !array_key_exists('uid', $candidates[0])) {
            return;
        }
        $this->store->attachTask((int) $row['id'], (int) $candidates[0]['uid']);
    }

    private function cancelAndDrainKnownTasks(string $batchId, float $deadline): void
    {
        $rows = $this->store->tasksForBatch($batchId);
        $open = [];
        foreach ($rows as $row) {
            if ($row['task_uid'] !== null && in_array($row['status'], ['enqueued', 'processing'], true)) {
                $open[] = (int) $row['task_uid'];
            }
        }
        if ($open) {
            try {
                $this->taskClient->cancelTasks($open);
            } catch (\Throwable $ignored) {
            }
        }
        foreach ($open as $taskUid) {
            try {
                $task = $this->taskClient->waitForTask($taskUid, $deadline);
                $this->store->finishTask($taskUid, (string) $task['status']);
            } catch (MeiliException $error) {
                try {
                    $task = $this->taskClient->getTask($taskUid);
                    $status = (string) ($task['status'] ?? '');
                    if (in_array($status, ['failed', 'canceled'], true)) {
                        $this->store->finishTask($taskUid, $status);
                    }
                } catch (\Throwable $ignored) {
                }
            }
        }
    }

    private function hasOpenTasks(string $batchId): bool
    {
        foreach ($this->store->tasksForBatch($batchId) as $row) {
            if (in_array($row['status'], ['pending', 'enqueued', 'processing'], true)) {
                return true;
            }
        }

        return false;
    }

    private function latestTask(array $rows, string $operation): ?array
    {
        $latest = null;
        foreach ($rows as $row) {
            if (($row['operation'] ?? '') === $operation) {
                $latest = $row;
            }
        }

        return $latest;
    }

    private function stripInternal(array $documents): array
    {
        foreach ($documents as &$document) {
            unset($document['_modified']);
        }

        return $documents;
    }

    private function taskUidFrom(\Throwable $error): ?int
    {
        return $error instanceof MeiliTaskTimeout ? $error->taskUid() : null;
    }

    private function normalize(array &$value): void
    {
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                $this->normalize($entry);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
    }

    private function log(string $message): void
    {
        fwrite(STDOUT, '[' . date('c') . '] ' . $message . PHP_EOL);
    }
}
