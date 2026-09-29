<?php
require __DIR__ . '/app/bootstrap.php';

page_header('About', 'about', ['description' => 'About ' . shop('name') . ': veteran-owned gun store in Torrance, CA. Services, hours and contact details.']);
?>
<div class="container" style="padding-top:48px">
  <div class="crumbs"><a href="<?= e(url()) ?>">Home</a> / About</div>
  <h1 class="page-title">About <?= e(shop('name')) ?></h1>

  <div class="prose">
    <p>Crosshairs is a veteran owned and operated gun store that has been serving shooters in California for almost a decade, from our shop on Pacific Coast Highway in Torrance. We sell pistols, rifles, shotguns, ammunition and accessories, and we're NRA affiliated.</p>
    <p>Brands we carry include Glock, SIG Sauer, Ruger, Dan Wesson, Heckler &amp; Koch, Colt, Springfield Armory, Smith &amp; Wesson and Beretta.</p>

    <h2>Services</h2>
    <ul>
      <li><strong>FFL and ammunition transfers.</strong> Buy online and have it shipped to us: $75 per long gun, $50 per handgun or receiver, $15 for each additional firearm, plus the $37.19 California DROS fee. Law enforcement: $50 rifle, $30 handgun or receiver. Ammunition: $10 per 1,000 rounds plus the $1 California ammunition DROS fee.</li>
      <li><strong>Private party transfers.</strong> Buyer and seller meet at the shop by appointment (Wednesdays and Thursdays): $10 per firearm plus the $37.19 California DROS fee.</li>
      <li><strong>Consignment.</strong> We hold your firearm for the state's 30-day period, then sell it for you; our fee is 25% of the selling price.</li>
      <li><strong>We buy used guns.</strong> Send us the details and we'll inspect the firearm and make an offer.</li>
      <li><strong>Firearms shipping.</strong> $30 per long gun, $20 per handgun or receiver, plus FedEx or USPS charges.</li>
      <li><strong>Gunsmithing, classes and training.</strong></li>
    </ul>
    <p class="muted" style="font-size:15px">Fees as listed on the store's website; please call to confirm current pricing.</p>

    <h2>Visit</h2>
    <p><?= e(shop('street')) ?><br><?= e(shop('city')) ?><br><?= e(shop('hours')) ?></p>

    <h2>Contact</h2>
    <p>Phone: <?= phone_link() ?><?= shop('phone_alt') !== '' ? ' or ' . e(shop('phone_alt')) : '' ?><?= shop('ffl') !== '' ? '<br>FFL #' . e(shop('ffl')) : '' ?></p>
    <p>The shop is often too busy to answer the phone, so a message is usually the quickest way to reach us.</p>
    <p><a class="btn btn-accent" href="<?= e(url('contact.php')) ?>">Send us a message</a></p>
  </div>
</div>
<?php page_footer();
