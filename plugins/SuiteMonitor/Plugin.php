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
 * @author luckyguo
 * @version 1.3.0
 * @link https://github.com/jinlio/luckyguo-typecho-suite
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        // Remove panel registrations from older private releases before adding
        // the public namespace. Typecho persists panel registrations in options.
        foreach (['SuiteMonitor/panel.php', 'Monitor/panel.php', 'LuckyguoMonitor/panel.php', 'LuckyguoStats/panel.php'] as $legacyPanel) {
            Helper::removePanel(3, $legacyPanel);
        }
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
            'brandName', null, '', _t('监控品牌名称（你的站点名称；留空继承主题站点名称）')
        ));
        $form->addInput(new Form\Element\Text(
            'brandHandle', null, '', _t('监控品牌标识（你的昵称或用户名；留空继承主题作者标识）')
        ));
        $brandAvatar = new Form\Element\Text(
            'brandAvatarUrl', null, '', _t('监控品牌头像地址（你的头像 HTTP(S) 地址；留空继承主题头像）')
        );
        $brandAvatar->addRule('url', _t('请输入有效的 HTTP(S) 地址'));
        $form->addInput($brandAvatar);
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
        $form->addInput((new Form\Element\Checkbox(
            'clearPasswords', ['1' => _t('清除已保存的数据库密码（谨慎操作）')], [], _t('密码管理')
        ))->multiMode());
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
            'siteLabels', null, "blog=主站\ndocs=文档", _t('监测目标显示名称（每行填写 key=名称，可选）')
        ));
        $form->addInput(new Form\Element\Textarea(
            'siteUrls', null, "blog=https://blog.example.com\ndocs=https://docs.example.com", _t('监测目标链接（每行填写 key=网址，可选）')
        ));
        $form->addInput(new Form\Element\Textarea(
            'navItems', null, "console=控制台|admin\nhome=首页|site\nlanding=落地页|landing", _t('监控顶部导航（默认：控制台、首页、落地页；每行填写 key=名称|目标，可自定义）')
        ));
        $footerRepo = new Form\Element\Text(
            'footerRepoUrl', null, '', _t('页脚代码仓库地址（留空隐藏）')
        );
        $footerRepo->addRule('url', _t('请输入有效的 HTTP(S) 地址'));
        $form->addInput($footerRepo);
        $form->addInput(new Form\Element\Text(
            'footerRepoLabel', null, '代码仓库', _t('页脚代码仓库名称')
        ));
        $form->addInput((new Form\Element\Checkbox(
            'showFooterRepo', ['1' => _t('在页脚显示代码仓库链接')], [], _t('页脚链接开关')
        ))->multiMode());
        $form->addInput(new Form\Element\Textarea(
            'logSources', null, "nginx=/var/log/nginx/error.log\nphp-fpm=/var/log/php-fpm/error.log", _t('异常日志文件来源（每行填写 source=绝对路径，可选；由采集器增量读取）')
        ));
        $form->addInput(new Form\Element\Text(
            'logJournalUnits', null, 'sshd nginx php-fpm mysqld', _t('异常日志 journald 服务（空格分隔，可选）')
        ));
        $form->addInput(new Form\Element\Textarea(
            'serviceLabels', null, "nginx=Nginx\nphp-fpm=PHP-FPM\nmysqld=MySQL", _t('服务显示名称（每行填写服务名=显示名称，可选）')
        ));
        $form->addInput(new Form\Element\Text(
            'cookieName', null, 'suite-theme', _t('主题偏好 Cookie 名称')
        ));
        $cookieDomain = new Form\Element\Text(
            'cookieDomain', null, '', _t('主题偏好 Cookie 域名')
        );
        $cookieDomain->input->setAttribute('placeholder', '留空表示仅当前主机');
        $form->addInput($cookieDomain);
        $form->addInput((new Form\Element\Checkbox(
            'enableStats',
            ['1' => _t('显示 Typecho 匿名访问统计（需要 suite_* 数据表）')],
            [],
            _t('博客访问统计')
        ))->multiMode());
        $form->addInput(new Form\Element\Select(
            'defaultRange',
            ['24h' => _t('24 小时'), '7d' => _t('7 天'), '30d' => _t('30 天'), '1y' => _t('1 年')],
            '24h',
            _t('监控面板默认时间范围')
        ));
        $form->addInput(new Form\Element\Select(
            'refreshSeconds',
            ['0' => _t('关闭自动刷新'), '30' => _t('每 30 秒'), '60' => _t('每 1 分钟'), '300' => _t('每 5 分钟')],
            '30',
            _t('监控面板自动刷新')
        ));
        $form->addInput(new Form\Element\Select(
            'defaultTheme',
            ['system' => _t('跟随系统'), 'light' => _t('默认浅色'), 'dark' => _t('默认深色')],
            'system',
            _t('监控面板默认主题')
        ));
    }

    public static function personalConfig(Form $form)
    {
    }

    /** Keep existing database passwords when their blank form fields are left unchanged. */
    public static function configHandle(array $settings, bool $isInit): void
    {
        $current = \Widget\Options::alloc()->plugin('SuiteMonitor');
        $secretFields = ['monitorRwPass', 'monitorRoPass'];
        $clear = is_array($settings['clearPasswords'] ?? null)
            ? in_array('1', array_map('strval', $settings['clearPasswords']), true)
            : (string) ($settings['clearPasswords'] ?? '') === '1';
        unset($settings['clearPasswords']);
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
        \Widget\Plugins\Edit::configPlugin('SuiteMonitor', $settings);
    }
}
