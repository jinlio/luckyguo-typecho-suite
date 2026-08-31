<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; suite_record_visit($this->options); ?>
<?php
$isIndex = method_exists($this, 'is') && $this->is('index');
$isPost = method_exists($this, 'is') && $this->is('post');
$isPage = method_exists($this, 'is') && $this->is('page');
$isCategories = method_exists($this, 'is') && $this->is('categories');
$isSearch = method_exists($this, 'is') && $this->is('search');
$currentPage = method_exists($this, 'getCurrentPage') ? (int) $this->getCurrentPage() : 1;
$isFrontPage = $isIndex && $currentPage <= 1;
$isSingle = method_exists($this, 'is') && $this->is('single') && !$isIndex;
$isNotFound = (method_exists($this, 'is') && $this->is('404'))
    || (function_exists('http_response_code') && http_response_code() === 404)
    || ((int) ($this->archiveSlug ?? 0) === 404 && (string) ($this->themeFile ?? '') === '404.php');
$siteUrl = suite_site_url($this->options);
$siteName = suite_option($this->options, 'siteName', (string) $this->options->title);
$siteDescription = suite_option($this->options, 'tagline', (string) $this->options->description);
$homeTitle = suite_option($this->options, 'seoHomeTitle', '');
$homeDescription = suite_option($this->options, 'seoHomeDescription', $siteDescription);
$archiveDescription = trim((string) ($this->getArchiveDescription() ?? ''));
$archiveTitle = suite_archive_title_text($this);
if ($isFrontPage) {
    $archiveDescription = $homeDescription;
} elseif ($isCategories) {
    $archiveDescription = '按主题分类浏览全部公开文章。';
} elseif ($isPost || $isPage) {
    $entryDescription = suite_entry_description($this, $this->options);
    if ($entryDescription !== '') {
        $archiveDescription = $entryDescription;
    }
}
if ($archiveDescription === '') {
    $archiveDescription = $archiveTitle !== ''
        ? $archiveTitle . ' - ' . $siteDescription
        : $siteDescription;
    $this->setArchiveDescription($archiveDescription);
}
$canonicalUrl = $isCategories
    ? suite_capability_url('categories', $this->options)
    : suite_current_canonical($this, $this->options);
$pageTitle = $archiveTitle;
$pageTitle = $pageTitle !== '' ? $pageTitle : $siteName;
$documentTitle = $isFrontPage
    ? ($homeTitle !== '' ? $homeTitle : $siteName)
    : ($pageTitle === $siteName ? $siteName : $pageTitle . ' · ' . $siteName);
$ogImage = ($isPost || $isPage) ? suite_entry_thumbnail($this, $this->options) : suite_asset($this->options, 'ogImageUrl');
if ($ogImage === '') {
    $ogImage = suite_asset($this->options, 'ogImageUrl');
}
if ($ogImage === '') {
    $ogImage = suite_asset($this->options, 'bannerUrl', (string) $this->options->themeUrl('assets/og-default.png', $this->options->theme));
}
$keywords = suite_meta_keywords($this, $this->options);
$isThinTag = $this->is('tag') && method_exists($this, 'getTotal')
    && $this->getTotal() < suite_tag_min_posts($this->options);
