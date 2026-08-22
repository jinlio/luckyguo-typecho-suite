<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

function themeConfig($form)
{
    $text = static function (string $name, string $label, string $placeholder = ''): object {
        return new \Typecho\Widget\Helper\Form\Element\Text($name, null, $placeholder, _t($label));
    };

    $form->addInput($text('siteName', '站点名称', '我的博客'));
    $form->addInput($text('authorName', '作者名称', '你的名字'));
    $form->addInput($text('authorHandle', '作者标识', 'username'));
    $form->addInput($text('tagline', '站点副标题', '记录正在发生的事'));
    $form->addInput($text('aboutLead', '关于页引导语', '介绍这个站点、你的工作或正在探索的方向。'));
    $form->addInput($text('aboutFocus', '关于页方向', '按你的实际方向填写'));
    $form->addInput($text('aboutStack', '关于页技术栈', '按你的实际技术栈填写'));
    $form->addInput($text('aboutStatus', '关于页状态', '持续学习与构建'));

    $bio = new \Typecho\Widget\Helper\Form\Element\Textarea(
        'bio',
        null,
        '介绍你自己、你的项目或这个站点。',
        _t('个人简介')
    );
    $form->addInput($bio);

    foreach ([
        ['avatarUrl', '头像地址', '留空显示中性主题标识'],
        ['bannerUrl', '首页横幅地址', '留空不显示横幅'],
        ['articleCoverUrl', '文章封面地址', '留空不显示文章封面'],
        ['landingUrl', '个人主页地址', 'https://example.com'],
        ['codeUrl', '代码仓库地址', 'https://github.com/username'],
    ] as [$name, $label, $placeholder]) {
        $input = $text($name, $label, $placeholder);
        $input->addRule('url', _t('请填写正确的网址'));
        $form->addInput($input);
    }

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

    $cookieName = $text('cookieName', '主题偏好 Cookie 名称', 'suite-theme');
    $cookieName->addRule('required', _t('请填写 Cookie 名称'));
    $form->addInput($cookieName);
    $form->addInput($text('cookieDomain', '主题偏好 Cookie 域名', '留空表示仅当前主机'));
    $form->addInput($text('siteStart', '站点开始运行时间', '2026-01-01 00:00:00'));
    $form->addInput($text('bannerAlt', '首页横幅替代文本', '站点首页横幅'));
    $form->addInput($text('articleCoverAlt', '文章封面替代文本', '文章封面'));
    $form->addInput(new \Typecho\Widget\Helper\Form\Element\Select(
        'counterBuckets',
        ['4' => '4', '8' => '8', '16' => '16', '32' => '32', '64' => '64'],
        '16',
        _t('统计写入分桶数')
    ));
    $form->addInput((new \Typecho\Widget\Helper\Form\Element\Checkbox(
        'enableStats',
        ['1' => _t('启用匿名访问统计（需要 suite_* 数据表）')],
        [],
        _t('访问统计')
    ))->multiMode());
}

function suite_option($options, string $name, string $fallback): string
{
    $value = $options->$name ?? '';
    if (is_array($value)) {
        $value = implode(',', array_map('strval', $value));
    }
    $value = trim((string) $value);
    return $value !== '' ? $value : $fallback;
}

function suite_asset($options, string $name, string $fallback = ''): string
{
    $value = suite_option($options, $name, '');
    return $value !== '' && preg_match('#^https?://#i', $value) ? $value : $fallback;
}

function suite_avatar_markup($options, string $className = ''): string
{
    $author = suite_option($options, 'authorName', '站点作者');
    $url = suite_asset($options, 'avatarUrl');
    $class = trim('suite-avatar ' . $className);
    if ($url !== '') {
        return '<img class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8')
            . '" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '" alt="' . htmlspecialchars($author, ENT_QUOTES, 'UTF-8') . '">';
    }

    return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8')
        . '" aria-label="' . htmlspecialchars($author, ENT_QUOTES, 'UTF-8')
        . '" title="' . htmlspecialchars($author, ENT_QUOTES, 'UTF-8') . '">TS</span>';
}

function suite_cookie_config($options): array
{
    $name = suite_option($options, 'cookieName', 'suite-theme');
    $name = preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name) ? $name : 'suite-theme';
    $domain = suite_option($options, 'cookieDomain', '');
    $domain = preg_match('/^\.?[A-Za-z0-9.-]+$/', $domain) ? $domain : '';
    return ['name' => $name, 'domain' => $domain];
}

function suite_statistics_enabled($options = null): bool
{
    $options = $options ?: \Widget\Options::alloc();
    $value = $options->enableStats ?? '';
    return is_array($value) ? in_array('1', $value, true) : (string) $value === '1';
}

function suite_table_name(string $name): string
{
    $prefix = suite_db()->getPrefix();
    if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new \RuntimeException('Unsafe database table name');
    }
    return '`' . $prefix . $name . '`';
}

function suite_accent_class($options): string
{
    $accent = suite_option($options, 'accent', 'rose');
    return in_array($accent, ['rose', 'coral', 'green'], true) ? 'accent-' . $accent : 'accent-rose';
}

function suite_db(): \Typecho\Db
{
    return \Typecho\Db::get();
}

function suite_is_bot(): bool
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

function suite_begin_tx(\Typecho\Db $db): array
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

function suite_commit_tx(array $context): void
{
    [$handle, $active] = $context;
    if ($active) {
        $handle->commit();
    }
}

function suite_rollback_tx(array $context): void
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

function suite_prepare_query(\Typecho\Db\Query $query): string
{
    return $query->prepare((string) $query);
}

