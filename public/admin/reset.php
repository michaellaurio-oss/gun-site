<?php
// Opened from the emailed link: choose a new password. The link works once, for 1 hour.
require __DIR__ . '/_admin.php';

$db = db();
$token = (string)req_str('token', '');
$row = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $st = $db->prepare("SELECT r.id AS rid, a.* FROM password_resets r JOIN admins a ON a.id = r.admin_id
                         WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > datetime('now') AND a.active = 1");
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch() ?: null;
}

$error = '';
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string)($_POST['new_password'] ?? '');
    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (($p = password_problem($pw, (string)($_POST['new_password2'] ?? ''))) !== '') {
        $error = $p;
    } else {
        $db->beginTransaction();
        $db->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE id = ?')
           ->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$row['id']]);
        $db->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE admin_id = ? AND used_at IS NULL")->execute([(int)$row['id']]);
        $db->commit();
        audit('password', 'admin', (int)$row['id'], 'Password reset with an emailed link (' . $row['username'] . ')', $row['username'] . ' ' . $row['first_name'] . ' ' . $row['last_name']);
        flash('Password changed. Please log in.');
        redirect(url('admin/login.php'));
    }
}

admin_header('Choose a new password');
?>
<div class="login-box">
  <h1 class="page-title" style="font-size:32px">Choose a new password</h1>
  <?php if (!$row): ?>
    <div class="alert alert-err" role="alert">This link has expired or was already used.</div>
    <p><a href="<?= e(url('admin/forgot.php')) ?>">Ask for a new link</a></p>
  <?php else: ?>
    <p class="muted">For <strong><?= e($row['first_name'] . ' ' . $row['last_name']) ?></strong> (<?= e($row['username']) ?>).</p>
    <?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form" action="<?= e(url('admin/reset.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="field"><label for="new_password">New password <span class="muted">(at least 12 characters)</span></label>
        <input class="input" id="new_password" name="new_password" type="password" required minlength="12" autocomplete="new-password" autofocus></div>
      <div class="field"><label for="new_password2">New password again</label>
        <input class="input" id="new_password2" name="new_password2" type="password" required minlength="12" autocomplete="new-password"></div>
      <div><button class="btn btn-dark" type="submit">Save new password</button></div>
    </form>
  <?php endif; ?>
</div>
<?php admin_footer();
