<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

function themeConfig($form)
{
    $bio = new \Typecho\Widget\Helper\Form\Element\Textarea(
        'bio',
        null,
        '记录学习、折腾和那些值得留住的细节。',
        _t('个人简介')
    );
    $form->addInput($bio);

    $accent = new \Typecho\Widget\Helper\Form\Element\Select(
        'accent',
        [
            'rose' => _t('淡粉'),
            'coral' => _t('珊瑚红'),
            'green' => _t('青绿色')
        ],
        'rose',
        _t('主题强调色')
    );
    $form->addInput($accent);

    $landingUrl = new \Typecho\Widget\Helper\Form\Element\Text(
        'landingUrl',
        null,
        'https://luckyguo.dpdns.org',
        _t('个人主页地址')
    );
    $form->addInput($landingUrl->addRule('url', _t('请填写正确的网址')));

    $giteaUrl = new \Typecho\Widget\Helper\Form\Element\Text(
        'giteaUrl',
        null,
        'https://git.luckyguo.dpdns.org',
        _t('Gitea 地址')
    );
    $form->addInput($giteaUrl->addRule('url', _t('请填写正确的网址')));
}

function luckyguo_option($options, string $name, string $fallback): string
{
    $value = trim((string) ($options->$name ?? ''));
    return $value !== '' ? $value : $fallback;
}

function luckyguo_accent_class($options): string
{
    $accent = luckyguo_option($options, 'accent', 'rose');
    return in_array($accent, ['rose', 'coral', 'green'], true) ? 'accent-' . $accent : 'accent-rose';
}

define('LUCKYGUO_SITE_START', '2026-08-10 08:00:00');
define('LUCKYGUO_COUNTER_BUCKETS', 16);

function luckyguo_db(): \Typecho\Db
{
    return \Typecho\Db::get();
}

function luckyguo_is_bot(): bool
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') {
        return false;
    }
    $keywords = [
        'bot', 'crawler', 'spider', 'slurp', 'scan', 'python', 'curl', 'wget', 'httpclient',
        'headless', 'semrush', 'ahrefs', 'mj12', 'petal', 'bytespider', 'gptbot', 'claudebot',
        'ccbot', 'chatgpt', 'facebookexternalhit', 'telegrambot', 'twitterbot', 'whatsapp',
        'monitor', 'uptimerobot', 'pingdom', 'zabbix', 'nagios'
    ];
    $uaLower = strtolower($ua);
    foreach ($keywords as $kw) {
        if (strpos($uaLower, $kw) !== false) {
            return true;
        }
    }
    return false;
}

function luckyguo_begin_tx(\Typecho\Db $db): array
{
    $handle = $db->selectDb(\Typecho\Db::WRITE);
    if ($handle instanceof \PDO) {
        $handle->beginTransaction();
    } elseif ($handle instanceof \mysqli) {
        $handle->begin_transaction();
    } else {
        throw new \RuntimeException('Unsupported database handle');
    }

    return [$handle, true];
}

function luckyguo_commit_tx(array $context): void
{
    [$handle, $active] = $context;
    if ($active) {
        $handle->commit();
    }
}

function luckyguo_rollback_tx(array $context): void
{
    [$handle, $active] = $context;
    if (!$active) {
        return;
    }

    try {
        if ($handle instanceof \PDO) {
            if ($handle->inTransaction()) {
                $handle->rollBack();
            }
        } elseif ($handle instanceof \mysqli) {
            $handle->rollback();
        }
    } catch (\Throwable $e) {
    }
}

function luckyguo_prepare_query(\Typecho\Db\Query $query): string
{
    return $query->prepare((string) $query);
}

function luckyguo_execute_native($handle, string $sql): void
{
    if ($handle instanceof \PDO) {
        if ($handle->exec($sql) === false) {
            throw new \RuntimeException('Database write failed');
        }
    } elseif ($handle instanceof \mysqli) {
        if ($handle->query($sql) === false) {
            throw new \RuntimeException($handle->error);
        }
    } else {
        throw new \RuntimeException('Unsupported database handle');
    }
}

