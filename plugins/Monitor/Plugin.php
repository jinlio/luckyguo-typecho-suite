<?php

namespace TypechoPlugin\Monitor;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 站点监控面板 (服务器资源 / 站点可用性 / 流量 / 博客访客)
 * 数据由 root cron 采集器写入 /var/lib/monitor/status.json 与 luckyguo_monitor 库, 本插件只读渲染
 *
 * @package Monitor
 * @author luckyguo
 * @version 1.2.0
 * @link https://blog.luckyguo.dpdns.org
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        Helper::addPanel(3, 'Monitor/panel.php', '站点监控', '服务器与站点状态', 'administrator');
        return _t('站点监控面板已启用');
    }

    public static function deactivate()
    {
        Helper::removePanel(3, 'Monitor/panel.php');
        return _t('站点监控面板已卸载');
    }

    public static function config(Form $form)
    {
    }

    public static function personalConfig(Form $form)
    {
    }
}
