<?php
require __DIR__ . '/_admin.php';
require_login();

/**
 * Change status, filling listed_at / sold_at the first time and giving a listing that goes
 * on the site the next free stock number if it has none. Returns an error message or ''.
 */
function set_listing_status(int $id, string $status, array &$assigned): string
{
    if (!in_array($status, LISTING_STATUSES, true)) {
        return 'Unknown status.';
    }
    try {
        $st = db()->prepare('SELECT stock_number FROM listings WHERE id = ?');
        $st->execute([$id]);
        $stock = $st->fetchColumn();
        $newStock = null;
        if (in_array($status, PUBLIC_STATUSES, true) && ($stock === null || $stock === '')) {
            $newStock = next_stock_number();
        }
        db()->prepare(
            "UPDATE listings SET status = :s, updated_at = datetime('now'),
               stock_number = COALESCE(:n, stock_number),
               listed_at = CASE WHEN :s IN ('coming_soon','available','on_hold','pending') AND listed_at IS NULL THEN date('now') ELSE listed_at END,
               sold_at   = CASE WHEN :s = 'sold' AND sold_at IS NULL THEN date('now') ELSE sold_at END
             WHERE id = :id"
        )->execute([':s' => $status, ':n' => $newStock, ':id' => $id]);
        if ($newStock !== null) {
            $assigned[] = $newStock;
        }
        return '';
    } catch (PDOException $e) {
        return friendly_db_error($e);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $status = (string)($_POST['status'] ?? '');
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    if (isset($_POST['id'])) {
        $ids = [(int)$_POST['id']];
    }
    // Bulk edit of the other fields: blank = leave as it is.
    $cond = (string)($_POST['condition'] ?? '');
    $priceIn = trim(str_replace([',', '$'], '', (string)($_POST['price'] ?? '')));
    $pctIn = trim(str_replace('%', '', (string)($_POST['price_pct'] ?? '')));
    $consIn = (string)($_POST['consignment'] ?? '');
    $bulkErr = '';
    if ($cond !== '' && $cond !== '__none' && !in_array($cond, CONDITIONS, true)) {
        $bulkErr = 'Unknown condition.';
    }
    if ($priceIn !== '' && (!is_numeric($priceIn) || (float)$priceIn < 0)) {
        $bulkErr = 'The new price must be a number, zero or more.';
    }
    if ($pctIn !== '' && (!is_numeric($pctIn) || (float)$pctIn < -90 || (float)$pctIn > 100)) {
        $bulkErr = 'The price change must be between -90 and 100 percent.';
    }
    if ($priceIn !== '' && $pctIn !== '') {
        $bulkErr = 'Use either a new price or a percent change, not both.';
    }
    $hasBulk = $cond !== '' || $priceIn !== '' || $pctIn !== '' || in_array($consIn, ['0', '1'], true);
    if ($bulkErr !== '') {
        flash($bulkErr, 'err');
        redirect_back();
    }
    if ($ids && $status === '' && !$hasBulk) {
        flash('Choose what to change: a status, a condition, a price or consignment.', 'err');
        redirect_back();
    }
    $ok = 0;
    $fails = [];
    $assigned = [];
    foreach ($status === '' ? [] : $ids as $id) {
        $err = set_listing_status($id, $status, $assigned);
        $err === '' ? $ok++ : $fails[$err] = ($fails[$err] ?? 0) + 1;
        if ($err === '') {
            audit('status', 'listing', $id, 'Status set to ' . status_name($status), listing_label($id));
        }
    }
    if ($ok) {
        flash($ok . ' listing' . ($ok === 1 ? '' : 's') . ' set to ' . status_name($status) . '.');
    }
    if ($assigned) {
        flash('New stock number' . (count($assigned) === 1 ? '' : 's') . ' assigned: ' . (count($assigned) > 3 ? $assigned[0] . ' to ' . end($assigned) : implode(', ', $assigned)) . '.');
    }
    foreach ($fails as $msg => $n) {
        flash($n . ' not changed: ' . $msg, 'err');
    }
    if ($hasBulk) {
        $sel = db()->prepare('SELECT id, new_used, condition, price_usd, consignment FROM listings WHERE id = ?');
        $edited = 0;
        foreach ($ids as $id) {
            $sel->execute([$id]);
            $cur = $sel->fetch();
            if (!$cur) {
                continue;
            }
            $new = [];
            if ($cond !== '') {
                $new['condition'] = $cond === '__none' ? null : $cond;
            }
            if ($priceIn !== '') {
                $new['price_usd'] = round((float)$priceIn, 2);
            } elseif ($pctIn !== '' && $cur['price_usd'] !== null) {
                $new['price_usd'] = round((float)$cur['price_usd'] * (1 + (float)$pctIn / 100), 2);
            }
            if (in_array($consIn, ['0', '1'], true)) {
                $new['consignment'] = (int)$consIn;
            }
            if (($new['condition'] ?? '') === 'New' && $cur['new_used'] === 'used') {
                $fails['A used gun can\'t have the condition New'] = ($fails['A used gun can\'t have the condition New'] ?? 0) + 1;
                continue;
            }
            $diff = audit_diff($cur, $new);
            if (!$diff) {
                continue;
            }
            $set = implode(', ', array_map(fn($k) => "\"$k\" = :$k", array_keys($new)));
            db()->prepare("UPDATE listings SET $set, updated_at = datetime('now') WHERE id = :id")->execute($new + ['id' => $id]);
            audit('edited', 'listing', $id, 'Bulk edit', listing_label($id), $diff);
            $edited++;
        }
        if ($edited) {
            flash($edited . ' listing' . ($edited === 1 ? '' : 's') . ' updated.');
        }
        foreach ($fails as $msg => $n) {
            if (strpos($msg, 'condition New') !== false) {
                flash($n . ' not changed: ' . $msg . '.', 'err');
            }
        }
    }
    if (!$ids) {
        flash('Tick at least one listing first.', 'err');
    }
    redirect_back();
}

// ----- list -----
$q = trim(get_str('q', ''));
$fStatus = get_str('status', '');
$fCat = get_str('cat', '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;

$where = [];
$args = [];
if ($q !== '') {
    $where[] = "(f.manufacturer || ' ' || f.model || ' ' || COALESCE(l.stock_number,'') || ' ' || COALESCE(l.title,'') || ' ' || COALESCE(f.caliber,'')) LIKE ?";
    $args[] = '%' . $q . '%';
}
if ($fStatus === 'public') {
    $where[] = "l.status IN ('coming_soon','available','on_hold','pending')";
} elseif (in_array($fStatus, LISTING_STATUSES, true)) {
    $where[] = 'l.status = ?';
    $args[] = $fStatus;
}
if (in_array($fCat, CATEGORIES, true)) {
    $where[] = 'f.category = ?';
    $args[] = $fCat;
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = db()->prepare("SELECT COUNT(*) FROM listings l JOIN firearms f ON f.id = l.firearm_id $sqlWhere");
$cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);

$st = db()->prepare(
    "SELECT l.id, l.stock_number, l.status, l.price_usd, l.new_used, l.condition, l.updated_at, l.title,
            f.manufacturer, f.model, f.category, f.caliber, f.ca_rostered, f.ca_exempt,
            (SELECT COUNT(*) FROM listing_photos p WHERE p.listing_id = l.id) AS photos
       FROM listings l JOIN firearms f ON f.id = l.firearm_id
       $sqlWhere
      ORDER BY l.updated_at DESC, l.id DESC
      LIMIT $per OFFSET " . (($page - 1) * $per)
);
$st->execute($args);
$rows = $st->fetchAll();

$counts = [];
foreach (db()->query('SELECT status, COUNT(*) n FROM listings GROUP BY status') as $r) {
    $counts[$r['status']] = (int)$r['n'];
}
$qs = fn(array $over) => url('admin/', array_merge(['q' => $q, 'status' => $fStatus, 'cat' => $fCat], $over));

admin_header('Listings', 'listings');
?>
<div class="admin-head">
  <h1 class="page-title">Listings</h1>
  <a class="btn btn-accent" href="<?= e(url('admin/listing.php')) ?>">+ Add listing</a>
</div>

<div class="status-pills">
  <a href="<?= e($qs(['status' => '', 'page' => null])) ?>"<?= $fStatus === '' ? ' aria-current="true"' : '' ?>>All <span><?= array_sum($counts) ?></span></a>
  <a href="<?= e($qs(['status' => 'public', 'page' => null])) ?>"<?= $fStatus === 'public' ? ' aria-current="true"' : '' ?>>On the site <span><?= ($counts['coming_soon'] ?? 0) + ($counts['available'] ?? 0) + ($counts['on_hold'] ?? 0) + ($counts['pending'] ?? 0) ?></span></a>
  <?php foreach (LISTING_STATUSES as $s): ?>
    <a href="<?= e($qs(['status' => $s, 'page' => null])) ?>"<?= $fStatus === $s ? ' aria-current="true"' : '' ?>><?= e(status_name($s)) ?> <span><?= $counts[$s] ?? 0 ?></span></a>
  <?php endforeach; ?>
</div>

<form class="admin-filters" method="get" action="<?= e(url('admin/')) ?>">
  <input type="hidden" name="status" value="<?= e($fStatus) ?>">
  <label for="q" class="sr-only">Search</label>
  <input class="input" id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Search make, model, stock #, caliber">
  <label for="cat" class="sr-only">Type</label>
  <select class="select" id="cat" name="cat">
    <?= opt('', 'All types', $fCat) ?>
    <?php foreach (CATEGORIES as $c) echo opt($c, type_label($c), $fCat); ?>
  </select>
  <button class="btn btn-dark" type="submit">Search</button>
</form>

<form method="post" action="<?= e(url('admin/')) ?>" id="bulk">
  <?= csrf_field() ?>
  <div class="bulk-bar">
    <strong>Change ticked listings:</strong>
    <label for="bulk-status">Status</label>
    <select class="select" id="bulk-status" name="status">
      <?= opt('', '— no change —', '') ?>
      <?php foreach (LISTING_STATUSES as $s) echo opt($s, status_name($s), ''); ?>
    </select>
    <label for="bulk-condition">Condition</label>
    <select class="select" id="bulk-condition" name="condition">
      <?= opt('', '— no change —', '') ?><?= opt('__none', 'Not graded yet', '') ?>
      <?php foreach (CONDITIONS as $c) echo opt($c, $c, ''); ?>
    </select>
    <label for="bulk-price">New price $</label>
    <input class="input" id="bulk-price" name="price" inputmode="decimal" size="8" placeholder="no change">
    <label for="bulk-pct">or change price by %</label>
    <input class="input" id="bulk-pct" name="price_pct" inputmode="decimal" size="6" placeholder="e.g. -10">
    <label for="bulk-cons">Consignment</label>
    <select class="select" id="bulk-cons" name="consignment">
      <?= opt('', '— no change —', '') ?><?= opt('1', 'Yes', '') ?><?= opt('0', 'No', '') ?>
    </select>
    <button class="btn btn-outline" type="submit">Apply to ticked</button>
    <span class="muted"><?= $total ?> listing<?= $total === 1 ? '' : 's' ?></span>
  </div>

  <div class="table-wrap">
  <table class="admin-table">
    <thead><tr>
      <th scope="col"><input type="checkbox" id="check-all" aria-label="Tick all on this page"></th>
      <th scope="col">Stock #</th><th scope="col">Gun</th><th scope="col">Status</th>
      <th scope="col">Price</th><th scope="col">New / used</th><th scope="col">Photos</th><th scope="col">Updated</th>
    </tr></thead>
    <tbody>
    <?php $justAdded = (int)($_GET['new'] ?? 0); ?>
    <?php foreach ($rows as $r): ?>
      <tr<?= (int)$r['id'] === $justAdded ? ' class="just-added"' : '' ?>>
        <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Tick <?= e($r['manufacturer'] . ' ' . $r['model']) ?>"></td>
        <td class="mono"><?= $r['stock_number'] ? e($r['stock_number']) : '<span class="muted">—</span>' ?></td>
        <td><a class="strong" href="<?= e(url('admin/listing.php', ['id' => $r['id']])) ?>"><?= e($r['title'] ?: $r['manufacturer'] . ' ' . $r['model']) ?></a>
          <div class="muted small"><?= e(type_label($r['category'])) ?> · <?= e($r['caliber'] ?? '') ?></div></td>
        <td><span class="st st-<?= e($r['status']) ?>"><?= e(status_name($r['status'])) ?></span></td>
        <td class="mono"><?= $r['price_usd'] !== null ? e(money($r['price_usd'])) : '<span class="muted">—</span>' ?></td>
        <td><?= $r['new_used'] === 'new' ? 'New' : 'Used' ?><?= $r['condition'] ? ' · ' . e($r['condition']) : '' ?>
          <?php if (leo_only($r['new_used'], $r['category'], $r['ca_rostered'], $r['ca_exempt'])): ?><div><span class="leo-badge-static small-leo"><?= e(LEO_LABEL) ?></span></div><?php endif; ?></td>
        <td class="mono"><?= (int)$r['photos'] ?: '<span class="muted">0</span>' ?></td>
        <td class="small muted"><?= e(substr((string)$r['updated_at'], 0, 10)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted" style="padding:24px">No listings match.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</form>

<?php if ($pages > 1): ?>
<nav class="pages" aria-label="Pages" style="margin-top:20px">
  <?php for ($n = 1; $n <= $pages; $n++): ?>
    <a class="pbtn" href="<?= e($qs(['page' => $n])) ?>"<?= $n === $page ? ' aria-current="page"' : '' ?>><?= $n ?></a>
  <?php endfor; ?>
</nav>
<?php endif; ?>
<?php admin_footer();