function luckyguo_client_ip(): string
{
    $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return substr(trim($ip), 0, 64);
}

function luckyguo_counter_bucket(): int
{
    return mt_rand(0, LUCKYGUO_COUNTER_BUCKETS - 1);
}

function luckyguo_record_visit(): void
{
    $transaction = [null, false];
    try {
        if (luckyguo_is_bot()) {
            return;
        }
        $db = luckyguo_db();
        $ip = luckyguo_client_ip();
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $bucket = luckyguo_counter_bucket();

        $transaction = luckyguo_begin_tx($db);

        luckyguo_execute_native(
            $transaction[0],
            'INSERT INTO typecho_luckyguo_visits (vday, bucket, pv) VALUES (CURDATE(), '
            . $bucket . ', 1) '
            . 'ON DUPLICATE KEY UPDATE pv = pv + 1'
        );

        $visitor = $db->insert('table.luckyguo_visitors')->rows([
            'vday'       => $today,
            'vip'        => $ip,
            'ua'         => $ua,
            'first_seen' => $now,
            'last_seen'  => $now,
        ]);
        luckyguo_execute_native(
            $transaction[0],
            luckyguo_prepare_query($visitor)
                . ' ON DUPLICATE KEY UPDATE ua = VALUES(ua), last_seen = VALUES(last_seen)'
        );

        luckyguo_commit_tx($transaction);
    } catch (\Throwable $e) {
        luckyguo_rollback_tx($transaction);
    }
}

function luckyguo_record_view(int $cid): void
{
    if ($cid <= 0 || luckyguo_is_bot()) {
        return;
    }
    try {
        $db = luckyguo_db();
        $view = $db->insert('table.luckyguo_views')->rows([
            'cid' => $cid,
            'bucket' => luckyguo_counter_bucket(),
            'views' => 1,
        ]);
        luckyguo_execute_native(
            $db->selectDb(\Typecho\Db::WRITE),
            luckyguo_prepare_query($view) . ' ON DUPLICATE KEY UPDATE views = views + 1'
        );
    } catch (\Throwable $e) {
    }
}

