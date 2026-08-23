<?php

namespace TypechoPlugin\SuiteAdmin;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 后台换肤: 通过 admin/header.php 过滤器注入覆盖样式与主题切换脚本
 * 主题遵循前台 cookie suite-theme (light/dark)
 *
 * @package SuiteAdmin
 * @author luckyguo
 * @version 1.3.0
 * @link https://github.com/jinlio/luckyguo-typecho-suite
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        \Typecho\Plugin::factory('admin/header.php')->header = __CLASS__ . '::header';
        return _t('后台换肤已启用');
    }

    public static function deactivate()
    {
        return _t('后台换肤已禁用');
    }

    public static function header(string $header): string
    {
        $options = \Widget\Options::alloc();
        $settings = $options->plugin('SuiteAdmin');
        $cookieName = trim((string) ($settings->cookieName ?? 'suite-theme'));
        $cookieName = preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $cookieName) ? $cookieName : 'suite-theme';
        $cookieDomain = trim((string) ($settings->cookieDomain ?? ''));
        $cookieDomain = preg_match('/^\.?[A-Za-z0-9.-]+$/', $cookieDomain) ? $cookieDomain : '';
        $defaultTheme = trim((string) ($settings->defaultTheme ?? 'system'));
        $defaultTheme = in_array($defaultTheme, ['system', 'light', 'dark'], true) ? $defaultTheme : 'system';
        // Typecho 1.3's pluginUrl() echoes instead of returning the URL.
        ob_start();
        $options->pluginUrl('SuiteAdmin');
        $base = rtrim((string) ob_get_clean(), '/') . '/';
        $config = json_encode(['name' => $cookieName, 'domain' => $cookieDomain, 'defaultTheme' => $defaultTheme], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        return $header
            . "\n" . '<script>window.SuiteAdminThemeConfig=' . $config . ';(function(){var c=window.SuiteAdminThemeConfig,m=document.cookie.match(new RegExp("(?:^|;\\\\s*)"+c.name+"=(dark|light)(?:;|$)")),saved="";try{saved=localStorage.getItem(c.name)||"";}catch(e){}var t=m?m[1]:saved||((matchMedia("(prefers-color-scheme:dark)").matches)?"dark":"light");if(!m&&!saved&&(c.defaultTheme==="dark"||c.defaultTheme==="light"))t=c.defaultTheme;document.documentElement.dataset.theme=t;})();</script>'
            . "\n" . '<link rel="stylesheet" href="' . $base . 'admin.css?v=1.3.9">'
            . "\n" . '<script src="' . $base . 'admin.js?v=1.3.9" defer></script>';
    }

    public static function config(Form $form)
    {
        $form->addInput((new Form\Element\Text(
            'cookieName', null, 'suite-theme', _t('主题偏好 Cookie 名称')
        ))->addRule('required', _t('请填写 Cookie 名称')));
        $form->addInput(new Form\Element\Text(
            'cookieDomain', null, '留空表示仅当前主机', _t('主题偏好 Cookie 域名')
        ));
        $form->addInput(new Form\Element\Select(
            'defaultTheme',
            ['system' => _t('跟随系统'), 'light' => _t('默认浅色'), 'dark' => _t('默认深色')],
            'system',
            _t('后台默认主题模式')
        ));
    }

    public static function personalConfig(Form $form)
    {
    }
}
