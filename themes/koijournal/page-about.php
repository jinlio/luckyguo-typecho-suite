<?php
/**
 * 关于页
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php');
?>
<main class="page-shell about-shell">
    <header class="about-masthead">
        <div class="about-title">
            <p class="eyebrow">ABOUT / SUITE</p>
            <h1><?php echo suite_stored_html($this->title); ?></h1>
            <p><?php echo htmlspecialchars(suite_option($this->options, 'aboutLead', suite_option($this->options, 'tagline', '介绍这个站点、你的工作或正在探索的方向。')), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <dl class="about-facts" aria-label="个人概览">
            <div><dt>FOCUS</dt><dd><?php echo htmlspecialchars(suite_option($this->options, 'aboutFocus', '按你的方向填写'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>STACK</dt><dd><?php echo htmlspecialchars(suite_option($this->options, 'aboutStack', '按你的技术栈填写'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>STATUS</dt><dd><span aria-hidden="true"></span><?php echo htmlspecialchars(suite_option($this->options, 'aboutStatus', '持续学习与构建'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
        </dl>
    </header>

    <section class="about-story" aria-labelledby="about-story-title">
        <header class="about-story-heading">
            <p class="eyebrow">PROFILE / 01</p>
            <h2 id="about-story-title"><?php echo htmlspecialchars(suite_option($this->options, 'aboutStoryTitle', '一些关于我的事'), ENT_QUOTES, 'UTF-8'); ?></h2>
            <span><?php echo htmlspecialchars(suite_option($this->options, 'aboutStorySubtitle', '学习、实践，也记录过程。'), ENT_QUOTES, 'UTF-8'); ?></span>
        </header>
        <div class="about-copy"><?php $aboutBody = trim((string) suite_option($this->options, 'aboutBody', '')); if ($aboutBody !== ''): echo nl2br(htmlspecialchars($aboutBody, ENT_QUOTES, 'UTF-8')); else: $this->content(); endif; ?></div>
    </section>

    <section class="about-panels" aria-label="技术栈与当前方向">
        <div class="about-panel about-stack-panel">
            <header class="about-panel-heading"><p class="eyebrow">STACK / TOOLS</p><h2>我常用的工具</h2></header>
            <div class="about-stack-grid">
                <?php foreach (suite_about_stack_items($this->options) as $stack): ?>
                    <article class="about-stack-card"><?php if (preg_match('#^https://#i', $stack['icon'])): ?><img class="stack-icon" src="<?php echo htmlspecialchars($stack['icon'], ENT_QUOTES, 'UTF-8'); ?>" alt="" width="28" height="28" loading="lazy" decoding="async"><?php else: ?><span class="stack-icon" aria-hidden="true"><?php echo htmlspecialchars($stack['icon'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?><div><strong><?php echo htmlspecialchars($stack['name'], ENT_QUOTES, 'UTF-8'); ?></strong><?php if ($stack['description'] !== ''): ?><span><?php echo htmlspecialchars($stack['description'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></div></article>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="about-panel about-direction-panel">
            <header class="about-panel-heading"><p class="eyebrow">NOW / NEXT</p><h2>正在做的事</h2></header>
            <p><?php echo htmlspecialchars(suite_option($this->options, 'aboutDoing', '持续学习、实践，并把想法做成可靠的工具。'), ENT_QUOTES, 'UTF-8'); ?></p>
            <p><?php echo htmlspecialchars(suite_option($this->options, 'aboutWriting', '大模型应用开发、落地实践与真实反馈'), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </section>

    <section class="about-contact" aria-labelledby="about-contact-title">
        <header class="about-panel-heading"><p class="eyebrow">CONTACT / FOLLOW</p><h2 id="about-contact-title">保持联系</h2></header>
        <div class="about-contact-grid">
            <?php $codeUrl = suite_asset($this->options, 'codeUrl'); $contactEmail = suite_option($this->options, 'contactEmail', ''); ?>
            <?php if ($codeUrl !== ''): ?><a href="<?php echo htmlspecialchars($codeUrl, ENT_QUOTES, 'UTF-8'); ?>" rel="me noopener" target="_blank"><strong>GitHub</strong><span>查看代码与项目</span></a><?php endif; ?>
            <?php if ($contactEmail !== ''): ?><a href="mailto:<?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>"><strong>邮件</strong><span><?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?></span></a><?php endif; ?>
            <a href="<?php $this->options->feedUrl(); ?>"><strong>RSS</strong><span>订阅最新文章</span></a>
        </div>
    </section>

</main>
<?php $this->need('footer.php'); ?>
