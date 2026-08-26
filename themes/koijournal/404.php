<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; $this->need('header.php'); ?>
<main id="main-content" class="error-page"><p class="eyebrow">404 / NOT FOUND</p><h1>这一页暂时不存在。</h1><p>也许它被移动了，或者还没有写下。</p><a href="<?php $this->options->siteUrl(); ?>">返回博客</a></main>
<?php $this->need('footer.php'); ?>
