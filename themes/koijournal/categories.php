<?php
/** Theme-owned category overview capability. */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php');
$categoryCounts = suite_visible_category_counts($this->options);
\Widget\Metas\Category\Rows::alloc()->to($categories);
?>
<main id="main-content" class="page-shell categories-page">
    <header class="archive-heading">
        <p class="eyebrow">BROWSE BY TOPIC</p>
        <h1>分类</h1>
        <p class="categories-intro">按主题分类浏览全部公开文章。</p>
    </header>
    <section class="category-overview" aria-label="文章分类">
        <?php $hasVisibleCategory = false; ?>
        <?php if ($categories->have()): ?>
            <?php while ($categories->next()): ?>
                <?php $count = (int) ($categoryCounts[(int) $categories->mid] ?? 0); if ($count < 1) { continue; } $hasVisibleCategory = true; $level = max(0, (int) $categories->levels); ?>
                <a class="category-overview-row" data-level="<?php echo $level; ?>" style="--category-level: <?php echo $level; ?>;" href="<?php $categories->permalink(); ?>">
                    <span><?php $categories->name(); ?></span><small><?php echo $count; ?> 篇</small>
                </a>
            <?php endwhile; ?>
        <?php endif; ?>
        <?php if (!$hasVisibleCategory): ?>
            <div class="empty-state"><span class="empty-index">00 / TOPICS</span><div><strong>还没有可浏览的分类</strong><span>发布文章并分配分类后，这里会自动整理。</span></div></div>
        <?php endif; ?>
    </section>
</main>
<?php $this->need('footer.php'); ?>
