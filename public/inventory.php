<?php
require __DIR__ . '/app/bootstrap.php';

$cards = array_map('card_data', public_listings());
$q = trim(get_str('q', ''));
// Header: "New Inventory" / "Used Inventory" are this page with the New / Used filter ticked.
$newUsed = (array)($_GET['newused'] ?? []);
$active = count($newUsed) === 1 && in_array($newUsed[0], ['New', 'Used'], true) ? strtolower((string)$newUsed[0]) : '';
$leoOnly = ($_GET['leo'] ?? '') === '1';   // New Inventory > LEO
if ($leoOnly) {
    $active = 'new';
}

$status = [];
foreach (['available', 'on_hold', 'pending', 'coming_soon'] as $s) {
    $status[$s] = status_info($s);
}
$data = [
    'items'  => $cards,
    'status' => $status,
    'notes'  => ['offRoster' => OFF_ROSTER_NOTE, 'capacity' => CA_CAPACITY_NOTE, 'stockPhoto' => STOCK_PHOTO_NOTE, 'leo' => LEO_NOTE, 'leoLabel' => LEO_LABEL],
];

// Server-rendered first page for browsers without JavaScript (JS replaces it).
$fallback = array_slice($leoOnly ? array_values(array_filter($cards, fn($c) => $c['leo'])) : $cards, 0, 24);

page_header('Inventory', $active, ['q' => $q, 'description' => 'Current inventory of new and used firearms.']);
?>
<div class="container">
  <section class="inv-title">
    <div>
      <div class="crumbs"><a href="<?= e(url()) ?>">Home</a> / Inventory</div>
      <h1 class="page-title">Inventory</h1>
      <p class="page-sub" id="listed-text"><?= count($cards) ?> <?= count($cards) === 1 ? 'gun' : 'guns' ?> listed</p>
    </div>
    <div class="inv-controls">
      <button type="button" class="btn btn-outline filters-open-btn" id="filters-open" aria-controls="filters" aria-expanded="false" hidden>Filters <span id="filters-open-count"></span></button>
      <div class="ctl">
        <label for="perpage-top">Show</label>
        <select id="perpage-top" class="select perpage">
          <option value="12">12 per page</option>
          <option value="24" selected>24 per page</option>
          <option value="48">48 per page</option>
          <option value="96">96 per page</option>
        </select>
      </div>
      <div class="ctl">
        <label for="sort">Sort by</label>
        <select id="sort" class="select">
          <option value="newest">Newest listed</option>
          <option value="price-asc">Price: low to high</option>
          <option value="price-desc">Price: high to low</option>
          <option value="make">Make A–Z</option>
        </select>
      </div>
    </div>
  </section>

  <div class="inv-body">
    <aside id="filters" class="filters" aria-label="Filters" hidden>
      <div class="filters-head">
        <h2>Filters</h2>
        <button type="button" class="link-btn" data-clear-all>Clear all</button>
      </div>
      <div id="filter-groups"></div>
      <label class="finclude"><input type="checkbox" id="include-other" checked> Include on hold, pending &amp; coming soon</label>
      <div class="filters-close"><button type="button" class="btn btn-dark" id="filters-done">Show results</button></div>
    </aside>

    <div class="results">
      <div class="chips" id="chips" hidden></div>
      <p class="sr-only" aria-live="polite" id="results-live"></p>
      <div class="no-results" id="no-results" hidden>No guns match these filters. Try removing one.</div>
      <div class="inv-grid" id="grid">
        <?php foreach ($fallback as $c) echo render_card($c, true, true); ?>
      </div>
      <?php if (!$cards): ?>
        <div class="empty-note" id="empty-inventory">No guns are listed right now. Call <?= phone_link() ?> to ask what's in the shop.</div>
      <?php endif; ?>
      <nav class="pager" aria-label="Pages" id="pager" hidden>
        <div class="pager-left">
          <div id="showing"></div>
          <div class="ctl">
            <label for="perpage-bottom">Show</label>
            <select id="perpage-bottom" class="select perpage">
              <option value="12">12 per page</option>
              <option value="24" selected>24 per page</option>
              <option value="48">48 per page</option>
              <option value="96">96 per page</option>
            </select>
          </div>
        </div>
        <div class="pages" id="pages"></div>
      </nav>
    </div>
  </div>
</div>
<div id="cmp-tray" data-compare-url="<?= e(url('compare.php')) ?>" hidden></div>
<script type="application/json" id="inv-data"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('assets/js/compare.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/inventory.js')) ?>" defer></script>
<?php page_footer();
