<?php
// Demo listings: add the made-up sample listings (same as demo_seed.sql) next to the real
// ones, or remove them again. Only adds; never changes firearms (the demo seed's CA roster
// flags are left out) and never touches real listings. Demo rows are found by DEMO_MARK in
// internal_notes, so "Remove" deletes exactly what "Add" created.
require __DIR__ . '/_admin.php';
require_login();

const DEMO_MARK = 'DEMO internal note: this text must never appear on the public site.';

// [firearm slug (or several to try in order), stock, status, price, new_used, condition, condition notes, rounds, mods, mags, box, included, comments, listed_at]
const DEMO_LISTINGS = [
    [['glock-19-gen3', 'glock-g19'], 'D-1001', 'available', 499, 'used', 'Very Good', 'Light holster wear on the slide edges. Bore bright and clean. Frame shows normal handling marks.', 800, null, 3, 1, 'Three factory magazines, loader, cable lock, manual.', 'Recently cleaned and function-checked.', '2026-09-20'],
    [['glock-19-gen3', 'glock-g19'], 'D-1002', 'available', 599, 'new', 'New', 'New in box, unfired.', 0, null, 3, 1, 'Three factory magazines, loader, cable lock, manual.', null, '2026-09-25'],
    [['glock-19-gen3', 'glock-g19'], 'D-1003', 'on_hold', 525, 'used', 'Excellent', 'Very minor wear at the muzzle. Bore excellent.', 300, 'AmeriGlo tritium night sights.', 2, 1, 'Two magazines, original case.', null, '2026-09-10'],
    [['glock-19-gen3', 'glock-g19'], 'D-1012', 'available', 399, 'used', 'Fair', 'Finish worn at the muzzle and slide serrations; scratches on the frame. Mechanically sound, bore good.', 3000, null, 1, 0, 'One magazine.', 'Priced to move. A solid shooter.', '2026-08-28'],
    ['cz-75-sp-01', 'D-1004', 'available', 699, 'used', 'Excellent', 'Light wear on the slide release and muzzle crown. Bore bright.', 500, null, 2, 1, 'Two magazines, factory case, manual.', null, '2026-09-22'],
    ['cz-75-sp-01', 'D-1005', 'pending', 629, 'used', 'Good', 'Holster wear on the slide and frame rails. Bore good with light fouling.', 2000, 'Cajun Gun Works trigger kit, extended safety.', 2, 0, 'Two magazines.', null, '2026-09-01'],
    ['cz-75-d-compact', 'D-1006', 'available', 579, 'used', 'Like New', 'No visible wear. Test-fired only per the previous owner.', 50, null, 2, 1, 'Two magazines, factory case, manual, cleaning rod.', null, '2026-09-18'],
    ['cz-75-d-compact', null, 'coming_soon', null, 'used', null, null, null, null, null, null, null, null, '2026-09-27'],
    ['mossberg-500-retrograde', 'D-1008', 'available', 429, 'used', 'Very Good', 'Small handling marks on the walnut stock. Bluing excellent. Bore bright.', 250, null, null, 0, 'Cable lock.', null, '2026-09-15'],
    ['mossberg-500-retrograde', 'D-1009', 'available', 504, 'new', 'New', 'New in box.', 0, null, null, 1, 'Original box, manual, cable lock.', null, '2026-09-26'],
    ['marlin-1894-trapper-357', 'D-1010', 'available', 1349, 'used', 'Excellent', 'A few light marks on the laminate stock. Action smooth. Bore excellent.', 200, 'Leather sling and QD swivel studs added.', null, 1, 'Original box, manual.', null, '2026-09-12'],
    ['marlin-1894-trapper-357', 'D-1011', 'coming_soon', null, 'used', null, null, null, null, null, null, null, null, '2026-09-24'],
];
// Placeholder photos shipped in public/assets/demo/ for these two listings.
const DEMO_PHOTO_STOCKS = ['D-1001', 'D-1010'];
const DEMO_PHOTOS = [['left', 'Left side'], ['right', 'Right side'], ['top', 'Top'], ['bore', 'Bore and muzzle'], ['included', 'Included items']];

