<?php
// Copy to config.local.php (same folder) and fill in. config.local.php is not committed.
// Generate a password hash with:  php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
// Generate an ip_salt with:       php -r "echo bin2hex(random_bytes(32));"
// (deploy/password-tool.php fills in both.)

return [
    // 'base_url'            => '/gun-site/',
    // 'db_path'             => __DIR__ . '/../data/gun_specs.db',
    'admin_password_hash' => '',
    // Random secret used to hash visitor IP addresses (login throttling, contact form).
    'ip_salt'             => '',
    'contact_to'          => '',
    'contact_from'        => '',
    'debug'               => false,
];
