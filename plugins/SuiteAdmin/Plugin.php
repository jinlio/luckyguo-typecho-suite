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
        // Content hooks moved to SuiteContent. Keep the legacy callbacks for
        // one compatibility cycle, but do not register them when the
        // dedicated content plugin is active (which would duplicate writes
        // and ORDER BY clauses).
        if (!self::suiteContentActive()) {
            \Typecho\Plugin::factory('admin/write-post.php')->option = __CLASS__ . '::postOption';
            \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->write = __CLASS__ . '::postWrite';
            \Typecho\Plugin::factory('Widget\Archive')->handleInit = __CLASS__ . '::archiveInit';
        }
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
        $categoryParents = [];
        try {
            $rows = \Typecho\Db::get()->fetchAll(
                \Typecho\Db::get()->select('mid', 'parent')
                    ->from('table.metas')
                    ->where('type = ?', 'category')
            );
            foreach ($rows as $row) {
                $categoryParents[(string) $row['mid']] = (int) ($row['parent'] ?? 0);
            }
        } catch (\Throwable $e) {
            $categoryParents = [];
        }
        $config = json_encode([
            'name' => $cookieName,
            'domain' => $cookieDomain,
            'defaultTheme' => $defaultTheme,
            'categoryParents' => $categoryParents,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        return $header
            . "\n" . '<script>window.SuiteAdminThemeConfig=' . $config . ';(function(){var c=window.SuiteAdminThemeConfig,m=document.cookie.match(new RegExp("(?:^|;\\\\s*)"+c.name+"=(dark|light)(?:;|$)")),saved="";try{saved=localStorage.getItem(c.name)||"";}catch(e){}var t=m?m[1]:saved||((matchMedia("(prefers-color-scheme:dark)").matches)?"dark":"light");if(!m&&!saved&&(c.defaultTheme==="dark"||c.defaultTheme==="light"))t=c.defaultTheme;document.documentElement.dataset.theme=t;})();</script>'
            . "\n" . '<link rel="stylesheet" href="' . $base . 'admin.css?v=1.4.0">'
            . "\n" . '<script src="' . $base . 'admin.js?v=1.4.0" defer></script>';
    }

    public static function config(Form $form)
    {
        $form->addInput((new Form\Element\Text(
            'cookieName', null, 'suite-theme', _t('主题偏好 Cookie 名称')
        ))->addRule('required', _t('请填写 Cookie 名称')));
        $cookieDomain = new Form\Element\Text(
            'cookieDomain', null, '', _t('主题偏好 Cookie 域名')
        );
        $cookieDomain->input->setAttribute('placeholder', '留空表示仅当前主机');
        $form->addInput($cookieDomain);
        $form->addInput(new Form\Element\Select(
            'defaultTheme',
            ['system' => _t('跟随系统'), 'light' => _t('默认浅色'), 'dark' => _t('默认深色')],
            'system',
            _t('后台默认主题模式')
        ));
    }

    /** Add the post-level sticky switch to Typecho's native editor. */
    public static function postOption($post): void
    {
        if (self::suiteContentActive()) {
            return;
        }
        $sticky = '0';
        try {
            $sticky = (string) ($post->fields->sticky ?? '0');
        } catch (\Throwable $e) {
            // A new post may not have a fields object yet.
        }
        echo '<section class="typecho-post-option suite-sticky-option">'
            . '<label class="typecho-label" for="suite-sticky">' . _t('文章排序') . '</label>'
            . '<p><label><input type="checkbox" id="suite-sticky" name="fields[sticky]" value="1"'
            . ($sticky === '1' ? ' checked="checked"' : '') . '> ' . _t('置顶文章') . '</label></p>'
            . '<p class="description">' . _t('置顶文章会排在首页文章列表最前面。') . '</p>'
            . '</section>';
    }

    /** Persist the checkbox as Typecho's native numeric ordering value. */
    public static function postWrite(array $contents, \Widget\Contents\Post\Edit $widget): array
    {
        if (self::suiteContentActive()) {
            return $contents;
        }
        $fields = $widget->request->getArray('fields');
        $contents['order'] = (string) ($fields['sticky'] ?? '') === '1' ? 1 : 0;
        return $contents;
    }

    /** Put sticky posts before regular posts while retaining date ordering within each group. */
    public static function archiveInit(\Widget\Archive $archive, \Typecho\Db\Query $select): void
    {
        if (self::suiteContentActive()) {
            return;
        }
        $select->order('table.contents.order', \Typecho\Db::SORT_DESC);
    }

    /** Return true when the dedicated content owner is enabled. */
    private static function suiteContentActive(): bool
    {
        try {
            $active = \Typecho\Plugin::export()['activated'] ?? [];
            return isset($active['SuiteContent']);
        } catch (\Throwable $e) {
            return class_exists('TypechoPlugin\\SuiteContent\\Plugin');
        }
    }

    public static function personalConfig(Form $form)
    {
    }
}
