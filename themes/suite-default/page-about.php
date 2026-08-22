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
            <h1><?php $this->title(); ?></h1>
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
            <h2 id="about-story-title">一些关于我的事</h2>
            <span>学习、实践，也记录过程。</span>
        </header>
        <div class="about-copy"><?php $this->content(); ?></div>
    </section>

</main>
<?php $this->need('footer.php'); ?>
