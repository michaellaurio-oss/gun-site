<?php
// Compare up to 4 listed guns side by side: compare.php?ids=16,22,31 (&diff=1 = differences only).
// The ids in the URL are the source of truth and the shareable link; compare-page.js makes
// the visitor's saved picks (compare.js) match them.
require __DIR__ . '/app/bootstrap.php';

const COMPARE_MAX = 4;

function compare_url(array $ids, bool $diff = false): string
{
    return url('compare.php') . '?ids=' . implode(',', $ids) . ($diff ? '&diff=1' : '');
}

/** One cell: plain text (NULL = no data, shown as a dash), optional sub-line, optional trusted HTML for the value. */
function cmp_cell(?string $text, string $sub = '', ?string $html = null): array
{
    return ['text' => $text, 'sub' => $sub, 'html' => $html ?? ($text === null ? '—' : e($text))];
}

function cmp_str($v): ?string
{
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function cmp_len($in, $mm): array
{
    return cmp_cell($in === null ? null : num($in) . ' in · ' . num($mm, 1) . ' mm');
}

/** Unloaded weight if known, otherwise weight with an empty magazine; the sub-line says which. */
function cmp_weight(array $f, bool $handgun): array
{
    foreach ([['weight_oz', 'weight_g', 'unloaded'], ['weight_empty_mag_oz', 'weight_empty_mag_g', 'with empty magazine']] as [$oz, $g, $what]) {
        if (($f[$oz] ?? null) !== null) {
            $v = $handgun
                ? num($f[$oz]) . ' oz · ' . num($f[$g], 0) . ' g'
                : num($f[$oz] / 16, 2) . ' lb · ' . num($f[$oz] * 0.0283495, 2) . ' kg';
            return cmp_cell($v, $what);
        }
    }
    return cmp_cell(null);
}

function cmp_trigger(array $f): array
{
    $sa = $f['trigger_pull_lb'] ?? null;
    $da = $f['trigger_pull_da_lb'] ?? null;
    if ($sa !== null && $da !== null) {
        return cmp_cell(num($sa) . ' lb SA · ' . num($da) . ' lb DA', num($f['trigger_pull_kg']) . ' kg · ' . num($f['trigger_pull_da_kg']) . ' kg');
    }
    if ($sa !== null) {
        return cmp_cell(num($sa) . ' lb', num($f['trigger_pull_kg']) . ' kg');
    }
    if ($da !== null) {
        return cmp_cell(num($da) . ' lb DA', num($f['trigger_pull_da_kg']) . ' kg');
    }
    return cmp_cell(null);
}

// ----- Which guns -----
$rawIds = $_GET['ids'] ?? null;
$hasIds = is_string($rawIds);
$ids = [];
foreach (preg_split('/[\s,]+/', $hasIds ? $rawIds : '', -1, PREG_SPLIT_NO_EMPTY) as $p) {
    if (ctype_digit($p) && (int)$p > 0 && !in_array((int)$p, $ids, true)) {
        $ids[] = (int)$p;
    }
}
$ids = array_slice($ids, 0, COMPARE_MAX);
$diff = ($_GET['diff'] ?? '') === '1';

$found = [];
if ($ids) {
    $st = db()->prepare('SELECT * FROM listings_public WHERE listing_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) {
        $found[(int)$row['listing_id']] = $row;
    }
}
$cols = [];
foreach ($ids as $id) {
    if (isset($found[$id])) {
        $l = $found[$id];
        $c = card_data($l);
        $photos = array_values(array_filter(array_map(fn($p) => thumb_url($p['file_path']), listing_photos($id))));
        if (!$photos && !empty($c['stockPhoto'])) {
            $photos = [$c['photo']];   // new gun, no own photos: the model's stock photo
        }
        $cols[] = ['l' => $l, 'f' => firearm_specs((int)$l['firearm_id']), 'c' => $c, 'name' => $l['manufacturer'] . ' ' . $c['model'], 'photos' => $photos];
    }
}
$n = count($cols);
$shownIds = array_map(fn($col) => (int)$col['l']['listing_id'], $cols);
$dropped = count($ids) - $n;   // sold, withdrawn or back to draft since they were picked

// ----- Rows: [label, mono, fn(listing, specs, column index) => cell] -----
$groups = [
    'Listing' => [
        ['New / used', false, fn($l) => cmp_cell($l['new_used'] === 'new' ? 'New' : 'Used')],
        ['Condition', false, fn($l) => cmp_cell(cmp_str($l['condition']))],
        ['Est. round count', true, fn($l) => cmp_cell($l['est_round_count'] !== null ? number_format((int)$l['est_round_count']) : null)],
        ['CA roster', false, function ($l, $f, $i) use ($n) {
            $r = roster_label($l['category'], $l['ca_rostered']);
            if ($r === 'On roster') {
                return cmp_cell($r, '', '<span class="ok">' . icon('check', 16) . 'On roster</span>');
            }
            if ($r === 'Off roster') {
                $tip = tooltip('Off roster' . icon('info', 14), OFF_ROSTER_NOTE, 'cmp-tip');
                return cmp_cell($r, '', $i >= $n / 2 ? '<span class="tip-right">' . $tip . '</span>' : $tip);
            }
            return cmp_cell($r === 'Not required (long guns)' ? 'Not required' : $r);
        }],
        ['Modifications', false, fn($l) => cmp_cell(cmp_str($l['modifications']) ?? 'None')],
        ['Included', false, fn($l) => cmp_cell(cmp_str($l['included_items']))],
    ],
    'Specifications' => [
        ['Type', false, fn($l) => cmp_cell(type_label($l['category']))],
        ['Caliber', false, fn($l) => cmp_cell(cmp_str($l['caliber']))],
        ['Action', false, fn($l, $f) => cmp_cell(cmp_str($f['action'] ?? null))],
        ['Capacity', false, fn($l) => cmp_cell(
            $l['capacity'] !== null ? capacity_text($l['capacity'], true) : null,
            $l['ca_capacity_note'] !== null ? CA_CAPACITY_NOTE : ''
        )],
        ['Barrel length', true, fn($l, $f) => cmp_len($f['barrel_length_in'] ?? null, $f['barrel_length_mm'] ?? null)],
        ['Overall length', true, fn($l, $f) => cmp_len($f['overall_length_in'] ?? null, $f['overall_length_mm'] ?? null)],
        ['Height', true, fn($l, $f) => cmp_len($f['height_in'] ?? null, $f['height_mm'] ?? null)],
        ['Width', true, fn($l, $f) => cmp_len($f['width_in'] ?? null, $f['width_mm'] ?? null)],
        ['Weight', true, fn($l, $f) => cmp_weight($f, $l['category'] === 'handgun')],
        ['Trigger pull', true, fn($l, $f) => cmp_trigger($f)],
        ['Frame', false, fn($l, $f) => cmp_cell(cmp_str($f['frame_material'] ?? null))],
        ['Sights', false, fn($l, $f) => cmp_cell(cmp_str($l['sights']))],
        ['MSRP (new)', true, fn($l) => cmp_cell($l['msrp_usd'] !== null ? money($l['msrp_usd']) : null)],
    ],
];

// Rows with no data for any gun are left out. "same" rows (identical for ALL compared guns)
// are hidden by "Show differences only", and so is a group heading whose rows are all hidden.
$table = [];
$anyDiff = false;
foreach ($groups as $title => $defs) {
    $rows = [];
    foreach ($defs as [$label, $mono, $fn]) {
        $cells = [];
        foreach ($cols as $i => $col) {
            $cells[] = $fn($col['l'], $col['f'], $i);
        }
        if (!array_filter($cells, fn($x) => $x['text'] !== null)) {
            continue;
        }
        $keys = array_unique(array_map(fn($x) => ($x['text'] ?? '') . "\n" . $x['sub'], $cells));
        $same = count($keys) === 1;
        $anyDiff = $anyDiff || !$same;
        $rows[] = ['label' => $label, 'mono' => $mono, 'cells' => $cells, 'same' => $same];
    }
    if ($rows) {
        $table[] = ['title' => $title, 'rows' => $rows, 'same' => !array_filter($rows, fn($r) => !$r['same'])];
    }
}

$addCol = $n < COMPARE_MAX;
$pageData = [
    'sync'     => $hasIds,
    'guns'     => array_map(fn($col) => ['id' => $col['c']['id'], 'make' => $col['c']['make'], 'model' => $col['c']['model'], 'photo' => $col['c']['photo']], $cols),
    'base'     => url('compare.php'),
    'ids'      => $shownIds,
    'cleanUrl' => $hasIds ? compare_url($shownIds, $diff) : null,
];

page_header('Compare', '', ['noindex' => true, 'description' => 'Compare guns side by side.']);
?>
<div class="container">
  <section class="inv-title cmp-title">
    <div>
      <div class="crumbs"><a href="<?= e(url()) ?>">Home</a> / <a href="<?= e(url('inventory.php')) ?>">Inventory</a> / Compare</div>
      <h1 class="page-title">Compare</h1>
      <?php if ($n >= 2): ?><p class="page-sub"><?= $n ?> guns side by side</p><?php endif; ?>
    </div>
    <?php if ($n >= 2): ?>
      <div class="cmp-controls">
        <label class="cmp-diff"><input type="checkbox" id="cmp-diff"<?= $diff ? ' checked' : '' ?>> Show differences only</label>
        <button type="button" class="btn btn-outline btn-sm" id="cmp-copy"><?= icon('link', 16) ?><span id="cmp-copy-text">Copy link</span></button>
        <a class="link-btn cmp-clear" href="<?= e(compare_url([])) ?>">Clear all</a>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($dropped > 0): ?>
    <p class="cmp-note" role="status"><?= $dropped === 1 ? '1 gun is no longer available and was removed.' : $dropped . ' guns are no longer available and were removed.' ?></p>
  <?php endif; ?>

  <?php if ($n < 2): ?>
    <div class="empty-note">Pick at least 2 guns to compare. <a class="cmp-back" href="<?= e(url('inventory.php')) ?>">Back to inventory</a></div>
  <?php else: ?>
    <div class="cmp-pager" id="cmp-pager" hidden>
      <button type="button" class="pbtn" id="cmp-prev" aria-label="Previous guns"><?= icon('prev', 18) ?></button>
      <div class="cmp-range" aria-live="polite"><b id="cmp-range"></b> of <?= $n ?> guns</div>
      <button type="button" class="pbtn" id="cmp-next" aria-label="Next guns"><?= icon('next', 18) ?></button>
    </div>

    <div class="cmp-table<?= $diff ? ' diff-only' : '' ?>" id="cmp-table" role="table" aria-label="<?= $n ?> guns compared" style="--cols:<?= $n + ($addCol ? 1 : 0) ?>">
      <div class="cmp-row cmp-heads" role="row" id="cmp-heads">
        <div class="cmp-label cmp-shown" role="columnheader"><span id="cmp-shown"><?= $diff ? 'Showing differences only' : 'All details' ?></span></div>
        <?php foreach ($cols as $i => ['l' => $l, 'c' => $c, 'name' => $name, 'photos' => $photos]): ?>
          <?php $others = array_values(array_diff($shownIds, [$c['id']])); ?>
          <div class="cmp-head" role="columnheader" data-col="<?= $i ?>">
            <a class="cmp-remove" href="<?= e(compare_url($others, $diff)) ?>" data-remove-ids="<?= e(implode(',', $others)) ?>" aria-label="Remove <?= e($name) ?> from compare"><?= icon('close', 16) ?></a>
            <div class="cmp-photo-wrap"<?= count($photos) > 1 ? ' data-photos="' . e(json_encode($photos, JSON_UNESCAPED_SLASHES)) . '"' : '' ?>>
              <a class="cmp-photo" href="<?= e($c['url']) ?>" tabindex="-1" aria-hidden="true">
                <?php if ($photos): ?><img src="<?= e($photos[0]) ?>" alt=""><?php else: ?><span>Photo coming soon</span><?php endif; ?>
              </a>
              <?php if (!empty($c['stockPhoto'])): ?><?= stock_photo_tag('cmp-stock-tag tip-below' . ($i >= $n / 2 ? ' tip-right' : '')) ?><?php endif; ?>
              <?php if (count($photos) > 1): ?>
                <button type="button" class="cmp-pnav prev" data-step="-1" aria-label="Previous photo of the <?= e($name) ?>"><?= icon('prev', 18) ?></button>
                <button type="button" class="cmp-pnav next" data-step="1" aria-label="Next photo of the <?= e($name) ?>"><?= icon('next', 18) ?></button>
                <span class="cmp-pcount" aria-live="polite">1 / <?= count($photos) ?></span>
              <?php endif; ?>
            </div>
            <div class="cmp-meta">
              <span class="<?= $i >= $n / 2 ? 'tip-right' : '' ?>"><?= status_badge($l['status'], true) ?></span>
              <?php if ($c['stock'] !== ''): ?><span class="cmp-stock">#<?= e($c['stock']) ?></span><?php endif; ?>
              <?php if ($c['leo']): ?><?= leo_badge('cmp-leo' . ($i >= $n / 2 ? ' tip-right' : '')) ?><?php endif; ?>
            </div>
            <div>
              <div class="cmp-make"><?= e($c['make']) ?></div>
              <a class="cmp-model" href="<?= e($c['url']) ?>"><?= e($c['model']) ?></a>
            </div>
            <div class="cmp-price"><?= e($c['priceText']) ?></div>
            <div class="cmp-btns">
              <a class="btn btn-accent btn-sm" href="<?= e(url('contact.php', ['stock' => $c['stock'], 'gun' => $name])) ?>" aria-label="Ask about the <?= e($name) ?>">Ask</a>
              <a class="btn btn-outline btn-sm cmp-view" href="<?= e($c['url']) ?>" aria-label="View the <?= e($name) ?>">View</a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($addCol): ?>
          <div class="cmp-add-col" role="columnheader"><a class="cmp-add" href="<?= e(url('inventory.php')) ?>"><?= icon('plus', 28) ?>Add a gun</a></div>
        <?php endif; ?>
      </div>

      <div class="cmp-mini" id="cmp-mini" aria-hidden="true">
        <div class="cmp-row cmp-mini-inner">
          <div class="cmp-label"></div>
          <?php foreach ($cols as $i => ['c' => $c]): ?>
            <div class="cmp-mini-cell" data-col="<?= $i ?>">
              <div class="cmp-make"><?= e($c['make']) ?></div>
              <div class="cmp-mini-model"><?= e($c['model']) ?></div>
              <div class="cmp-mini-price"><?= e($c['priceText']) ?></div>
            </div>
          <?php endforeach; ?>
          <?php if ($addCol): ?><div class="cmp-add-col"></div><?php endif; ?>
        </div>
      </div>

      <?php foreach ($table as $g): ?>
        <div class="cmp-group" role="row"<?= $g['same'] ? ' data-same' : '' ?>><div role="rowheader"><?= e($g['title']) ?></div></div>
        <?php foreach ($g['rows'] as $r): ?>
          <div class="cmp-row" role="row"<?= $r['same'] ? ' data-same' : '' ?>>
            <div class="cmp-label" role="rowheader"><?= e($r['label']) ?></div>
            <?php foreach ($r['cells'] as $i => $cell): ?>
              <div class="cmp-cell<?= $cell['text'] === null ? ' empty' : ($r['mono'] ? ' is-mono' : '') ?>" role="cell" data-col="<?= $i ?>">
                <div class="v"><?= $cell['html'] ?></div>
                <?php if ($cell['sub'] !== ''): ?><div class="sub"><?= e($cell['sub']) ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if ($addCol): ?><div class="cmp-cell cmp-add-col" role="cell"></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <?php if (!$anyDiff): ?><p class="cmp-nodiff">These guns have the same details. Compare the price and photos above.</p><?php endif; ?>
      <p class="cmp-fine">Listing details are for each specific gun. Specifications are factory figures for the model.</p>
    </div>
  <?php endif; ?>
</div>
<script type="application/json" id="cmp-data"><?= json_encode($pageData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('assets/js/compare.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/compare-page.js')) ?>" defer></script>
<?php page_footer();
