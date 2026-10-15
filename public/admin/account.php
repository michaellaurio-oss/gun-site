<?php
// My account: contact details and my own password. Everyone can reach this page.
require __DIR__ . '/_admin.php';
require_login();

$me = current_admin();
$db = db();
if (!empty($me['recovery'])) {
    flash('The recovery login has no account of its own. Use Admins to manage accounts.', 'err');
    redirect(url('admin/admins.php'));
}
$st = $db->prepare('SELECT * FROM admins WHERE id = ?');
$st->execute([(int)$me['id']]);
$a = $st->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'password') {
        $pw = (string)($_POST['new_password'] ?? '');
        if (!password_verify((string)($_POST['current_password'] ?? ''), $a['password_hash'])) {
            flash('Your current password is wrong.', 'err');
        } elseif (($p = password_problem($pw, (string)($_POST['new_password2'] ?? ''))) !== '') {
            flash($p, 'err');
        } else {
            $db->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE id = ?')
               ->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$a['id']]);
            $_SESSION['admin_user']['must_change'] = false;
            audit('password', 'admin', (int)$a['id'], 'Changed own password', $a['username'] . ' ' . $a['first_name'] . ' ' . $a['last_name']);
            flash('Password changed.');
            redirect(url('admin/'));
        }
    } elseif ($action === 'test_mail') {
        if (!$a['email']) {
            flash('Add your email address first, save it, then send the test.', 'err');
        } else {
            $ok = @mail($a['email'], '=?UTF-8?B?' . base64_encode('Admin test email') . '?=',
                "This is a test from the admin site. If you can read this, password reset emails will reach you.\n",
                "Content-Type: text/plain; charset=UTF-8\r\nFrom: " . mail_from());
            flash($ok ? 'The server accepted the test email for ' . $a['email'] . '. Check your inbox (and spam) in a minute or two.' : 'The server refused to send the email. Password reset emails will not work until the host mail settings are fixed.', $ok ? 'ok' : 'err');
        }
    } elseif ($action === 'contact') {
        $email = trim((string)($_POST['email'] ?? ''));
        $mobile = trim((string)($_POST['mobile'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash("That email address doesn't look right.", 'err');
        } else {
            $db->prepare('UPDATE admins SET email = ?, mobile = ? WHERE id = ?')->execute([$email ?: null, mobile_clean($mobile) ?: null, (int)$a['id']]);
            audit('edited', 'admin', (int)$a['id'], 'Updated own contact details', $a['username'] . ' ' . $a['first_name'] . ' ' . $a['last_name']);
            flash('Contact details saved.');
        }
    }
    redirect(url('admin/account.php'));
}

/** Keep digits, spaces, + ( ) - and dots only. */
function mobile_clean(string $m): string
{
    return trim(preg_replace('/[^0-9+()\-. ]/', '', mb_substr($m, 0, 30)));
}

admin_header('My account');
?>
<div class="admin-head"><h1 class="page-title">My account</h1></div>
<?php if (!empty($me['must_change'])): ?>
  <div class="alert alert-err" role="alert">You are using a temporary password. Choose your own below to continue.</div>
<?php endif; ?>
<p class="muted"><strong><?= e($a['first_name'] . ' ' . $a['last_name']) ?></strong> · short name <span class="mono"><?= e($a['username']) ?></span> · <?= $a['role'] === 'owner' ? 'Owner' : 'Staff' ?></p>

<form method="post" class="admin-form" action="<?= e(url('admin/account.php')) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="password">
  <fieldset>
    <legend>Change my password</legend>
    <div class="grid-2">
      <div class="field span-2"><label for="current_password">Current password</label>
        <input class="input" id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
      <div class="field"><label for="new_password">New password <span class="muted">(at least 12 characters)</span></label>
        <input class="input" id="new_password" name="new_password" type="password" required minlength="12" autocomplete="new-password"></div>
      <div class="field"><label for="new_password2">New password again</label>
        <input class="input" id="new_password2" name="new_password2" type="password" required minlength="12" autocomplete="new-password"></div>
    </div>
    <div class="form-actions"><button class="btn btn-dark" type="submit">Change password</button></div>
  </fieldset>
</form>

<form method="post" class="admin-form" action="<?= e(url('admin/account.php')) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="contact">
  <fieldset>
    <legend>How to reach me</legend>
    <div class="grid-2">
      <div class="field"><label for="email">Email <span class="muted">(used for "Forgot password")</span></label>
        <input class="input" id="email" name="email" type="email" value="<?= e((string)$a['email']) ?>" autocomplete="email"></div>
      <div class="field"><label for="mobile">Mobile</label>
        <input class="input" id="mobile" name="mobile" type="tel" value="<?= e((string)$a['mobile']) ?>" autocomplete="tel"></div>
    </div>
    <div class="form-actions"><button class="btn btn-dark" type="submit">Save</button></div>
  </fieldset>
</form>
<form method="post" class="admin-form" action="<?= e(url('admin/account.php')) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="test_mail">
  <button class="btn btn-outline" type="submit">Send me a test email</button>
  <span class="hint">Checks that "Forgot password" emails can reach your saved address.</span>
</form>
<?php admin_footer();
