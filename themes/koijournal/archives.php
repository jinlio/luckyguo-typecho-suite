<?php
/**
 * 归档
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php');
$archiveLimit = suite_int_option($this->options, 'archiveLimit', 1000, 100, 10000);
\Widget\Contents\Post\Recent::alloc('pageSize=' . $archiveLimit)->to($archives);
$archiveGroups = [];
while ($archives->next()) {
    $created = (int) $archives->created;
    $year = date('Y', $created);
    $month = date('m', $created);
    $archiveGroups[$year][$month][] = [
        'title' => (string) $archives->title,
        'permalink' => (string) $archives->permalink,
        'date' => date('Y-m-d', $created),
        'displayDate' => date('m.d', $created),
    ];
}
?>
<main id="main-content" class="page-shell archives-page">
    <header class="archive-heading"><p class="eyebrow">EVERYTHING, IN ORDER</p><h1><?php $this->title(); ?></h1></header>
    <?php if ($archiveGroups): ?>
    <div class="archive-timeline">
        <?php $archiveIndex = 0; ?>
        <?php foreach ($archiveGroups as $year => $months): ?>
        <?php $yearCount = array_sum(array_map('count', $months)); ?>
        <section class="archive-year">
            <header class="archive-year-heading"><strong><?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo $yearCount; ?> 篇</span></header>
            <div class="archive-year-content">
                <?php foreach ($months as $month => $posts): ?>
                <section class="archive-month">
                    <header class="archive-month-heading"><time datetime="<?php echo htmlspecialchars($year . '-' . $month, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($month, ENT_QUOTES, 'UTF-8'); ?> 月</time><span><?php echo count($posts); ?> 篇</span></header>
                    <div class="archive-list">
                        <?php foreach ($posts as $post): ?>
                        <a class="archive-row" href="<?php echo htmlspecialchars($post['permalink'], ENT_QUOTES, 'UTF-8'); ?>" style="--archive-delay: <?php echo min($archiveIndex++, 12) * 50; ?>ms">
                            <time datetime="<?php echo htmlspecialchars($post['date'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($post['displayDate'], ENT_QUOTES, 'UTF-8'); ?></time>
                            <strong><?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state"><span class="empty-index">00 / ARCHIVE</span><div><strong>归档还是空的</strong><span>写下第一篇文章后，这里会自动整理时间线。</span></div></div>
    <?php endif; ?>
</main>
<?php $this->need('footer.php'); ?>
