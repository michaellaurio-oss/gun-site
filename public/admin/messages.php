<?php
require __DIR__ . '/_admin.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $mid = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM contact_messages WHERE id = ?')->execute([$mid]);
        flash('Message deleted.');
    } else {
        db()->prepare('UPDATE contact_messages SET handled = 1 - handled WHERE id = ?')->execute([$mid]);
    }
    redirect_back();
}

$showAll = isset($_GET['all']);
$rows = db()->query('SELECT * FROM contact_messages' . ($showAll ? '' : ' WHERE handled = 0') . ' ORDER BY created_at DESC LIMIT 200')->fetchAll();

admin_header('Messages', 'messages');
?>
<div class="admin-head">
  <h1 class="page-title">Messages</h1>
  <a class="link-btn" href="<?= e(url('admin/messages.php', $showAll ? [] : ['all' => 1])) ?>"><?= $showAll ? 'Show only open messages' : 'Show handled messages too' ?></a>
</div>
<?php if (!$rows): ?><p class="muted">No <?= $showAll ? '' : 'open ' ?>messages.</p><?php endif; ?>
<div class="msg-list">
<?php foreach ($rows as $m):
    $gun = null;
    if ($m['stock_number']) {
        $g = db()->prepare('SELECT id FROM listings WHERE stock_number = ?');
        $g->execute([$m['stock_number']]);
        $gun = $g->fetchColumn();
    } ?>
  <article class="msg<?= $m['handled'] ? ' handled' : '' ?>">
    <div class="msg-head">
      <strong><?= e($m['name']) ?></strong>
      <a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a>
      <?php if ($m['phone']): ?><span><?= e($m['phone']) ?></span><?php endif; ?>
      <?php if ($m['stock_number']): ?><span class="mono">Stock #<?= $gun ? '<a href="' . e(url('admin/listing.php', ['id' => $gun])) . '">' . e($m['stock_number']) . '</a>' : e($m['stock_number']) ?></span><?php endif; ?>
      <span class="muted small"><?= e($m['created_at']) ?> UTC<?= $m['emailed'] ? ' · emailed' : '' ?></span>
    </div>
    <p><?= nl2br(e($m['message'])) ?></p>
    <div class="msg-actions">
      <form method="post" action="<?= e(url('admin/messages.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
        <button class="btn btn-outline" type="submit"><?= $m['handled'] ? 'Mark as open' : 'Mark as handled' ?></button></form>
      <form method="post" action="<?= e(url('admin/messages.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="action" value="delete">
        <button class="link-btn" type="submit">Delete</button></form>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php admin_footer();
