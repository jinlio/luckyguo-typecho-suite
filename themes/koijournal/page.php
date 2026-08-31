<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php');
?>
<main id="main-content" class="article-shell page-shell-content">
    <a class="back-link" data-context-back href="<?php $this->options->siteUrl(); ?>">← 返回文章列表</a>
    <div class="page-layout">
        <article class="article page-article" data-reading-progress="<?php echo suite_flag($this->options, 'showReadingProgress', true) ? 'on' : 'off'; ?>">
            <header class="article-header"><p class="eyebrow">PAGE</p><h1><?php echo suite_stored_html($this->title); ?></h1></header>
            <div class="article-content"><?php $this->content(); ?></div>
        </article>
    </div>
    <?php $this->need('comments.php'); ?>
</main>
<?php $this->need('footer.php'); ?>
