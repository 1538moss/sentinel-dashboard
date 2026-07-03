<?php
/**
 * Henter OpenStreetMap-tiles for AOI, setter dem sammen og cacher resultatet.
 * Brukes som bakgrunnsbilde bak satellittbildene.
 */

$config    = require __DIR__ . '/config.php';
$cacheFile = $config['data_dir'] . 'map_bg.png';

// Returner cache hvis den finnes
if (file_exists($cacheFile)) {
    header('Content-Type: image/png');
    header('Cache-Control: max-age=86400');
    readfile($cacheFile);
    exit;
}

if (!is_dir($config['data_dir'])) mkdir($config['data_dir'], 0755, true);

$aoi      = $config['aoi'];
$outW     = $config['image_width'];
$outH     = $config['image_height'];
$zoom     = 11;        // zoom 11 = 15 tiles for Vansjø-området
$tileSize = 256;

function lon2tile(float $lon, int $z): int {
    return (int)floor(($lon + 180) / 360 * (1 << $z));
}
function lat2tile(float $lat, int $z): int {
    $rad = deg2rad($lat);
    return (int)floor((1 - log(tan($rad) + 1 / cos($rad)) / M_PI) / 2 * (1 << $z));
}
function tile2lon(int $x, int $z): float { return $x / (1 << $z) * 360 - 180; }
function tile2lat(int $y, int $z): float {
    $n = M_PI - 2 * M_PI * $y / (1 << $z);
    return rad2deg(atan(0.5 * (exp($n) - exp(-$n))));
}

$x1 = lon2tile($aoi['west'],  $zoom);
$x2 = lon2tile($aoi['east'],  $zoom);
$y1 = lat2tile($aoi['north'], $zoom);   // nord = lavere tile-Y
$y2 = lat2tile($aoi['south'], $zoom);   // sør = høyere tile-Y

$cols = $x2 - $x1 + 1;
$rows = $y2 - $y1 + 1;

// Lim tiles på ett canvas
$canvas = imagecreatetruecolor($cols * $tileSize, $rows * $tileSize);
$ctx    = stream_context_create(['http' => [
    'header'  => "User-Agent: SentinelDashboard/1.0\r\n",
    'timeout' => 10,
]]);

$failedTiles = 0;
for ($y = $y1; $y <= $y2; $y++) {
    for ($x = $x1; $x <= $x2; $x++) {
        $url  = "https://a.basemaps.cartocdn.com/light_all/$zoom/$x/$y.png";
        $data = @file_get_contents($url, false, $ctx);
        $tile = ($data !== false) ? @imagecreatefromstring($data) : false;
        if ($tile) {
            imagecopy($canvas, $tile,
                ($x - $x1) * $tileSize, ($y - $y1) * $tileSize,
                0, 0, $tileSize, $tileSize);
            imagedestroy($tile);
        } else {
            $failedTiles++;
        }
    }
}

// Klipp til nøyaktig AOI og skaler til utbildestørrelsen
$tileWest  = tile2lon($x1,      $zoom);
$tileNorth = tile2lat($y1,      $zoom);
$tileEast  = tile2lon($x2 + 1,  $zoom);
$tileSouth = tile2lat($y2 + 1,  $zoom);

$cW = $cols * $tileSize;
$cH = $rows * $tileSize;

$srcX = (int)(($aoi['west']  - $tileWest)  / ($tileEast  - $tileWest)  * $cW);
$srcY = (int)(($tileNorth    - $aoi['north']) / ($tileNorth - $tileSouth) * $cH);
$srcW = (int)(($aoi['east']  - $aoi['west'])  / ($tileEast  - $tileWest)  * $cW);
$srcH = (int)(($aoi['north'] - $aoi['south']) / ($tileNorth - $tileSouth) * $cH);

$out = imagecreatetruecolor($outW, $outH);
imagecopyresampled($out, $canvas, 0, 0, $srcX, $srcY, $outW, $outH, $srcW, $srcH);
imagedestroy($canvas);

// Cache kun komplette kart, og skriv atomisk så samtidige førstegangsforespørsler
// aldri leser en halvskrevet fil. Feilet noen tiles, prøves det på nytt neste gang.
if ($failedTiles === 0) {
    $tmpFile = $cacheFile . '.tmp.' . getmypid();
    imagepng($out, $tmpFile, 6);
    rename($tmpFile, $cacheFile);
}

header('Content-Type: image/png');
header('Cache-Control: max-age=86400');
imagepng($out);
imagedestroy($out);
