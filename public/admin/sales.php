<?php
// Sales (owners only): sold listings, with price, days to sell and a summary by month.
require __DIR__ . '/_admin.php';
require_owner();

$db = db();
$year = (int)get_str('year', '0');
$years = $db->query("SELECT DISTINCT substr(sold_at, 1, 4) FROM listings WHERE status = 'sold' AND sold_at IS NOT NULL ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$year && $years) {
    $year = (int)$years[0];
}
$st = $db->prepare(
    "SELECT l.id, l.stock_number, l.new_used, l.condition, l.consignment, l.price_usd, l.sold_price_usd, l.listed_at, l.sold_at,
            f.manufacturer, f.model, f.category,
            CASE WHEN l.listed_at IS NOT NULL AND l.sold_at IS NOT NULL THEN CAST(julianday(l.sold_at) - julianday(l.listed_at) AS INTEGER) END AS days
       FROM listings l JOIN firearms f ON f.id = l.firearm_id
      WHERE l.status = 'sold' AND (? = 0 OR substr(l.sold_at, 1, 4) = ?)
      ORDER BY l.sold_at DESC, l.id DESC"
);
$st->execute([$year, (string)$year]);
$rows = $st->fetchAll();

$months = [];
$tot = ['n' => 0, 'rev' => 0.0, 'daysSum' => 0, 'daysN' => 0, 'noPrice' => 0];
foreach ($rows as $r) {
    $m = substr((string)$r['sold_at'], 0, 7) ?: 'no date';
    $months[$m] ??= ['n' => 0, 'rev' => 0.0, 'daysSum' => 0, 'daysN' => 0];
    $sold = $r['sold_price_usd'] ?? $r['price_usd'];
    $months[$m]['n']++;
    $tot['n']++;
    if ($sold !== null) {
        $months[$m]['rev'] += (float)$sold;
        $tot['rev'] += (float)$sold;
    } else {
        $tot['noPrice']++;
    }
    if ($r['days'] !== null && $r['days'] >= 0) {
        $months[$m]['daysSum'] += (int)$r['days'];
        $months[$m]['daysN']++;
        $tot['daysSum'] += (int)$r['days'];
        $tot['daysN']++;
    }
}
krsort($months);
$avg = fn(array $a) => $a['n'] && $a['rev'] ? money(round($a['rev'] / max(1, $a['n']))) : '—';
$days = fn(array $a) => $a['daysN'] ? number_format($a['daysSum'] / $a['daysN'], 0) : '—';

admin_header('Sales', 'sales');
?>
<div class="admin-head">
  <h1 class="page-title">Sales</h1>
  <form method="get" class="admin-filters" action="<?= e(url('admin/sales.php')) ?>">
    <label for="year" class="sr-only">Year</label>
    <select class="select" id="year" name="year" onchange="this.form.submit()">
      <?= opt('0', 'All years', (string)$year) ?>
      <?php foreach ($years as $y) echo opt($y, $y, (string)$year); ?>
    </select>
    <noscript><button class="btn btn-dark" type="submit">Show</button></noscript>
  </form>
</div>
<p class="muted">Listings with status Sold. Revenue uses the sold price, or the asking price when no sold price was entered.
  Days to sell = listed date to sold date.</p>

<?php if (!$rows): ?>
  <p class="muted">No sold listings yet. Set a listing to <strong>Sold</strong> (and enter the sold price on its page) and it appears here.</p>
<?php else: ?>
<div class="hero-grid" style="margin-bottom:24px">
  <div class="hero-item"><span class="muted small">Guns sold</span><strong style="font-size:28px"><?= (int)$tot['n'] ?></strong></div>
  <div class="hero-item"><span class="muted small">Revenue</span><strong style="font-size:28px"><?= e(money($tot['rev'])) ?></strong></div>
  <div class="hero-item"><span class="muted small">Average price</span><strong style="font-size:28px"><?= e($avg($tot)) ?></strong></div>
  <div class="hero-item"><span class="muted small">Average days to sell</span><strong style="font-size:28px"><?= e($days($tot)) ?></strong></div>
</div>
<?php if ($tot['noPrice']): ?><p class="muted small"><?= (int)$tot['noPrice'] ?> sold listing(s) have no price at all and are left out of revenue.</p><?php endif; ?>

<h2 style="font-size:22px">By month</h2>
<div class="table-wrap">
<table class="admin-table">
  <thead><tr><th scope="col">Month</th><th scope="col">Sold</th><th scope="col">Revenue</th><th scope="col">Average price</th><th scope="col">Average days to sell</th></tr></thead>
  <tbody>
  <?php foreach ($months as $m => $a): ?>
    <tr><td class="mono"><?= e($m) ?></td><td><?= (int)$a['n'] ?></td><td class="mono"><?= e(money($a['rev'])) ?></td><td class="mono"><?= e($avg($a)) ?></td><td><?= e($days($a)) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2 style="font-size:22px;margin-top:32px">Each sale</h2>
<div class="table-wrap">
<table class="admin-table">
  <thead><tr><th scope="col">Sold</th><th scope="col">Stock #</th><th scope="col">Gun</th><th scope="col">New / used</th><th scope="col">Asked</th><th scope="col">Sold for</th><th scope="col">Days</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="mono"><?= e((string)$r['sold_at']) ?></td>
      <td class="mono"><?= e((string)$r['stock_number']) ?></td>
      <td><a class="strong" href="<?= e(url('admin/listing.php', ['id' => $r['id']])) ?>"><?= e($r['manufacturer'] . ' ' . $r['model']) ?></a>
        <?= $r['consignment'] ? ' <span class="muted small">consignment</span>' : '' ?></td>
      <td><?= $r['new_used'] === 'new' ? 'New' : 'Used' ?></td>
      <td class="mono"><?= $r['price_usd'] !== null ? e(money($r['price_usd'])) : '—' ?></td>
      <td class="mono"><?= $r['sold_price_usd'] !== null ? e(money($r['sold_price_usd'])) : '<span class="muted">—</span>' ?></td>
      <td class="mono"><?= $r['days'] !== null ? (int)$r['days'] : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php admin_footer();
