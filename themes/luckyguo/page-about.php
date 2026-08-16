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
            <p class="eyebrow">ABOUT / LUCKYGUO</p>
            <h1><?php $this->title(); ?></h1>
            <p>软件工程在校生，专注后端工程，也在探索大模型如何稳定地进入真实系统。</p>
        </div>
        <dl class="about-facts" aria-label="个人概览">
            <div><dt>FOCUS</dt><dd>后端工程 / 大模型应用</dd></div>
            <div><dt>STACK</dt><dd>Java · Spring · Python</dd></div>
            <div><dt>STATUS</dt><dd><span aria-hidden="true"></span>持续学习与构建</dd></div>
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
