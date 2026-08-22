<?php

namespace TypechoPlugin\Sitemap;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Generate a sitemap for public Typecho content.
 *
 * Based on joyqi/typecho-plugin-sitemap v1.0.0 (MIT).
 *
 * @package Sitemap
 * @author joyqi, suite
 * @version 1.1.0
 * @since 1.2.1
 * @link https://github.com/joyqi/typecho-plugin-sitemap
 */
final class Plugin implements PluginInterface
{
    public static function activate(): void
    {
        Helper::addRoute(
            'sitemap',
            '/sitemap.xml',
            Generator::class,
            'generate',
            'index'
        );
    }

    public static function deactivate(): void
    {
        Helper::removeRoute('sitemap');
    }

    public static function config(Form $form): void
    {
        $sitemapBlock = new Form\Element\Checkbox(
            'sitemapBlock',
            [
                'posts' => _t('生成文章链接'),
                'pages' => _t('生成独立页面链接'),
                'categories' => _t('生成分类链接'),
                'tags' => _t('生成标签链接'),
            ],
            ['posts', 'pages', 'categories', 'tags'],
            _t('站点地图显示')
        );

        $updateFreq = new Form\Element\Select(
            'updateFreq',
            [
                'daily' => _t('每天'),
                'weekly' => _t('每周'),
                'monthly' => _t('每月或更久'),
            ],
            'daily',
            _t('更新频率')
        );

        $form->addInput($sitemapBlock->multiMode());
        $form->addInput($updateFreq);
    }

    public static function personalConfig(Form $form): void
    {
    }
}
