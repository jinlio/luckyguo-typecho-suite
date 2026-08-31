<?php

namespace TypechoPlugin\SuiteContent;

use Typecho\Common;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element;
use Typecho\Db;
use Typecho\Db\Query;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Content rules which must remain active independently of the admin skin.
 *
 * SuiteAdmin used to own the sticky-post hooks.  Keeping those hooks in a
 * content plugin makes the behaviour survive a skin change and gives us one
 * place to enforce tag slug uniqueness before Typecho's native tag scanner
 * runs.
 *
 * @package SuiteContent
 * @author luckyguo
 * @version 1.0.0
 * @link https://github.com/jinlio/luckyguo-typecho-suite
 */
final class Plugin implements PluginInterface
{
    private const TAG_LIMIT = 150;

    public static function activate(): string
    {
        \Typecho\Plugin::factory('admin/write-post.php')->option = __CLASS__ . '::postOption';
        \Typecho\Plugin::factory('Widget\\Contents\\Post\\Edit')->write = __CLASS__ . '::postWrite';
        \Typecho\Plugin::factory('Widget\\Archive')->handleInit = __CLASS__ . '::archiveInit';
        Helper::addPanel(3, 'SuiteContent/doctor.php', '内容健康', '标签与置顶诊断', 'administrator');
        return _t('内容治理已启用');
    }

    public static function deactivate(): string
    {
        Helper::removePanel(3, 'SuiteContent/doctor.php');
        return _t('内容治理已禁用');
    }

    public static function config(Form $form): void
    {
        $form->addInput(new Element\Text(
            'tagSlugSuffix',
            null,
            '-{mid}',
            _t('标签冲突后缀（支持 {mid}，建议保持默认）')
        ));
        $form->addInput((new Element\Checkbox(
            'enableSticky',
            ['1' => _t('启用文章置顶与排序')],
            ['1'],
            _t('文章置顶')
        ))->multiMode());
    }


    public static function personalConfig(Form $form): void
    {
    }

    /**
     * Render a native-looking option while keeping the field outside
     * `fields[]`; the presence marker lets API/autosave requests omit the
     * option without accidentally clearing an existing sticky state.
     */
    public static function postOption($post): void
    {
        $options = \Widget\Options::alloc();
        if (!self::stickyEnabled($options)) {
            return;
        }

        $checked = false;
        try {
            $checked = (int) ($post->order ?? 0) > 0;
        } catch (\Throwable $e) {
            $checked = false;
        }

        echo '<section class="typecho-post-option suite-sticky-option">'
            . '<label class="typecho-label" for="suite-sticky">' . _t('文章排序') . '</label>'
            . '<p><input type="hidden" name="suite_sticky_present" value="1">'
            . '<label><input type="checkbox" id="suite-sticky" name="suite_sticky" value="1"'
            . ($checked ? ' checked="checked"' : '') . '> ' . _t('置顶文章') . '</label></p>'
            . '<p class="description">' . _t('置顶文章会排在文章列表最前面。') . '</p>'
            . '</section>';
    }

    /**
     * Apply the explicit sticky field and pre-create tags with a collision-
     * free slug.  The latter makes the native Metas::scanTags() reuse our row
     * instead of silently inserting a second row with the same slug.
     */
    public static function postWrite(array $contents, \Widget\Contents\Post\Edit $widget): array
    {
        $options = \Widget\Options::alloc();
        if (self::stickyEnabled($options)) {
            $present = self::requestFlag($widget, 'suite_sticky_present');
            // Accept the old SuiteAdmin field for one upgrade cycle.
            $legacyPresent = self::requestHas($widget, 'fields')
                && array_key_exists('sticky', (array) $widget->request->getArray('fields'));
            if ($present || $legacyPresent) {
                $value = $present
                    ? self::requestFlag($widget, 'suite_sticky')
                    : ((string) (($widget->request->getArray('fields')['sticky'] ?? '')) === '1');
                $contents['order'] = $value ? 1 : 0;
            } elseif (!array_key_exists('order', $contents) && !self::requestHas($widget, 'cid')) {
                // New content has no prior ordering value.
                $contents['order'] = 0;
            }
        }

        if (array_key_exists('tags', $contents)) {
            $tagInput = is_array($contents['tags'])
                ? implode(',', array_map('strval', $contents['tags']))
                : (string) $contents['tags'];
            self::ensureTags($tagInput, $options);
        }

        return $contents;
    }

    /**
     * Sticky ordering applies to list archives only.  It must not alter a
     * single post/page, feed, search result, or the special category overview.
     */
    public static function archiveInit(\Widget\Archive $archive, Query $select): void
    {
        $options = \Widget\Options::alloc();
        if (!self::stickyEnabled($options)) {
            return;
        }

        $type = '';
        try {
            $type = (string) ($archive->parameter->type ?? '');
        } catch (\Throwable $e) {
            return;
        }
        $listTypes = [
            'index', 'index_page', 'archive', 'archive_page',
            'category', 'category_page', 'tag', 'tag_page', 'author', 'author_page',
            'archive_year', 'archive_year_page', 'archive_month', 'archive_month_page',
            'archive_day', 'archive_day_page'
        ];
        if (in_array($type, $listTypes, true)) {
            $select->order('table.contents.order', Db::SORT_DESC);
        }
    }

    private static function stickyEnabled($options): bool
    {
        $value = $options->plugin('SuiteContent')->enableSticky ?? ['1'];
        if (is_array($value)) {
            return in_array('1', array_map('strval', $value), true);
        }
        return !in_array(strtolower((string) $value), ['', '0', 'false', 'off', 'no'], true);
    }

