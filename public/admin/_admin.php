<?php
// Shared by every admin page: login check, layout, flash messages.
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/security.php';
require __DIR__ . '/../app/photos.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

const ADMIN_IDLE_SECONDS = 4 * 3600;
const LISTING_STATUSES = ['draft', 'coming_soon', 'available', 'on_hold', 'pending', 'sold', 'withdrawn'];
const CONDITIONS = ['New', 'Like New', 'Excellent', 'Very Good', 'Good', 'Fair', 'Poor'];
const CATEGORIES = ['handgun', 'rifle', 'shotgun', 'other'];

function status_name(string $s): string
{
    return ['draft' => 'Draft (hidden)', 'coming_soon' => 'Coming soon', 'available' => 'Available', 'on_hold' => 'On hold',
            'pending' => 'Sale pending', 'sold' => 'Sold (hidden)', 'withdrawn' => 'Withdrawn (hidden)'][$s] ?? $s;
}

function is_logged_in(): bool
{
    start_session();
    if (empty($_SESSION['admin']) || (time() - ($_SESSION['admin_seen'] ?? 0)) > ADMIN_IDLE_SECONDS) {
        unset($_SESSION['admin']);
        return false;
    }
    $_SESSION['admin_seen'] = time();
    return true;
}

function require_login(): void
{
    if (!is_logged_in()) {
        $back = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . url('admin/login.php', ['next' => $back]));
        exit;
    }
}

/** POST-only actions must pass CSRF; anything else is rejected. */
function require_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
        flash('Your session expired. Please try again.', 'err');
        redirect_back();
    }
}

function flash(string $msg, string $type = 'ok'): void
{
    start_session();
    $_SESSION['flash'][] = [$type, $msg];
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
    exit;
}

function redirect_back(): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $base = config('base_url') . 'admin/';
    $path = parse_url($ref, PHP_URL_PATH) ?: '';
    redirect(strpos($path, $base) === 0 ? $ref : url('admin/'));
}

/** Turn SQLite trigger / constraint errors into something readable. */
function friendly_db_error(Throwable $e): string
{
    $m = $e->getMessage();
    if (preg_match('/: \d+ (.*)$/s', $m, $x)) {
        $m = $x[1];
    }
    if (stripos($m, 'UNIQUE constraint failed: listings.stock_number') !== false) {
        return 'That stock number is already used by another listing.';
    }
    if (stripos($m, 'CHECK constraint failed') !== false && stripos($m, 'stock_number') !== false) {
        return 'A stock number is required once a listing is past Draft / Coming soon.';
    }
    if (stripos($m, 'UNIQUE constraint failed: firearms.slug') !== false) {
        return 'Another model already uses that URL name (slug).';
    }
    return $m;
}

function admin_header(string $title, string $active = ''): void
{
    $nav = ['listings' => ['Listings', url('admin/')], 'models' => ['Models', url('admin/models.php')],
            'messages' => ['Messages', url('admin/messages.php')]];
    $unread = 0;
    try {
        $unread = (int)db()->query('SELECT COUNT(*) FROM contact_messages WHERE handled = 0')->fetchColumn();
    } catch (Throwable $e) {
    }
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> – Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
<script src="<?= e(asset('assets/js/admin.js')) ?>" defer></script>
</head>
<body class="admin">
<a class="skip-link" href="#main">Skip to content</a>
<header class="admin-bar">
  <div class="container admin-bar-inner">
    <a class="logo" href="<?= e(url('admin/')) ?>">Admin</a>
    <?php if (!empty($_SESSION['admin'])): ?>
      <nav aria-label="Admin">
        <?php foreach ($nav as $k => [$label, $href]): ?>
          <a href="<?= e($href) ?>"<?= $k === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?><?= $k === 'messages' && $unread ? ' <span class="fcount">' . $unread . '</span>' : '' ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="admin-bar-right">
        <a href="<?= e(url()) ?>" target="_blank" rel="noopener">View site</a>
        <form method="post" action="<?= e(url('admin/logout.php')) ?>"><?= csrf_field() ?><button type="submit" class="link-btn">Log out</button></form>
      </div>
    <?php endif; ?>
  </div>
</header>
<main id="main" class="container admin-main">
<?php
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<div class="alert alert-' . ($type === 'err' ? 'err' : 'ok') . '" role="' . ($type === 'err' ? 'alert' : 'status') . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

function admin_footer(): void
{
    echo "</main>\n</body>\n</html>\n";
}

function opt(string $value, string $label, $current): string
{
    return '<option value="' . e($value) . '"' . ((string)$current === $value ? ' selected' : '') . '>' . e($label) . '</option>';
}

/** Nullable form values: '' -> NULL, numbers parsed. */
function in_text(string $k, int $max = 5000): ?string
{
    $v = post_str($k, $max);
    return $v === '' ? null : $v;
}
function in_num(string $k): ?float
{
    $v = str_replace([',', '$'], '', post_str($k, 40));
    return $v === '' || !is_numeric($v) ? null : (float)$v;
}
function in_int(string $k): ?int
{
    $v = in_num($k);
    return $v === null ? null : (int)round($v);
}
function in_bool(string $k): ?int
{
    $v = post_str($k, 3);
    return $v === '' ? null : ($v === '1' ? 1 : 0);
}
