<?php
// Tidy values: merge spellings of the same caliber / action into one ("Revolver, DAO" ->
// "Revolver, double action only"). Firearms are updated, and the merged spelling is remembered
// in value_aliases, so it is mapped automatically when it's typed again or comes from the CA
// roster (canonical_value()). Removing a remembered spelling doesn't change any firearm.
require __DIR__ . '/_admin.php';
require_login();

$fields = ['caliber' => 'Calibers', 'action' => 'Actions'];
$field = req_str('field', 'caliber');
if (!isset($fields[$field])) {
    $field = 'caliber';
}
$self = url('admin/values.php', ['field' => $field]);
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'merge') {
        $into = trim((string)($_POST['into'] ?? ''));
        if ($into === '__new') {
            $into = trim((string)($_POST['into_new'] ?? ''));
        }
        $from = array_values(array_filter(array_map('strval', (array)($_POST['values'] ?? [])), fn($v) => $v !== '' && $v !== $into));
        if ($into === '' || !$from) {
            flash('Tick the spellings to merge, and choose (or type) the one to keep.', 'err');
            redirect($self);
        }
        $changed = 0;
        $db->beginTransaction();
        // The kept spelling must not itself be an alias, or values would bounce between the two.
        $db->prepare('DELETE FROM value_aliases WHERE field = ? AND alias = ?')->execute([$field, $into]);
        $upd = $db->prepare("UPDATE firearms SET $field = ?, updated_at = datetime('now') WHERE $field = ?");
        $alias = $db->prepare('INSERT OR REPLACE INTO value_aliases (field, alias, canonical) VALUES (?, ?, ?)');
        $repoint = $db->prepare('UPDATE value_aliases SET canonical = ? WHERE field = ? AND canonical = ?');
        foreach ($from as $v) {
            $upd->execute([$into, $v]);
            $changed += $upd->rowCount();
            $alias->execute([$field, $v, $into]);
            $repoint->execute([$into, $field, $v]);   // older aliases that pointed at the merged spelling
        }
        $db->commit();
        flash('Merged ' . count($from) . ' spelling' . (count($from) === 1 ? '' : 's') . ' into "' . $into . '" (' . $changed . ' firearm' . ($changed === 1 ? '' : 's') . ' changed). They will be mapped automatically from now on.');
    } elseif ($action === 'forget') {
        $db->prepare('DELETE FROM value_aliases WHERE field = ? AND alias = ?')->execute([$field, (string)($_POST['alias'] ?? '')]);
        flash('Removed. That spelling is no longer changed automatically (firearms were not changed).');
    }
    redirect($self);
}

$values = $db->query("SELECT $field AS v, COUNT(*) AS n FROM firearms WHERE TRIM(COALESCE($field, '')) <> '' GROUP BY $field ORDER BY $field COLLATE NOCASE")->fetchAll();
$aliases = $db->prepare('SELECT alias, canonical FROM value_aliases WHERE field = ? ORDER BY canonical COLLATE NOCASE, alias COLLATE NOCASE');
$aliases->execute([$field]);
$aliases = $aliases->fetchAll();
$one = rtrim(strtolower($fields[$field]), 's');

admin_header('Tidy values', 'models');
?>
<div class="crumbs"><a href="<?= e(url('admin/models.php')) ?>">Firearms</a> / Tidy values</div>
<div class="admin-head"><h1 class="page-title">Tidy values</h1></div>
<p class="muted">Merge spellings that mean the same <?= e($one) ?>, e.g. ".38 Spl" and ".38 Special". Every firearm using a merged spelling is changed, and the spelling is remembered so it's changed automatically when it turns up again (typed in, or copied from the CA roster).</p>

<div class="status-pills">
  <?php foreach ($fields as $k => $label): ?>
    <a href="<?= e(url('admin/values.php', ['field' => $k])) ?>"<?= $k === $field ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<form method="post" action="<?= e($self) ?>" class="admin-form" data-confirm="Merge the ticked spellings? Every firearm using them will be changed.">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="merge">
  <input type="hidden" name="field" value="<?= e($field) ?>">
  <div class="bulk-bar values-bar">
    <label for="into">Merge ticked into</label>
    <select class="select choice-select" id="into" name="into" data-choice="<?= e($one) ?>">
      <?= opt('', '— choose the spelling to keep —', '') ?>
      <?php foreach ($values as $r) echo opt($r['v'], $r['v'], ''); ?>
      <option value="__new">A new spelling…</option>
    </select>
    <span class="choice-new">
      <label for="into__new" class="sr-only">New spelling</label>
      <input class="input" id="into__new" name="into_new" type="text" placeholder="Type the spelling to keep" autocomplete="off">
    </span>
    <button class="btn btn-accent" type="submit">Merge</button>
  </div>

  <div class="table-wrap">
  <table class="admin-table">
    <thead><tr><th scope="col"><span class="sr-only">Tick</span></th><th scope="col"><?= e(ucfirst($one)) ?></th><th scope="col">Firearms</th></tr></thead>
    <tbody>
    <?php foreach ($values as $i => $r): ?>
      <tr>
        <td><input type="checkbox" name="values[]" value="<?= e($r['v']) ?>" id="v<?= $i ?>"></td>
        <td><label for="v<?= $i ?>"><?= e($r['v']) ?></label></td>
        <td class="mono"><?php if ($field === 'caliber'): ?><a href="<?= e(url('admin/models.php', ['q' => $r['v']])) ?>"><?= (int)$r['n'] ?></a><?php else: ?><?= (int)$r['n'] ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</form>

<section class="admin-section">
  <h2 style="font-size:22px">Remembered spellings</h2>
  <?php if (!$aliases): ?>
    <p class="muted">None yet.</p>
  <?php else: ?>
    <p class="muted">These are changed automatically when they turn up.</p>
    <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th scope="col">Spelling</th><th scope="col">Becomes</th><th scope="col"><span class="sr-only">Remove</span></th></tr></thead>
      <tbody>
      <?php foreach ($aliases as $a): ?>
        <tr>
          <td><?= e($a['alias']) ?></td>
          <td><?= e($a['canonical']) ?></td>
          <td><form method="post" action="<?= e($self) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="forget"><input type="hidden" name="field" value="<?= e($field) ?>"><input type="hidden" name="alias" value="<?= e($a['alias']) ?>"><button class="link-btn" type="submit">Remove</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>
<?php admin_footer();