function demo_ids(): array
{
    $st = db()->prepare('SELECT id FROM listings WHERE internal_notes = ?');
    $st->execute([DEMO_MARK]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $pdo = db();
    if (($_POST['action'] ?? '') === 'add') {
        $added = 0;
        $skipped = [];
        $firearm = $pdo->prepare('SELECT id FROM firearms WHERE slug = ?');
        $exists = $pdo->prepare('SELECT 1 FROM listings WHERE stock_number = ?');
        $existsNoStock = $pdo->prepare('SELECT 1 FROM listings WHERE stock_number IS NULL AND firearm_id = ? AND internal_notes = ?');
        $insert = $pdo->prepare(
            'INSERT INTO listings (firearm_id, stock_number, status, price_usd, new_used, condition, condition_notes, est_round_count,
                                   modifications, magazines_included, original_box, included_items, additional_comments, internal_notes, listed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $photo = $pdo->prepare('INSERT INTO listing_photos (listing_id, file_path, caption, sort_order, is_primary) VALUES (?, ?, ?, ?, ?)');
        foreach (DEMO_LISTINGS as [$slugs, $stock, $status, $price, $nu, $cond, $notes, $rounds, $mods, $mags, $box, $incl, $comments, $listed]) {
            $slugs = (array)$slugs;
            $label = $stock ?? ($slugs[0] . ' (coming soon, no stock #)');
            $fid = false;
            foreach ($slugs as $slug) {
                $firearm->execute([$slug]);
                if (($fid = $firearm->fetchColumn()) !== false) {
                    break;
                }
            }
            if ($fid === false) {
                $skipped[] = "$label: firearm \"" . implode('" / "', $slugs) . "\" is not in the Firearms list";
                continue;
            }
            if ($stock !== null) {
                $exists->execute([$stock]);
                if ($exists->fetchColumn()) {
                    $skipped[] = "$label: already exists";
                    continue;
                }
            } else {
                $existsNoStock->execute([$fid, DEMO_MARK]);
                if ($existsNoStock->fetchColumn()) {
                    $skipped[] = "$label: already exists";
                    continue;
                }
            }
            try {
                $pdo->beginTransaction();
                $insert->execute([$fid, $stock, $status, $price, $nu, $cond, $notes, $rounds, $mods, $mags, $box, $incl, $comments, DEMO_MARK, $listed]);
                $id = (int)$pdo->lastInsertId();
                if (in_array($stock, DEMO_PHOTO_STOCKS, true)) {
                    foreach (DEMO_PHOTOS as $i => [$file, $caption]) {
                        $photo->execute([$id, 'assets/demo/' . $file . '.jpg', $caption, $i + 1, $i === 0 ? 1 : 0]);
                    }
                }
                $pdo->commit();
                $added++;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $skipped[] = "$label: " . friendly_db_error($e);
            }
        }
        flash($added . ' demo listing' . ($added === 1 ? '' : 's') . ' added.');
        foreach ($skipped as $s) {
            flash('Skipped ' . $s, 'err');
        }
    } elseif (($_POST['action'] ?? '') === 'remove') {
        if (trim((string)($_POST['confirm'] ?? '')) !== 'DELETE') {
            flash('Type DELETE to confirm.', 'err');
            redirect(url('admin/demo.php'));
        }
        $ids = demo_ids();
        if ($ids) {
            $in = implode(',', $ids);  // integers from the database
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM listing_photos WHERE listing_id IN ($in)");
            $pdo->exec("DELETE FROM listings WHERE id IN ($in)");
            $pdo->commit();
        }
        flash(count($ids) . ' demo listing' . (count($ids) === 1 ? '' : 's') . ' removed.');
    }
    redirect(url('admin/demo.php'));
}

$ids = demo_ids();
admin_header('Demo listings', 'listings');
?>
<div class="admin-head">
  <h1 class="page-title">Demo listings</h1>
</div>
<p class="muted">Made-up sample listings (stock numbers D-1001 to D-1012) for trying out the site. They are added next to your real listings and never change them. Firearm details and CA roster status are not touched.</p>
<p><strong><?= count($ids) ?></strong> demo listing<?= count($ids) === 1 ? '' : 's' ?> in the database now.</p>

<form method="post" action="<?= e(url('admin/demo.php')) ?>" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <button class="btn btn-accent" type="submit">Add demo listings</button>
  <p class="muted">Adds any of the <?= count(DEMO_LISTINGS) ?> that are missing. Safe to press twice.</p>
</form>

<?php if ($ids): ?>
<form method="post" action="<?= e(url('admin/demo.php')) ?>" class="admin-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="remove">
  <label for="confirm">Type DELETE to remove all <?= count($ids) ?> demo listings and their photo records</label>
  <input class="input" id="confirm" name="confirm" autocomplete="off">
  <button class="btn btn-dark" type="submit">Remove demo listings</button>
</form>
<?php endif; ?>
<?php admin_footer();
