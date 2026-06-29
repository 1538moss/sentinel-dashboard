<?php
/**
 * Genererer et placeholder-bilde (PNG) med dato og skydekke.
 * Bruk: <img src="placeholder.php?date=2024-01-15&cloud=23">
 * Krever PHP GD-utvidelse.
 */

$date  = preg_replace('/[^0-9\-]/', '', $_GET['date']  ?? date('Y-m-d'));
$cloud = (int)($_GET['cloud'] ?? 0);

$w = 600; $h = 600;
$im = imagecreatetruecolor($w, $h);

// Bakgrunn — mørk blågrå gradient (simulert med rektangler)
for ($y = 0; $y < $h; $y++) {
    $v  = (int)(8 + ($y / $h) * 12);
    $col = imagecolorallocate($im, $v, $v, $v + 8);
    imageline($im, 0, $y, $w, $y, $col);
}

// Grid-linjer
$grid = imagecolorallocatealpha($im, 56, 189, 248, 115);
for ($x = 0; $x < $w; $x += 40) imageline($im, $x, 0, $x, $h, $grid);
for ($y = 0; $y < $h; $y += 40) imageline($im, 0, $y, $w, $y, $grid);

// Senter-kors
$cx = imagecolorallocate($im, 56, 189, 248);
imageline($im, $w/2-20, $h/2, $w/2+20, $h/2, $cx);
imageline($im, $w/2, $h/2-20, $w/2, $h/2+20, $cx);

// Dato-tekst
$white  = imagecolorallocate($im, 220, 230, 240);
$accent = imagecolorallocate($im, 56, 189, 248);
$grey   = imagecolorallocate($im, 100, 120, 140);

$font = 5; // innebygd font
$dateLabel = 'INGEN BILDE TILGJENGELIG';
imagestring($im, 2, (int)(($w - imagefontwidth(2)*strlen($dateLabel))/2), $h/2 + 30, $dateLabel, $grey);

$dateStr = $date;
imagestring($im, 4, (int)(($w - imagefontwidth(4)*strlen($dateStr))/2), $h/2 + 50, $dateStr, $white);

if ($cloud > 0) {
    $cc = "Skydekke: {$cloud}%";
    imagestring($im, 2, (int)(($w - imagefontwidth(2)*strlen($cc))/2), $h/2 + 74, $cc, $grey);
}

// Ramme
$border = imagecolorallocate($im, 40, 100, 160);
imagerectangle($im, 0, 0, $w-1, $h-1, $border);

header('Content-Type: image/png');
header('Cache-Control: max-age=86400');
imagepng($im);
imagedestroy($im);
