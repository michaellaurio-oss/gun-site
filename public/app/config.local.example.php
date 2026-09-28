<?php
// Copy to config.local.php (same folder) and fill in. config.local.php is not committed.
// Generate a password hash with:  php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"

return [
    // 'base_url'            => '/gun-site/',
    // 'db_path'             => __DIR__ . '/../data/gun_specs.db',
    'admin_password_hash' => '',
    'contact_to'          => '',
    'contact_from'        => '',
    'debug'               => false,
];
