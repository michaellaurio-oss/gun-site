<?php
// Admin accounts (owners only): add people, change role or details, switch accounts on or off,
// set a temporary password. Passwords are never shown; only hashes are stored.
require __DIR__ . '/_admin.php';
require_owner();

$db = db();
$me = current_admin();
$self = url('admin/admins.php');

/** A random temporary password that is easy to read out: three words-ish chunks of letters and digits. */
function temp_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 16; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        if ($i % 4 === 3 && $i < 15) {
            $out .= '-';
        }
    }
    return $out;
}

$label = fn(array $a) => $a['username'] . ' ' . $a['first_name'] . ' ' . $a['last_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $st = $db->prepare('SELECT * FROM admins WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch() ?: null;
    $role = ($_POST['role'] ?? '') === 'owner' ? 'owner' : 'staff';

    try {
        if ($action === 'add') {
            $f = [];
            foreach (['username', 'first_name', 'last_name', 'email', 'mobile'] as $k) {
                $f[$k] = trim((string)($_POST[$k] ?? ''));
            }
            if (!valid_username($f['username'])) {
                throw new RuntimeException('Short name: 2 to 30 letters, numbers, dots, dashes or underscores. "recovery" is reserved.');
            }
            if ($f['first_name'] === '' || $f['last_name'] === '') {
                throw new RuntimeException('First and last name are required.');
            }
            if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("That email address doesn't look right.");
            }
            $temp = temp_password();
            $db->prepare('INSERT INTO admins (username, first_name, last_name, email, mobile, password_hash, role, must_change_password, created_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)')
               ->execute([$f['username'], $f['first_name'], $f['last_name'], $f['email'] ?: null,
                          preg_replace('/[^0-9+()\-. ]/', '', $f['mobile']) ?: null, password_hash($temp, PASSWORD_DEFAULT), $role, $me['username']]);
            $newId = (int)$db->lastInsertId();
            audit('created', 'admin', $newId, 'Added ' . ($role === 'owner' ? 'owner' : 'staff') . ' account', $f['username'] . ' ' . $f['first_name'] . ' ' . $f['last_name']);
            $_SESSION['temp_pw'] = ['id' => $newId, 'who' => $f['first_name'] . ' ' . $f['last_name'] . ' (' . $f['username'] . ')', 'pw' => $temp];
            flash('Account added. Give them the temporary password shown below; they must choose their own at first login.');
        } elseif ($a && $action === 'save') {
            $new = ['first_name' => trim((string)($_POST['first_name'] ?? '')), 'last_name' => trim((string)($_POST['last_name'] ?? '')),
                    'email' => trim((string)($_POST['email'] ?? '')), 'mobile' => preg_replace('/[^0-9+()\-. ]/', '', trim((string)($_POST['mobile'] ?? ''))),
                    'role' => $role, 'active' => isset($_POST['active']) ? 1 : 0];
            if ($new['first_name'] === '' || $new['last_name'] === '') {
                throw new RuntimeException('First and last name are required.');
            }
            if ($new['email'] !== '' && !filter_var($new['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("That email address doesn't look right.");
            }
            $db->prepare('UPDATE admins SET first_name = :first_name, last_name = :last_name, email = :email, mobile = :mobile, role = :role, active = :active WHERE id = :id')
               ->execute([':first_name' => $new['first_name'], ':last_name' => $new['last_name'], ':email' => $new['email'] ?: null,
                          ':mobile' => $new['mobile'] ?: null, ':role' => $new['role'], ':active' => $new['active'], ':id' => $id]);
            $diff = audit_diff($a, $new);
            if ($diff) {
                audit('edited', 'admin', $id, 'Changed account details', $label($a), $diff);
            }
            flash('Saved.');
        } elseif ($a && $action === 'reset') {
            $temp = temp_password();
            $db->prepare('UPDATE admins SET password_hash = ?, must_change_password = 1 WHERE id = ?')->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
            audit('password', 'admin', $id, 'Set a temporary password', $label($a));
            $_SESSION['temp_pw'] = ['id' => $id, 'who' => $a['first_name'] . ' ' . $a['last_name'] . ' (' . $a['username'] . ')', 'pw' => $temp];
            flash('Temporary password set. They must choose their own at next login.');
        }
    } catch (Throwable $e) {
        $msg = $e instanceof PDOException ? friendly_db_error($e) : $e->getMessage();
        if ($e instanceof PDOException && strpos($e->getMessage(), 'UNIQUE') !== false) {
            $msg = 'That short name is already used.';
        }
        flash($msg, 'err');
    }
    redirect($self);
}

$temp = $_SESSION['temp_pw'] ?? null;
unset($_SESSION['temp_pw']);   // shown once

$rows = $db->query('SELECT * FROM admins ORDER BY active DESC, role, last_name COLLATE NOCASE, first_name COLLATE NOCASE')->fetchAll();
$edit = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

admin_header('Admins', 'admins');
?>
<div class="admin-head"><h1 class="page-title">Admins</h1></div>
<?php if (!empty($me['recovery'])): ?>
  <div class="alert alert-err" role="alert">You are signed in with the recovery login. Create or fix accounts here, then log out and sign in with your own.</div>
<?php endif; ?>
<?php if ($temp): ?>
  <div class="alert alert-ok" role="status">
    Temporary password for <strong><?= e($temp['who']) ?></strong>:
    <code class="mono" style="font-size:18px;user-select:all"><?= e($temp['pw']) ?></code>
    <br><span class="muted">Shown only once. Give it to them in person or by phone; they must choose a new one at first login.</span>
  </div>
<?php endif; ?>

<table class="table admin-table">
  <thead><tr><th>Name</th><th>Short name</th><th>Role</th><th>Email / mobile</th><th>Last login</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $a): ?>
    <tr<?= $a['active'] ? '' : ' class="muted"' ?>>
      <td><strong><?= e($a['first_name'] . ' ' . $a['last_name']) ?></strong></td>
      <td class="mono"><?= e($a['username']) ?></td>
      <td><?= $a['role'] === 'owner' ? 'Owner' : 'Staff' ?></td>
      <td><?= e((string)$a['email']) ?><?= $a['email'] && $a['mobile'] ? '<br>' : '' ?><?= e((string)$a['mobile']) ?></td>
      <td><?= $a['last_login_at'] ? e(local_time($a['last_login_at'])) : '<span class="muted">never</span>' ?></td>
      <td><?= $a['active'] ? ($a['must_change_password'] ? 'Temporary password' : 'Active') : 'Switched off' ?></td>
      <td><a href="<?= e(url('admin/admins.php', ['edit' => $a['id']])) ?>#edit">Edit</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php
