<?php
require __DIR__ . '/app/bootstrap.php';

$l = find_public_listing(get_str('stock'), (int)get_str('id', '0') ?: null);
if (!$l) {
    http_response_code(404);
    page_header('Gun not found', '', ['noindex' => true]);
    ?>
<div class="container" style="padding-top:48px">
  <div class="crumbs"><a href="<?= e(url()) ?>">Home</a> / <a href="<?= e(url('inventory.php')) ?>">Inventory</a></div>
  <h1 class="page-title">This gun isn't listed any more</h1>
  <p class="page-sub">It may have sold. <a href="<?= e(url('inventory.php')) ?>" style="text-decoration:underline">Browse current inventory</a> or call <?= phone_link() ?>.</p>
</div>
<?php
    page_footer();
    exit;
}

$f = firearm_specs((int)$l['firearm_id']);
$photos = listing_photos((int)$l['listing_id']);
$c = card_data($l);
// A new gun without its own photos shows the model's stock photo, labelled as such.
$isStockPhoto = false;
if (!$photos && $l['new_used'] === 'new' && stock_photo_url($l['slug']) !== null) {
    $photos = [['file_path' => 'stock-photos/' . $l['slug'] . '.jpg', 'caption' => 'Stock photo']];
    $isStockPhoto = true;
}
$isHandgun = $l['category'] === 'handgun';
$heading = $c['model'];
$fullName = $l['manufacturer'] . ' ' . $heading;

// ----- Specification rows: [label, imperial, metric, note] (NULL values are skipped) -----
function spec_len($in, $mm): ?array
{
    return $in === null ? null : [num($in) . ' in', num($mm, 1) . ' mm'];
}
function spec_weight($oz, $g, bool $handgun): ?array
{
    if ($oz === null) {
        return null;
    }
    return $handgun
        ? [num($oz) . ' oz', num($g, 0) . ' g']
        : [num($oz / 16, 2) . ' lb', num($oz * 0.0283495, 2) . ' kg'];
}
function spec_twist(?string $twist): ?array
{
    if ($twist === null || $twist === '') {
        return null;
    }
    $met = '';
    if (preg_match('~1\s*:\s*([\d.]+)\s*(?:"|in)?\s*(.*)$~i', $twist, $m)) {
        $met = '1:' . num(round((float)$m[1] * 25.4)) . ' mm' . ($m[2] !== '' ? ' ' . $m[2] : '');
    }
    return [$twist, $met];
}

$rows = [];
$add = function (string $label, ?array $pair, string $note = '') use (&$rows) {
    if ($pair !== null) {
        $rows[] = [$label, $pair[0], $pair[1] ?? '', $note];
    }
};
$text = fn($v) => ($v === null || $v === '') ? null : [(string)$v, ''];

$add('Caliber', $text($f['caliber']));
$add('Action', $text($f['action']));
if ($f['capacity'] !== null) {
    $cap = capacity_text($f['capacity'], true) . ($f['capacity'] > 10 ? ' ' . CA_CAPACITY_NOTE : '');
    $add('Capacity', [$cap, ''], (string)$f['capacity_note']);
}
$add('Barrel length', spec_len($f['barrel_length_in'], $f['barrel_length_mm']));
$add('Overall length', spec_len($f['overall_length_in'], $f['overall_length_mm']));
$add('Height', spec_len($f['height_in'], $f['height_mm']));
$add('Width', spec_len($f['width_in'], $f['width_mm']));
$add('Sight radius', spec_len($f['sight_radius_in'], $f['sight_radius_mm']));
$add('Length of pull', spec_len($f['length_of_pull_in'], $f['length_of_pull_mm']));
$add('Weight, unloaded', spec_weight($f['weight_oz'], $f['weight_g'], $isHandgun));
$add('Weight, empty magazine', spec_weight($f['weight_empty_mag_oz'], $f['weight_empty_mag_g'], $isHandgun));
$add('Weight, loaded', spec_weight($f['weight_loaded_oz'], $f['weight_loaded_g'], $isHandgun));
$add('Chamber length', spec_len($f['chamber_length_in'], $f['chamber_length_mm']));
$add('Choke', $text($f['choke']));
if ($f['trigger_pull_lb'] !== null) {
    $add($f['trigger_pull_da_lb'] !== null ? 'Trigger pull, single action' : 'Trigger pull', [num($f['trigger_pull_lb']) . ' lb', num($f['trigger_pull_kg']) . ' kg']);
}
if ($f['trigger_pull_da_lb'] !== null) {
    $add('Trigger pull, double action', [num($f['trigger_pull_da_lb']) . ' lb', num($f['trigger_pull_da_kg']) . ' kg']);
}
$add('Barrel twist', spec_twist($f['barrel_twist']));
$add('Barrel', $text($f['barrel_material']));
$add('Thread pattern', $text($f['thread_pattern']));
$add('Frame', $text($f['frame_material']));
$add('Finish', $text($l['finish']));
$add($isHandgun ? 'Grip' : 'Stock', $text($f['stock_grip']));
$add('Sights', $text($f['sights']));

