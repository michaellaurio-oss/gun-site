<?php
// "Forgot your password?": emails a one-time link (valid 1 hour) to the address on the account.
// The answer is always the same, so the page can't be used to find out which names exist.
require __DIR__ . '/_admin.php';

$db = db();
$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = client_ip_hash();
    $st = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND attempted_at > datetime('now', '-15 minutes')");
    $st->execute([$ip]);
    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif ((int)$st->fetchColumn() >= 5) {
        $error = 'Too many tries from this connection. Wait 15 minutes and try again.';
    } else {
        $db->prepare('INSERT INTO login_attempts (ip_hash) VALUES (?)')->execute([$ip]);   // counts toward the same limit
        $who = trim((string)($_POST['who'] ?? ''));
        $st = $db->prepare('SELECT * FROM admins WHERE active = 1 AND email IS NOT NULL AND email <> \'\' AND (username = ? OR LOWER(email) = LOWER(?)) LIMIT 1');
        $st->execute([$who, $who]);
        if ($who !== '' && ($a = $st->fetch())) {
            $token = bin2hex(random_bytes(32));
            $db->prepare('UPDATE password_resets SET used_at = datetime(\'now\') WHERE admin_id = ? AND used_at IS NULL')->execute([(int)$a['id']]);
            $db->prepare("INSERT INTO password_resets (admin_id, token_hash, expires_at) VALUES (?, ?, datetime('now', '+1 hour'))")
               ->execute([(int)$a['id'], hash('sha256', $token)]);
            $link = rtrim((string)config('site_url'), '/') . url('admin/reset.php', ['token' => $token]);
            $from = mail_from();
            $headers = ['Content-Type: text/plain; charset=UTF-8'];
            if ($from !== '') {
                $headers[] = 'From: ' . preg_replace('/[\r\n]+/', ' ', $from);
            }
            $body = "Hello " . $a['first_name'] . ",\n\nSomeone asked to reset the admin password for short name \"" . $a['username'] . "\".\n"
                  . "Use this link within 1 hour to choose a new password:\n\n" . $link . "\n\n"
                  . "If you didn't ask for this, ignore this message; your password has not changed.\n";
            @mail($a['email'], '=?UTF-8?B?' . base64_encode('Reset your admin password') . '?=', $body, implode("\r\n", $headers));
            audit('reset requested', 'admin', (int)$a['id'], 'Password reset link requested', $a['username'] . ' ' . $a['first_name'] . ' ' . $a['last_name']);
        }
        $sent = true;
    }
}

admin_header('Forgot password');
?>
<div class="login-box">
  <h1 class="page-title" style="font-size:32px">Forgot your password?</h1>
  <?php if ($sent): ?>
    <div class="alert alert-ok" role="status">If that short name or email belongs to an account with an email address, a reset link is on its way. It works once and expires in 1 hour.</div>
    <p class="muted">Nothing arrived after a few minutes? Check spam, or ask an owner to set a new temporary password for you.</p>
    <p><a href="<?= e(url('admin/login.php')) ?>">Back to log in</a></p>
  <?php else: ?>
    <?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form" action="<?= e(url('admin/forgot.php')) ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="who">Short name or email</label>
        <input class="input" id="who" name="who" required autocomplete="username" autocapitalize="none" autofocus></div>
      <div><button class="btn btn-dark" type="submit">Email me a reset link</button></div>
      <p><a href="<?= e(url('admin/login.php')) ?>">Back to log in</a></p>
    </form>
  <?php endif; ?>
</div>
<?php admin_footer();
