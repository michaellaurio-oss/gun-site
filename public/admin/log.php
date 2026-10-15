<?php
// Activity log (owners only): who created / edited / deleted what, and when.
require __DIR__ . '/_admin.php';
require_owner();

$db = db();
$who = (int)($_GET['who'] ?? 0);
$type = trim((string)get_str('type', ''));
$q = trim((string)get_str('q', ''));
$page = max(1, (int)get_str('page', '1'));
$per = 100;

$where = [];
$args = [];
if ($who) {
    $where[] = 'admin_id = ?';
    $args[] = $who;
}
if ($type !== '') {
    $where[] = 'entity_type = ?';
    $args[] = $type;
}
if ($q !== '') {
    $where[] = "(summary LIKE ? ESCAPE '!' OR entity_label LIKE ? ESCAPE '!' OR details LIKE ? ESCAPE '!')";
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
    array_push($args, $like, $like, $like);
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total = $db->prepare("SELECT COUNT(*) FROM audit_log $w");
$total->execute($args);
$total = (int)$total->fetchColumn();
$st = $db->prepare("SELECT * FROM audit_log $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll();

$people = $db->query('SELECT id, username, first_name, last_name FROM admins ORDER BY last_name COLLATE NOCASE')->fetchAll();
$types = $db->query('SELECT DISTINCT entity_type FROM audit_log ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN);
$pages = max(1, (int)ceil($total / $per));
$link = fn(int $p) => url('admin/log.php', array_filter(['who' => $who ?: null, 'type' => $type ?: null, 'q' => $q ?: null, 'page' => $p > 1 ? $p : null]));

admin_header('Activity log', 'log');
?>
<div class="admin-head"><h1 class="page-title">Activity log</h1></div>
<form method="get" class="filters" action="<?= e(url('admin/log.php')) ?>">
  <label class="sr-only" for="who">Person</label>
  <select class="select" id="who" name="who"><option value="">Everyone</option>
    <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $who === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['first_name'] . ' ' . $p['last_name'] . ' (' . $p['username'] . ')') ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="type">What</label>
  <select class="select" id="type" name="type"><option value="">Everything</option>
    <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $type === $t ? ' selected' : '' ?>><?= e(ucfirst($t)) ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="q">Search</label>
  <input class="input" id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Search, e.g. U-0004 or price">
  <button class="btn btn-dark" type="submit">Filter</button>
</form>
<p class="muted"><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?>. Times are Los Angeles time.</p>

<?php if (!$rows): ?><p class="muted">Nothing logged yet.</p><?php endif; ?>
<?php if ($rows): ?>
<table class="table admin-table">
  <thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $target = null;
      if ($r['entity_type'] === 'listing' && $r['entity_id']) {
          $target = url('admin/listing.php', ['id' => $r['entity_id']]);
      } elseif ($r['entity_type'] === 'firearm' && $r['entity_id']) {
          $target = url('admin/model.php', ['id' => $r['entity_id']]);
      } ?>
    <tr>
      <td class="nowrap"><?= e(local_time($r['at'])) ?></td>
      <td><?= e($r['admin_name']) ?><br><span class="muted mono"><?= e($r['username']) ?></span></td>
      <td><strong><?= e($r['summary']) ?></strong>
        <?php if ($r['entity_label']): ?><br><?= $target ? '<a href="' . e($target) . '">' . e($r['entity_label']) . '</a>' : e($r['entity_label']) ?><?php endif; ?></td>
      <td class="small"><?= $r['details'] ? nl2br(e($r['details'])) : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php if ($pages > 1): ?>
  <p>
    <?php if ($page > 1): ?><a href="<?= e($link($page - 1)) ?>">← Newer</a><?php endif; ?>
    Page <?= $page ?> of <?= $pages ?>
    <?php if ($page < $pages): ?><a href="<?= e($link($page + 1)) ?>">Older →</a><?php endif; ?>
  </p>
<?php endif; ?>
<?php admin_footer();