    private static function requestHas($widget, string $name): bool
    {
        try {
            return $widget->request->get($name, null) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function requestFlag($widget, string $name): bool
    {
        try {
            $value = $widget->request->get($name, null);
            if (is_array($value)) {
                return in_array('1', array_map('strval', $value), true);
            }
            return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Ensure every named tag has a deterministic, type-local slug.  Existing
     * rows are never renamed unless another tag already owns their slug; the
     * doctor command handles historical collisions explicitly.
     */
    private static function ensureTags(string $input, $options): void
    {
        $tags = array_values(array_unique(array_filter(array_map(
            static fn (string $tag): string => trim($tag),
            preg_split('/[,，]+/u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: []
        ))));
        if (!$tags) {
            return;
        }

        try {
            $db = Db::get();
            foreach ($tags as $name) {
                $row = $db->fetchRow($db->select('mid', 'slug')->from('table.metas')
                    ->where('type = ?', 'tag')->where('name = ?', $name)->limit(1));
                if ($row) {
                    $slug = (string) ($row['slug'] ?? '');
                    if ($slug !== '' && self::slugOwner($db, $slug, (int) $row['mid']) === null) {
                        continue;
                    }
                    $newSlug = self::uniqueSlug($db, $slug !== '' ? $slug : $name, (int) $row['mid'], $options);
                    if ($newSlug !== $slug) {
                        $db->query($db->update('table.metas')->rows(['slug' => $newSlug])
                            ->where('mid = ?', (int) $row['mid'])->where('type = ?', 'tag'));
                    }
                    continue;
                }

                $base = (string) Common::slugName($name);
                if ($base === '') {
                    // Typecho's slugger may return an empty value for a
                    // non-Latin name; use a stable digest instead of '-'.
                    $base = 'tag-' . substr(sha1($name), 0, 12);
                }
                $configuredSuffix = self::tagSlugSuffix($options);
                $slug = self::uniqueSlug($db, $base, 0, $options);
                // Insert first, then replace the placeholder suffix with the
                // allocated mid when the configured suffix requests it.  The
                // auto-increment id is unknowable before INSERT, so the first
                // slug is only a collision-free provisional value.
                $mid = (int) $db->query($db->insert('table.metas')->rows([
                    'name' => $name,
                    'slug' => $slug,
                    'type' => 'tag',
                    'count' => 0,
                    'order' => 0,
                ]));
                if ($mid > 0 && strpos($configuredSuffix, '{mid}') !== false) {
                    $final = $base . str_replace('{mid}', (string) $mid, $configuredSuffix);
                    $final = self::trimSlug($final, $mid);
                    if (self::slugOwner($db, $final, $mid) !== null) {
                        $final = self::uniqueSlug($db, $final, $mid, $options);
                    }
                    if ($final !== $slug) {
                        try {
                            $db->query($db->update('table.metas')->rows(['slug' => $final])
                                ->where('mid = ?', $mid)->where('type = ?', 'tag'));
                        } catch (\Throwable $updateError) {
                            // Keep the provisional unique slug if a concurrent
                            // writer wins the final-suffix race.
                            error_log('[SuiteContent] tag final slug skipped: ' . $updateError->getMessage());
                        }
                    }
                }
            }
        } catch (\Throwable $error) {
            // The native scanner remains the fallback when an optional plugin
            // cannot access the database; log without breaking post saves.
            error_log('[SuiteContent] tag preflight skipped: ' . $error->getMessage());
        }
    }

    private static function slugOwner(Db $db, string $slug, int $exceptMid = 0): ?int
    {
        $query = $db->select('mid')->from('table.metas')
            ->where('type = ?', 'tag')->where('slug = ?', $slug)->limit(1);
        if ($exceptMid > 0) {
            $query->where('mid != ?', $exceptMid);
        }
        $row = $db->fetchRow($query);
        return $row ? (int) $row['mid'] : null;
    }

    private static function uniqueSlug(Db $db, string $base, int $mid, $options): string
    {
        $base = self::trimSlug(trim($base), $mid);
        $suffix = self::tagSlugSuffix($options);

        $candidate = $base;
        if (self::slugOwner($db, $candidate, $mid) === null) {
            return $candidate;
        }
        $seed = $base;
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $candidate = $seed . str_replace('{mid}', $mid > 0 ? (string) $mid : (string) $attempt, $suffix);
            $candidate = self::trimSlug($candidate, $mid > 0 ? $mid : $attempt);
            if (self::slugOwner($db, $candidate, $mid) === null) {
                return $candidate;
            }
        }
        return $seed . '-' . substr(sha1($seed . ':' . $mid), 0, 8);
    }

    private static function tagSlugSuffix($options): string
    {
        $suffix = '-{mid}';
        try {
            $configured = $options->plugin('SuiteContent')->tagSlugSuffix ?? $suffix;
            if (is_string($configured)
                && preg_match('/^[A-Za-z0-9_-]*\{mid\}[A-Za-z0-9_-]*$/', $configured)) {
                $suffix = $configured;
            }
        } catch (\Throwable $e) {
        }
        return $suffix;
    }

    private static function trimSlug(string $slug, int $suffix): string
    {
        if (strlen($slug) <= self::TAG_LIMIT) {
            return $slug;
        }
        $tail = '-' . (string) max(1, $suffix);
        return substr($slug, 0, max(1, self::TAG_LIMIT - strlen($tail))) . $tail;
    }
}
