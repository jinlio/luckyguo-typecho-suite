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
 * @author suite
 * @version 1.0.0
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
    }

    public static function personalConfig(\Typecho\Widget\Helper\Form $form): void
    {
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
