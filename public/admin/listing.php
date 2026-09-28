<?php
require __DIR__ . '/_admin.php';
require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$db = db();

function load_listing(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM listings WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

$listing = $id ? load_listing($id) : null;
if ($id && !$listing) {
    flash('That listing no longer exists.', 'err');
    redirect(url('admin/'));
}
$self = fn(array $q = []) => url('admin/listing.php', array_merge(['id' => $id ?: null], $q));

// ---------------- POST actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'save') {
        $status = (string)($_POST['status'] ?? 'draft');
        $data = [
            'firearm_id'          => (int)($_POST['firearm_id'] ?? 0),
            'stock_number'        => in_text('stock_number', 40),
            'title'               => in_text('title', 200),
            'status'              => in_array($status, LISTING_STATUSES, true) ? $status : 'draft',
            'price_usd'           => in_num('price_usd'),
            'new_used'            => ($_POST['new_used'] ?? '') === 'new' ? 'new' : 'used',
            'consignment'         => isset($_POST['consignment']) ? 1 : 0,
            'condition'           => in_array($_POST['condition'] ?? '', CONDITIONS, true) ? $_POST['condition'] : null,
            'condition_notes'     => in_text('condition_notes'),
            'est_round_count'     => in_int('est_round_count'),
            'finish_color'        => in_text('finish_color', 200),
            'modifications'       => in_text('modifications'),
            'magazines_included'  => in_int('magazines_included'),
            'original_box'        => in_bool('original_box'),
            'included_items'      => in_text('included_items'),
            'description'         => in_text('description', 10000),
            'additional_comments' => in_text('additional_comments'),
            'internal_notes'      => in_text('internal_notes', 10000),
            'listed_at'           => in_text('listed_at', 10),
            'sold_at'             => in_text('sold_at', 10),
            'sold_price_usd'      => in_num('sold_price_usd'),
        ];
        $assignedStock = null;
        if (in_array($data['status'], PUBLIC_STATUSES, true)) {
            if (!$data['listed_at']) {
                $data['listed_at'] = date('Y-m-d');
            }
            if (!$data['stock_number']) {
                $data['stock_number'] = $assignedStock = next_stock_number();
            }
        }
        if ($data['status'] === 'sold' && !$data['sold_at']) {
            $data['sold_at'] = date('Y-m-d');
        }
        $_SESSION['form'] = $data;   // kept so the form isn't lost on an error
        try {
            if (!$data['firearm_id']) {
                throw new RuntimeException('Choose a model.');
            }
            if ($id) {
                $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
                $db->prepare("UPDATE listings SET $set, updated_at = datetime('now') WHERE id = :id")->execute($data + ['id' => $id]);
                flash('Listing saved.');
            } else {
                $cols = implode(', ', array_keys($data));
                $vals = ':' . implode(', :', array_keys($data));
                $db->prepare("INSERT INTO listings ($cols) VALUES ($vals)")->execute($data);
                $id = (int)$db->lastInsertId();
                flash('Listing created. Add photos below.');
            }
            if ($assignedStock) {
                flash('Stock # ' . $assignedStock . ' assigned.');
            }
            unset($_SESSION['form']);
            redirect(url('admin/listing.php', ['id' => $id]));
        } catch (Throwable $e) {
            flash(friendly_db_error($e), 'err');
            redirect($self(['keep' => 1]));
        }
    }

    if (!$listing) {
        redirect(url('admin/'));
    }

    if ($action === 'upload') {
        $files = $_FILES['photos'] ?? null;
        $saved = 0;
        if ($files && is_array($files['name'])) {
            $next = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) FROM listing_photos WHERE listing_id = ' . $id)->fetchColumn();
            $hasPrimary = (int)$db->query('SELECT COUNT(*) FROM listing_photos WHERE is_primary = 1 AND listing_id = ' . $id)->fetchColumn();
            $ins = $db->prepare('INSERT INTO listing_photos (listing_id, file_path, caption, sort_order, is_primary) VALUES (?, ?, NULL, ?, ?)');
            foreach ($files['name'] as $i => $name) {
                $one = ['name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                try {
                    $path = save_listing_photo($one, photo_folder($listing));
                    $ins->execute([$id, $path, ++$next, $hasPrimary ? 0 : 1]);
                    $hasPrimary = 1;
                    $saved++;
                } catch (RuntimeException $e) {
                    flash($e->getMessage(), 'err');
                }
            }
        } else {
            flash('No photos were received. They may be larger than the server allows.', 'err');
        }
        if ($saved) {
            $db->prepare("UPDATE listings SET updated_at = datetime('now') WHERE id = ?")->execute([$id]);
            flash($saved . ' photo' . ($saved === 1 ? '' : 's') . ' added.');
        }
        redirect($self() . '#photos');
    }

    if ($action === 'photos') {
        $mine = $db->prepare('SELECT id, file_path FROM listing_photos WHERE listing_id = ?');
        $mine->execute([$id]);
        $own = array_column($mine->fetchAll(), 'file_path', 'id');
        $del = array_map('intval', (array)($_POST['delete'] ?? []));
        $primary = (int)($_POST['primary'] ?? 0);
        $db->beginTransaction();
        foreach ($own as $pid => $path) {
            if (in_array($pid, $del, true)) {
                $db->prepare('DELETE FROM listing_photos WHERE id = ?')->execute([$pid]);
                delete_photo_files($path);
                continue;
            }
            $cap = mb_substr(trim((string)($_POST['caption'][$pid] ?? '')), 0, 120);
            $ord = (int)($_POST['order'][$pid] ?? 0);
            $db->prepare('UPDATE listing_photos SET caption = ?, sort_order = ?, is_primary = ? WHERE id = ?')
               ->execute([$cap === '' ? null : $cap, $ord, $pid === $primary ? 1 : 0, $pid]);
        }
        // Make sure one photo is still the main one.
        $db->exec("UPDATE listing_photos SET is_primary = 1 WHERE id = (SELECT id FROM listing_photos WHERE listing_id = $id ORDER BY sort_order, id LIMIT 1)
                   AND NOT EXISTS (SELECT 1 FROM listing_photos WHERE listing_id = $id AND is_primary = 1)");
        $db->prepare("UPDATE listings SET updated_at = datetime('now') WHERE id = ?")->execute([$id]);
        $db->commit();
        flash('Photos updated.');
        redirect($self() . '#photos');
    }

    if ($action === 'delete') {
        if (($_POST['confirm'] ?? '') !== 'DELETE') {
            flash('Type DELETE to confirm.', 'err');
            redirect($self());
        }
        $ph = $db->prepare('SELECT file_path FROM listing_photos WHERE listing_id = ?');
        $ph->execute([$id]);
        foreach ($ph->fetchAll(PDO::FETCH_COLUMN) as $p) {
            delete_photo_files($p);
        }
        $db->prepare('DELETE FROM listings WHERE id = ?')->execute([$id]);
        flash('Listing deleted.');
        redirect(url('admin/'));
    }
    redirect($self());
}

