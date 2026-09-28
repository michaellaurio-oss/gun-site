<?php
require __DIR__ . '/_admin.php';
require_login();

$db = db();
$today = date('Y-m-d');

function roster_row_label(array $r): string
{
    $bits = array_filter([$r['caliber'], $r['barrel_length_in'] !== null ? num($r['barrel_length_in']) . '" barrel' : null, $r['gun_type'], $r['material']]);
    return $r['model'] . ' — ' . implode(', ', $bits);
}

// ---------------- actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string)($_POST['action'] ?? '');
    $fid = (int)($_POST['firearm_id'] ?? 0);
    try {
        if ($action === 'refresh' || $action === 'upload') {
            if ($action === 'refresh') {
                $html = roster_download();
            } else {
                $f = $_FILES['roster_file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                    throw new RuntimeException('No file was received.');
                }
                $html = (string)file_get_contents($f['tmp_name']);
            }
            $s = roster_import($db, roster_parse($html));
            flash('Roster updated: ' . $s['rows'] . ' handguns. ' . $s['confirmed'] . ' of your firearms re-confirmed as still listed.'
                . ($s['dropped'] ? ' ' . $s['dropped'] . ' no longer listed: see the warning below.' : ''));
        } elseif ($action === 'link') {
            $path = (string)($_POST['path'] ?? '');
            $ok = $db->prepare('SELECT COUNT(*) FROM ca_roster WHERE detail_path = ?');
            $ok->execute([$path]);
            if (!(int)$ok->fetchColumn()) {
                throw new RuntimeException('That roster entry no longer exists. Refresh the page and try again.');
            }
            $db->prepare("UPDATE firearms SET ca_rostered = 1, ca_roster_checked_on = ?, ca_roster_entry = ?, updated_at = datetime('now') WHERE id = ? AND category = 'handgun'")
               ->execute([$today, $path, $fid]);
            flash('Marked on the CA roster.');
        } elseif ($action === 'off') {
            $db->prepare("UPDATE firearms SET ca_rostered = 0, ca_roster_checked_on = ?, ca_roster_entry = NULL, updated_at = datetime('now') WHERE id = ? AND category = 'handgun'")
               ->execute([$today, $fid]);
            flash('Marked not on the CA roster.');
        } elseif ($action === 'clear') {
            $db->prepare("UPDATE firearms SET ca_rostered = NULL, ca_roster_checked_on = NULL, ca_roster_entry = NULL, updated_at = datetime('now') WHERE id = ?")
               ->execute([$fid]);
            flash('Roster status cleared (not checked).');
        } elseif ($action === 'confirm_exact') {
            $roster = roster_prepare($db->query('SELECT * FROM ca_roster')->fetchAll());
            $set = $db->prepare("UPDATE firearms SET ca_rostered = 1, ca_roster_checked_on = ?, ca_roster_entry = ?, updated_at = datetime('now') WHERE id = ?");
            $n = 0;
            foreach ($db->query("SELECT id, manufacturer, model FROM firearms WHERE category = 'handgun' AND ca_rostered IS NULL")->fetchAll() as $f) {
                $m = roster_matches($f, $roster, 1);
                if ($m && $m[0]['score'] >= 100) {
                    $set->execute([$today, $m[0]['row']['detail_path'], $f['id']]);
                    $n++;
                }
            }
            flash($n . ' firearm' . ($n === 1 ? '' : 's') . ' marked on the CA roster.');
        }
    } catch (Throwable $e) {
        flash(friendly_db_error($e), 'err');
    }
    redirect_back();
}

// ---------------- page ----------------
$status = roster_status($db);
$show = (string)($_GET['show'] ?? 'review');
$q = trim((string)($_GET['q'] ?? ''));
$for = (int)($_GET['for'] ?? 0);

