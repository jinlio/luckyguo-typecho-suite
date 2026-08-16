<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php');
?>
<main class="article-shell page-shell-content">
    <a class="back-link" href="<?php $this->options->siteUrl(); ?>">← 返回文章列表</a>
    <div class="page-layout">
        <article class="article page-article">
            <header class="article-header"><p class="eyebrow">PAGE</p><h1><?php $this->title(); ?></h1></header>
            <div class="article-content"><?php $this->content(); ?></div>
        </article>
    </div>
    <?php $this->need('comments.php'); ?>
</main>
<?php $this->need('footer.php'); ?>