function suite_execute_native($handle, string $sql): void
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

function suite_client_ip(): string
{
    $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return substr(trim($ip), 0, 64);
}

function suite_counter_bucket($options = null): int
{
    $options = $options ?: \Widget\Options::alloc();
    $buckets = (int) suite_option($options, 'counterBuckets', '16');
    $buckets = max(1, min(64, $buckets));
    return mt_rand(0, $buckets - 1);
}

function suite_record_visit($options = null): void
{
    $transaction = [null, false];
    try {
        if (!suite_statistics_enabled($options) || suite_is_bot()) {
            return;
        }
        $db = suite_db();
        $ip = suite_client_ip();
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $bucket = suite_counter_bucket($options);

        $transaction = suite_begin_tx($db);

        suite_execute_native(
            $transaction[0],
            'INSERT INTO ' . suite_table_name('suite_visits') . ' (vday, bucket, pv) VALUES (CURDATE(), '
            . $bucket . ', 1) '
            . 'ON DUPLICATE KEY UPDATE pv = pv + 1'
        );

        $visitor = $db->insert('table.suite_visitors')->rows([
            'vday'       => $today,
            'vip'        => $ip,
            'ua'         => $ua,
            'first_seen' => $now,
            'last_seen'  => $now,
        ]);
        suite_execute_native(
            $transaction[0],
            suite_prepare_query($visitor)
                . ' ON DUPLICATE KEY UPDATE ua = VALUES(ua), last_seen = VALUES(last_seen)'
        );

        suite_commit_tx($transaction);
    } catch (\Throwable $e) {
        suite_rollback_tx($transaction);
    }
}

function suite_record_view(int $cid, $options = null): void
{
    if ($cid <= 0 || !suite_statistics_enabled($options) || suite_is_bot()) {
        return;
    }
    try {
        $db = suite_db();
        $view = $db->insert('table.suite_views')->rows([
            'cid' => $cid,
            'bucket' => suite_counter_bucket($options),
            'views' => 1,
        ]);
        suite_execute_native(
            $db->selectDb(\Typecho\Db::WRITE),
            suite_prepare_query($view) . ' ON DUPLICATE KEY UPDATE views = views + 1'
        );
    } catch (\Throwable $e) {
    }
}

function suite_get_views(int $cid): int
{
    if ($cid <= 0 || !suite_statistics_enabled()) {
        return 0;
    }
    try {
        $row = suite_db()->fetchRow(
            suite_db()->select('SUM(views) AS views')->from('table.suite_views')->where('cid = ?', $cid)
        );
        return (int) ($row['views'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

function suite_get_views_batch(array $cids): array
{
    $cids = array_values(array_unique(array_filter(
        array_map('intval', $cids),
        static fn (int $cid): bool => $cid > 0
    )));
    $views = array_fill_keys($cids, 0);
    if (!suite_statistics_enabled()) {
        return $views;
    }
    if (!$cids) {
        return $views;
    }

    try {
        $db = suite_db();
        $rows = $db->fetchAll(
            $db->select('cid', 'SUM(views) AS views')
                ->from('table.suite_views')
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
function suite_list_excerpt(string $text, int $length = 150): string
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
function suite_get_post_metas_batch(array $cids, \Widget\Options $options): array
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
        $db = suite_db();
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

function suite_today_stats(): array
{
    if (!suite_statistics_enabled()) {
        return ['pv' => 0, 'uv' => 0];
    }
    try {
        $db = suite_db();
        $pvRow = $db->fetchRow(
            $db->select('SUM(pv) AS pv')->from('table.suite_visits')->where('vday = CURDATE()')
        );
        $uvRow = $db->fetchRow($db->select('COUNT(*) AS n')->from('table.suite_visitors')->where('vday = CURDATE()'));
        return ['pv' => (int) ($pvRow['pv'] ?? 0), 'uv' => (int) ($uvRow['n'] ?? 0)];
    } catch (\Throwable $e) {
        return ['pv' => 0, 'uv' => 0];
    }
}

function suite_total_visitors(): int
{
    if (!suite_statistics_enabled()) {
        return 0;
    }
    try {
        $db = suite_db();
        $row = $db->fetchRow($db->select('COUNT(*) AS n')->from('table.suite_visitors'));
        $archived = $db->fetchRow(
            $db->select('COALESCE(SUM(uv), 0) AS n')->from('table.suite_visitors_daily')
        );
        return (int) ($row['n'] ?? 0) + (int) ($archived['n'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

function suite_footer_stats($options = null): array
{
    static $stats;
    if (isset($stats)) {
        return $stats;
    }

    if (!suite_statistics_enabled($options)) {
        return $stats = ['uv' => 0, 'total' => 0];
    }
    try {
        $db = suite_db();
        $row = $db->fetchRow(
            $db->select(
                'COUNT(*) AS total',
                'COALESCE(SUM(vday = CURDATE()), 0) AS uv'
            )->from('table.suite_visitors')
        );
        $archived = $db->fetchRow(
            $db->select('COALESCE(SUM(uv), 0) AS n')->from('table.suite_visitors_daily')
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

function suite_uptime_text($options = null): string
{
    $start = strtotime(suite_option($options ?: \Widget\Options::alloc(), 'siteStart', ''));
    if (!$start) {
        return '未设置';
    }
    $diff = max(0, time() - $start);
    $days = intdiv($diff, 86400);
    $hours = intdiv($diff % 86400, 3600);
    $minutes = intdiv($diff % 3600, 60);
    return $days . ' 天 ' . $hours . ' 小时 ' . $minutes . ' 分';
}