$roster = $status['rows'] ? roster_prepare($db->query('SELECT * FROM ca_roster')->fetchAll()) : [];
$byPath = [];
foreach ($roster as $r) {
    $byPath[$r['detail_path']] = $byPath[$r['detail_path']] ?? $r;
}
$dropped = roster_dropped($db);

$where = "category = 'handgun'" . ($show === 'review' ? ' AND ca_rostered IS NULL' : '');
$handguns = $db->query("SELECT * FROM firearms WHERE $where ORDER BY manufacturer COLLATE NOCASE, model COLLATE NOCASE")->fetchAll();
$counts = $db->query("SELECT COUNT(*) total, SUM(ca_rostered IS NULL) unchecked, SUM(ca_rostered = 1) onr, SUM(ca_rostered = 0) offr FROM firearms WHERE category = 'handgun'")->fetch();

$exactCount = 0;
$matches = [];
foreach ($handguns as $f) {
    $matches[$f['id']] = $roster ? roster_matches($f, $roster, 3) : [];
    if ($f['ca_rostered'] === null && $matches[$f['id']] && $matches[$f['id']][0]['score'] >= 100) {
        $exactCount++;
    }
}
$forFirearm = null;
if ($for) {
    $st = $db->prepare("SELECT * FROM firearms WHERE id = ? AND category = 'handgun'");
    $st->execute([$for]);
    $forFirearm = $st->fetch() ?: null;
}
$results = ($q !== '' && $status['rows']) ? roster_search($db, $q, 40) : [];

admin_header('CA roster', 'roster');
?>
<div class="admin-head">
  <h1 class="page-title">CA handgun roster</h1>
</div>

<section class="roster-status">
  <?php if ($status['rows']): ?>
    <p><strong><?= number_format($status['rows']) ?> handguns</strong> on the roster, downloaded <?= e(substr((string)$status['imported_at'], 0, 16)) ?> UTC from
      <a href="<?= e(ROSTER_URL) ?>" target="_blank" rel="noopener">oag.ca.gov</a>. Models are added and removed regularly, so refresh about once a month.</p>
  <?php else: ?>
    <p><strong>The roster hasn't been downloaded yet.</strong> Download it to check your handguns.</p>
  <?php endif; ?>
  <div class="roster-actions">
    <form method="post" action="<?= e(url('admin/roster.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="refresh">
      <button class="btn btn-dark" type="submit"><?= $status['rows'] ? 'Download the latest roster' : 'Download the roster' ?></button></form>
    <details>
      <summary>Download not working? Upload the page instead</summary>
      <form method="post" enctype="multipart/form-data" action="<?= e(url('admin/roster.php')) ?>" class="admin-filters" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="action" value="upload">
        <p class="muted small" style="margin:0">Open the roster page in your browser, save it (Ctrl+S, "Webpage, HTML only"), then choose the saved file here.</p>
        <label for="roster_file" class="sr-only">Saved roster page</label>
        <input id="roster_file" name="roster_file" type="file" accept=".html,.htm,text/html" required>
        <button class="btn btn-outline" type="submit">Upload</button>
      </form>
    </details>
  </div>
</section>

