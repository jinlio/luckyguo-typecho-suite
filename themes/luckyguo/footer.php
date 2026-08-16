<?php $footerStats = luckyguo_footer_stats(); ?>
<footer class="site-footer">
    <div><strong>锦鲤小果</strong><span>记录正在发生的事</span></div>
    <nav aria-label="页脚导航">
        <a href="<?php echo htmlspecialchars(luckyguo_option($this->options, 'landingUrl', 'https://luckyguo.dpdns.org')); ?>">个人主页</a>
        <a href="<?php echo htmlspecialchars(luckyguo_option($this->options, 'giteaUrl', 'https://git.luckyguo.dpdns.org')); ?>">Gitea</a>
        <a href="<?php $this->options->feedUrl(); ?>">文章 RSS</a>
        <a href="<?php $this->options->commentsFeedUrl(); ?>">评论 RSS</a>
        <?php if ($this->user->hasLogin()): ?>
            <a href="<?php $this->options->adminUrl(); ?>">管理</a>
            <a href="<?php $this->options->profileUrl(); ?>">个人资料</a>
            <a href="<?php $this->options->logoutUrl(); ?>">退出</a>
        <?php endif; ?>
    </nav>
    <small>Powered by Typecho · <?php echo date('Y'); ?> · 今日访客 <?php echo $footerStats['uv']; ?> · 累计访客 <?php echo $footerStats['total']; ?> · 已运行 <?php echo luckyguo_uptime_text(); ?></small>
</footer>
<script src="<?php $this->options->themeUrl('site.js?v=1.6.11'); ?>" defer></script>

<?php if ($this->is('post') || $this->is('page')): ?>
<script src="<?php $this->options->themeUrl('assets/prism-core.min.js?v=1.29.0'); ?>" defer></script>
<script src="<?php $this->options->themeUrl('assets/prism-autoloader.min.js?v=1.29.0'); ?>" defer></script>
<script src="<?php $this->options->themeUrl('assets/prism-line-numbers.min.js?v=1.29.0'); ?>" defer></script>
<script src="<?php $this->options->themeUrl('assets/mac-code.js?v=1.1.0'); ?>" defer></script>
<?php endif; ?>
<?php $this->footer(); ?>
</body>
</html>
