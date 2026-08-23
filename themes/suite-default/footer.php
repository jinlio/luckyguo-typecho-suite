<?php $footerStats = suite_footer_stats($this->options); ?>
<footer class="site-footer">
    <div><strong><?php echo htmlspecialchars(suite_option($this->options, 'siteName', (string) $this->options->title), ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo htmlspecialchars(suite_option($this->options, 'tagline', (string) $this->options->description), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <nav aria-label="页脚导航">
        <?php $landingUrl = suite_option($this->options, 'landingUrl', ''); $codeUrl = suite_option($this->options, 'codeUrl', ''); ?>
        <?php if ($landingUrl !== ''): ?><a href="<?php echo htmlspecialchars($landingUrl, ENT_QUOTES, 'UTF-8'); ?>">个人主页</a><?php endif; ?>
        <?php if ($codeUrl !== ''): ?><a href="<?php echo htmlspecialchars($codeUrl, ENT_QUOTES, 'UTF-8'); ?>">代码仓库</a><?php endif; ?>
        <a href="<?php $this->options->feedUrl(); ?>">文章 RSS</a>
        <?php if (suite_flag($this->options, 'showCommentsFeed', true)): ?><a href="<?php $this->options->commentsFeedUrl(); ?>">评论 RSS</a><?php endif; ?>
        <?php if ($this->user->hasLogin()): ?>
            <a href="<?php $this->options->adminUrl(); ?>">管理</a>
            <a href="<?php $this->options->profileUrl(); ?>">个人资料</a>
            <a href="<?php $this->options->logoutUrl(); ?>">退出</a>
        <?php endif; ?>
    </nav>
    <small><?php echo htmlspecialchars(suite_option($this->options, 'tagline', (string) $this->options->description), ENT_QUOTES, 'UTF-8'); ?> · <?php echo date('Y'); ?><?php if (suite_statistics_enabled($this->options)): ?> · 今日访客 <?php echo $footerStats['uv']; ?> · 累计访客 <?php echo $footerStats['total']; ?><?php endif; ?> · 已运行 <?php echo suite_uptime_text($this->options); ?></small>
</footer>
<script src="<?php $this->options->themeUrl('site.js?v=2.0.2'); ?>" defer></script>

<?php if ($this->is('post') || $this->is('page')): ?>
<script src="<?php $this->options->themeUrl('assets/prism-core.min.js?v=1.29.0'); ?>" defer></script>
<script src="<?php $this->options->themeUrl('assets/prism-autoloader.min.js?v=1.29.0'); ?>" data-autoloader-path="<?php $this->options->themeUrl('assets/prism/'); ?>" defer></script>
<script src="<?php $this->options->themeUrl('assets/prism-line-numbers.min.js?v=1.29.0'); ?>" defer></script>
<script src="<?php $this->options->themeUrl('assets/mac-code.js?v=1.1.0'); ?>" defer></script>
<?php endif; ?>
<?php $this->footer(); ?>
</body>
</html>
