<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$user = \Widget\User::alloc();
$user->pass('administrator', true);
$db = \Typecho\Db::get();
$rows = $db->fetchAll($db->select('mid', 'name', 'slug', 'type')
    ->from('table.metas')->where('type IN ?', ['tag', 'category'])
    ->order('mid', \Typecho\Db::SORT_ASC));
$groups = [];
foreach ($rows as $row) {
    $key = (string) $row['type'] . '|' . (string) $row['slug'];
    $groups[$key][] = $row;
}
$conflicts = [];
foreach ($groups as $key => $members) {
    if (count($members) > 1 || substr($key, -1) === '|') {
        $conflicts[] = ['key' => $key, 'members' => $members];
    }
}
?><!doctype html>
<meta charset="utf-8">
<title>内容健康</title>
<h1>内容健康</h1>
<p>检查了 <?php echo count($rows); ?> 个分类/标签；发现 <?php echo count($conflicts); ?> 个 slug 冲突或空 slug。</p>
<?php if ($conflicts): ?><table><thead><tr><th>类型/slug</th><th>记录</th></tr></thead><tbody>
<?php foreach ($conflicts as $conflict): ?><tr><td><?php echo htmlspecialchars($conflict['key'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php foreach ($conflict['members'] as $member): ?>#<?php echo (int) $member['mid']; ?> <?php echo htmlspecialchars((string) $member['name'], ENT_QUOTES, 'UTF-8'); ?><br><?php endforeach; ?></td></tr><?php endforeach; ?>
</tbody></table><?php else: ?><p>未发现问题。</p><?php endif; ?>