$editRow = null;
foreach ($rows as $a) {
    if ((int)$a['id'] === $edit) {
        $editRow = $a;
    }
}
if ($editRow): $a = $editRow; ?>
<form method="post" class="admin-form" id="edit" action="<?= e($self) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
  <fieldset>
    <legend>Edit <?= e($a['first_name'] . ' ' . $a['last_name']) ?> <span class="muted mono">(<?= e($a['username']) ?>)</span></legend>
    <div class="grid-2">
      <div class="field"><label for="first_name">First name</label><input class="input" id="first_name" name="first_name" value="<?= e($a['first_name']) ?>" required></div>
      <div class="field"><label for="last_name">Last name</label><input class="input" id="last_name" name="last_name" value="<?= e($a['last_name']) ?>" required></div>
      <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" value="<?= e((string)$a['email']) ?>"></div>
      <div class="field"><label for="mobile">Mobile</label><input class="input" id="mobile" name="mobile" type="tel" value="<?= e((string)$a['mobile']) ?>"></div>
      <div class="field"><label for="role">Role</label>
        <select class="select" id="role" name="role"><?= opt('staff', 'Staff — listings, photos, firearms', $a['role']) ?><?= opt('owner', 'Owner — everything, incl. admins and the log', $a['role']) ?></select></div>
      <div class="field"><label><input type="checkbox" name="active" value="1"<?= $a['active'] ? ' checked' : '' ?>> Account is switched on</label>
        <span class="hint">Switch off instead of deleting: their name stays in the activity log. There must always be one active owner.</span></div>
    </div>
    <div class="form-actions"><button class="btn btn-dark" type="submit">Save</button> <a class="btn btn-outline" href="<?= e($self) ?>">Cancel</a></div>
  </fieldset>
</form>
<form method="post" class="admin-form" action="<?= e($self) ?>" onsubmit="return confirm('Set a new temporary password for this person? Their old password stops working.');">
  <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
  <button class="btn btn-outline" type="submit">Set a temporary password…</button>
</form>
<?php endif; ?>

<form method="post" class="admin-form" action="<?= e($self) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <fieldset>
    <legend>Add a person</legend>
    <div class="grid-2">
      <div class="field"><label for="n_username">Short name <span class="muted">(or employee number)</span></label>
        <input class="input mono" id="n_username" name="username" required maxlength="30" autocapitalize="none"></div>
      <div class="field"><label for="n_role">Role</label>
        <select class="select" id="n_role" name="role"><?= opt('staff', 'Staff — listings, photos, firearms', 'staff') ?><?= opt('owner', 'Owner — everything, incl. admins and the log', 'staff') ?></select></div>
      <div class="field"><label for="n_first">First name</label><input class="input" id="n_first" name="first_name" required></div>
      <div class="field"><label for="n_last">Last name</label><input class="input" id="n_last" name="last_name" required></div>
      <div class="field"><label for="n_email">Email <span class="muted">(for "Forgot password")</span></label><input class="input" id="n_email" name="email" type="email"></div>
      <div class="field"><label for="n_mobile">Mobile</label><input class="input" id="n_mobile" name="mobile" type="tel"></div>
    </div>
    <p class="hint">A temporary password is made for them and shown once on the next screen.</p>
    <div class="form-actions"><button class="btn btn-dark" type="submit">Add person</button></div>
  </fieldset>
</form>
<?php admin_footer();
