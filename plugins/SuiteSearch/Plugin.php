<?php

namespace TypechoPlugin\SuiteSearch;

use Typecho\Plugin\PluginInterface;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Meilisearch-backed Typecho search with a parameterized LIKE fallback.
 *
 * @package SuiteSearch
 * @author luckyguo
 * @version 1.1.0
 * @link https://github.com/jinlio/luckyguo-typecho-suite
 */
final class Plugin implements PluginInterface
{
    public static function activate(): void
    {
        foreach ([
            'RuntimeConfig',
            'MeiliClient',
            'Indexer',
            'SearchService',
        ] as $file) {
            require_once __DIR__ . '/' . $file . '.php';
        }

        \Typecho\Plugin::factory('Widget\Archive')->search = __CLASS__ . '::search';
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishPublish = __CLASS__ . '::finishPublish';
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishSave = __CLASS__ . '::finishSave';
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishMark = __CLASS__ . '::finishMark';
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishDelete = __CLASS__ . '::finishDelete';
    }

    public static function deactivate(): void
    {
    }

    public static function config(\Typecho\Widget\Helper\Form $form): void
    {
        $form->addInput((new \Typecho\Widget\Helper\Form\Element\Checkbox(
            'enabled', ['1' => _t('启用 Meilisearch 搜索')], ['1'], _t('搜索引擎')
        ))->multiMode());
        $form->addInput((new \Typecho\Widget\Helper\Form\Element\Text(
            'meiliUrl', null, 'http://127.0.0.1:7700', _t('Meilisearch 地址（留空则使用 MySQL 搜索）')
        ))->addRule('url', _t('请输入有效的 HTTP(S) 地址')));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Password(
            'searchKey', null, '', _t('搜索 API Key（只读）')
        ));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Password(
            'writeKey', null, '', _t('写入 API Key（可选，用于发布后同步）')
        ));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Password(
            'rebuildKey', null, '', _t('重建 API Key（可选，用于完整重建）')
        ));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Password(
            'taskKey', null, '', _t('任务查询 API Key（留空使用重建 API Key）')
        ));
        $form->addInput((new \Typecho\Widget\Helper\Form\Element\Checkbox(
            'clearKeys', ['1' => _t('清除已保存的 API Key（谨慎操作）')], [], _t('密钥管理')
        ))->multiMode());
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'liveIndex', null, 'posts_live', _t('在线索引名称')
        ));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'buildIndex', null, 'posts_build', _t('构建索引名称')
        ));
        $form->addInput((new \Typecho\Widget\Helper\Form\Element\Text(
            'rebuildFenceTimeout', null, '30', _t('重建切换超时（秒，最少 5 秒）')
        ))->addRule('isInteger', _t('请输入整数')));
        $form->addInput((new \Typecho\Widget\Helper\Form\Element\Checkbox(
            'autoSync', ['1' => _t('文章发布、保存和删除后自动同步索引')], ['1'], _t('实时同步')
        ))->multiMode());
        $form->addInput((new \Typecho\Widget\Helper\Form\Element\Checkbox(
            'mysqlFallback', ['1' => _t('Meilisearch 不可用时使用 MySQL LIKE 搜索')], ['1'], _t('降级策略')
        ))->multiMode());
    }

    public static function personalConfig(\Typecho\Widget\Helper\Form $form): void
    {
    }

    /**
     * Password fields are intentionally blank in the form. Preserve saved
     * credentials when an administrator changes unrelated settings.
     */
    public static function configHandle(array $settings, bool $isInit): void
    {
        $current = \Widget\Options::alloc()->plugin('SuiteSearch');
        $secretFields = ['searchKey', 'writeKey', 'rebuildKey', 'taskKey'];
        $clear = is_array($settings['clearKeys'] ?? null)
            ? in_array('1', array_map('strval', $settings['clearKeys']), true)
            : (string) ($settings['clearKeys'] ?? '') === '1';
        unset($settings['clearKeys']);
        foreach ($secretFields as $field) {
            if ($clear) {
                $settings[$field] = '';
                continue;
            }
            if (array_key_exists($field, $settings)
                && trim((string) $settings[$field]) === ''
                && trim((string) ($current->$field ?? '')) !== '') {
                unset($settings[$field]);
            }
        }
        \Widget\Plugins\Edit::configPlugin('SuiteSearch', $settings);
    }

    public static function search(?string $keywords, \Widget\Archive $archive): void
    {
        SearchService::instance()->search((string) $keywords, $archive);
    }

    public static function finishPublish(array $contents, \Widget\Contents\Post\Edit $widget): void
    {
        self::sync((int) $widget->cid, 'upsert');
    }

    public static function finishSave(array $contents, \Widget\Contents\Post\Edit $widget): void
    {
        self::sync((int) $widget->cid, 'upsert');
    }

    public static function finishMark(string $status, int $cid): void
    {
        self::sync($cid, $status === 'publish' ? 'upsert' : 'delete');
    }

    public static function finishDelete(int $cid): void
    {
        self::sync($cid, 'delete');
    }

    private static function sync(int $cid, string $operation): void
    {
        try {
            SearchService::instance()->sync($cid, $operation);
        } catch (\Throwable $error) {
            error_log('[SuiteSearch] queue write failed: ' . $error->getMessage());
        }
    }
}
