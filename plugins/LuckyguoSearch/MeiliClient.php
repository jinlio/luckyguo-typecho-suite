<?php

namespace TypechoPlugin\LuckyguoSearch;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class MeiliException extends \RuntimeException
{
    private int $statusCode;
    private ?string $errorCode;

    public function __construct(string $message, int $statusCode = 0, ?string $errorCode = null)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }
}

final class MeiliTaskTimeout extends MeiliException
{
    private int $taskUid;

    public function __construct(int $taskUid)
    {
        parent::__construct('Meilisearch task did not reach a terminal state: ' . $taskUid);
        $this->taskUid = $taskUid;
    }

    public function taskUid(): int
    {
        return $this->taskUid;
    }
}

final class MeiliClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $connectTimeoutMs;
    private int $timeoutMs;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        int $connectTimeoutMs = 300,
        int $timeoutMs = 800
    ) {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The PHP curl extension is required');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->connectTimeoutMs = $connectTimeoutMs;
        $this->timeoutMs = $timeoutMs;
    }

    public function search(string $index, string $query, int $limit = 20, int $offset = 0): array
    {
        return $this->request('POST', '/indexes/' . rawurlencode($index) . '/search', [
            'q' => $query,
            'limit' => $limit,
            'offset' => max(0, $offset),
            'attributesToHighlight' => [],
        ]);
    }

    public function addDocuments(string $index, array $documents): int
    {
        return $this->taskUid($this->request(
            'POST',
            '/indexes/' . rawurlencode($index) . '/documents',
            array_values($documents),
            30000
        ));
    }

    public function deleteDocuments(string $index, array $ids): int
    {
        return $this->taskUid($this->request(
            'POST',
            '/indexes/' . rawurlencode($index) . '/documents/delete-batch',
            array_values(array_map('intval', $ids)),
            30000
        ));
    }

    public function deleteAllDocuments(string $index): int
    {
        return $this->taskUid($this->request(
            'DELETE',
            '/indexes/' . rawurlencode($index) . '/documents',
            null,
            30000
        ));
    }

    public function updateSettings(string $index, array $settings): int
    {
        return $this->taskUid($this->request(
            'PATCH',
            '/indexes/' . rawurlencode($index) . '/settings',
            $settings,
            30000
        ));
    }

    public function getSettings(string $index): array
    {
        return $this->request('GET', '/indexes/' . rawurlencode($index) . '/settings');
    }

    public function getStats(string $index): array
    {
        return $this->request('GET', '/indexes/' . rawurlencode($index) . '/stats');
    }

    public function getTask(int $taskUid): array
    {
        return $this->request('GET', '/tasks/' . $taskUid);
    }

    public function getTasks(array $query): array
    {
        return $this->request('GET', '/tasks?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function cancelTasks(array $taskUids): int
    {
        if (!$taskUids) {
            throw new \InvalidArgumentException('At least one task UID is required');
        }

        $query = http_build_query(['uids' => implode(',', array_map('intval', $taskUids))]);
        return $this->taskUid($this->request('POST', '/tasks/cancel?' . $query, null, 30000));
    }

    public function swapIndexes(string $first, string $second): int
    {
        return $this->taskUid($this->request('POST', '/swap-indexes', [[
            'indexes' => [$first, $second],
        ]], 30000));
    }

    public function waitForTask(int $taskUid, float $deadline): array
    {
        do {
            $task = $this->getTask($taskUid);
            $status = (string) ($task['status'] ?? 'unknown');
            if ($status === 'succeeded') {
                return $task;
            }
            if ($status === 'failed' || $status === 'canceled') {
                $message = (string) ($task['error']['message'] ?? ('Task ' . $status));
                throw new MeiliException($message, 0, (string) ($task['error']['code'] ?? ''));
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new MeiliTaskTimeout($taskUid);
    }

    public function request(
        string $method,
        string $path,
        ?array $body = null,
        ?int $timeoutMs = null
    ): array {
        $curl = curl_init($this->baseUrl . $path);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize curl');
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs ?? $this->timeoutMs,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $options[CURLOPT_POSTFIELDS] = $payload;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($response === false) {
            throw new MeiliException('Meilisearch request failed: ' . $error);
        }

        $decoded = json_decode((string) $response, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $response) : (string) $response;
            $code = is_array($decoded) ? (string) ($decoded['code'] ?? '') : null;
            throw new MeiliException($message, $status, $code);
        }
        if (!is_array($decoded)) {
            throw new MeiliException('Meilisearch returned invalid JSON', $status);
        }

        return $decoded;
    }

    private function taskUid(array $response): int
    {
        if (!array_key_exists('taskUid', $response)) {
            throw new MeiliException('Meilisearch response did not include taskUid');
        }

        return (int) $response['taskUid'];
    }
}
