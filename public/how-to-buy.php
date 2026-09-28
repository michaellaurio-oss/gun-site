<?php
require __DIR__ . '/app/bootstrap.php';

page_header('How to buy', 'how', ['description' => 'How buying a firearm from us works in California: reserve, paperwork, background check and pickup.']);
?>
<div class="container" style="padding-top:48px">
  <div class="crumbs"><a href="<?= e(url()) ?>">Home</a> / How to buy</div>
  <h1 class="page-title">How buying works</h1>
  <p class="page-sub">All sales go through a licensed dealer (FFL) with a background check and the California waiting period.</p>

  <div class="prose">
    <h2>1. Find your gun</h2>
    <p>Every listing shows photos of the actual gun, its condition report and full factory specs. Note the <strong>stock number</strong> shown on the listing.</p>

    <h2>2. Contact us to reserve it</h2>
    <p>Call <?= phone_link() ?> or use <a href="<?= e(url('contact.php')) ?>" style="text-decoration:underline">Ask about this gun</a> with the stock number, and we'll confirm it's available. <?= e(shop('hold_policy')) ?></p>

    <h2>3. Paperwork and background check</h2>
    <p>In California you'll need:</p>
    <ul>
      <li>A valid California driver's license or ID card.</li>
      <li>A Firearm Safety Certificate (FSC). We can help you take the test if you don't have one.</li>
      <li>For handguns: proof of California residency and a safe-handling demonstration.</li>
    </ul>
    <p>We submit your background check (DROS) to the California DOJ, and the state's 10-day waiting period starts.</p>

    <h2>4. Pick up</h2>
    <p>Once the waiting period is over and you're cleared, come in to <?= e(shop('transfer_at')) ?> to complete the paperwork and take your gun home.</p>

    <h2>Questions?</h2>
    <p>State rules change from time to time. Call <?= phone_link() ?> and we'll walk you through what applies to your purchase.</p>
  </div>
</div>
<?php page_footer();