// ----- Facts -----
$yesNo = fn($v) => $v === null ? 'Not recorded' : ((int)$v === 1 ? 'Yes' : 'No');
$status = status_info($l['status']);
$description = trim((string)$l['listing_description']);
$blurb = trim((string)$l['model_description']);

page_header($fullName, $l['new_used'] === 'new' ? 'new' : 'used', ['description' => $blurb !== '' ? mb_substr($blurb, 0, 155) : $fullName]);
?>
<div class="container">
  <div class="crumbs" style="padding-top:28px"><a href="<?= e(url()) ?>">Home</a> / <a href="<?= e(url('inventory.php')) ?>">Inventory</a> / <?= e($fullName) ?></div>

  <section class="detail">
    <div class="detail-head">
      <div>
        <div class="kicker-row">
          <div class="kicker"><?= e($l['manufacturer']) ?> · <?= e(type_label($l['category'])) ?></div>
          <span class="tip-below"><?= status_badge($l['status'], true) ?></span>
        </div>
        <h1 class="detail-title"><?= e($heading) ?></h1>
        <?php if ($c['leo']): ?>
          <p class="leo-notice"><span class="leo-badge-static"><?= e(LEO_LABEL) ?></span><span><?= e(LEO_NOTE) ?></span></p>
        <?php endif; ?>
      </div>

      <div class="price-row">
        <div class="price-main">
          <div class="price-big"><?= e($c['priceText']) ?></div>
          <?php if ($l['msrp_usd'] !== null): ?><div class="msrp">MSRP <?= e(money($l['msrp_usd'])) ?></div><?php endif; ?>
        </div>
        <?php if ($l['stock_number']): ?>
          <div class="stock-box"><div class="stock-label">Stock #</div><div class="stock-num"><?= e($l['stock_number']) ?></div></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="detail-info">
      <dl class="facts">
        <div class="fact"><dt>New / Used</dt><dd><?= $l['new_used'] === 'new' ? 'New' : 'Used' ?></dd></div>
        <div class="fact"><dt>Condition</dt><dd><?= e($l['condition'] ?? 'Not yet graded') ?></dd></div>
        <div class="fact"><dt>CA Roster</dt><dd>
          <?php if ($c['roster'] === 'On roster'): ?><span class="ok"><?= icon('check', 16) ?>On roster</span>
          <?php elseif ($c['roster'] === 'Off roster'): ?><?= tooltip('Off roster' . icon('info', 15), OFF_ROSTER_NOTE) ?>
          <?php elseif ($c['roster'] === 'Not yet checked'): ?>Not yet checked
          <?php else: ?>Not required<?php endif; ?>
        </dd></div>
        <div class="fact"><dt>Est. round count</dt><dd><?= $l['est_round_count'] !== null ? e(number_format((int)$l['est_round_count'])) : 'Not recorded' ?></dd></div>
        <div class="fact"><dt>Caliber</dt><dd><?= e($l['caliber'] ?? '—') ?></dd></div>
        <div class="fact"><dt>Capacity</dt><dd><?= $l['capacity'] !== null ? e(capacity_text($l['capacity'], true)) : '—' ?></dd>
          <?php if ($l['ca_capacity_note']): ?><dd class="sub"><?= e(CA_CAPACITY_NOTE) ?></dd><?php endif; ?></div>
        <div class="fact"><dt>Magazines included</dt><dd><?= $l['magazines_included'] !== null ? (int)$l['magazines_included'] : ($isHandgun ? 'Not recorded' : '—') ?></dd></div>
        <div class="fact"><dt>Original box</dt><dd><?= e($yesNo($l['original_box'])) ?></dd></div>
      </dl>

      <div>
        <div class="cta-grid">
          <a class="btn btn-accent" href="<?= e(url('contact.php', ['stock' => $l['stock_number'] ?? '', 'gun' => $fullName])) ?>">Ask about this gun</a>
          <?= phone_link('btn btn-outline', icon('phone') . 'Call ' . e(shop('phone'))) ?>
        </div>
        <?= compare_toggle($c, 'cmp-check') ?>
        <p class="fine">Transfer through a licensed dealer (FFL) with background check and any state waiting period. <a href="<?= e(url('how-to-buy.php')) ?>">How buying works</a></p>
      </div>

      <?php if ($description !== ''): ?><p class="blurb"><?= nl2br(e($description)) ?></p><?php endif; ?>
      <?php if ($blurb !== ''): ?><p class="blurb"<?= $description !== '' ? ' style="color:var(--text-2);font-size:16px"' : '' ?>><?= e($blurb) ?></p><?php endif; ?>
    </div>

    <div class="detail-media">
      <div class="tabs" role="tablist" aria-label="Photos and specifications">
        <button type="button" role="tab" class="tab" id="tab-photos" aria-controls="panel-photos" aria-selected="true">Photos</button>
        <button type="button" role="tab" class="tab" id="tab-specs" aria-controls="panel-specs" aria-selected="false" tabindex="-1">Specifications</button>
      </div>

      <div class="tabpanel" role="tabpanel" id="panel-photos" aria-labelledby="tab-photos" tabindex="0">
        <?php if ($photos): ?>
          <?php $first = $photos[0]; ?>
          <div class="photo-stage">
            <button type="button" class="photo-main" id="photo-main" data-index="0" aria-label="Enlarge photo: <?= e($first['caption'] ?: 'Photo 1') ?>">
              <img src="<?= e(photo_url($first['file_path'])) ?>" alt="<?= e($fullName . ($first['caption'] ? ' – ' . $first['caption'] : '')) ?>">
              <span class="enlarge-hint"><?= icon('zoom', 14) ?>Click to enlarge</span>
            </button>
            <?php if ($isStockPhoto): ?><?= stock_photo_tag('detail-stock-tag tip-below') ?><?php endif; ?>
            <?php if (count($photos) > 1): ?>
              <button type="button" class="photo-nav prev" id="photo-prev" aria-label="Previous photo"><span><?= icon('prev', 22) ?></span></button>
              <button type="button" class="photo-nav next" id="photo-next" aria-label="Next photo"><span><?= icon('next', 22) ?></span></button>
              <p class="sr-only" aria-live="polite" id="photo-live"></p>
            <?php endif; ?>
          </div>
          <?php if (count($photos) > 1): ?>
            <div class="thumbs">
              <?php foreach ($photos as $i => $p): ?>
                <button type="button" class="thumb" data-index="<?= $i ?>" aria-label="Show photo <?= $i + 1 ?>: <?= e($p['caption'] ?: 'Photo ' . ($i + 1)) ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>>
                  <img src="<?= e(thumb_url($p["file_path"])) ?>" alt="" loading="lazy">
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="no-photos ph"><?= icon('photo', 40) ?><span>Photos of this gun are coming soon.</span></div>
        <?php endif; ?>
      </div>

      <div class="tabpanel" role="tabpanel" id="panel-specs" aria-labelledby="tab-specs" tabindex="0" data-start-hidden>
        <?php if ($rows): ?>
          <table class="spec-table">
            <caption class="sr-only">Factory specifications for the <?= e($fullName) ?></caption>
            <thead><tr><th scope="col">Spec</th><th scope="col">Imperial</th><th scope="col">Metric</th></tr></thead>
            <tbody>
              <?php foreach ($rows as [$label, $imp, $met, $note]): ?>
                <tr>
                  <th scope="row"><?= e($label) ?></th>
                  <td><?= e($imp) ?><?php if ($note !== ''): ?><span class="note"><?= e($note) ?></span><?php endif; ?></td>
                  <td class="met"><?= $met !== '' ? e($met) : '<span class="sr-only">Same as imperial</span>' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="table-note">Factory specs for this model.</div>
        <?php else: ?>
          <div class="empty-note">Specifications for this model are being researched.</div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php
  $report = [
      'Condition notes'     => $l['condition_notes'],
      'Modifications'       => trim((string)$l['modifications']) !== '' ? $l['modifications'] : 'None, factory configuration.',
      'Included'            => $l['included_items'],
      'Additional comments' => $l['additional_comments'],
  ];
  $report = array_filter($report, fn($v) => $v !== null && trim((string)$v) !== '');
  ?>
  <?php if ($l['status'] !== 'coming_soon' || count($report) > 1): ?>
  <section class="report" aria-labelledby="report-title">
    <h2 id="report-title">Condition report</h2>
    <div class="report-grid">
      <?php foreach ($report as $title => $body): ?>
        <div class="report-card"><h3><?= e($title) ?></h3><div><?= e(trim((string)$body)) ?></div></div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<?php if ($photos): ?>
