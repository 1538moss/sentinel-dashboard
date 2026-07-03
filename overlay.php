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

/**
 * Syr way-segmenter sammen til hele ringer ved å matche endepunkter.
 * OSM-relasjoner leverer omrisset som biter i vilkårlig rekkefølge og retning —
 * uten sammensying vil en Z per bit tegne rette korder tvers over vannet.
 */
function assembleRings(array $segments): array
{
    $key   = fn(array $pt) => $pt[0] . ',' . $pt[1];
    $rings = [];

    while ($segments) {
        $ring = array_shift($segments);

        while ($key($ring[0]) !== $key(end($ring))) {
            $endKey = $key(end($ring));
            $found  = false;

            foreach ($segments as $i => $seg) {
                if ($key(end($seg)) === $endKey) $seg = array_reverse($seg);
                elseif ($key($seg[0]) !== $endKey) continue;

                array_shift($seg);                  // dropp delt endepunkt
                $ring = array_merge($ring, $seg);
                unset($segments[$i]);
                $segments = array_values($segments);
                $found = true;
                break;
            }

            if (!$found) break;                     // åpen kjede — ingen flere biter passer
        }

        $rings[] = $ring;
    }

    return $rings;
}

function ringToPath(array $ring, array $aoi, int $w, int $h): string
{
    if (count($ring) < 2) return '';
    $pts = array_map(
        fn($pt) => implode(',', geoToPx($pt[0], $pt[1], $aoi, $w, $h)),
        $ring
    );
    $closed = $pts[0] === end($pts);
    if ($closed) array_pop($pts);                   // Z lukker — unngå duplisert punkt
    return 'M ' . implode(' L ', $pts) . ($closed ? ' Z' : '');
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

// Samle segmenter som punktlister [lon, lat]. Relasjonsmedlemmer først, og
// way-id-er noteres slik at samme geometri ikke også tas med som frittstående way.
$segments = [];
$seenWays = [];

$toPoints = fn(array $geom) => array_map(fn($g) => [(float)$g['lon'], (float)$g['lat']], $geom);

foreach ($elements as $el) {
    if ($el['type'] !== 'relation') continue;
    foreach ($el['members'] ?? [] as $m) {
        if (($m['type'] ?? '') === 'way'
            && ($m['role'] ?? '') !== 'inner'
            && !empty($m['geometry'])) {
            if (isset($m['ref'])) $seenWays[$m['ref']] = true;
            $segments[] = $toPoints($m['geometry']);
        }
    }
}
foreach ($elements as $el) {
    if ($el['type'] === 'way' && !empty($el['geometry']) && !isset($seenWays[$el['id']])) {
        $segments[] = $toPoints($el['geometry']);
    }
}

$paths = [];
foreach (assembleRings($segments) as $ring) {
    $p = ringToPath($ring, $aoi, $w, $h);
    if ($p) $paths[] = $p;
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
    fill="rgba(26,95,143,0.13)"
    stroke="#1A5F8F"
    stroke-width="3"
    stroke-linejoin="round"
    fill-rule="evenodd"/>
  <text x="{$lx}" y="{$ly}"
    text-anchor="middle"
    font-family="monospace"
    font-size="26"
    font-weight="bold"
    letter-spacing="3"
    fill="#1A5F8F"
    opacity="0.85">VANSJØ</text>
</svg>
SVG;

file_put_contents($cacheFile, $svg);
header('Content-Type: image/svg+xml');
header('Cache-Control: max-age=86400');
echo $svg;
