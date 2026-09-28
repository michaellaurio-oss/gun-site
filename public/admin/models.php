<?php
require __DIR__ . '/_admin.php';
require_login();

$q = trim((string)($_GET['q'] ?? ''));
$show = (string)($_GET['show'] ?? '');
$where = [];
$args = [];
if ($q !== '') {
    $where[] = "(manufacturer || ' ' || model || ' ' || COALESCE(caliber,'')) LIKE ?";
    $args[] = '%' . $q . '%';
}
if ($show === 'placeholder') {
    $where[] = "data_notes LIKE '%Caliber is a placeholder%'";
}
if ($show === 'roster') {
    $where[] = "category = 'handgun' AND ca_rostered IS NULL";
}
if ($show === 'nospecs') {
    $where[] = 'barrel_length_in IS NULL AND weight_oz IS NULL';
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$st = db()->prepare(
    "SELECT f.id, f.manufacturer, f.model, f.category, f.caliber, f.ca_rostered, f.barrel_length_in, f.weight_oz,
            f.data_notes LIKE '%Caliber is a placeholder%' AS placeholder,
            (SELECT COUNT(*) FROM listings l WHERE l.firearm_id = f.id) AS listings
       FROM firearms f $sqlWhere
      ORDER BY manufacturer COLLATE NOCASE, model COLLATE NOCASE"
);
$st->execute($args);
$rows = $st->fetchAll();
$stat = db()->query("SELECT COUNT(*) total,
    SUM(data_notes LIKE '%Caliber is a placeholder%') placeholder,
    SUM(category = 'handgun' AND ca_rostered IS NULL) roster,
    SUM(barrel_length_in IS NULL AND weight_oz IS NULL) nospecs FROM firearms")->fetch();
$link = fn($s) => url('admin/models.php', ['show' => $s, 'q' => $q]);

admin_header('Models', 'models');
?>
<div class="admin-head">
  <h1 class="page-title">Models</h1>
  <a class="btn btn-accent" href="<?= e(url('admin/model.php')) ?>">+ Add model</a>
</div>
<p class="muted">One row per make/model: specs, caliber, description and CA roster status shared by every listing of that model.</p>

<div class="status-pills">
  <a href="<?= e($link('')) ?>"<?= $show === '' ? ' aria-current="true"' : '' ?>>All <span><?= (int)$stat['total'] ?></span></a>
  <a href="<?= e($link('placeholder')) ?>"<?= $show === 'placeholder' ? ' aria-current="true"' : '' ?>>Caliber to confirm <span><?= (int)$stat['placeholder'] ?></span></a>
  <a href="<?= e($link('roster')) ?>"<?= $show === 'roster' ? ' aria-current="true"' : '' ?>>Roster not checked <span><?= (int)$stat['roster'] ?></span></a>
  <a href="<?= e($link('nospecs')) ?>"<?= $show === 'nospecs' ? ' aria-current="true"' : '' ?>>No specs yet <span><?= (int)$stat['nospecs'] ?></span></a>
</div>

<form class="admin-filters" method="get" action="<?= e(url('admin/models.php')) ?>">
  <input type="hidden" name="show" value="<?= e($show) ?>">
  <label for="q" class="sr-only">Search models</label>
  <input class="input" id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Search make, model, caliber">
  <button class="btn btn-dark" type="submit">Search</button>
</form>

<div class="table-wrap">
<table class="admin-table">
  <thead><tr><th scope="col">Model</th><th scope="col">Type</th><th scope="col">Caliber</th><th scope="col">CA roster</th><th scope="col">Specs</th><th scope="col">Listings</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a class="strong" href="<?= e(url('admin/model.php', ['id' => $r['id']])) ?>"><?= e($r['manufacturer'] . ' ' . $r['model']) ?></a></td>
      <td><?= e(type_label($r['category'])) ?></td>
      <td><?= e($r['caliber'] ?? '') ?><?= $r['placeholder'] ? ' <span class="st st-on_hold">confirm</span>' : '' ?></td>
      <td><?= $r['category'] !== 'handgun' ? '<span class="muted">n/a</span>' : ($r['ca_rostered'] === null ? '<span class="st st-draft">not checked</span>' : ((int)$r['ca_rostered'] ? 'On roster' : 'Off roster')) ?></td>
      <td><?= $r['barrel_length_in'] !== null || $r['weight_oz'] !== null ? 'Yes' : '<span class="muted">—</span>' ?></td>
      <td class="mono"><a href="<?= e(url('admin/listing.php', ['firearm_id' => $r['id']])) ?>" title="Add a listing for this model">+</a> <?= (int)$r['listings'] ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted" style="padding:24px">No models match.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php admin_footer();