$favicon = suite_asset($this->options, 'faviconUrl', (string) $this->options->themeUrl('assets/favicon.svg', $this->options->theme));
$authorName = suite_option($this->options, 'authorName', '网站作者');
$aboutUrl = suite_about_url($this->options);
$authorUrl = $aboutUrl !== '' ? $aboutUrl : $siteUrl . '/';
$createdAt = (int) ($this->created ?? 0);
$modifiedAt = (int) ($this->modified ?? 0);
$publishedIso = $createdAt > 0 ? date(DATE_ATOM, $createdAt) : '';
$modifiedIso = $modifiedAt > 0 ? date(DATE_ATOM, $modifiedAt) : $publishedIso;
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="<?php $this->options->charset(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcfafb">
    <title><?php echo suite_html($documentTitle); ?></title>
    <meta name="description" content="<?php echo suite_html($archiveDescription); ?>">
    <meta name="keywords" content="<?php echo suite_html_attr($keywords); ?>">
    <?php if (!$isSingle && !$isNotFound): ?><link rel="canonical" href="<?php echo suite_html_attr($canonicalUrl); ?>"><?php endif; ?>
    <?php if ($isSearch || $isThinTag): ?><meta name="robots" content="noindex,follow"><?php elseif ($isNotFound): ?><meta name="robots" content="noindex,noarchive"><?php endif; ?>
    <link rel="icon" href="<?php echo suite_html_attr($favicon); ?>">
    <link rel="sitemap" type="application/xml" title="Sitemap" href="<?php echo suite_html_attr($siteUrl . '/sitemap.xml'); ?>">
    <?php if (!$isNotFound): ?><meta property="og:type" content="<?php echo $isPost ? 'article' : 'website'; ?>">
    <meta property="og:title" content="<?php echo suite_html($documentTitle); ?>">
    <meta property="og:description" content="<?php echo suite_html($archiveDescription ?: $siteDescription); ?>">
    <meta property="og:url" content="<?php echo suite_html_attr($canonicalUrl); ?>">
    <meta property="og:site_name" content="<?php echo suite_html($siteName); ?>">
    <meta property="og:locale" content="zh_CN">
    <?php if ($ogImage !== ''): ?><meta property="og:image" content="<?php echo suite_html_attr($ogImage); ?>"><?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo suite_html($documentTitle); ?>">
    <meta name="twitter:description" content="<?php echo suite_html($archiveDescription ?: $siteDescription); ?>">
    <?php if ($ogImage !== ''): ?><meta name="twitter:image" content="<?php echo suite_html_attr($ogImage); ?>"><?php endif; ?>
    <?php if ($isPost && $publishedIso !== ''): ?><meta property="article:published_time" content="<?php echo htmlspecialchars($publishedIso, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($modifiedIso !== ''): ?><meta property="article:modified_time" content="<?php echo htmlspecialchars($modifiedIso, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
    <?php endif; ?>
    <?php if (!$isNotFound): ?>
    <?php
    $breadcrumbItems = [
        ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => $siteUrl . '/'],
    ];
    if (!$isFrontPage) {
        $breadcrumbItems[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $pageTitle, 'item' => $canonicalUrl];
    }
    $schemaGraph = [];
    if ($isFrontPage) {
        $schemaGraph[] = ['@type' => 'WebSite', 'name' => $siteName, 'url' => $siteUrl . '/', 'description' => $siteDescription];
        $schemaGraph[] = ['@type' => 'Person', 'name' => $authorName, 'url' => $authorUrl];
    } elseif ($isCategories) {
        $schemaGraph[] = [
            '@type' => 'CollectionPage',
            '@id' => $canonicalUrl,
            'url' => $canonicalUrl,
            'name' => '分类',
            'description' => $archiveDescription,
            'inLanguage' => 'zh-CN',
            'isPartOf' => ['@type' => 'WebSite', 'url' => $siteUrl . '/', 'name' => $siteName],
        ];
    } elseif ($isPost) {
        $articleSchema = [
            '@type' => 'BlogPosting',
            '@id' => $canonicalUrl . '#article',
            'url' => $canonicalUrl,
            'headline' => suite_title_text($this->title),
            'description' => $archiveDescription,
            'inLanguage' => 'zh-CN',
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonicalUrl],
            'author' => ['@type' => 'Person', 'name' => $authorName, 'url' => $authorUrl],
            'publisher' => ['@type' => 'Person', 'name' => $authorName, 'url' => $authorUrl],
        ];
        if ($ogImage !== '') {
            $articleSchema['image'] = [$ogImage];
        }
        if ($publishedIso !== '') {
            $articleSchema['datePublished'] = $publishedIso;
        }
        if ($modifiedIso !== '') {
            $articleSchema['dateModified'] = $modifiedIso;
        }
        $schemaGraph[] = $articleSchema;
    } elseif ($isPage) {
        $isAboutPage = strtolower((string) ($this->slug ?? '')) === 'about';
        $schemaGraph[] = [
            '@type' => $isAboutPage ? 'ProfilePage' : 'WebPage',
            '@id' => $canonicalUrl,
            'url' => $canonicalUrl,
            'name' => $pageTitle,
            'description' => $archiveDescription,
            'inLanguage' => 'zh-CN',
            'isPartOf' => ['@type' => 'WebSite', 'url' => $siteUrl . '/', 'name' => $siteName],
        ];
        if ($isAboutPage) {
            $schemaGraph[] = ['@type' => 'Person', 'name' => $authorName, 'url' => $authorUrl];
        }
    }
    $schemaGraph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $breadcrumbItems];
    $schema = [
        '@context' => 'https://schema.org',
        '@graph' => $schemaGraph,
    ]; ?>
    <script type="application/ld+json"><?php echo suite_json($schema); ?></script>
    <?php endif; ?>
    <?php endif; ?>
    <?php $cookie = suite_cookie_config($this->options); $defaultTheme = suite_option($this->options, 'defaultTheme', 'system'); $cookie['defaultTheme'] = in_array($defaultTheme, ['light', 'dark', 'system'], true) ? $defaultTheme : 'system'; $cookie['motion'] = suite_flag($this->options, 'enableMotion', true) ? 'on' : 'off'; $cookieJson = suite_json($cookie); ?>
    <script>window.SuiteThemeConfig=<?php echo $cookieJson; ?>;(function(){var c=window.SuiteThemeConfig||{name:'suite-theme',domain:''},m=document.cookie.match(new RegExp('(?:^|;\\s*)'+c.name+'=(dark|light)(?:;|$)')),saved='';try{saved=localStorage.getItem(c.name)||'';}catch(e){}var t=m?m[1]:saved||((matchMedia('(prefers-color-scheme:dark)').matches)?'dark':'light');document.documentElement.dataset.theme=t;})();</script>
    <script>(function(){var c=window.SuiteThemeConfig||{},hasCookie=document.cookie.indexOf(c.name+'=')!==-1,saved='';try{saved=localStorage.getItem(c.name)||'';}catch(e){}if(!hasCookie&&saved!=='dark'&&saved!=='light'&&(c.defaultTheme==='dark'||c.defaultTheme==='light'))document.documentElement.dataset.theme=c.defaultTheme;})();</script>
    <link rel="stylesheet" href="<?php $this->options->themeUrl('style.css?v=1.8.0'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/comment-form.css?v=1.0.0'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/mac-code.css?v=1.1.4'); ?>">
    <?php echo suite_layout_style($this->options); ?>
    <?php echo suite_custom_accent_style($this->options); ?>
    <?php $this->header('description=0&keywords=0&social=0'); ?>
