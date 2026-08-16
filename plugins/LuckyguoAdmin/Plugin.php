<?php

namespace TypechoPlugin\LuckyguoAdmin;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 后台换肤: 通过 admin/header.php 过滤器注入覆盖样式与主题切换脚本
 * 主题遵循前台 cookie luckyguo-theme (light/dark)
 *
 * @package LuckyguoAdmin
 * @author luckyguo
 * @version 1.2.0
 * @link https://blog.luckyguo.dpdns.org
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
        $base = '/usr/plugins/LuckyguoAdmin/';
        return $header
            . "\n" . '<script>(function(){var m=document.cookie.match(/(?:^|;\\s*)luckyguo-theme=(dark|light)(?:;|$)/),t=m?m[1]:localStorage.getItem("luckyguo-theme")||((matchMedia("(prefers-color-scheme:dark)").matches)?"dark":"light");document.documentElement.dataset.theme=t;})();</script>'
            . "\n" . '<link rel="stylesheet" href="' . $base . 'admin.css?v=1.3.6">'
            . "\n" . '<script src="' . $base . 'admin.js?v=1.3.6" defer></script>';
    }

    public static function config(Form $form)
    {
    }

    public static function personalConfig(Form $form)
    {
    }
}
