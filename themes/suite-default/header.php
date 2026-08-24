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
$canonicalUrl = suite_current_canonical($this, $this->options);
$siteName = suite_option($this->options, 'siteName', (string) $this->options->title);
$siteDescription = suite_option($this->options, 'tagline', (string) $this->options->description);
$ogImage = ($this->is('post') || $this->is('page')) ? suite_entry_thumbnail($this, $this->options) : suite_asset($this->options, 'ogImageUrl');
if ($ogImage === '') {
    $ogImage = suite_asset($this->options, 'ogImageUrl');
}
if ($ogImage === '') {
    $ogImage = suite_asset($this->options, 'bannerUrl', (string) $this->options->themeUrl('assets/og-default.png', $this->options->theme));
}
$keywords = suite_meta_keywords($this, $this->options);
$favicon = suite_asset($this->options, 'faviconUrl', (string) $this->options->themeUrl('assets/favicon.svg', $this->options->theme));
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="<?php $this->options->charset(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcfafb">
    <title><?php $this->archiveTitle('', '', ' · '); ?><?php $this->options->title(); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($archiveDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($keywords, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="icon" href="<?php echo htmlspecialchars($favicon, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="sitemap" type="application/xml" title="Sitemap" href="<?php echo htmlspecialchars(suite_site_url($this->options) . '/sitemap.xml', ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:type" content="<?php echo $this->is('post') ? 'article' : 'website'; ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars((string) $this->title, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($archiveDescription ?: $siteDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($ogImage !== ''): ?><meta property="og:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <?php if ($ogImage !== ''): ?><meta name="twitter:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
    <?php if ($this->is('index')): ?>
    <?php $schema = [
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'WebSite', 'name' => $siteName, 'url' => suite_site_url($this->options) . '/', 'description' => $siteDescription],
            ['@type' => 'Person', 'name' => suite_option($this->options, 'authorName', '网站作者'), 'url' => suite_site_url($this->options) . '/about.html'],
            ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => suite_site_url($this->options) . '/']]],
        ],
    ]; ?>
    <script type="application/ld+json"><?php echo json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <?php endif; ?>
    <?php $cookie = suite_cookie_config($this->options); $defaultTheme = suite_option($this->options, 'defaultTheme', 'system'); $cookie['defaultTheme'] = in_array($defaultTheme, ['light', 'dark', 'system'], true) ? $defaultTheme : 'system'; $cookie['motion'] = suite_flag($this->options, 'enableMotion', true) ? 'on' : 'off'; $cookieJson = json_encode($cookie, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
    <script>window.SuiteThemeConfig=<?php echo $cookieJson; ?>;(function(){var c=window.SuiteThemeConfig||{name:'suite-theme',domain:''},m=document.cookie.match(new RegExp('(?:^|;\\s*)'+c.name+'=(dark|light)(?:;|$)')),saved='';try{saved=localStorage.getItem(c.name)||'';}catch(e){}var t=m?m[1]:saved||((matchMedia('(prefers-color-scheme:dark)').matches)?'dark':'light');document.documentElement.dataset.theme=t;})();</script>
    <script>(function(){var c=window.SuiteThemeConfig||{},hasCookie=document.cookie.indexOf(c.name+'=')!==-1,saved='';try{saved=localStorage.getItem(c.name)||'';}catch(e){}if(!hasCookie&&saved!=='dark'&&saved!=='light'&&(c.defaultTheme==='dark'||c.defaultTheme==='light'))document.documentElement.dataset.theme=c.defaultTheme;})();</script>
    <link rel="stylesheet" href="<?php $this->options->themeUrl('style.css?v=1.7.0'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/comment-form.css?v=1.0.0'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/mac-code.css?v=1.1.4'); ?>">
    <?php echo suite_layout_style($this->options); ?>
    <?php echo suite_custom_accent_style($this->options); ?>
    <?php $this->header(); ?>
</head>
<body class="<?php echo suite_accent_class($this->options) . (suite_custom_accent($this->options) !== '' ? ' suite-custom-accent' : '') . (!suite_flag($this->options, 'enableMotion', true) ? ' suite-no-motion' : ''); ?>">
<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header">
    <div class="header-inner">
        <a class="site-brand" href="<?php $this->options->siteUrl(); ?>">
            <?php echo suite_avatar_markup($this->options); ?>
            <span><strong><?php echo htmlspecialchars(suite_option($this->options, 'siteName', (string) $this->options->title), ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars(suite_option($this->options, 'authorHandle', 'journal'), ENT_QUOTES, 'UTF-8'); ?></small></span>
        </a>
        <nav id="site-nav" class="site-nav" aria-label="主导航">
            <a<?php if ($this->is('index')): ?> class="current"<?php endif; ?> href="<?php $this->options->siteUrl(); ?>">首页</a>
            <?php \Widget\Contents\Page\Rows::alloc()->to($navPages); ?>
            <?php while ($navPages->next()): ?>
                <a<?php if ($this->is('page', $navPages->slug)): ?> class="current"<?php endif; ?> href="<?php $navPages->permalink(); ?>"><?php $navPages->title(); ?></a>
            <?php endwhile; ?>
        </nav>
        <div class="header-tools">
            <button class="icon-button nav-toggle" type="button" aria-label="打开导航菜单" aria-expanded="false" aria-controls="site-nav">
                <span class="menu-glyph" aria-hidden="true"></span>
            </button>
            <?php if (suite_flag($this->options, 'showSearch', true)): ?>
                <button class="search-toggle" type="button" aria-label="打开搜索" aria-expanded="false" aria-controls="site-search-bar">
                    <span class="search-glyph" aria-hidden="true"></span><b>搜索</b><kbd data-search-shortcut aria-hidden="true">⌘K</kbd>
                </button>
            <?php endif; ?>
            <button class="icon-button theme-toggle" type="button" aria-label="切换深浅色主题" title="切换主题">◐</button>
        </div>
    </div>
    <?php if (suite_flag($this->options, 'showSearch', true)): ?>
        <form id="site-search-bar" class="search-bar" method="post" action="<?php $this->options->siteUrl(); ?>" role="search">
            <label for="site-search">搜索文章</label>
            <input id="site-search" name="s" type="search" placeholder="输入关键词" autocomplete="off">
            <button type="submit">搜索</button>
        </form>
    <?php endif; ?>
</header>