// ---------------- form ----------------
$v = $listing ?? ['status' => 'draft', 'new_used' => 'used', 'consignment' => 0, 'firearm_id' => (int)($_GET['firearm_id'] ?? 0)];
if (isset($_GET['keep']) && !empty($_SESSION['form'])) {
    $v = array_merge($v, $_SESSION['form']);
}
unset($_SESSION['form']);
$val = fn(string $k) => e($v[$k] ?? '');

$models = $db->query("SELECT id, manufacturer, model, category, caliber, data_notes LIKE '%Caliber is a placeholder%' AS placeholder FROM firearms ORDER BY manufacturer COLLATE NOCASE, model COLLATE NOCASE")->fetchAll();

// Coming back from "Add a new firearm": pre-select the firearm that was just created.
$picked = (int)($_GET['firearm_id'] ?? 0);
$pickedChanged = false;
if ($picked && in_array($picked, array_map('intval', array_column($models, 'id')), true)) {
    $pickedChanged = $listing && $picked !== (int)$listing['firearm_id'];
    $v['firearm_id'] = $picked;
}
$photos = [];
if ($listing) {
    $st = $db->prepare('SELECT * FROM listing_photos WHERE listing_id = ? ORDER BY sort_order, id');
    $st->execute([$id]);
    $photos = $st->fetchAll();
}
$suggest = next_stock_number();