<dialog class="viewer" id="viewer" aria-label="Photo viewer">
  <div class="viewer-bar">
    <div><span class="viewer-name"><?= e($fullName) ?></span><span class="viewer-pos" id="viewer-pos"></span></div>
    <button type="button" class="vbtn" id="viewer-close" aria-label="Close photo viewer"><?= icon('close', 22) ?></button>
  </div>
  <div class="viewer-body">
    <button type="button" class="vbtn" id="viewer-prev" aria-label="Previous photo"<?= count($photos) < 2 ? ' hidden' : '' ?>><?= icon('prev', 24) ?></button>
    <div class="viewer-img"><img id="viewer-img" src="" alt=""></div>
    <button type="button" class="vbtn" id="viewer-next" aria-label="Next photo"<?= count($photos) < 2 ? ' hidden' : '' ?>><?= icon('next', 24) ?></button>
  </div>
  <p class="sr-only" aria-live="polite" id="viewer-live"></p>
</dialog>
<script type="application/json" id="photo-data"><?= json_encode(array_map(fn($p) => ['src' => photo_url($p['file_path']), 'caption' => (string)$p['caption']], $photos), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<div id="cmp-tray" data-compare-url="<?= e(url('compare.php')) ?>" data-add-url="<?= e(url('inventory.php')) ?>" hidden></div>
<script src="<?= e(asset('assets/js/compare.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/detail.js')) ?>" defer></script>
<?php page_footer();
