<?php

namespace TypechoPlugin\SuiteCore;

use Typecho\Plugin\PluginInterface;
use Typecho\Router;
use Typecho\Widget\Helper\Form;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Lightweight bridge for theme-owned capability routes.
 * SuiteCore registers routes and delegates rendering to the active theme.
 *
 * @package SuiteCore
 * @author luckyguo
 * @version 1.0.0
 */
final class Plugin implements PluginInterface
{
    private const PREFIX = 'suitecore_';

    public static function activate(): string
    {
        self::syncRoutes();
        \Typecho\Plugin::factory('Widget\\Archive')->handle = __CLASS__ . '::handle';
        return _t('SuiteCore 已启用');
    }

    public static function deactivate(): string
    {
        foreach (self::capabilities() as $capability) {
            $id = (string) ($capability['id'] ?? '');
            if ($id !== '') {
                $route = Router::get(self::PREFIX . $id);
                if (is_array($route) && ($route['widget'] ?? '') === '\\Widget\\Archive'
                    && ($route['action'] ?? '') === 'render') {
                    Helper::removeRoute(self::PREFIX . $id);
                }
            }
        }
        return _t('SuiteCore 已禁用');
    }

    public static function config(Form $form): void
    {
    }

    public static function personalConfig(Form $form): void
    {
    }

    /** Register theme-owned routes without replacing another route. */
    public static function syncRoutes(): void
    {
        foreach (self::capabilities() as $capability) {
            $id = (string) ($capability['id'] ?? '');
            $path = (string) ($capability['path'] ?? '');
            $handler = (string) ($capability['handler'] ?? '');
            if ($id === '' || $path === '' || $handler === '' || $id === 'home') {
                continue;
            }

            $name = self::PREFIX . $id;
            $existing = Router::get($name);
            if ($existing !== null) {
                if (($existing['url'] ?? '') === $path
                    && ($existing['widget'] ?? '') === '\\Widget\\Archive'
                    && ($existing['action'] ?? '') === 'render') {
                    continue;
                }
                continue;
            }

            $conflict = false;
            foreach ((array) (\Widget\Options::alloc()->routingTable[0] ?? []) as $route) {
                if (($route['url'] ?? '') === $path) {
                    $conflict = true;
                    break;
                }
            }
            if (!$conflict) {
                Helper::addRoute($name, $path, '\\Widget\\Archive', 'render', 'index');
            }
        }
    }

    /** Archive's generic handler delegates the actual page to the theme. */
    public static function handle(string $type, \Widget\Archive $archive, $select): bool
    {
        if (strpos($type, self::PREFIX) !== 0) {
            return false;
        }
        $id = substr($type, strlen(self::PREFIX));
        $options = \Widget\Options::alloc();
        $functions = rtrim((string) $options->themeFile($options->theme), '/') . '/functions.php';
        if (!is_file($functions)) {
            return false;
        }
        require_once $functions;
        $handler = 'suite_core_handle_capability';
        return function_exists($handler) && $handler($id, $archive, $select);
    }

    /** Public capabilities eligible for sitemap output. */
    public static function publicCapabilities($options = null): array
    {
        $result = [];
        foreach (self::capabilities($options) as $capability) {
            if (empty($capability['enabled']) || empty($capability['sitemap'])) {
                continue;
            }
            $id = (string) ($capability['id'] ?? '');
            $route = Router::get(self::PREFIX . $id);
            if ($id === '' || $route === null) {
                continue;
            }
            $result[] = $capability;
        }
        return $result;
    }

    /** Return diagnostics for a registered capability without mutating routes. */
    public static function capabilityStatus(string $id, $options = null): array
    {
        $capability = null;
        foreach (self::capabilities($options) as $item) {
            if (($item['id'] ?? '') === $id) {
                $capability = $item;
                break;
            }
        }
        if ($capability === null) {
            return ['registered' => false, 'enabled' => false, 'route_available' => false, 'status' => 'unregistered'];
        }
        if (empty($capability['enabled'])) {
            return ['registered' => true, 'enabled' => false, 'route_available' => false, 'status' => 'disabled'];
        }
        $active = \Typecho\Plugin::export()['activated'] ?? [];
        if (!isset($active['SuiteCore'])) {
            return ['registered' => true, 'enabled' => true, 'route_available' => false, 'status' => 'not_enabled'];
        }
        $route = Router::get(self::PREFIX . $id);
        if (is_array($route) && ($route['url'] ?? '') === ($capability['path'] ?? '')) {
            return ['registered' => true, 'enabled' => true, 'route_available' => true, 'status' => 'available'];
        }
        foreach ((array) (\Widget\Options::alloc()->routingTable[0] ?? []) as $item) {
            if (($item['url'] ?? '') === ($capability['path'] ?? '')) {
                return ['registered' => true, 'enabled' => true, 'route_available' => false, 'status' => 'conflict'];
            }
        }
        return ['registered' => true, 'enabled' => true, 'route_available' => false, 'status' => 'unavailable'];
    }

    private static function capabilities($options = null): array
    {
        $options = $options ?: \Widget\Options::alloc();
        $functions = rtrim((string) $options->themeFile($options->theme), '/') . '/functions.php';
        if (!is_file($functions)) {
            return [];
        }
        require_once $functions;
        return function_exists('suite_capabilities') ? (array) suite_capabilities($options) : [];
    }
}
