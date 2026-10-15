<?php
// Home page pictures (owners only): upload, show / hide, reorder and remove the pictures that
// cycle in the big photo on the home page. Files live in assets/hero/ with their order in order.json.
require __DIR__ . '/_admin.php';
require_owner();
require __DIR__ . '/../app/hero.php';

$self = url('admin/hero.php');
$name_ok = fn($n) => is_string($n) && preg_match('~^[a-z0-9-]+\.jpg$~', $n) && is_file(hero_dir() . '/' . $n);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string)($_POST['action'] ?? '');
    $file = (string)($_POST['file'] ?? '');
    $shown = hero_shown();
    try {
        if ($action === 'upload') {
            $files = $_FILES['pictures'] ?? null;
            $added = 0;
            if (!$files || !is_array($files['name'])) {
                throw new RuntimeException('No pictures were received. They may be larger than the server allows.');
            }
            foreach ($files['name'] as $i => $orig) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$i])) {
                    throw new RuntimeException('"' . $orig . '" was not received (too large for the server?).');
                }
                $slug = 'home-' . date('ymd-His') . '-' . bin2hex(random_bytes(2));
                try {
                    stock_save((string)file_get_contents($files['tmp_name'][$i]), $slug, hero_dir());
                } catch (RuntimeException $e) {
                    throw new RuntimeException('"' . $orig . '": ' . $e->getMessage());
                }
                @unlink(hero_dir() . '/' . $slug . '-sm.jpg');   // only the one size is used here
                $shown[] = $slug . '.jpg';
                $added++;
                audit('photos', 'home', null, 'Added a home page picture', $slug . '.jpg (' . $orig . ')');
            }
            if (!$added) {
                throw new RuntimeException('Choose one or more pictures first.');
            }
            hero_save_shown($shown);
            flash($added . ' picture' . ($added === 1 ? '' : 's') . ' added at the end of the slideshow.');
        } elseif ($name_ok($file) && ($action === 'up' || $action === 'down')) {
            $i = array_search($file, $shown, true);
            if ($i !== false) {
                $j = $action === 'up' ? $i - 1 : $i + 1;
                if (isset($shown[$j])) {
                    [$shown[$i], $shown[$j]] = [$shown[$j], $shown[$i]];
                    hero_save_shown($shown);
                    audit('edited', 'home', null, 'Changed the home page picture order', $file);
                }
            }
        } elseif ($name_ok($file) && $action === 'hide') {
            hero_save_shown(array_values(array_diff($shown, [$file])));
            audit('edited', 'home', null, 'Hid a home page picture', $file);
            flash('Hidden. It stays in the list below if you want it back.');
        } elseif ($name_ok($file) && $action === 'show') {
            $shown[] = $file;
            hero_save_shown(array_unique($shown));
            audit('edited', 'home', null, 'Showed a home page picture', $file);
            flash('Added to the end of the slideshow.');
        } elseif ($name_ok($file) && $action === 'delete') {
            hero_save_shown(array_values(array_diff($shown, [$file])));
            hero_delete_files($file);
            audit('deleted', 'home', null, 'Deleted a home page picture', $file);
            flash('Picture deleted.');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect($self);
}

$shown = hero_shown();
$hidden = array_values(array_diff(hero_all_files(), $shown));
$src = fn($n) => asset('assets/hero/' . $n);

admin_header('Home page pictures', 'hero');
?>
<div class="admin-head">
  <h1 class="page-title">Home page pictures</h1>
  <a class="btn btn-outline" href="<?= e(url()) ?>" target="_blank" rel="noopener">View the home page</a>
</div>
<p class="muted">These pictures cycle every 5 seconds in the big photo on the home page. The box is a little wider than tall, so
  wide pictures are trimmed at the sides and tall ones at the top and bottom: keep the main subject in the middle.
  Each picture is shrunk and saved as a JPEG when you upload it.</p>

<section class="admin-section">
  <h2 style="font-size:22px">Shown on the home page <span class="muted">(<?= count($shown) ?>)</span></h2>
  <?php if (!$shown): ?>
    <p class="muted">Nothing is shown, so the home page falls back to the photo of a listed gun. Add a picture below.</p>
  <?php endif; ?>
  <ol class="hero-grid">
  <?php foreach ($shown as $i => $n): ?>
    <li class="hero-item">
      <div class="hero-thumb"><img src="<?= e($src($n)) ?>" alt="" loading="lazy"></div>
      <div class="hero-meta"><strong><?= $i + 1 ?></strong> <span class="mono muted small"><?= e($n) ?></span></div>
      <div class="hero-actions">
        <form method="post" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="file" value="<?= e($n) ?>">
          <button class="btn btn-outline btn-sm" name="action" value="up" type="submit"<?= $i === 0 ? ' disabled' : '' ?> aria-label="Move picture <?= $i + 1 ?> earlier">← Earlier</button>
          <button class="btn btn-outline btn-sm" name="action" value="down" type="submit"<?= $i === count($shown) - 1 ? ' disabled' : '' ?> aria-label="Move picture <?= $i + 1 ?> later">Later →</button>
          <button class="btn btn-outline btn-sm" name="action" value="hide" type="submit">Hide</button>
        </form>
      </div>
    </li>
  <?php endforeach; ?>
  </ol>
</section>

<section class="admin-section">
  <h2 style="font-size:22px">Add pictures</h2>
  <form method="post" action="<?= e($self) ?>" enctype="multipart/form-data" class="admin-filters">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <label for="pictures" class="sr-only">Choose pictures</label>
    <input class="input" id="pictures" name="pictures[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required>
    <button class="btn btn-dark" type="submit">Upload</button>
  </form>
  <p class="hint">JPEG, PNG or WebP, at least 300 pixels wide. They are added at the end; use Earlier / Later to reorder.</p>
</section>

<?php if ($hidden): ?>
<section class="admin-section">
  <h2 style="font-size:22px">Not shown <span class="muted">(<?= count($hidden) ?>)</span></h2>
  <ol class="hero-grid">
  <?php foreach ($hidden as $n): ?>
    <li class="hero-item">
      <div class="hero-thumb"><img src="<?= e($src($n)) ?>" alt="" loading="lazy"></div>
      <div class="hero-meta"><span class="mono muted small"><?= e($n) ?></span></div>
      <div class="hero-actions">
        <form method="post" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="file" value="<?= e($n) ?>">
          <button class="btn btn-outline btn-sm" name="action" value="show" type="submit">Show</button>
          <button class="btn btn-outline btn-sm danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this picture permanently?');">Delete</button>
        </form>
      </div>
    </li>
  <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>
<?php admin_footer();
