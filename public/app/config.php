<?php
// Site configuration. Secrets and per-server values go in config.local.php
// (same folder, never committed); see config.local.example.php.

$config = [
    // URL path the site is served from, with leading and trailing slash.
    'base_url'            => '/gun-site/',
    // SQLite database file (the data/ folder is blocked from the web by .htaccess).
    'db_path'             => __DIR__ . '/../data/gun_specs.db',
    // password_hash() of the admin password. Empty = admin login disabled.
    'admin_password_hash' => '',
    // Where "Ask about this gun" messages are emailed. Empty = saved to the DB only.
    'contact_to'          => '',
    'contact_from'        => '',
    // Show PHP errors in the browser (local development only).
    'debug'               => false,
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace($config, require $local);
}

return $config;
