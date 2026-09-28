<?php
// Generates labelled placeholder photos for the demo data (public/assets/demo/*.jpg).
$out = dirname(__DIR__) . '/public/assets/demo';
@mkdir($out, 0777, true);
$shots = ['left' => 'Left side', 'right' => 'Right side', 'top' => 'Top', 'bore' => 'Bore and muzzle', 'included' => 'Included items'];
foreach ($shots as $file => $label) {
    $im = imagecreatetruecolor(1600, 1200);
    $bg = imagecolorallocate($im, 0xEB, 0xE8, 0xE2);
    $fg = imagecolorallocate($im, 0x6B, 0x6F, 0x74);
    $ln = imagecolorallocate($im, 0xD6, 0xD3, 0xCC);
    imagefill($im, 0, 0, $bg);
    for ($x = -1200; $x < 1600; $x += 80) imageline($im, $x, 1200, $x + 1200, 0, $ln);
    imagefilledrectangle($im, 400, 500, 1200, 700, $bg);
    $txt = "DEMO PHOTO: $label";
    $w = imagefontwidth(5) * strlen($txt);
    $scaled = imagecreatetruecolor($w, 16);
    imagefill($scaled, 0, 0, $bg);
    imagestring($scaled, 5, 0, 0, $txt, $fg);
    imagecopyresized($im, $scaled, (int)(800 - $w * 1.5), 576, 0, 0, $w * 3, 48, $w, 16);
    imagejpeg($im, "$out/$file.jpg", 80);
    echo "$file.jpg\n";
}