</head>
<body class="<?php echo suite_accent_class($this->options) . (suite_custom_accent($this->options) !== '' ? ' suite-custom-accent' : '') . (!suite_flag($this->options, 'enableMotion', true) ? ' suite-no-motion' : ''); ?>">
<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header">
    <div class="header-inner">
        <a class="site-brand" href="<?php $this->options->siteUrl(); ?>">
            <?php echo suite_avatar_markup($this->options); ?>
            <span><strong><?php echo suite_html(suite_option($this->options, 'siteName', (string) $this->options->title)); ?></strong><small><?php echo suite_html(suite_option($this->options, 'authorHandle', 'journal')); ?></small></span>
        </a>
        <nav id="site-nav" class="site-nav" aria-label="主导航">
            <?php foreach (suite_navigation_items($this->options) as $navItem): ?>
                <?php $navCurrent = ($navItem['id'] === 'home' && $this->is('index'))
                    || ($navItem['id'] === 'categories' && ($isCategories || $this->is('category') || $this->is('tag')))
                    || ($navItem['id'] === 'archives' && ($this->is('page', 'archives') || $this->is('date')))
                    || ($navItem['id'] === 'about' && $this->is('page', 'about')); ?>
                <a<?php if ($navCurrent): ?> class="current" aria-current="page"<?php endif; ?> href="<?php echo suite_html_attr($navItem['url']); ?>"><?php echo suite_html($navItem['label']); ?></a>
            <?php endforeach; ?>
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
        <form id="site-search-bar" class="search-bar" method="get" action="<?php echo suite_html_attr($siteUrl); ?>" role="search">
            <label for="site-search">搜索文章</label>
            <input id="site-search" name="s" type="search" placeholder="输入关键词" autocomplete="off" enterkeyhint="search" required>
            <button type="submit">搜索</button>
        </form>
    <?php endif; ?>
</header>
