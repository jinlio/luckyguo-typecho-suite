<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php');
luckyguo_record_view((int) $this->cid);
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
                <span>阅读 <?php echo luckyguo_get_views((int) $this->cid); ?></span>
                <span class="article-reading-time">约 <?php echo $readingMinutes; ?> 分钟阅读</span>
            </div>
        </header>
        <figure class="article-cover">
            <img src="<?php $this->options->themeUrl('article-cover.webp'); ?>" alt="淡粉色书桌与学习用品插画" fetchpriority="high">
        </figure>
        <div class="article-layout">
            <div class="article-content" itemprop="articleBody"><?php $this->content(); ?></div>
            <aside class="article-aside">
                <p>WRITTEN BY</p>
                <img src="<?php $this->options->themeUrl('avatar.jpg'); ?>" alt="锦鲤小果头像">
                <strong>锦鲤小果</strong>
                <span>luckyguo</span>
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
