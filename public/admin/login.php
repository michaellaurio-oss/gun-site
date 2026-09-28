<?php
require __DIR__ . '/_admin.php';

$hash = (string)config('admin_password_hash');
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
if (strpos($next, config('base_url') . 'admin/') !== 0 || strpos($next, '//') !== false) {
    $next = url('admin/');
}
if (is_logged_in()) {
    redirect($next);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hash !== '') {
    $ip = client_ip_hash();
    $db = db();
    $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-1 day')");
    $st = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND attempted_at > datetime('now', '-15 minutes')");
    $st->execute([$ip]);
    $mine = (int)$st->fetchColumn();
    $all = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at > datetime('now', '-1 hour')")->fetchColumn();

    if ($mine >= 5 || $all >= 50) {
        $error = 'Too many wrong passwords. Wait 15 minutes and try again.';
    } elseif (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (password_verify((string)($_POST['password'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_seen'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $db->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([$ip]);
        redirect($next);
    } else {
        $db->prepare('INSERT INTO login_attempts (ip_hash) VALUES (?)')->execute([$ip]);
        usleep(700000);
        $error = 'Wrong password.';
    }
}

admin_header('Log in');
?>
<div class="login-box">
  <h1 class="page-title" style="font-size:36px">Admin login</h1>
  <?php if ($hash === ''): ?>
    <div class="alert alert-err" role="alert">The admin password hasn't been set up yet. Add <code>admin_password_hash</code> to <code>app/config.local.php</code> on the server.</div>
  <?php else: ?>
    <?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form" action="<?= e(url('admin/login.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="field"><label for="password">Password</label>
        <input class="input" id="password" name="password" type="password" required autocomplete="current-password" autofocus></div>
      <div><button class="btn btn-dark" type="submit">Log in</button></div>
    </form>
  <?php endif; ?>
</div>
<?php admin_footer();
