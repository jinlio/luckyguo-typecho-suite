<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php');
suite_record_view((int) $this->cid, $this->options);
$readingText = trim(strip_tags((string) $this->text));
$readingUnits = preg_match_all('/[\p{Han}]|[A-Za-z0-9]+/u', $readingText, $readingMatches);
$readingMinutes = max(1, (int) ceil(($readingUnits ?: 0) / 480));
?>
<main class="article-shell">
    <a class="back-link" href="<?php $this->options->siteUrl(); ?>">← 返回文章列表</a>
    <article class="article" itemscope itemtype="https://schema.org/BlogPosting">
        <header class="article-header">
            <div class="post-kicker"><?php $this->category(' / ', true, '未分类'); ?></div>
            <h1 itemprop="headline"><?php $this->title(); ?></h1>
            <div class="article-meta">
                <time datetime="<?php $this->date('c'); ?>" itemprop="datePublished"><?php $this->date('Y 年 m 月 d 日'); ?></time>
                <span><?php $this->commentsNum('暂无评论', '1 条评论', '%d 条评论'); ?></span>
                <span>阅读 <?php echo suite_get_views((int) $this->cid); ?></span>
                <span class="article-reading-time">约 <?php echo $readingMinutes; ?> 分钟阅读</span>
            </div>
        </header>
        <?php $coverUrl = suite_asset($this->options, 'articleCoverUrl'); ?>
        <?php if ($coverUrl !== ''): ?><figure class="article-cover"><img src="<?php echo htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(suite_option($this->options, 'articleCoverAlt', '文章封面'), ENT_QUOTES, 'UTF-8'); ?>" fetchpriority="high"></figure><?php endif; ?>
        <div class="article-layout">
            <div class="article-content" itemprop="articleBody"><?php $this->content(); ?></div>
            <aside class="article-aside">
                <p>WRITTEN BY</p>
                <?php echo suite_avatar_markup($this->options); ?>
                <strong><?php echo htmlspecialchars(suite_option($this->options, 'authorName', '站点作者'), ENT_QUOTES, 'UTF-8'); ?></strong>
                <span><?php echo htmlspecialchars(suite_option($this->options, 'authorHandle', 'author'), ENT_QUOTES, 'UTF-8'); ?></span>
                <nav class="article-toc" aria-label="文章目录">
                    <p>ON THIS PAGE</p>
                    <ol></ol>
                </nav>
            </aside>
        </div>
        <div class="article-tags"><?php $this->tags('#', true, ''); ?></div>
    </article>
    <?php $this->need('comments.php'); ?>
    <nav class="post-near" aria-label="相邻文章">
        <div><small>上一篇</small><?php $this->thePrev('%s', '<span>没有更早的文章</span>'); ?></div>
        <div><small>下一篇</small><?php $this->theNext('%s', '<span>已经是最新一篇</span>'); ?></div>
    </nav>
</main>
<?php $this->need('footer.php'); ?>
