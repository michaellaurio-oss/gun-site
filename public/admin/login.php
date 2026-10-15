<?php
require __DIR__ . '/_admin.php';

// The shared password from before accounts existed (config) is now the RECOVERY password: it creates the
// first owner account, and signs in as username "recovery" if every owner is locked out.
$recoveryHash = (string)config('admin_password_hash');
$next = req_str('next', '');
if (strpos($next, config('base_url') . 'admin/') !== 0 || strpos($next, '//') !== false) {
    $next = url('admin/');
}
if (is_logged_in()) {
    redirect($next);
}

$db = db();
$noAccounts = (int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0;
$error = '';
$form = ['username' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'mobile' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $recoveryHash !== '') {
    $ip = client_ip_hash();
    $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-1 day')");
    $st = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND attempted_at > datetime('now', '-15 minutes')");
    $st->execute([$ip]);
    $mine = (int)$st->fetchColumn();
    $all = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at > datetime('now', '-1 hour')")->fetchColumn();

    // Per address: 5 wrong passwords lock that address out for 15 minutes. Site-wide: lots of
    // failures (a bot) only slow every attempt down, so nobody can lock the owner out on purpose.
    if ($all >= 50) {
        sleep(3);
    }
    $fail = function (string $msg) use ($db, $ip, &$error) {
        $db->prepare('INSERT INTO login_attempts (ip_hash) VALUES (?)')->execute([$ip]);
        usleep(700000);
        $error = $msg;
    };
    if ($mine >= 5) {
        $error = 'Too many wrong passwords. Wait 15 minutes and try again.';
    } elseif (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($noAccounts) {
        // First sign-in: the recovery password creates the first owner account.
        foreach (array_keys($form) as $k) {
            $form[$k] = trim((string)($_POST[$k] ?? ''));
        }
        $pw = (string)($_POST['new_password'] ?? '');
        if (!password_verify((string)($_POST['recovery_password'] ?? ''), $recoveryHash)) {
            $fail('The current admin password is wrong.');
        } elseif (!valid_username($form['username'])) {
            $error = 'Short name: 2 to 30 letters, numbers, dots, dashes or underscores (an employee number is fine).';
        } elseif ($form['first_name'] === '' || $form['last_name'] === '') {
            $error = 'Please enter your first and last name.';
        } elseif ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $error = "That email address doesn't look right.";
        } elseif (($p = password_problem($pw, (string)($_POST['new_password2'] ?? ''))) !== '') {
            $error = $p;
        } else {
            $db->prepare('INSERT INTO admins (username, first_name, last_name, email, mobile, password_hash, role, created_by) VALUES (?, ?, ?, ?, ?, ?, \'owner\', ?)')
               ->execute([$form['username'], $form['first_name'], $form['last_name'], $form['email'] ?: null, $form['mobile'] ?: null,
                          password_hash($pw, PASSWORD_DEFAULT), 'first sign-in']);
            $row = $db->query('SELECT * FROM admins WHERE id = ' . (int)$db->lastInsertId())->fetch();
            sign_in($row);
            $db->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([$ip]);
            audit('created', 'admin', (int)$row['id'], 'First owner account created', $row['username'] . ' ' . $row['first_name'] . ' ' . $row['last_name']);
            flash('Welcome, ' . $row['first_name'] . '. Your owner account is ready. Add the other people under Admins.');
            redirect(url('admin/admins.php'));
        }
    } else {
        $user = trim((string)($_POST['username'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        if (strtolower($user) === 'recovery') {
            if (password_verify($pw, $recoveryHash)) {
                sign_in([], true);
                $db->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([$ip]);
                audit('login', 'session', null, 'Recovery login used');
                redirect($next);
            }
            $fail('Wrong short name or password.');
        } else {
            $st = $db->prepare('SELECT * FROM admins WHERE username = ?');
            $st->execute([$user]);
            $a = $st->fetch();
            // Always run one password check, so the answer takes about as long for unknown names.
            $okPw = password_verify($pw, $a ? $a['password_hash'] : '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            if ($a && $okPw && (int)$a['active'] === 1) {
                sign_in($a);
                $db->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([$ip]);
                audit('login', 'session', (int)$a['id'], 'Signed in');
                redirect(!empty($_SESSION['admin_user']['must_change']) ? url('admin/account.php') : $next);
            }
            if ($a && $okPw) {
                $fail('That account is switched off. Ask an owner.');
            } else {
                $fail('Wrong short name or password.');
            }
        }
    }
}

admin_header($noAccounts ? 'First sign-in' : 'Log in');
?>
<div class="login-box">
  <?php if ($recoveryHash === ''): ?>
    <h1 class="page-title" style="font-size:36px">Admin login</h1>
    <div class="alert alert-err" role="alert">The admin password hasn't been set up yet. Add <code>admin_password_hash</code> to <code>app/config.local.php</code> on the server.</div>
  <?php elseif ($noAccounts): ?>
    <h1 class="page-title" style="font-size:32px">Set up the first owner</h1>
    <p class="muted">There are no admin accounts yet. Enter the current admin password once, then choose your own short name and password. After this, everyone signs in with their own account.</p>
    <?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form" action="<?= e(url('admin/login.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="field"><label for="recovery_password">Current admin password</label>
        <input class="input" id="recovery_password" name="recovery_password" type="password" required autocomplete="current-password" autofocus></div>
      <div class="field"><label for="username">Short name <span class="muted">(or employee number)</span></label>
        <input class="input" id="username" name="username" value="<?= e($form['username']) ?>" required maxlength="30" autocomplete="username"></div>
      <div class="field"><label for="first_name">First name</label>
        <input class="input" id="first_name" name="first_name" value="<?= e($form['first_name']) ?>" required autocomplete="given-name"></div>
      <div class="field"><label for="last_name">Last name</label>
        <input class="input" id="last_name" name="last_name" value="<?= e($form['last_name']) ?>" required autocomplete="family-name"></div>
      <div class="field"><label for="email">Email <span class="muted">(for "Forgot password")</span></label>
        <input class="input" id="email" name="email" type="email" value="<?= e($form['email']) ?>" autocomplete="email"></div>
      <div class="field"><label for="mobile">Mobile <span class="muted">(optional)</span></label>
        <input class="input" id="mobile" name="mobile" type="tel" value="<?= e($form['mobile']) ?>" autocomplete="tel"></div>
      <div class="field"><label for="new_password">New password <span class="muted">(at least 12 characters)</span></label>
        <input class="input" id="new_password" name="new_password" type="password" required minlength="12" autocomplete="new-password"></div>
      <div class="field"><label for="new_password2">New password again</label>
        <input class="input" id="new_password2" name="new_password2" type="password" required minlength="12" autocomplete="new-password"></div>
      <div><button class="btn btn-dark" type="submit">Create owner account</button></div>
    </form>
  <?php else: ?>
    <h1 class="page-title" style="font-size:36px">Admin login</h1>
    <?php if ($error): ?><div class="alert alert-err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form" action="<?= e(url('admin/login.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="field"><label for="username">Short name</label>
        <input class="input" id="username" name="username" required autocomplete="username" autocapitalize="none" autofocus></div>
      <div class="field"><label for="password">Password</label>
        <input class="input" id="password" name="password" type="password" required autocomplete="current-password"></div>
      <div><button class="btn btn-dark" type="submit">Log in</button></div>
      <p><a href="<?= e(url('admin/forgot.php')) ?>">Forgot your password?</a></p>
    </form>
  <?php endif; ?>
</div>
<?php admin_footer();
