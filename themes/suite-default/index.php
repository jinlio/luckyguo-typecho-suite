<?php
/**
 * A configurable Typecho journal theme
 *
 * @package Suite Default Journal
 * @author suite
 * @version 1.6.1
 * @link https://github.com/jinlio/luckyguo-typecho-suite
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php');
?>
<main class="page-shell">
    <?php if ($this->is('index')): ?>
    <section class="blog-intro">
        <div class="journal-identity">
            <?php echo suite_avatar_markup($this->options); ?>
            <div>
                <p class="eyebrow"><?php echo htmlspecialchars(suite_option($this->options, 'homeEyebrow', 'JOURNAL / PERSONAL SPACE'), ENT_QUOTES, 'UTF-8'); ?></p>
                <h1><?php echo htmlspecialchars(suite_option($this->options, 'siteName', (string) $this->options->title), ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="intro-note"><?php echo nl2br(htmlspecialchars(suite_option($this->options, 'bio', '记录学习、折腾和那些值得留住的细节。'))); ?></p>
            </div>
        </div>
        <p class="intro-signature"><?php echo htmlspecialchars(suite_option($this->options, 'homeSignature', 'LEARN · BUILD · LIVE'), ENT_QUOTES, 'UTF-8'); ?><span><?php echo htmlspecialchars(suite_option($this->options, 'homeSignatureNote', '慢慢写，也认真生活。'), ENT_QUOTES, 'UTF-8'); ?></span></p>
    </section>
    <?php $bannerUrl = suite_asset($this->options, 'bannerUrl'); ?>
    <?php if ($bannerUrl !== ''): ?><figure class="journal-banner"><img src="<?php echo htmlspecialchars($bannerUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(suite_option($this->options, 'bannerAlt', '站点首页横幅'), ENT_QUOTES, 'UTF-8'); ?>" fetchpriority="high"></figure><?php endif; ?>
    <?php else: ?>
    <header class="archive-heading">
        <p class="eyebrow">BROWSE THE JOURNAL</p>
        <h1><?php $this->archiveTitle([
            'category' => _t('%s'),
            'search' => _t('搜索：%s'),
            'tag' => _t('#%s'),
            'author' => _t('%s 的文章')
        ], '', ''); ?></h1>
    </header>
    <?php endif; ?>

    <div class="section-heading"><strong><?php echo htmlspecialchars($this->is('index') ? suite_option($this->options, 'postListTitle', '最新记录') : '文章列表', ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo htmlspecialchars(suite_option($this->options, 'postListLabel', 'RECENT WRITING'), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <section class="post-list" aria-label="文章列表">
        <?php if ($this->have()): ?>
            <?php $postCids = $this->toArray('cid'); ?>
            <?php $postViews = suite_get_views_batch($postCids); ?>
            <?php $postMetas = suite_get_post_metas_batch($postCids, $this->options); ?>
            <?php $postIndex = 1; ?>
            <?php while ($this->next()): ?>
            <?php $postMeta = $postMetas[(int) $this->cid] ?? ['categories' => [], 'tags' => []]; ?>
            <article class="post-row">
                <div class="post-date"><span class="post-sequence"><?php printf('%02d', $postIndex++); ?></span><time datetime="<?php $this->date('c'); ?>"><?php $this->date('Y.m.d'); ?></time></div>
                <div class="post-main">
                    <div class="post-kicker"><?php if ($postMeta['categories']): ?><?php foreach ($postMeta['categories'] as $categoryIndex => $category): ?><?php echo $categoryIndex ? ' / ' : ''; ?><a href="<?php echo htmlspecialchars($category['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></a><?php endforeach; ?><?php else: ?>未分类<?php endif; ?></div>
                    <h2><a href="<?php $this->permalink(); ?>"><?php $this->title(); ?></a></h2>
                    <div class="post-summary"><?php echo htmlspecialchars(suite_list_excerpt((string) $this->text, suite_int_option($this->options, 'homeExcerptLength', 150, 40, 400)), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="post-meta">
                        <span><?php $this->commentsNum('暂无评论', '1 条评论', '%d 条评论'); ?></span>
                        <span>阅读 <?php echo $postViews[(int) $this->cid] ?? 0; ?></span>
                        <span><?php foreach ($postMeta['tags'] as $tagIndex => $tag): ?><?php echo $tagIndex ? ' #' : ''; ?><a href="<?php echo htmlspecialchars($tag['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8'); ?></a><?php endforeach; ?></span>
                    </div>
                </div>
            </article>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <span class="empty-index">00 / START</span>
                <div><strong>第一篇记录还在路上</strong><span>慢慢写，慢慢积累。</span></div>
                <a href="<?php $this->options->siteUrl('about.html'); ?>">先认识一下我 <span aria-hidden="true">→</span></a>
            </div>
        <?php endif; ?>
    </section>
    <nav class="pagination" aria-label="文章翻页"><?php $this->pageNav('上一页', '下一页', 2, '…'); ?></nav>

    <?php if ($this->is('index')): ?>
        <?php \Widget\Metas\Category\Rows::alloc()->to($categories); ?>
        <?php \Widget\Contents\Post\Date::alloc('type=month&format=Y 年 m 月&limit=6')->to($months); ?>
        <?php $recentCommentsCount = suite_int_option($this->options, 'recentCommentsCount', 5, 1, 20); ?>
        <?php \Widget\Comments\Recent::alloc('pageSize=' . $recentCommentsCount)->to($recentComments); ?>
        <?php if (suite_flag($this->options, 'showHomeWidgets', true)): ?>
        <section class="typecho-widgets" aria-label="站点浏览">
            <section class="native-widget">
                <div class="native-widget-heading"><strong>分类</strong><span>CATEGORIES</span></div>
                <div class="native-widget-list">
                    <?php if ($categories->have()): ?>
                        <?php while ($categories->next()): ?>
                            <?php $categoryLevel = (int) $categories->levels; ?>
                            <a class="category-link" data-level="<?php echo $categoryLevel; ?>" style="--category-level: <?php echo $categoryLevel; ?>;" href="<?php $categories->permalink(); ?>"><span><?php $categories->name(); ?></span><small><?php $categories->count(); ?></small></a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p>暂无分类</p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="native-widget">
                <div class="native-widget-heading"><strong>文章归档</strong><span>ARCHIVES</span></div>
                <div class="native-widget-list">
                    <?php if ($months->have()): ?>
                        <?php while ($months->next()): ?>
                            <a href="<?php $months->permalink(); ?>"><span><?php $months->date(); ?></span><small><?php $months->count(); ?></small></a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p>暂无归档</p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="native-widget">
                <div class="native-widget-heading"><strong>最近回复</strong><span>COMMENTS</span></div>
                <div class="recent-comment-list">
                    <?php if ($recentComments->have()): ?>
                        <?php while ($recentComments->next()): ?>
                            <a href="<?php $recentComments->permalink(); ?>"><strong><?php $recentComments->author(false); ?></strong><span><?php $recentComments->excerpt(34, '…'); ?></span></a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p>暂无回复</p>
                    <?php endif; ?>
                </div>
            </section>
        </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php $this->need('footer.php'); ?>
