<?php
// Manufacturer stock photos per firearm model: stock-photos/<slug>.jpg + <slug>-sm.jpg.
// New listings without their own photos show them, labelled "Stock photo" (see stock_photo_url()).
// Used by Admin > Firearms (upload / fetch / remove) and dev/fetch_stock_photos.php (bulk).
// Only the manufacturer's own product pages are used; CA DOJ roster pages have no photos.

const STOCK_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const STOCK_MAX_EDGE = 1600;
const STOCK_SMALL_EDGE = 800;

function stock_photos_dir(): string
{
    return dirname(__DIR__) . '/stock-photos';
}

/** Manufacturer page to look for a photo on: product URL, else the spec source unless it's the CA roster. */
function stock_page_for(array $firearm): ?string
{
    foreach (['product_url', 'source_url'] as $col) {
        $u = trim((string)($firearm[$col] ?? ''));
        if ($u !== '' && preg_match('~^https?://~i', $u) && stripos($u, 'oag.ca.gov') === false) {
            return $u;
        }
    }
    return null;
}

/** GET a URL. Returns [HTTP code, body, final URL after redirects]. */
function stock_http_get(string $url, string $accept = 'text/html,*/*'): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 40, CURLOPT_USERAGENT => STOCK_UA, CURLOPT_ENCODING => '',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'Accept-Language: en-US,en;q=0.9'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [$code, $body === false ? '' : $body, $final ?: $url];
}

function stock_absolute_url(string $src, string $base): string
{
    $src = html_entity_decode(trim($src), ENT_QUOTES);
    if (preg_match('~^https?://~i', $src)) {
        return $src;
    }
    $p = parse_url($base);
    $origin = $p['scheme'] . '://' . $p['host'];
    if (strpos($src, '//') === 0) {
        return $p['scheme'] . ':' . $src;
    }
    $path = $src[0] === '/' ? $src : preg_replace('~/[^/]*$~', '/', $p['path'] ?? '/') . $src;
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') {
            array_pop($parts);   // "../" past the site root stays at the root
        } elseif ($seg !== '.' && $seg !== '') {
            $parts[] = $seg;
        }
    }
    return $origin . '/' . implode('/', $parts);
}

