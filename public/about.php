<?php
require __DIR__ . '/app/bootstrap.php';

page_header('About', 'about', ['description' => 'About ' . shop('name') . ': visit us, hours and contact details.']);
?>
<div class="container" style="padding-top:48px">
  <div class="crumbs"><a href="<?= e(url()) ?>">Home</a> / About</div>
  <h1 class="page-title">About <?= e(shop('name')) ?></h1>

  <div class="prose">
    <p>[A few sentences about the shop: who you are, how long you've been in business, what you specialise in.]</p>

    <h2>Visit</h2>
    <p><?= e(shop('street')) ?><br><?= e(shop('city')) ?><br><?= e(shop('hours')) ?></p>

    <h2>Contact</h2>
    <p>Phone: <?= phone_link() ?><br>Email: <?= e(shop('email')) ?><br>FFL #<?= e(shop('ffl')) ?></p>
    <p><a class="btn btn-accent" href="<?= e(url('contact.php')) ?>">Send us a message</a></p>
  </div>
</div>
<?php page_footer();
