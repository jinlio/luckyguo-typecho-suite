<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; luckyguo_record_visit(); ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="<?php $this->options->charset(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcfafb">
    <title><?php $this->archiveTitle('', '', ' · '); ?><?php $this->options->title(); ?></title>
    <meta name="description" content="<?php $this->options->description(); ?>">
    <script>(function(){var m=document.cookie.match(/(?:^|;\s*)luckyguo-theme=(dark|light)(?:;|$)/),t=m?m[1]:localStorage.getItem('luckyguo-theme')||((matchMedia('(prefers-color-scheme:dark)').matches)?'dark':'light');if(!m)document.cookie='luckyguo-theme='+t+'; Max-Age=31536000; Path=/; Domain=.luckyguo.dpdns.org; SameSite=Lax; Secure';document.documentElement.dataset.theme=t;})();</script>
    <link rel="stylesheet" href="<?php $this->options->themeUrl('style.css?v=1.6.14'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/comment-form.css?v=1.0.0'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/mac-code.css?v=1.1.4'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php $this->options->themeUrl('favicon-32-v3.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php $this->options->themeUrl('favicon-16-v3.png'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php $this->options->themeUrl('apple-touch-icon-v3.png'); ?>">
    <link rel="shortcut icon" type="image/x-icon" href="<?php $this->options->themeUrl('favicon-v3.ico'); ?>">
    <?php $this->header(); ?>
</head>
<body class="<?php echo luckyguo_accent_class($this->options); ?>">
<header class="site-header">
    <div class="header-inner">
        <a class="site-brand" href="<?php $this->options->siteUrl(); ?>">
            <img src="<?php $this->options->themeUrl('avatar.jpg'); ?>" alt="锦鲤小果头像">
            <span><strong>锦鲤小果</strong><small>luckyguo</small></span>
        </a>
        <nav class="site-nav" aria-label="主导航">
            <a<?php if ($this->is('index')): ?> class="current"<?php endif; ?> href="<?php $this->options->siteUrl(); ?>">首页</a>
            <?php \Widget\Contents\Page\Rows::alloc()->to($navPages); ?>
            <?php while ($navPages->next()): ?>
                <a<?php if ($this->is('page', $navPages->slug)): ?> class="current"<?php endif; ?> href="<?php $navPages->permalink(); ?>"><?php $navPages->title(); ?></a>
            <?php endwhile; ?>
        </nav>
        <div class="header-tools">
            <button class="search-toggle" type="button" aria-label="打开搜索" aria-expanded="false" aria-controls="site-search-bar">
                <span class="search-glyph" aria-hidden="true"></span><b>搜索</b><kbd aria-hidden="true">⌘K</kbd>
            </button>
            <button class="icon-button theme-toggle" type="button" aria-label="切换深浅色主题" title="切换主题">◐</button>
        </div>
    </div>
    <form id="site-search-bar" class="search-bar" method="post" action="<?php $this->options->siteUrl(); ?>" role="search">
        <label for="site-search">搜索文章</label>
        <input id="site-search" name="s" type="search" placeholder="输入关键词" autocomplete="off">
        <button type="submit">搜索</button>
    </form>
</header>
