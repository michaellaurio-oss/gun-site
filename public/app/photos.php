<?php
// Listing photo storage: photos/<folder>/<name>.jpg plus a small <name>-sm.jpg for cards
// and thumbnails. Every upload is decoded and re-encoded with GD, which also strips
// EXIF metadata (GPS location, camera serial numbers) from phone photos.

const PHOTO_MAX_EDGE = 2400;   // full-size viewer image
const PHOTO_THUMB_EDGE = 800;  // cards and thumbnails
const PHOTO_MAX_BYTES = 25 * 1024 * 1024;

function photos_root(): string
{
    return realpath(__DIR__ . '/..') . '/photos';
}

/** Folder name for a listing's photos: its stock number, or L<id> before it has one. */
function photo_folder(array $listing): string
{
    $s = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($listing['stock_number'] ?? ''));
    $s = trim($s, '-');
    return $s !== '' ? $s : 'L' . (int)$listing['id'];
}

/**
 * Save one uploaded file. Returns the site-relative path (photos/X/name.jpg).
 * Throws RuntimeException with a user-facing message on failure.
 */
function save_listing_photo(array $file, string $folder): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $codes = [UPLOAD_ERR_INI_SIZE => 'is larger than the server allows', UPLOAD_ERR_FORM_SIZE => 'is too large',
                  UPLOAD_ERR_PARTIAL => 'only partly uploaded', UPLOAD_ERR_NO_FILE => 'was not received'];
        throw new RuntimeException(($file['name'] ?? 'Photo') . ' ' . ($codes[$file['error'] ?? 4] ?? 'failed to upload') . '.');
    }
    if (!is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > PHOTO_MAX_BYTES) {
        throw new RuntimeException($file['name'] . ' is too large or invalid.');
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException($file['name'] . ' is not a JPEG, PNG or WebP image.');
    }
    if ($info[0] * $info[1] > 60_000_000) {
        throw new RuntimeException($file['name'] . ' has too many pixels.');
    }
    $img = @$types[$info[2]]($file['tmp_name']);
    if (!$img) {
        throw new RuntimeException($file['name'] . ' could not be read.');
    }
    if ($info[2] === IMAGETYPE_JPEG) {
        $img = apply_exif_orientation($img, $file['tmp_name']);
    }

    $dir = photos_root() . '/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create the photo folder on the server (check folder permissions for photos/).');
    }
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    write_jpeg($img, PHOTO_MAX_EDGE, "$dir/$name.jpg", 85);
    write_jpeg($img, PHOTO_THUMB_EDGE, "$dir/$name-sm.jpg", 80);
    imagedestroy($img);
    return "photos/$folder/$name.jpg";
}

function apply_exif_orientation($img, string $path)
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($path);
    $o = (int)($exif['Orientation'] ?? 1);
    $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
    if ($rot) {
        $img = imagerotate($img, $rot, 0);
    }
    return $img;
}

function write_jpeg($img, int $maxEdge, string $dest, int $quality): void
{
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, $maxEdge / max($w, $h));
    $nw = (int)round($w * $scale);
    $nh = (int)round($h * $scale);
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // PNG transparency -> white
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imageinterlace($out, true);
    if (!imagejpeg($out, $dest, $quality)) {
        throw new RuntimeException('Could not save the photo on the server.');
    }
    imagedestroy($out);
}

/** Delete a stored photo and its thumbnail (only files inside photos/). */
function delete_photo_files(string $relPath): void
{
    if (strpos($relPath, 'photos/') !== 0 || strpos($relPath, '..') !== false) {
        return;
    }
    $full = realpath(__DIR__ . '/../' . $relPath);
    $norm = fn($p) => str_replace('\\', '/', (string)$p);
    if ($full && strpos($norm($full), $norm(photos_root()) . '/') === 0) {
        @unlink($full);
        @unlink(preg_replace('/\.jpg$/', '-sm.jpg', $full));
    }
}
