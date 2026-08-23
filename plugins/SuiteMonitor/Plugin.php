<?php

namespace TypechoPlugin\SuiteMonitor;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 站点监控面板 (服务器资源 / 站点可用性 / 流量 / 博客访客)
 * 数据由外部采集器写入配置的状态文件和监控数据库，本插件只读渲染
 *
 * @package SuiteMonitor
 * @author suite
 * @version 1.2.0
 * @link https://github.com/jinlio/luckyguo-typecho-suite
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        Helper::addPanel(3, 'SuiteMonitor/panel.php', '站点监控', '服务器与站点状态', 'administrator');
        return _t('站点监控面板已启用');
    }

    public static function deactivate()
    {
        Helper::removePanel(3, 'SuiteMonitor/panel.php');
        return _t('站点监控面板已卸载');
    }

    public static function config(Form $form)
    {
        $form->addInput(new Form\Element\Text(
            'statusFile', null, '/var/lib/typecho-suite/monitor/status.json', _t('状态 JSON 路径（采集器写入、面板读取）')
        ));
        $form->addInput(new Form\Element\Text(
            'envFile', null, '/etc/typecho-suite/monitor.env', _t('旧版监控环境文件路径（仅作兼容回退）')
        ));
        $form->addInput(new Form\Element\Text(
            'stateDir', null, '/var/lib/typecho-suite/monitor', _t('采集器状态目录')
        ));
        $form->addInput(new Form\Element\Text(
            'logFile', null, '/var/log/nginx/access.log', _t('Nginx 访问日志路径')
        ));
        $form->addInput(new Form\Element\Text(
            'monitorCnf', null, '/etc/typecho-suite/monitor-rw.cnf', _t('监控数据库写入凭据文件路径（旧版兼容）')
        ));
        $form->addInput(new Form\Element\Text(
            'monitorDb', null, 'monitor', _t('监控数据库名称')
        ));
        $form->addInput(new Form\Element\Text(
            'monitorDbHost', null, '127.0.0.1', _t('监控数据库主机')
        ));
        $form->addInput(new Form\Element\Text(
            'monitorDbPort', null, '3306', _t('监控数据库端口')
        ));
        $form->addInput(new Form\Element\Text(
            'monitorRwUser', null, '', _t('采集器写入数据库用户名（可选）')
        ));
        $form->addInput(new Form\Element\Password(
            'monitorRwPass', null, '', _t('采集器写入数据库密码（可选）')
        ));
        $form->addInput(new Form\Element\Text(
            'databaseDsn', null, 'mysql:host=127.0.0.1;dbname=monitor;charset=utf8mb4', _t('监控数据库 DSN')
        ));
        $form->addInput(new Form\Element\Text(
            'monitorRoUser', null, '', _t('监控面板只读数据库用户名（可选）')
        ));
        $form->addInput(new Form\Element\Password(
            'monitorRoPass', null, '', _t('监控面板只读数据库密码（可选）')
        ));
        $form->addInput(new Form\Element\Text(
            'typechoDatabase', null, 'typecho', _t('Typecho 数据库名（统计关联）')
        ));
        $form->addInput(new Form\Element\Text(
            'typechoPrefix', null, 'typecho_', _t('Typecho 表前缀（统计关联）')
        ));
        $form->addInput(new Form\Element\Text(
            'cpuCores', null, '1', _t('CPU 核数')
        ));
        $form->addInput(new Form\Element\Text(
            'serviceUnits', null, 'nginx php-fpm mysqld', _t('需要监测的 systemd 服务（空格分隔）')
        ));
        $form->addInput(new Form\Element\Text(
            'siteTargets', null, '', _t('站点探测目标（空格分隔，例如 blog=blog.example.com:80）')
        ));
        $form->addInput(new Form\Element\Text(
            'statusOwner', null, '', _t('状态文件 owner（可选）')
        ));
        $form->addInput(new Form\Element\Text(
            'statusGroup', null, '', _t('状态文件 group（可选）')
        ));
        $form->addInput(new Form\Element\Text(
            'statusMode', null, '0640', _t('状态文件权限（例如 0640）')
        ));
        $form->addInput(new Form\Element\Text(
            'rawRetentionDays', null, '45', _t('原始监控数据保留天数')
        ));
        $form->addInput(new Form\Element\Text(
            'rollupRetentionDays', null, '400', _t('汇总监控数据保留天数')
        ));
        $form->addInput(new Form\Element\Textarea(
            'siteLabels', null, '{"blog":"主站","docs":"文档"}', _t('监测目标显示名称 JSON（可选）')
        ));
        $form->addInput(new Form\Element\Textarea(
            'siteUrls', null, '{"blog":"https://blog.example.com","docs":"https://docs.example.com"}', _t('监测目标链接 JSON（可选）')
        ));
        $form->addInput(new Form\Element\Textarea(
            'serviceLabels', null, '{"nginx":"Nginx","php-fpm":"PHP-FPM","mysqld":"MySQL"}', _t('服务显示名称 JSON（可选）')
        ));
        $form->addInput(new Form\Element\Text(
            'cookieName', null, 'suite-theme', _t('主题偏好 Cookie 名称')
        ));
        $form->addInput(new Form\Element\Text(
            'cookieDomain', null, '留空表示仅当前主机', _t('主题偏好 Cookie 域名')
        ));
        $form->addInput((new Form\Element\Checkbox(
            'enableStats',
            ['1' => _t('显示 Typecho 匿名访问统计（需要 suite_* 数据表）')],
            [],
            _t('博客访问统计')
        ))->multiMode());
    }

    public static function personalConfig(Form $form)
    {
    }
}
