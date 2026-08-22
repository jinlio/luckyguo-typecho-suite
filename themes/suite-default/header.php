<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; suite_record_visit($this->options); ?>
<?php
$archiveDescription = trim((string) ($this->getArchiveDescription() ?? ''));
if ($archiveDescription === '') {
    $archiveTitle = trim((string) ($this->getArchiveTitle() ?? ''));
    $archiveDescription = $archiveTitle !== ''
        ? $archiveTitle . ' - ' . (string) $this->options->description
        : (string) $this->options->description;
    $this->setArchiveDescription($archiveDescription);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="<?php $this->options->charset(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcfafb">
    <title><?php $this->archiveTitle('', '', ' · '); ?><?php $this->options->title(); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($archiveDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <?php $cookie = suite_cookie_config($this->options); $cookieJson = json_encode($cookie, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
    <script>window.SuiteThemeConfig=<?php echo $cookieJson; ?>;(function(){var c=window.SuiteThemeConfig||{name:'suite-theme',domain:''},m=document.cookie.match(new RegExp('(?:^|;\\s*)'+c.name+'=(dark|light)(?:;|$)')),t=m?m[1]:localStorage.getItem(c.name)||((matchMedia('(prefers-color-scheme:dark)').matches)?'dark':'light');document.documentElement.dataset.theme=t;})();</script>
    <link rel="stylesheet" href="<?php $this->options->themeUrl('style.css?v=1.6.17'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/comment-form.css?v=1.0.0'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/mac-code.css?v=1.1.4'); ?>">
    <?php $this->header(); ?>
</head>
<body class="<?php echo suite_accent_class($this->options); ?>">
<header class="site-header">
    <div class="header-inner">
        <a class="site-brand" href="<?php $this->options->siteUrl(); ?>">
            <?php echo suite_avatar_markup($this->options); ?>
            <span><strong><?php echo htmlspecialchars(suite_option($this->options, 'siteName', (string) $this->options->title), ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars(suite_option($this->options, 'authorHandle', 'journal'), ENT_QUOTES, 'UTF-8'); ?></small></span>
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
