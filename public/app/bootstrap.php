<?php
// Included first by every page.

declare(strict_types=1);

$GLOBALS['config'] = require __DIR__ . '/config.php';
$GLOBALS['shop']   = require __DIR__ . '/shop.php';

if (config('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/listings.php';
require __DIR__ . '/layout.php';

function config(string $key)
{
    return $GLOBALS['config'][$key] ?? null;
}

function shop(string $key): string
{
    return (string)($GLOBALS['shop'][$key] ?? '');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $path = config('db_path');
        if (!is_file($path)) {
            throw new RuntimeException('Database file not found.');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    return $pdo;
}
