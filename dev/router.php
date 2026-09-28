<?php
// Local dev server that mimics the live sub-folder:
//   php -S localhost:8000 dev/router.php   then open http://localhost:8000/gun-site/

$base = '/gun-site/';
$public = realpath(__DIR__ . '/../public');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/' || $path === rtrim($base, '/')) {
    header('Location: ' . $base);
    return true;
}
if (strpos($path, $base) !== 0) {
    http_response_code(404);
    echo 'Not found (site lives under ' . $base . ')';
    return true;
}

$rel = substr($path, strlen($base));
// Same protection the .htaccess files give on the live server.
if (preg_match('~^(app|data)(/|$)~', $rel) || preg_match('~(^|/)\.~', $rel)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

$file = realpath($public . '/' . $rel);
if ($file !== false && is_dir($file)) {
    $file = realpath($file . '/index.php');
}
if ($file === false || strpos($file, $public) !== 0) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

if (substr($file, -4) === '.php') {
    chdir(dirname($file));
    $_SERVER['SCRIPT_NAME'] = $path;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    require $file;
    return true;
}

$types = ['css' => 'text/css', 'js' => 'text/javascript', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
          'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
readfile($file);
return true;
