<?php

namespace TypechoPlugin\Sitemap;

use Widget\Base\Contents;
use Widget\Contents\Page\Rows;
use Widget\Contents\Post\Recent;
use Widget\Metas\Category\Rows as CategoryRows;
use Widget\Metas\Tag\Cloud;
use Typecho\Router;
use TypechoPlugin\SuiteCore\Plugin as SuiteCorePlugin;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Sitemap generator based on joyqi/typecho-plugin-sitemap v1.0.0.
 */
final class Generator extends Contents
{
    private const MAX_URLS = 50000;

    public function generate(): void
    {
        $settings = $this->options->plugin('Sitemap');
        $blocks = is_array($settings->sitemapBlock)
            ? $settings->sitemapBlock
            : ['posts', 'pages', 'categories', 'tags'];
        $updateFreq = in_array($settings->updateFreq, ['daily', 'weekly', 'monthly'], true)
            ? $settings->updateFreq
            : 'daily';
        $configuredTagMinPosts = (int) ($settings->tagMinPosts ?? 0);
        $tagMinPosts = $configuredTagMinPosts > 0
            ? min(100, $configuredTagMinPosts)
            : 2;

        $entries = [];
        $latestModified = 0;

        if (in_array('posts', $blocks, true)) {
            $posts = Recent::alloc(['pageSize' => self::MAX_URLS - 1]);

            while (count($entries) < self::MAX_URLS - 1 && $posts->next()) {
                if (!empty($posts->password)) {
                    continue;
                }

                $modified = max((int) $posts->created, (int) $posts->modified);
                $latestModified = max($latestModified, $modified);
                $entries[] = $this->urlEntry($posts->permalink, $modified, $updateFreq, '0.8');
            }
        }

        if (in_array('pages', $blocks, true) && count($entries) < self::MAX_URLS - 1) {
            $pages = Rows::alloc();

            while (count($entries) < self::MAX_URLS - 1 && $pages->next()) {
                if (!empty($pages->password)) {
                    continue;
                }

                $modified = max((int) $pages->created, (int) $pages->modified);
                $latestModified = max($latestModified, $modified);
                $entries[] = $this->urlEntry($pages->permalink, $modified, $updateFreq, '0.5');
            }
        }

        if (in_array('categories', $blocks, true) && count($entries) < self::MAX_URLS - 1) {
            $categories = CategoryRows::alloc();

            while (count($entries) < self::MAX_URLS - 1 && $categories->next()) {
                if ((int) $categories->count < 1) {
                    continue;
                }

                $entries[] = $this->urlEntry($categories->permalink, null, $updateFreq, '0.6');
            }
        }

        if (in_array('tags', $blocks, true) && count($entries) < self::MAX_URLS - 1) {
            $tags = Cloud::alloc(['ignoreZeroCount' => true]);

            while (count($entries) < self::MAX_URLS - 1 && $tags->next()) {
                if ((int) $tags->count < $tagMinPosts) {
                    continue;
                }
                $entries[] = $this->urlEntry($tags->permalink, null, $updateFreq, '0.4');
            }
        }

        // Theme-owned public capabilities are independent from navigation visibility.
        $hasCategoryOverview = false;
        if (count($entries) < self::MAX_URLS - 1) {
            if (!class_exists(SuiteCorePlugin::class)) {
                $suiteCoreFile = rtrim((string) $this->options->pluginDir, '/') . '/SuiteCore/Plugin.php';
                if (is_file($suiteCoreFile)) {
                    require_once $suiteCoreFile;
                }
            }
        }
        if (count($entries) < self::MAX_URLS - 1 && class_exists(SuiteCorePlugin::class)) {
            foreach (SuiteCorePlugin::publicCapabilities($this->options) as $capability) {
                $path = ltrim((string) ($capability['path'] ?? ''), '/');
                if ($path === '') {
                    continue;
                }
                if ($path === 'categories/') {
                    $hasCategoryOverview = true;
                }
                $entries[] = $this->urlEntry(rtrim((string) $this->options->siteUrl, '/') . '/' . $path, null, $updateFreq, '0.5');
            }
        }
        if (!$hasCategoryOverview && count($entries) < self::MAX_URLS - 1 && Router::get('suitecore_categories') !== null) {
            // Keep the current category capability discoverable if SuiteCore's class is autoloaded late.
            $entries[] = $this->urlEntry(rtrim((string) $this->options->siteUrl, '/') . '/categories/', null, $updateFreq, '0.5');
        }

        $homepage = $this->urlEntry($this->options->siteUrl, $latestModified ?: null, 'daily', '1.0');
        $sitemap = '<?xml version="1.0" encoding="' . $this->xml($this->options->charset) . '"?>' . "\n";
        $sitemap .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $sitemap .= $homepage . implode('', $entries);
        $sitemap .= '</urlset>' . "\n";

        $this->response->setHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=60');
        $this->response->throwContent($sitemap, 'application/xml');
    }

    private function urlEntry(
        string $location,
        ?int $modified,
        string $changeFrequency,
        string $priority
    ): string {
        $entry = "  <url>\n";
        $entry .= '    <loc>' . $this->xml($location) . "</loc>\n";
        if ($modified !== null && $modified > 0) {
            $entry .= '    <lastmod>' . gmdate("Y-m-d\TH:i:s\Z", $modified) . "</lastmod>\n";
        }
        $entry .= '    <changefreq>' . $changeFrequency . "</changefreq>\n";
        $entry .= '    <priority>' . $priority . "</priority>\n";
        $entry .= "  </url>\n";

        return $entry;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
