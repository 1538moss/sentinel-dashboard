<?php
/**
 * Henter Vansjø-omriss fra OSM Overpass API, konverterer til SVG i bilde-koordinater og cacher.
 * Overlay brukes i frontend når skydekke > 50 %.
 */

$config    = require __DIR__ . '/config.php';
$cacheFile = $config['data_dir'] . 'lake_overlay.svg';

// Server fra cache (30 dager)
if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 86400 * 30) {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: max-age=86400');
    readfile($cacheFile);
    exit;
}

$aoi = $config['aoi'];
$w   = $config['image_width'];
$h   = $config['image_height'];

function geoToPx(float $lon, float $lat, array $aoi, int $w, int $h): array
{
    return [
        round(($lon - $aoi['west'])  / ($aoi['east']  - $aoi['west'])  * $w, 1),
        round(($aoi['north'] - $lat) / ($aoi['north'] - $aoi['south']) * $h, 1),
    ];
}

function geometryToPath(array $geom, array $aoi, int $w, int $h): string
{
    if (count($geom) < 2) return '';
    $pts = array_map(
        fn($g) => implode(',', geoToPx((float)$g['lon'], (float)$g['lat'], $aoi, $w, $h)),
        $geom
    );
    return 'M ' . implode(' L ', $pts) . ' Z';
}

// Overpass: hent alle vannflater med navn som inneholder "Vansj" innenfor AOI
$bbox  = "{$aoi['south']},{$aoi['west']},{$aoi['north']},{$aoi['east']}";
$query = '[out:json][timeout:30][bbox:' . $bbox . '];'
       . '(way["natural"="water"]["name"~"Vansj"];'
       . 'relation["natural"="water"]["name"~"Vansj"];);'
       . 'out geom;';

$ch = curl_init('https://overpass-api.de/api/interpreter');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => 'data=' . urlencode($query),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: SentinelDashboard/1.0',
    ],
    CURLOPT_TIMEOUT => 25,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$body || $code !== 200) {
    http_response_code(503);
    exit('Overpass API utilgjengelig');
}

$data     = json_decode($body, true);
$elements = $data['elements'] ?? [];
$paths    = [];

foreach ($elements as $el) {
    if ($el['type'] === 'way' && !empty($el['geometry'])) {
        $p = geometryToPath($el['geometry'], $aoi, $w, $h);
        if ($p) $paths[] = $p;
    }
    if ($el['type'] === 'relation') {
        foreach ($el['members'] ?? [] as $m) {
            if (($m['type'] ?? '') === 'way'
                && ($m['role'] ?? '') !== 'inner'
                && !empty($m['geometry'])) {
                $p = geometryToPath($m['geometry'], $aoi, $w, $h);
                if ($p) $paths[] = $p;
            }
        }
    }
}

if (empty($paths)) {
    http_response_code(404);
    exit('Ingen Vansjø-geometri funnet i OSM');
}

$d = implode(' ', $paths);

// Omtrentlig sentrum av Vansjø
[$lx, $ly] = geoToPx(10.76, 59.43, $aoi, $w, $h);

$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$w} {$h}">
  <path d="{$d}"
    fill="rgba(56,189,248,0.13)"
    stroke="#38bdf8"
    stroke-width="3"
    stroke-linejoin="round"
    fill-rule="evenodd"/>
  <text x="{$lx}" y="{$ly}"
    text-anchor="middle"
    font-family="monospace"
    font-size="26"
    font-weight="bold"
    letter-spacing="3"
    fill="#38bdf8"
    opacity="0.85">VANSJØ</text>
</svg>
SVG;

file_put_contents($cacheFile, $svg);
header('Content-Type: image/svg+xml');
header('Cache-Control: max-age=86400');
echo $svg;