$heading = $listing ? ($listing['title'] ?: '') : 'New listing';
if ($listing && !$heading) {
    foreach ($models as $m) {
        if ((int)$m['id'] === (int)$listing['firearm_id']) {
            $heading = $m['manufacturer'] . ' ' . $m['model'];
        }
    }
}
$isPublic = $listing && in_array($listing['status'], ['coming_soon', 'available', 'on_hold', 'pending'], true);

admin_header($heading, 'listings');
?>
<div class="crumbs"><a href="<?= e(url('admin/')) ?>">Listings</a> / <?= e($heading) ?></div>
<div class="admin-head">
  <h1 class="page-title"><?= e($heading) ?></h1>
  <?php if ($isPublic): ?>
    <a class="btn btn-outline" href="<?= e(url('gun.php', $listing['stock_number'] ? ['stock' => $listing['stock_number']] : ['id' => $id])) ?>" target="_blank" rel="noopener">View on site</a>
  <?php elseif ($listing): ?>
    <span class="muted">Not visible on the site (<?= e(status_name($listing['status'])) ?>)</span>
  <?php endif; ?>
</div>

<form method="post" action="<?= e($self()) ?>" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <fieldset>
    <legend>The gun</legend>
    <div class="grid-2">
      <div class="field span-2 firearm-picker">
        <label for="firearm_id">Firearm</label>
        <?php if ($pickedChanged): ?><div class="alert alert-ok" role="status">Firearm changed. Click <strong>Save changes</strong> to keep it.</div><?php endif; ?>
        <div class="fsearch" hidden data-firearm-search>
          <?= icon('search', 16) ?>
          <label for="firearm-search" class="sr-only">Search firearms</label>
          <input id="firearm-search" type="search" placeholder="Type make or model to find it, e.g. glock 19" autocomplete="off">
        </div>
        <select class="select" id="firearm_id" name="firearm_id" required>
          <option value="">Choose a firearm…</option>
          <?php $mf = null;
          foreach ($models as $m):
              if ($m['manufacturer'] !== $mf) {
                  echo ($mf !== null ? '</optgroup>' : '') . '<optgroup label="' . e($m['manufacturer']) . '">';
                  $mf = $m['manufacturer'];
              }
              echo '<option value="' . (int)$m['id'] . '"' . ((int)$m['id'] === (int)($v['firearm_id'] ?? 0) ? ' selected' : '') . '>'
                  . e($m['manufacturer'] . ' ' . $m['model'] . ' — ' . ($m['caliber'] ?? 'caliber not set') . ($m['placeholder'] ? ' (caliber not confirmed)' : '')) . '</option>';
          endforeach;
          echo $mf !== null ? '</optgroup>' : ''; ?>
        </select>
        <p class="picker-empty muted" hidden data-firearm-none>No firearm matches that search.</p>
        <p class="picker-add">Not on the list?
          <a class="btn btn-outline btn-sm" data-add-firearm
             href="<?= e(url('admin/model.php', ['return' => 'listing', 'listing' => $id ?: null])) ?>"
             data-base="<?= e(url('admin/model.php', ['return' => 'listing', 'listing' => $id ?: null])) ?>">+ Add a new firearm</a></p>
        <span class="hint">Specs, caliber and CA roster status come from the firearm.
          <?php if (!empty($v['firearm_id'])): ?><a href="<?= e(url('admin/model.php', ['id' => $v['firearm_id']])) ?>">Edit this firearm</a><?php endif; ?>
          <?php if ($listing): ?> Adding a new firearm leaves this page, so save any other changes first.<?php endif; ?></span>
      </div>
      <div class="field">
        <label for="stock_number">Stock #</label>
        <input class="input mono" id="stock_number" name="stock_number" value="<?= $val('stock_number') ?>" placeholder="<?= e($suggest) ?>">
        <span class="hint">Leave empty and the next free number (<?= e($suggest) ?>) is filled in when the listing goes on the site. Or type your own.</span>
      </div>
      <div class="field">
        <label for="status">Status</label>
        <select class="select" id="status" name="status">
          <?php foreach (LISTING_STATUSES as $s) echo opt($s, status_name($s), $v['status'] ?? 'draft'); ?>
        </select>
      </div>
      <div class="field">
        <label for="new_used">New or used</label>
        <select class="select" id="new_used" name="new_used">
          <?= opt('used', 'Used', $v['new_used'] ?? 'used') ?><?= opt('new', 'New', $v['new_used'] ?? 'used') ?>
        </select>
        <span class="hint">New handguns must be on the CA roster.</span>
      </div>
      <div class="field">
        <label for="price_usd">Price ($)</label>
        <input class="input" id="price_usd" name="price_usd" inputmode="decimal" value="<?= isset($v['price_usd']) && $v['price_usd'] !== null ? e(num($v['price_usd'])) : '' ?>">
      </div>
      <div class="field span-2">
        <label for="title">Custom title <span class="muted">(optional)</span></label>
        <input class="input" id="title" name="title" value="<?= $val('title') ?>" placeholder="Leave empty to use make + model">
      </div>
      <label class="check span-2"><input type="checkbox" name="consignment" value="1"<?= !empty($v['consignment']) ? ' checked' : '' ?>> Consignment (not shown on the site)</label>
    </div>
  </fieldset>

  <fieldset>
    <legend>Condition</legend>
    <div class="grid-2">
      <div class="field">
        <label for="condition">Condition grade</label>
        <select class="select" id="condition" name="condition">
          <?= opt('', 'Not graded yet', $v['condition'] ?? '') ?>
          <?php foreach (CONDITIONS as $c) echo opt($c, $c, $v['condition'] ?? ''); ?>
        </select>
      </div>
      <div class="field">
        <label for="est_round_count">Estimated round count</label>
        <input class="input" id="est_round_count" name="est_round_count" inputmode="numeric" value="<?= $val('est_round_count') ?>">
      </div>
      <div class="field span-2">
        <label for="condition_notes">Condition notes</label>
        <textarea class="input" id="condition_notes" name="condition_notes" rows="3" placeholder="Wear, holster marks, finish, bore condition"><?= $val('condition_notes') ?></textarea>
      </div>
      <div class="field span-2">
        <label for="modifications">Modifications</label>
        <textarea class="input" id="modifications" name="modifications" rows="2" placeholder="Leave empty if factory original"><?= $val('modifications') ?></textarea>
      </div>
      <div class="field">
        <label for="magazines_included">Magazines included</label>
        <input class="input" id="magazines_included" name="magazines_included" inputmode="numeric" value="<?= $val('magazines_included') ?>">
      </div>
      <div class="field">
        <label for="original_box">Original box</label>
        <select class="select" id="original_box" name="original_box">
          <?= opt('', 'Not recorded', $v['original_box'] ?? '') ?><?= opt('1', 'Yes', $v['original_box'] ?? '') ?><?= opt('0', 'No', $v['original_box'] ?? '') ?>
        </select>
      </div>
      <div class="field span-2">
        <label for="included_items">What's included</label>
        <input class="input" id="included_items" name="included_items" value="<?= $val('included_items') ?>" placeholder="Magazines, case, manual, extras">
      </div>
      <div class="field span-2">
        <label for="finish_color">Finish / colour <span class="muted">(if different from the model's)</span></label>
        <input class="input" id="finish_color" name="finish_color" value="<?= $val('finish_color') ?>">
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Text on the listing</legend>
    <div class="field">
      <label for="description">About this gun <span class="muted">(optional; the model's description is shown too)</span></label>
      <textarea class="input" id="description" name="description" rows="4"><?= $val('description') ?></textarea>
    </div>
    <div class="field">
      <label for="additional_comments">Additional comments</label>
      <textarea class="input" id="additional_comments" name="additional_comments" rows="2"><?= $val('additional_comments') ?></textarea>
    </div>
  </fieldset>

  <fieldset>
    <legend>Dates &amp; sale</legend>
    <div class="grid-3">
      <div class="field"><label for="listed_at">Listed on</label><input class="input" type="date" id="listed_at" name="listed_at" value="<?= $val('listed_at') ?>"><span class="hint">Filled automatically when it goes on the site.</span></div>
      <div class="field"><label for="sold_at">Sold on</label><input class="input" type="date" id="sold_at" name="sold_at" value="<?= $val('sold_at') ?>"></div>
      <div class="field"><label for="sold_price_usd">Sold for ($)</label><input class="input" id="sold_price_usd" name="sold_price_usd" inputmode="decimal" value="<?= isset($v['sold_price_usd']) && $v['sold_price_usd'] !== null ? e(num($v['sold_price_usd'])) : '' ?>"></div>
    </div>
  </fieldset>

  <fieldset class="private">
    <legend>Private notes <span class="muted">(never shown on the site)</span></legend>
    <div class="field">
      <label for="internal_notes" class="sr-only">Private notes</label>
      <textarea class="input" id="internal_notes" name="internal_notes" rows="3"><?= $val('internal_notes') ?></textarea>
    </div>
  </fieldset>

  <div class="save-bar"><button class="btn btn-accent" type="submit"><?= $listing ? 'Save changes' : 'Create listing' ?></button>
    <a class="link-btn" href="<?= e(url('admin/')) ?>">Cancel</a></div>
</form>

<?php if ($listing): ?>
<section id="photos" class="admin-section">
  <h2 class="section-title" style="font-size:28px">Photos</h2>
  <p class="muted">Suggested shots: left side, right side, top, bore and muzzle, included items. Photos are resized and location data is removed automatically.</p>

  <form method="post" action="<?= e($self()) ?>" enctype="multipart/form-data" class="upload-form" data-resize>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <label for="photos-input" class="btn btn-dark">Choose photos…</label>
    <input id="photos-input" class="sr-only" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
    <span class="upload-status muted" aria-live="polite"></span>
    <noscript><button class="btn btn-outline" type="submit">Upload</button></noscript>
  </form>

  <?php if ($photos): ?>
  <form method="post" action="<?= e($self()) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="photos">
    <datalist id="captions"><option value="Left side"><option value="Right side"><option value="Top"><option value="Bore and muzzle"><option value="Included items"></datalist>
    <div class="photo-admin-grid">
      <?php foreach ($photos as $p): $pid = (int)$p['id']; ?>
        <div class="photo-admin">
          <a href="<?= e(photo_url($p['file_path'])) ?>" target="_blank" rel="noopener"><img src="<?= e(thumb_url($p['file_path'])) ?>" alt="<?= e($p['caption'] ?: 'Photo') ?>" loading="lazy"></a>
          <div class="field"><label for="cap-<?= $pid ?>">Caption</label><input class="input" id="cap-<?= $pid ?>" name="caption[<?= $pid ?>]" list="captions" value="<?= e($p['caption'] ?? '') ?>"></div>
          <div class="photo-admin-row">
            <div class="field"><label for="ord-<?= $pid ?>">Order</label><input class="input" id="ord-<?= $pid ?>" name="order[<?= $pid ?>]" inputmode="numeric" value="<?= (int)$p['sort_order'] ?>" style="width:72px"></div>
            <label class="check"><input type="radio" name="primary" value="<?= $pid ?>"<?= $p['is_primary'] ? ' checked' : '' ?>> Main photo</label>
            <label class="check danger"><input type="checkbox" name="delete[]" value="<?= $pid ?>"> Delete</label>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="save-bar"><button class="btn btn-outline" type="submit">Save photo changes</button></div>
  </form>
  <?php endif; ?>
</section>

<section class="admin-section danger-zone">
  <h2 style="font-size:20px">Delete listing</h2>
  <p class="muted">Usually it's better to set the status to Sold or Withdrawn, which keeps the record. Deleting removes the listing and its photos permanently.</p>
  <form method="post" action="<?= e($self()) ?>" class="admin-filters">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <label for="confirm" class="sr-only">Type DELETE to confirm</label>
    <input class="input" id="confirm" name="confirm" placeholder="Type DELETE" autocomplete="off">
    <button class="btn btn-outline danger" type="submit">Delete permanently</button>
  </form>
</section>
<?php endif; ?>
<?php admin_footer();
