<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php');
suite_record_view((int) $this->cid, $this->options);
$readingText = trim(strip_tags((string) $this->text));
$readingUnits = preg_match_all('/[\p{Han}]|[A-Za-z0-9]+/u', $readingText, $readingMatches);
$readingSpeed = suite_int_option($this->options, 'readingSpeed', 480, 100, 1000);
$readingMinutes = max(1, (int) ceil(($readingUnits ?: 0) / $readingSpeed));
?>
<main id="main-content" class="article-shell">
    <a class="back-link" data-context-back href="<?php $this->options->siteUrl(); ?>">← 返回文章列表</a>
    <article class="article" data-reading-progress="<?php echo suite_flag($this->options, 'showReadingProgress', true) ? 'on' : 'off'; ?>" itemscope itemtype="https://schema.org/BlogPosting">
        <header class="article-header">
            <div class="post-kicker"><?php $this->category(' / ', true, '未分类'); ?></div>
            <h1 itemprop="headline"><?php $this->title(); ?></h1>
            <div class="article-meta">
                <time datetime="<?php $this->date('c'); ?>" itemprop="datePublished"><?php $this->date('Y 年 m 月 d 日'); ?></time>
                <?php if (suite_flag($this->options, 'showReadingMeta', true)): ?>
                    <span><?php $this->commentsNum('暂无评论', '1 条评论', '%d 条评论'); ?></span>
                    <span>阅读 <?php echo suite_get_views((int) $this->cid); ?></span>
                    <span class="article-reading-time">约 <?php echo $readingMinutes; ?> 分钟阅读</span>
                <?php endif; ?>
            </div>
        </header>
        <?php if (suite_flag($this->options, 'showArticleCover', false)): ?>
            <?php $coverUrl = suite_entry_thumbnail($this, $this->options); ?>
            <?php if ($coverUrl !== ''): ?><figure class="article-cover"><img src="<?php echo htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(suite_option($this->options, 'articleCoverAlt', '文章封面'), ENT_QUOTES, 'UTF-8'); ?>" fetchpriority="high" decoding="async"></figure><?php endif; ?>
        <?php endif; ?>
        <div class="article-layout">
            <div class="article-content" itemprop="articleBody"><?php $this->content(); ?></div>
            <aside class="article-aside">
                <p><?php echo htmlspecialchars(suite_option($this->options, 'articleAuthorLabel', 'WRITTEN BY'), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php echo suite_avatar_markup($this->options); ?>
                <strong><?php echo htmlspecialchars(suite_option($this->options, 'authorName', '站点作者'), ENT_QUOTES, 'UTF-8'); ?></strong>
                <span><?php echo htmlspecialchars(suite_option($this->options, 'authorHandle', 'author'), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if (suite_flag($this->options, 'showArticleToc', true)): ?>
                <nav class="article-toc" data-toc-depth="<?php echo suite_option($this->options, 'tocDepth', 'h2-h3') === 'h2' ? 'h2' : 'h2-h3'; ?>" aria-label="文章目录">
                    <p><?php echo htmlspecialchars(suite_option($this->options, 'articleTocLabel', 'ON THIS PAGE'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <ol></ol>
                </nav>
                <?php endif; ?>
            </aside>
        </div>
        <div class="article-tags"><?php $this->tags('#', true, ''); ?></div>
        <div class="article-share" data-share-url="<?php echo htmlspecialchars(suite_current_canonical($this, $this->options), ENT_QUOTES, 'UTF-8'); ?>" data-share-title="<?php echo htmlspecialchars((string) $this->title, ENT_QUOTES, 'UTF-8'); ?>" aria-label="分享文章">
            <span>分享</span>
            <button type="button" data-share-copy>复制链接</button>
            <a href="https://twitter.com/intent/tweet?url=<?php echo rawurlencode(suite_current_canonical($this, $this->options)); ?>&text=<?php echo rawurlencode((string) $this->title); ?>" target="_blank" rel="noopener">X</a>
            <a href="https://service.weibo.com/share/share.php?url=<?php echo rawurlencode(suite_current_canonical($this, $this->options)); ?>&title=<?php echo rawurlencode((string) $this->title); ?>" target="_blank" rel="noopener">微博</a>
            <a href="https://t.me/share/url?url=<?php echo rawurlencode(suite_current_canonical($this, $this->options)); ?>&text=<?php echo rawurlencode((string) $this->title); ?>" target="_blank" rel="noopener">Telegram</a>
            <details class="share-qr"><summary>二维码</summary><img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&amp;data=<?php echo rawurlencode(suite_current_canonical($this, $this->options)); ?>" alt="文章二维码" width="180" height="180" loading="lazy" decoding="async"></details>
            <span class="share-toast" data-share-toast role="status" aria-live="polite"></span>
        </div>
    </article>
    <?php $this->need('comments.php'); ?>
    <nav class="post-near" aria-label="相邻文章">
        <div><small>上一篇</small><?php $this->thePrev('%s', '<span>没有更早的文章</span>'); ?></div>
        <div><small>下一篇</small><?php $this->theNext('%s', '<span>已经是最新一篇</span>'); ?></div>
    </nav>
</main>
<?php $this->need('footer.php'); ?>
