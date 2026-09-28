<?php
require __DIR__ . '/_admin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $_SESSION = [];
    session_destroy();
}
redirect(url('admin/login.php'));
