<?php
require __DIR__ . '/_admin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    if (is_logged_in()) {
        audit('logout', 'session', null, 'Signed out');
    }
    $_SESSION = [];
    session_destroy();
}
redirect(url('admin/login.php'));