<?php if ($dropped): ?>
<div class="alert alert-err" role="alert">
  <strong>No longer on the roster:</strong> these firearms were matched to roster entries that have since been removed. New ones can no longer be sold; used ones still can.
  <ul class="dropped">
    <?php foreach ($dropped as $f): ?>
      <li><?= e($f['manufacturer'] . ' ' . $f['model']) ?>
        <form method="post" action="<?= e(url('admin/roster.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="off"><input type="hidden" name="firearm_id" value="<?= (int)$f['id'] ?>">
          <button class="btn btn-outline btn-sm" type="submit">Mark not on roster</button></form>
        <a href="<?= e(url('admin/roster.php', ['q' => $f['manufacturer'] . ' ' . $f['model'], 'for' => $f['id']])) ?>">Find a new entry</a></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<section class="admin-section" style="max-width:none">
  <h2 class="section-title" style="font-size:24px">Search the roster</h2>
  <form class="admin-filters" method="get" action="<?= e(url('admin/roster.php')) ?>">
    <?php if ($forFirearm): ?><input type="hidden" name="for" value="<?= (int)$forFirearm['id'] ?>"><?php endif; ?>
    <label for="q" class="sr-only">Search the roster</label>
    <input class="input" id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="e.g. glock 19, 686, ruger lcr" <?= $status['rows'] ? '' : 'disabled' ?>>
    <button class="btn btn-dark" type="submit" <?= $status['rows'] ? '' : 'disabled' ?>>Search</button>
    <?php if ($forFirearm): ?><span class="muted">Choosing an entry for <strong><?= e($forFirearm['manufacturer'] . ' ' . $forFirearm['model']) ?></strong> · <a href="<?= e(url('admin/roster.php', ['q' => $q])) ?>">cancel</a></span><?php endif; ?>
  </form>
  <?php if ($q !== ''): ?>
    <?php if (!$results): ?><p class="muted">Nothing on the roster matches "<?= e($q) ?>".</p><?php else: ?>
    <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th scope="col">Manufacturer</th><th scope="col">Model</th><th scope="col">Type</th><th scope="col">Barrel</th><th scope="col">Caliber</th><th scope="col">Listed until</th><th scope="col"></th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><?= e($r['manufacturer']) ?></td>
          <td><a href="<?= e(ROSTER_SITE . $r['detail_path']) ?>" target="_blank" rel="noopener"><?= e($r['model']) ?></a><?= $r['court_order'] ? ' <span class="muted small" title="Added by court order (Boland v. Bonta)">*</span>' : '' ?></td>
          <td><?= e($r['gun_type']) ?></td>
          <td class="mono"><?= $r['barrel_length_in'] !== null ? e(num($r['barrel_length_in'])) . '"' : '' ?></td>
          <td><?= e($r['caliber']) ?></td>
          <td class="small"><?= e($r['expires_on']) ?></td>
          <td class="nowrap">
            <?php if ($forFirearm): ?>
              <form method="post" action="<?= e(url('admin/roster.php', ['show' => 'all'])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="firearm_id" value="<?= (int)$forFirearm['id'] ?>"><input type="hidden" name="path" value="<?= e($r['detail_path']) ?>">
                <button class="btn btn-accent btn-sm" type="submit">Use for this firearm</button></form>
            <?php else: ?>
              <a class="btn btn-outline btn-sm" href="<?= e(url('admin/model.php', ['roster' => $r['detail_path']])) ?>">Add as a new firearm</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if (count($results) >= 40): ?><p class="muted small">Showing the first 40. Add more words to narrow it down.</p><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="admin-section" style="max-width:none">
  <h2 class="section-title" style="font-size:24px">Your handguns</h2>
  <div class="status-pills">
    <a href="<?= e(url('admin/roster.php', ['show' => 'review'])) ?>"<?= $show === 'review' ? ' aria-current="true"' : '' ?>>To check <span><?= (int)$counts['unchecked'] ?></span></a>
    <a href="<?= e(url('admin/roster.php', ['show' => 'all'])) ?>"<?= $show === 'all' ? ' aria-current="true"' : '' ?>>All handguns <span><?= (int)$counts['total'] ?></span></a>
    <span class="muted small">On roster <?= (int)$counts['onr'] ?> · Not on roster <?= (int)$counts['offr'] ?></span>
  </div>

  <?php if (!$status['rows']): ?>
    <p class="muted">Download the roster first to see matches.</p>
  <?php elseif ($exactCount): ?>
    <form method="post" action="<?= e(url('admin/roster.php')) ?>" class="confirm-bar"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_exact">
      <span><strong><?= $exactCount ?></strong> firearm<?= $exactCount === 1 ? ' has' : 's have' ?> an exact name match on the roster (marked <span class="st st-available">Exact</span> below). Check them, then:</span>
      <button class="btn btn-accent" type="submit">Confirm all <?= $exactCount ?> exact matches</button>
    </form>
  <?php endif; ?>

  <?php if (!$handguns): ?><p class="muted"><?= $show === 'review' ? 'Nothing left to check.' : 'No handguns in Firearms yet.' ?></p><?php endif; ?>
  <div class="roster-list">
  <?php foreach ($handguns as $f):
      $fid = (int)$f['id'];
      $linked = $f['ca_roster_entry'] ? ($byPath[$f['ca_roster_entry']] ?? null) : null; ?>
    <article class="roster-item">
      <div class="roster-item-head">
        <a class="strong" href="<?= e(url('admin/model.php', ['id' => $fid])) ?>"><?= e($f['manufacturer'] . ' ' . $f['model']) ?></a>
        <?php if ($f['ca_rostered'] === null): ?><span class="st st-draft">Not checked</span>
        <?php elseif ((int)$f['ca_rostered'] === 1): ?><span class="st st-available">On roster</span>
        <?php else: ?><span class="st st-on_hold">Not on roster</span><?php endif; ?>
        <?php if ($f['ca_roster_checked_on']): ?><span class="muted small">checked <?= e($f['ca_roster_checked_on']) ?></span><?php endif; ?>
      </div>

      <?php if ($linked): ?>
        <p class="small" style="margin:0">Roster entry: <a href="<?= e(ROSTER_SITE . $linked['detail_path']) ?>" target="_blank" rel="noopener"><?= e(roster_row_label($linked)) ?></a></p>
      <?php endif; ?>

      <?php if ($f['ca_rostered'] === null || $show === 'all'): ?>
        <?php $cands = array_filter($matches[$fid] ?? [], fn($m) => !$linked || $m['row']['detail_path'] !== $linked['detail_path']); ?>
        <?php if ($cands): ?>
          <ul class="cands">
          <?php foreach ($cands as $m): $r = $m['row']; ?>
            <li>
              <form method="post" action="<?= e(url('admin/roster.php', ['show' => $show])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="firearm_id" value="<?= $fid ?>"><input type="hidden" name="path" value="<?= e($r['detail_path']) ?>">
                <button class="btn btn-outline btn-sm" type="submit">On roster: this one</button></form>
              <?= $m['score'] >= 100 ? '<span class="st st-available">Exact</span>' : '<span class="st">Possible</span>' ?>
              <a href="<?= e(ROSTER_SITE . $r['detail_path']) ?>" target="_blank" rel="noopener"><?= e(roster_row_label($r)) ?></a>
            </li>
          <?php endforeach; ?>
          </ul>
        <?php elseif ($status['rows'] && !$linked): ?>
          <p class="muted small" style="margin:0">No similar name on the roster.</p>
        <?php endif; ?>
      <?php endif; ?>

      <div class="roster-item-actions">
        <a class="btn btn-outline btn-sm" href="<?= e(url('admin/roster.php', ['q' => $f['manufacturer'] . ' ' . preg_replace('/\bG(?=\d)/', '', $f['model']), 'for' => $fid])) ?>">Search the roster</a>
        <?php if ((string)$f['ca_rostered'] !== '0'): ?>
          <form method="post" action="<?= e(url('admin/roster.php', ['show' => $show])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="off"><input type="hidden" name="firearm_id" value="<?= $fid ?>">
            <button class="btn btn-outline btn-sm" type="submit">Not on roster</button></form>
        <?php endif; ?>
        <?php if ($f['ca_rostered'] !== null): ?>
          <form method="post" action="<?= e(url('admin/roster.php', ['show' => $show])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><input type="hidden" name="firearm_id" value="<?= $fid ?>">
            <button class="link-btn" type="submit">Reset to not checked</button></form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php admin_footer();
