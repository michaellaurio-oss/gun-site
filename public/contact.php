<?php
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/security.php';
csrf_token();  // start the session before any output

$stock = mb_substr(trim(get_str('stock', '')), 0, 40);
$gun = mb_substr(trim(get_str('gun', '')), 0, 120);
$form = ['name' => '', 'email' => '', 'phone' => '', 'stock' => $stock, 'message' => ''];
if ($gun !== '') {
    $form['message'] = 'I have a question about the ' . $gun . ($stock !== '' ? ' (stock #' . $stock . ')' : '') . '.';
}
$errors = [];
$sent = isset($_GET['sent']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'name'    => post_str('name', 100),
        'email'   => post_str('email', 200),
        'phone'   => post_str('phone', 40),
        'stock'   => post_str('stock', 40),
        'message' => post_str('message', 4000),
    ];
    $started = (int)($_POST['t'] ?? 0);

    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please send the form again.';
    }
    // Spam traps: hidden field must stay empty, the time field must be there, and humans take more than 3 seconds.
    $isBot = post_str('website', 200) !== '' || $started <= 0 || time() - $started < 3;
    if ($form['name'] === '') {
        $errors[] = 'Please enter your name.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($form['message'] === '') {
        $errors[] = 'Please enter a message.';
    }

    if (!$errors && !$isBot) {
        $ip = client_ip_hash();
        $st = db()->prepare("SELECT COUNT(*) FROM contact_messages WHERE ip_hash = ? AND created_at > datetime('now', '-1 hour')");
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= 5) {
            $errors[] = 'Too many messages from your connection. Please call us instead, or try again later.';
        }
    }

    if (!$errors) {
        if (!$isBot) {
            $ins = db()->prepare('INSERT INTO contact_messages (name, email, phone, stock_number, message, ip_hash) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->execute([$form['name'], $form['email'], $form['phone'] ?: null, $form['stock'] ?: null, $form['message'], client_ip_hash()]);
            $id = (int)db()->lastInsertId();

            $to = (string)config('contact_to');
            if ($to !== '') {
                $clean = fn($s) => preg_replace('/[\r\n]+/', ' ', $s);  // no header injection
                $subject = 'Website enquiry' . ($form['stock'] !== '' ? ' – stock #' . $clean($form['stock']) : '');
                $body = "Name: {$form['name']}\nEmail: {$form['email']}\nPhone: {$form['phone']}\nStock #: {$form['stock']}\n\n{$form['message']}\n";
                $headers = [
                    'From: ' . $clean((string)(config('contact_from') ?: $to)),
                    'Reply-To: ' . $clean($form['email']),
                    'Content-Type: text/plain; charset=UTF-8',
                ];
                if (@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers))) {
                    db()->prepare('UPDATE contact_messages SET emailed = 1 WHERE id = ?')->execute([$id]);
                }
            }
        }
        header('Location: ' . url('contact.php', ['sent' => 1]), true, 303);
        exit;
    }
}

page_header('Contact', 'about', ['q' => '']);
?>
<div class="container" style="padding-top:48px">
  <div class="crumbs"><a href="<?= e(url()) ?>">Home</a> / Contact</div>
  <h1 class="page-title">Ask about a gun</h1>
  <p class="page-sub">Send us a message and we'll get back to you, or call <?= phone_link() ?>.</p>

  <div style="margin-top:32px">
  <?php if ($sent): ?>
    <div class="alert alert-ok" role="status">Thanks, your message has been sent. We'll be in touch soon.</div>
    <p><a class="arrow-link" href="<?= e(url('inventory.php')) ?>">Back to inventory <?= icon('arrow', 16) ?></a></p>
  <?php else: ?>
    <?php if ($errors): ?>
      <div class="alert alert-err" role="alert" style="margin-bottom:20px"><?= implode('<br>', array_map('e', $errors)) ?></div>
    <?php endif; ?>
    <form class="form" method="post" action="<?= e(url('contact.php')) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="t" value="<?= time() ?>">
      <div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
      <div class="field"><label for="name">Name</label><input class="input" id="name" name="name" type="text" required autocomplete="name" value="<?= e($form['name']) ?>"></div>
      <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" required autocomplete="email" value="<?= e($form['email']) ?>"></div>
      <div class="field"><label for="phone">Phone <span class="muted">(optional)</span></label><input class="input" id="phone" name="phone" type="tel" autocomplete="tel" value="<?= e($form['phone']) ?>"></div>
      <div class="field"><label for="stock">Stock # <span class="muted">(optional)</span></label><input class="input" id="stock" name="stock" type="text" value="<?= e($form['stock']) ?>"><span class="hint">Shown on each listing, e.g. U-0001.</span></div>
      <div class="field"><label for="message">Message</label><textarea class="input" id="message" name="message" required><?= e($form['message']) ?></textarea></div>
      <div><button type="submit" class="btn btn-accent">Send message</button></div>
      <p class="fine">We use your details only to reply to this message.</p>
    </form>
  <?php endif; ?>
  </div>
</div>
<?php page_footer();