function stock_meta_image(string $html): ?string
{
    foreach (['og:image:secure_url', 'og:image:url', 'og:image', 'twitter:image'] as $prop) {
        $q = preg_quote($prop, '~');
        if (preg_match('~<meta[^>]+(?:property|name)=["\']' . $q . '["\'][^>]*content=["\']([^"\']+)~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']' . $q . '["\']~i', $html, $m)) {
            return $m[1];
        }
    }
    if (preg_match('~"image"\s*:\s*\[?\s*"([^"]+\.(?:jpe?g|png|webp)[^"]*)"~i', $html, $m)) {   // JSON-LD product data
        return stripslashes($m[1]);
    }
    return null;
}

/** The product image on a manufacturer page, with site-specific rules where the page tags don't name it. */
function stock_find_image(string $html, string $page, ?string $knownImage = null): ?string
{
    $host = preg_replace('~^www\.~', '', (string)parse_url($page, PHP_URL_HOST));
    $pick = null;
    switch ($host) {
        case 'ruger.com':
            if (preg_match('~/productImages/\d+/detail/1\.(?:jpe?g|png)~i', $html, $m)) {
                $pick = $m[0];
            }
            break;
        case 'marlinfirearms.com':
            $pick = $knownImage ?: (preg_match('~/prodimages/\d+/hero\.jpg~i', $html, $m) ? $m[0] : null);
            break;
        case 'czfirearms.com':
            if (preg_match('~/storage-pim/assets/large_[^"\' ]+\.(?:webp|png|jpe?g)~i', $html, $m)
                || preg_match('~/storage-pim/assets/[^"\' ]+\.(?:webp|png|jpe?g)~i', $html, $m)) {
                $pick = $m[0];
            }
            break;
        case 'wilsoncombat.com':
            if (preg_match('~[^"\' ]*media/wysiwyg/wilsoncombat/catalog/[^"\' ]*hero[^"\' ]*\.jpe?g~i', $html, $m)
                || preg_match('~[^"\' ]*media/wysiwyg/wilsoncombat/catalog/[^"\' ]+\.jpe?g~i', $html, $m)) {
                $pick = $m[0];
            }
            break;
        case 'us.glock.com':
            if (preg_match_all('~eu-images\.contentstack\.com%2Fv3%2Fassets%2F[^&"]+~', $html, $m)) {
                foreach ($m[0] as $enc) {
                    $u = 'https://' . rawurldecode($enc);
                    if (!preg_match('~button|specs|prop65|logo|icon|flag|badge~i', basename($u))) {
                        $pick = $u;
                        break;
                    }
                }
            }
            break;
    }
    $pick = $pick ?? stock_meta_image($html);
    if (!$pick) {
        return null;
    }
    $url = str_replace(' ', '%20', stock_absolute_url($pick, $page));
    // BigCommerce stores (S&W, Staccato) put the size in the file name: ask for a larger copy.
    if (strpos($url, 'bigcommerce.com') !== false) {
        $url = preg_replace('~\.\d{2,4}\.\d{2,4}\.(png|jpe?g|webp)~i', '.1280.1280.$1', $url);
    }
    return $url;
}

/**
 * Decode image bytes, check them, and write <dir>/<slug>.jpg + <slug>-sm.jpg (white behind
 * transparency). Returns "WxH" of the original. Throws RuntimeException with a readable message.
 */
function stock_save(string $bytes, string $slug, ?string $dir = null, int $maxPixels = 16_000_000): string
{
    if (!preg_match('~^[a-z0-9-]+$~', $slug)) {
        throw new RuntimeException('This firearm has no usable URL name (slug).');
    }
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        throw new RuntimeException('That is not a JPEG, PNG or WebP image.');
    }
    [$w, $h] = $info;
    if ($w < 300 || $h < 150) {
        throw new RuntimeException("The image is too small ({$w}x{$h}).");
    }
    if ($w * $h > $maxPixels) {
        throw new RuntimeException("The image is too large to process ({$w}x{$h}).");
    }
    $im = @imagecreatefromstring($bytes);
    if (!$im) {
        throw new RuntimeException('The image could not be read.');
    }
    $dir = $dir ?? stock_photos_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create the stock-photos folder on the server.');
    }
    foreach ([[STOCK_MAX_EDGE, "$dir/$slug.jpg", 85], [STOCK_SMALL_EDGE, "$dir/$slug-sm.jpg", 80]] as [$max, $file, $quality]) {
        $scale = min(1, $max / max($w, $h));
        $nw = (int)round($w * $scale);
        $nh = (int)round($h * $scale);
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imageinterlace($out, true);
        if (!imagejpeg($out, $file, $quality)) {
            throw new RuntimeException('Could not save the photo on the server.');
        }
        imagedestroy($out);
    }
    imagedestroy($im);
    return "{$w}x{$h}";
}

/** Find and download the product image from a manufacturer page. Returns [image URL, bytes]. */
function stock_fetch_from_page(string $page, ?string $knownImage = null): array
{
    [$code, $html, $final] = stock_http_get($page);
    if ($code !== 200 || $html === '') {
        throw new RuntimeException("The manufacturer page didn't load (HTTP $code). Some sites block automatic requests; upload a photo instead.");
    }
    $img = stock_find_image($html, $final, $knownImage);
    if (!$img) {
        throw new RuntimeException('No product photo was found on that page. Upload one instead.');
    }
    [$code, $bytes] = stock_http_get($img, 'image/avif,image/webp,image/png,image/jpeg,*/*');
    if ($code !== 200 || $bytes === '') {
        throw new RuntimeException("The photo didn't download (HTTP $code).");
    }
    return [$img, $bytes];
}

function stock_delete(string $slug): void
{
    if (preg_match('~^[a-z0-9-]+$~', $slug)) {
        @unlink(stock_photos_dir() . "/$slug.jpg");
        @unlink(stock_photos_dir() . "/$slug-sm.jpg");
    }
}

/** Keep the photo with its firearm when the firearm's slug changes. */
function stock_rename(string $old, string $new): void
{
    $d = stock_photos_dir();
    if ($old === $new || !preg_match('~^[a-z0-9-]+$~', $old) || !preg_match('~^[a-z0-9-]+$~', $new) || !is_file("$d/$old.jpg") || is_file("$d/$new.jpg")) {
        return;
    }
    @rename("$d/$old.jpg", "$d/$new.jpg");
    @rename("$d/$old-sm.jpg", "$d/$new-sm.jpg");
}
