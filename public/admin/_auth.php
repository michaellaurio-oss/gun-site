<?php
// Admin accounts, roles and the audit log (migration 009). Included by _admin.php.

/** The signed-in admin: ['id' (0 = recovery login), 'username', 'name', 'role', 'recovery', 'must_change'] or null. */
function current_admin(): ?array
{
    return is_logged_in() ? ($_SESSION['admin_user'] ?? null) : null;
}

function is_owner(): bool
{
    $a = current_admin();
    return $a !== null && $a['role'] === 'owner';
}

/** Owner-only pages and actions. Staff are sent back to the listings with a message. */
function require_owner(): void
{
    require_login();
    if (!is_owner()) {
        flash('That is for owners only.', 'err');
        redirect(url('admin/'));
    }
}

/** Start the session for a signed-in account row (from table admins) or the recovery login. */
function sign_in(array $a, bool $recovery = false): void
{
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['admin_user'] = $recovery
        ? ['id' => 0, 'username' => 'recovery', 'name' => 'Recovery login', 'role' => 'owner', 'recovery' => true, 'must_change' => false]
        : admin_session_row($a);
    if (!$recovery) {
        db()->prepare("UPDATE admins SET last_login_at = datetime('now') WHERE id = ?")->execute([(int)$a['id']]);
    }
}

function admin_session_row(array $a): array
{
    return ['id' => (int)$a['id'], 'username' => $a['username'], 'name' => trim($a['first_name'] . ' ' . $a['last_name']),
            'role' => $a['role'], 'recovery' => false, 'must_change' => (bool)$a['must_change_password']];
}

/** Re-read the account on every request: a deactivated or demoted admin loses access at once. */
function refresh_admin_session(): bool
{
    $u = $_SESSION['admin_user'] ?? null;
    if (!$u) {
        return false;   // a session from before accounts existed: sign in again
    }
    if (!empty($u['recovery'])) {
        return true;
    }
    $st = db()->prepare('SELECT * FROM admins WHERE id = ? AND active = 1');
    $st->execute([(int)$u['id']]);
    $a = $st->fetch();
    if (!$a) {
        return false;
    }
    $_SESSION['admin_user'] = admin_session_row($a);
    return true;
}

/**
 * Write one line to the audit log. Never breaks the action it records.
 * $label names the thing ("U-0004 Glock G19 Gen3"); $details are the changed fields, old -> new.
 */
function audit(string $action, string $entityType, ?int $entityId, string $summary, ?string $label = null, array $details = []): void
{
    try {
        $who = $_SESSION['admin_user'] ?? null;
        db()->prepare('INSERT INTO audit_log (admin_id, username, admin_name, action, entity_type, entity_id, entity_label, summary, details)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $who && (int)$who['id'] > 0 ? (int)$who['id'] : null,
            $who['username'] ?? 'system', $who['name'] ?? 'System',
            $action, $entityType, $entityId, $label, $summary,
            $details ? implode("\n", $details) : null,
        ]);
    } catch (Throwable $e) {
        error_log('gun-site audit: ' . $e->getMessage());
    }
}

/** "field: old → new" lines for the values that really changed (long text is shortened). */
function audit_diff(array $old, array $new, array $skip = []): array
{
    $short = function ($v): string {
        $v = trim((string)$v);
        $v = $v === '' ? '(empty)' : preg_replace('/\s+/', ' ', $v);
        return mb_strlen($v) > 70 ? mb_substr($v, 0, 70) . '…' : $v;
    };
    $skip = array_merge($skip, ['updated_at', 'created_at', 'password_hash']);
    $out = [];
    foreach ($new as $k => $v) {
        if (in_array($k, $skip, true) || !array_key_exists($k, $old)) {
            continue;
        }
        $o = $old[$k];
        if ((string)$o === (string)$v || (($o === null || $o === '') && ($v === null || $v === ''))) {
            continue;
        }
        $out[] = str_replace('_', ' ', preg_replace('/^spec_/', 'spec: ', $k)) . ': ' . $short($o) . ' → ' . $short($v);
    }
    return $out;
}

/** Who last changed a record, from the log: ['admin_name', 'username', 'at', 'summary'] or null. */
function last_change(string $entityType, int $entityId): ?array
{
    try {
        $st = db()->prepare('SELECT admin_name, username, at, summary FROM audit_log
                              WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$entityType, $entityId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** UTC database timestamp -> shop time, e.g. "Oct 14, 2026, 3:05 pm". */
function local_time(?string $utc): string
{
    if (!$utc) {
        return '';
    }
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone('America/Los_Angeles'));
        return $d->format('M j, Y, g:i a');
    } catch (Throwable $e) {
        return $utc;
    }
}

function valid_username(string $u): bool
{
    return (bool)preg_match('/^[A-Za-z0-9._-]{2,30}$/', $u) && strtolower($u) !== 'recovery';
}

/** Password rule shared by every place a password is set. Returns an error message or ''. */
function password_problem(string $pw, string $again): string
{
    if (mb_strlen($pw) < 12) {
        return 'Please use at least 12 characters.';
    }
    if ($pw !== $again) {
        return 'The two passwords did not match.';
    }
    return '';
}

/** "U-0004 Glock G19 Gen3" for the log. */
function listing_label(int $id): string
{
    $st = db()->prepare('SELECT l.stock_number, f.manufacturer, f.model FROM listings l JOIN firearms f ON f.id = l.firearm_id WHERE l.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? trim(($r['stock_number'] ?: 'no stock #') . ' ' . $r['manufacturer'] . ' ' . $r['model']) : '#' . $id;
}

function firearm_label(int $id): string
{
    $st = db()->prepare('SELECT manufacturer, model FROM firearms WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? trim($r['manufacturer'] . ' ' . $r['model']) : '#' . $id;
}
/** Sender for emails the admin sends (password links, test mail). */
function mail_from(): string
{
    $from = (string)(config('contact_from') ?: config('contact_to'));
    if ($from === '') {
        $host = (string)parse_url((string)config('site_url'), PHP_URL_HOST);
        $from = 'noreply@' . ($host ?: 'localhost');
    }
    return preg_replace('/[\r\n]+/', ' ', $from);
}

/**
 * One consistent copy of the database per day (data/backups/gun_specs-YYYYMMDD.db, newest 14 kept),
 * made the first time anyone opens an admin page that day. data/ is blocked from the web.
 * Never lets a failure get in the way of the page.
 */
function auto_backup(): void
{
    try {
        $dir = dirname((string)config('db_path')) . '/backups';
        $file = $dir . '/gun_specs-' . gmdate('Ymd') . '.db';
        if (is_file($file)) {
            return;
        }
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return;
        }
        db()->exec("VACUUM INTO '" . str_replace("'", "''", $file) . "'");
        $all = glob($dir . '/gun_specs-*.db') ?: [];
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - 14)) as $old) {
            @unlink($old);
        }
    } catch (Throwable $e) {
        error_log('gun-site auto_backup: ' . $e->getMessage());
    }
}

/** Date of the newest automatic copy, or null. */
function last_auto_backup(): ?string
{
    $all = glob(dirname((string)config('db_path')) . '/backups/gun_specs-*.db') ?: [];
    if (!$all) {
        return null;
    }
    sort($all);
    return preg_match('/(\d{4})(\d{2})(\d{2})\.db$/', end($all), $m) ? "$m[1]-$m[2]-$m[3]" : null;
}