function luckyguo_get_views(int $cid): int
{
    if ($cid <= 0) {
        return 0;
    }
    try {
        $row = luckyguo_db()->fetchRow(
            luckyguo_db()->select('SUM(views) AS views')->from('table.luckyguo_views')->where('cid = ?', $cid)
        );
        return (int) ($row['views'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

function luckyguo_get_views_batch(array $cids): array
{
    $cids = array_values(array_unique(array_filter(
        array_map('intval', $cids),
        static fn (int $cid): bool => $cid > 0
    )));
    $views = array_fill_keys($cids, 0);
    if (!$cids) {
        return $views;
    }

    try {
        $db = luckyguo_db();
        $rows = $db->fetchAll(
            $db->select('cid', 'SUM(views) AS views')
                ->from('table.luckyguo_views')
                ->where('cid IN ?', $cids)
                ->group('cid')
        );
        foreach ($rows as $row) {
            $cid = (int) ($row['cid'] ?? 0);
            if (isset($views[$cid])) {
                $views[$cid] = (int) ($row['views'] ?? 0);
            }
        }
    } catch (\Throwable $e) {
    }

    return $views;
}

/**
 * Build a lightweight list summary without parsing every full Markdown document.
 */
function luckyguo_list_excerpt(string $text, int $length = 150): string
{
    $text = preg_replace('/^<!--markdown-->\s*/u', '', $text) ?? $text;
    $text = explode('<!--more-->', $text, 2)[0];
    $text = preg_replace('/```[\s\S]*?```/u', ' ', $text) ?? $text;
    $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;
    $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $text) ?? $text;
    $text = strip_tags($text);
    $text = preg_replace('/^[\s>*#+-]+/mu', '', $text) ?? $text;
    $text = preg_replace('/[`*_~]/u', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return \Typecho\Common::subStr(trim($text), 0, $length, '…');
}

/**
 * Fetch category and tag links for a post list in one relationship query.
 */
function luckyguo_get_post_metas_batch(array $cids, \Widget\Options $options): array
{
    $cids = array_values(array_unique(array_filter(
        array_map('intval', $cids),
        static fn (int $cid): bool => $cid > 0
    )));
    $metas = array_fill_keys($cids, ['categories' => [], 'tags' => []]);
    if (!$cids) {
        return $metas;
    }

    try {
        $db = luckyguo_db();
        $rows = $db->fetchAll(
            $db->select(
                'table.relationships.cid',
                'table.metas.mid',
                'table.metas.name',
                'table.metas.slug',
                'table.metas.type'
            )
                ->from('table.relationships')
                ->join('table.metas', 'table.relationships.mid = table.metas.mid')
                ->where('table.relationships.cid IN ?', $cids)
                ->where('table.metas.type IN ?', ['category', 'tag'])
                ->order('table.relationships.cid')
        );
        foreach ($rows as $row) {
            $cid = (int) ($row['cid'] ?? 0);
            $type = (string) ($row['type'] ?? '');
            if (!isset($metas[$cid]) || !in_array($type, ['category', 'tag'], true)) {
                continue;
            }
            $metas[$cid][$type === 'category' ? 'categories' : 'tags'][] = [
                'name' => (string) ($row['name'] ?? ''),
                'url' => \Typecho\Router::url($type, $row, $options->index),
            ];
        }
    } catch (\Throwable $e) {
        // List pages remain usable if optional metadata cannot be loaded.
    }

    return $metas;
}

function luckyguo_today_stats(): array
{
    try {
        $db = luckyguo_db();
        $pvRow = $db->fetchRow(
            $db->select('SUM(pv) AS pv')->from('table.luckyguo_visits')->where('vday = CURDATE()')
        );
        $uvRow = $db->fetchRow($db->select('COUNT(*) AS n')->from('table.luckyguo_visitors')->where('vday = CURDATE()'));
        return ['pv' => (int) ($pvRow['pv'] ?? 0), 'uv' => (int) ($uvRow['n'] ?? 0)];
    } catch (\Throwable $e) {
        return ['pv' => 0, 'uv' => 0];
    }
}

function luckyguo_total_visitors(): int
{
    try {
        $db = luckyguo_db();
        $row = $db->fetchRow($db->select('COUNT(*) AS n')->from('table.luckyguo_visitors'));
        $archived = $db->fetchRow(
            $db->select('COALESCE(SUM(uv), 0) AS n')->from('table.luckyguo_visitors_daily')
        );
        return (int) ($row['n'] ?? 0) + (int) ($archived['n'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

function luckyguo_footer_stats(): array
{
    static $stats;
    if (isset($stats)) {
        return $stats;
    }

    try {
        $db = luckyguo_db();
        $row = $db->fetchRow(
            $db->select(
                'COUNT(*) AS total',
                'COALESCE(SUM(vday = CURDATE()), 0) AS uv'
            )->from('table.luckyguo_visitors')
        );
        $archived = $db->fetchRow(
            $db->select('COALESCE(SUM(uv), 0) AS n')->from('table.luckyguo_visitors_daily')
        );
        $stats = [
            'uv' => (int) ($row['uv'] ?? 0),
            'total' => (int) ($row['total'] ?? 0) + (int) ($archived['n'] ?? 0),
        ];
    } catch (\Throwable $e) {
        $stats = ['uv' => 0, 'total' => 0];
    }

    return $stats;
}

function luckyguo_uptime_text(): string
{
    $diff = max(0, time() - strtotime(LUCKYGUO_SITE_START));
    $days = intdiv($diff, 86400);
    $hours = intdiv($diff % 86400, 3600);
    $minutes = intdiv($diff % 3600, 60);
    return $days . ' 天 ' . $hours . ' 小时 ' . $minutes . ' 分';
}
