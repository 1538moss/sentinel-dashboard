<?php
/**
 * Sentinel Image Fetcher — bruker Sentinel Hub Processing API via CDSE
 *
 * Kjør manuelt  : php fetch.php
 * Cron (kl 07)  : 0 7 * * * php /path/to/fetch.php >> /path/to/fetch.log 2>&1
 *
 * Krever OAuth2-klient fra CDSE-dashbordet:
 *   https://shapps.dataspace.copernicus.eu/dashboard/#/account/settings
 *   → OAuth Clients → Create client (grant: client_credentials)
 */

class SentinelFetcher
{
    private array   $config;
    private ?string $token      = null;
    private int     $tokenExpiry = 0;
    private string  $logFile    = '';
    private ?string $usgsToken  = null;
    private ?string $odataToken = null;
    private int     $odataTokenExpiry = 0;

    public function __construct(array $config)
    {
        $this->config  = $config;
        $this->ensureDirectories();
        $this->logFile = $config['data_dir'] . 'fetch.log';
    }

    private function log(string $line): void
    {
        $entry = '[' . date('Y-m-d H:i:s') . ']  ' . $line . "\n";
        if (PHP_SAPI === 'cli') echo $entry;
        file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->config['images_dir'], $this->config['thumbs_dir'], $this->config['data_dir']] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }
    }

    // ── Thumbnail ─────────────────────────────────────────────────────────────
    public function generateThumb(string $srcPath, string $thumbPath): bool
    {
        if (!function_exists('imagecreatefrompng')) return false;
        $src = @imagecreatefrompng($srcPath);
        if (!$src) return false;

        $size  = 136;
        $thumb = imagecreatetruecolor($size, $size);
        $paperFill = imagecolorallocate($thumb, 0xE7, 0xE3, 0xD6);
        imagefill($thumb, 0, 0, $paperFill);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
        imagejpeg($thumb, $thumbPath, 78);
        imagedestroy($src);
        imagedestroy($thumb);
        return true;
    }

    // ── Auth ──────────────────────────────────────────────────────────────────
    private function getToken(): string
    {
        if ($this->token && time() < $this->tokenExpiry) return $this->token;

        $cid = $this->config['sh']['client_id'];
        $sec = $this->config['sh']['client_secret'];

        if (empty($cid) || empty($sec)) {
            throw new RuntimeException(
                "Mangler Sentinel Hub OAuth2-klient.\n" .
                "Opprett OAuth-klient på https://shapps.dataspace.copernicus.eu/dashboard/#/account/settings\n" .
                "→ OAuth Clients → Create client (grant type: client_credentials)\n" .
                "Lim inn SH_CLIENT_ID og SH_CLIENT_SECRET i .sentinel.env (ett nivå opp fra webroot)."
            );
        }

        $ch = curl_init($this->config['sh']['token_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $cid,
                'client_secret' => $sec,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new RuntimeException("Token-forespørsel feilet (HTTP $code): $body");
        }

        $data = json_decode($body, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException("Ingen access_token i svar: $body");
        }

        $this->token      = $data['access_token'];
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 300) - 30;
        return $this->token;
    }

    // ── Auth (OData — nedlasting av S3-produkter) ────────────────────────────
    // Egen tokentype: OData $value-nedlasting avviser client_credentials-tokenet
    // over ("Token audience not allowed") og krever i stedet et ekte CDSE-konto-
    // passord via grant_type=password mot den offentlige klienten cdse-public.
    // Bekreftet i praksis — se BACKLOG.md.
    private function getODataToken(): string
    {
        if ($this->odataToken && time() < $this->odataTokenExpiry) return $this->odataToken;

        $user = $this->config['cdse_odata']['username'] ?? '';
        $pass = $this->config['cdse_odata']['password'] ?? '';

        if (empty($user) || empty($pass)) {
            throw new RuntimeException(
                "Mangler CDSE-kontopassord for S3-nedlasting.\n" .
                "Lim inn CDSE_USERNAME og CDSE_PASSWORD i .sentinel.env (ett nivå opp fra webroot)."
            );
        }

        $ch = curl_init($this->config['sh']['token_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'password',
                'client_id'  => 'cdse-public',
                'username'   => $user,
                'password'   => $pass,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new RuntimeException("CDSE OData-token-forespørsel feilet (HTTP $code): $body");
        }

        $data = json_decode($body, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException("Ingen access_token i OData-token-svar: $body");
        }

        $this->odataToken       = $data['access_token'];
        $this->odataTokenExpiry = time() + ($data['expires_in'] ?? 300) - 30;
        return $this->odataToken;
    }

    // ── Catalog search ────────────────────────────────────────────────────────
    /** Returnerer array med ['date'=>'YYYY-MM-DD', 'cloud_cover'=>float, 'acquired_at'=>ISO8601] */
    public function searchDates(string $from, string $to): array
    {
        $token = $this->getToken();
        $aoi   = $this->config['aoi'];

        $payload = [
            'bbox'        => [$aoi['west'], $aoi['south'], $aoi['east'], $aoi['north']],
            'datetime'    => "{$from}T00:00:00Z/{$to}T23:59:59Z",
            'collections' => ['sentinel-2-l2a'],
            'limit'       => 100,
            'filter'      => 'eo:cloud_cover < ' . $this->config['max_cloud_cover'],
            'filter-lang' => 'cql2-text',
        ];

        $ch = curl_init($this->config['sh']['catalog_url'] . '/search');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
                'Accept: */*',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new RuntimeException("Katalogsøk feilet (HTTP $code): $body");
        }

        $data     = json_decode($body, true);
        $features = $data['features'] ?? [];

        // Grupper per dato — behold lavest skydekke
        $byDate = [];
        foreach ($features as $f) {
            $date  = substr($f['properties']['datetime'], 0, 10);
            $cloud = (float)($f['properties']['eo:cloud_cover'] ?? 100);
            if (!isset($byDate[$date]) || $cloud < $byDate[$date]['cloud_cover']) {
                $byDate[$date] = ['date' => $date, 'cloud_cover' => round($cloud, 1), 'acquired_at' => $f['properties']['datetime']];
            }
        }

        // Nyeste først
        krsort($byDate);
        return array_values($byDate);
    }

    // ── Sentinel-1 catalog search ─────────────────────────────────────────────
    /** Returnerer array med ['date'=>'YYYY-MM-DD', 'cloud_cover'=>null, 'coverage'=>float 0.0-1.0] */
    public function searchDatesS1(string $from, string $to): array
    {
        $token = $this->getToken();
        $aoi   = $this->config['aoi'];

        $payload = [
            'bbox'        => [$aoi['west'], $aoi['south'], $aoi['east'], $aoi['north']],
            'datetime'    => "{$from}T00:00:00Z/{$to}T23:59:59Z",
            'collections' => ['sentinel-1-grd'],
            'limit'       => 100,
        ];

        $ch = curl_init($this->config['sh']['catalog_url'] . '/search');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
                'Accept: */*',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new RuntimeException("S1 katalogsøk feilet (HTTP $code): $body");
        }

        $features = json_decode($body, true)['features'] ?? [];

        // Dedup: én entry per dato — flere scener (ulike baner/passeringer) kan
        // dekke samme dato, og noen dekker bare DELER av AOI-et (resten blir
        // transparent i det ferdige bildet). Behold scenen med mest AOI-dekning,
        // ikke bare den første som dukker opp i søket.
        $byDate = [];
        foreach ($features as $f) {
            $date = substr($f['properties']['datetime'], 0, 10);
            $coverage = $this->estimateAoiCoverage($f['geometry'] ?? null, $aoi);
            if (!isset($byDate[$date]) || $coverage > $byDate[$date]['coverage']) {
                $byDate[$date] = ['date' => $date, 'cloud_cover' => null, 'coverage' => $coverage, 'acquired_at' => $f['properties']['datetime']];
            }
        }

        krsort($byDate);
        return array_values($byDate);
    }

    /** Ray-casting point-in-polygon (én ring, [ [lon,lat], ... ]) */
    private function pointInRing(float $lon, float $lat, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $ring[$i][0]; $yi = $ring[$i][1];
            $xj = $ring[$j][0]; $yj = $ring[$j][1];
            $intersects = (($yi > $lat) !== ($yj > $lat)) &&
                ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);
            if ($intersects) $inside = !$inside;
        }
        return $inside;
    }

    /** true hvis (lon,lat) ligger i en GeoJSON Polygon/MultiPolygon sin ytre ring (hull ignoreres — relevant for satellitt-footprints uten hull) */
    private function pointInGeometry(float $lon, float $lat, array $geometry): bool
    {
        $type = $geometry['type'] ?? '';
        if ($type === 'Polygon') {
            return $this->pointInRing($lon, $lat, $geometry['coordinates'][0] ?? []);
        }
        if ($type === 'MultiPolygon') {
            foreach ($geometry['coordinates'] ?? [] as $poly) {
                if ($this->pointInRing($lon, $lat, $poly[0] ?? [])) return true;
            }
        }
        return false;
    }

    /**
     * Estimerer hvor stor andel (0.0-1.0) av AOI-et en scenes footprint dekker,
     * ved å punktteste et rutenett av samplepunkter over AOI-boksen mot scenens
     * geometri — enklere og god nok tilnærming, uten behov for et fullt
     * geometri-bibliotek (GEOS o.l. er ikke tilgjengelig i standard PHP).
     */
    private function estimateAoiCoverage(?array $geometry, array $aoi, int $gridSize = 5): float
    {
        if (!$geometry) return 0.0;
        $hits = 0;
        $total = $gridSize * $gridSize;
        for ($iy = 0; $iy < $gridSize; $iy++) {
            $lat = $aoi['south'] + ($aoi['north'] - $aoi['south']) * ($iy + 0.5) / $gridSize;
            for ($ix = 0; $ix < $gridSize; $ix++) {
                $lon = $aoi['west'] + ($aoi['east'] - $aoi['west']) * ($ix + 0.5) / $gridSize;
                if ($this->pointInGeometry($lon, $lat, $geometry)) $hits++;
            }
        }
        return $hits / $total;
    }

    // ── Sentinel-3 SLSTR L2 LST — OData Products-søk ─────────────────────────
    // IKKE samme katalog som S2/S1 (Process API-katalogen har ikke SL_2_LST-
    // produktet) — søker CDSE sin generelle produktkatalog i stedet.
    /** Returnerer array med ['date'=>'YYYY-MM-DD', 'cloud_cover'=>null, 'product_id'=>string, 'product_name'=>string, 'acquired_at'=>ISO8601] */
    public function searchDatesS3(string $from, string $to): array
    {
        $token = $this->getToken();
        $aoi   = $this->config['aoi'];
        $odata = $this->config['cdse_odata'];

        $w = $aoi['west']; $e = $aoi['east']; $s = $aoi['south']; $n = $aoi['north'];
        $polygon = "POLYGON(($w $s,$e $s,$e $n,$w $n,$w $s))";
        $filter = "Collection/Name eq 'SENTINEL-3' and " .
            "Attributes/OData.CSC.StringAttribute/any(att:att/Name eq 'productType' and att/OData.CSC.StringAttribute/Value eq '{$odata['product_type']}') and " .
            "OData.CSC.Intersects(area=geography'SRID=4326;{$polygon}') and " .
            "ContentDate/Start gt {$from}T00:00:00.000Z and ContentDate/Start lt {$to}T23:59:59.000Z";
        $url = $odata['products_url'] . '?$filter=' . urlencode($filter) .
            '&$top=100&$orderby=' . urlencode('ContentDate/Start asc');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new RuntimeException("S3 OData-søk feilet (HTTP $code): $body");
        }

        $products = json_decode($body, true)['value'] ?? [];

        // Dedup: én entry per dato. Hvert overflygning finnes typisk i to varianter —
        // _NR_ (Near Real Time, tilgjengelig raskt) og _NT_ (Non-Time-Critical,
        // reprosessert med bedre kalibrering et par dager senere) — foretrekk _NT_.
        $byDate = [];
        foreach ($products as $p) {
            $date = substr($p['ContentDate']['Start'], 0, 10);
            $isNT = str_contains($p['Name'], '_NT_');
            if (!isset($byDate[$date]) || ($isNT && !$byDate[$date]['is_nt'])) {
                $byDate[$date] = [
                    'date'         => $date,
                    'cloud_cover'  => null,
                    'product_id'   => $p['Id'],
                    'product_name' => $p['Name'],
                    'acquired_at'  => $p['ContentDate']['Start'],
                    'is_nt'        => $isNT,
                ];
            }
        }
        foreach ($byDate as &$entry) unset($entry['is_nt']);

        krsort($byDate);
        return array_values($byDate);
    }

    // ── Sentinel-1 Process API — hent SAR-bilde ───────────────────────────────
    public function fetchImageS1(string $date): string
    {
        $token = $this->getToken();
        $aoi   = $this->config['aoi'];
        $w     = $this->config['image_width'];
        $h     = $this->config['image_height'];

        $evalscript = <<<'JS'
//VERSION=3
function setup() {
  return { input: ["VV","VH","dataMask"], output: { bands: 4 } };
}
function jet(sample) {
  const jetList = [
    [0,0,.5625],[0,0,.625],[0,0,.6875],[0,0,.75],[0,0,.8125],[0,0,.875],
    [0,0,.9375],[0,0,1],[0,.0625,1],[0,.125,1],[0,.1875,1],[0,.25,1],
    [0,.3125,1],[0,.375,1],[0,.4375,1],[0,.5,1],[0,.5625,1],[0,.625,1],
    [0,.6875,1],[0,.75,1],[0,.8125,1],[0,.875,1],[0,.9375,1],[0,1,1],
    [.0625,1,.9375],[.125,1,.875],[.1875,1,.8125],[.25,1,.75],
    [.3125,1,.6875],[.375,1,.625],[.4375,1,.5625],[.5,1,.5],
    [.5625,1,.4375],[.625,1,.375],[.6875,1,.3125],[.75,1,.25],
    [.8125,1,.1875],[.875,1,.125],[.9375,1,.0625],[1,1,0],
    [1,.9375,0],[1,.875,0],[1,.8125,0],[1,.75,0],[1,.6875,0],
    [1,.625,0],[1,.5625,0],[1,.5,0],[1,.4375,0],[1,.375,0],
    [1,.3125,0],[1,.25,0],[1,.1875,0],[1,.125,0],[1,.0625,0],
    [1,0,0],[.9375,0,0],[.875,0,0],[.8125,0,0],[.75,0,0],
    [.6875,0,0],[.625,0,0],[.5625,0,0],[.5,0,0]
  ];
  const vv = (sample.VV > 0) ? sample.VV : 0;
  const index = Math.min(Math.floor(vv * 1024), 63);
  return [jetList[index][0], jetList[index][1], jetList[index][2], sample.dataMask];
}
function evaluatePixel(sample) { return jet(sample); }
JS;

        $payload = [
            'input' => [
                'bounds' => [
                    'bbox'       => [$aoi['west'], $aoi['south'], $aoi['east'], $aoi['north']],
                    'properties' => ['crs' => 'http://www.opengis.net/def/crs/OGC/1.3/CRS84'],
                ],
                'data' => [[
                    'type'       => 'sentinel-1-grd',
                    'dataFilter' => [
                        'timeRange' => [
                            'from' => $date . 'T00:00:00Z',
                            'to'   => $date . 'T23:59:59Z',
                        ],
                        'acquisitionMode' => 'IW',
                        'polarization'    => 'DV',
                        'resolution'      => 'HIGH',
                    ],
                ]],
            ],
            'output' => [
                'width'  => $w,
                'height' => $h,
                'responses' => [[
                    'identifier' => 'default',
                    'format'     => ['type' => 'image/png'],
                ]],
            ],
            'evalscript' => $evalscript,
        ];

        $ch = curl_init($this->config['sh']['process_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
                'Accept: image/png',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $imageData   = curl_exec($ch);
        $code        = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($code !== 200) {
            $msg = is_string($imageData) ? substr($imageData, 0, 200) : '';
            throw new RuntimeException("S1 Process API feilet (HTTP $code): $msg");
        }

        if (!str_contains((string)$contentType, 'image')) {
            throw new RuntimeException("S1 svar er ikke et bilde (Content-Type: $contentType)");
        }

        return $imageData;
    }

    // ── USGS M2M (Landsat 8-9) ─────────────────────────────────────────────────
    private function usgsRequest(string $endpoint, array $payload): array
    {
        $headers = ['Content-Type: application/json'];
        if ($this->usgsToken) $headers[] = "X-Auth-Token: {$this->usgsToken}";

        $ch = curl_init($this->config['usgs']['base_url'] . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("M2M $endpoint curl-feil: $err");
        }

        $data = json_decode((string)$body, true);
        if ($data === null) {
            throw new RuntimeException("M2M $endpoint svarte med ugyldig JSON (HTTP $code)");
        }
        if (!empty($data['errorCode'])) {
            throw new RuntimeException("M2M $endpoint feil: {$data['errorCode']} — {$data['errorMessage']}");
        }
        if ($code !== 200) {
            throw new RuntimeException("M2M $endpoint feilet (HTTP $code)");
        }

        return $data;
    }

    private function usgsLogin(): void
    {
        $username = $this->config['usgs']['username'] ?? '';
        $token    = $this->config['usgs']['token']    ?? '';
        if ($username === '' || $token === '') {
            throw new RuntimeException("Mangler USGS_USERNAME/USGS_M2M_TOKEN i .sentinel.env");
        }

        $resp = $this->usgsRequest('login-token', ['username' => $username, 'token' => $token]);
        $this->usgsToken = $resp['data'] ?? null;
        if (!$this->usgsToken) {
            throw new RuntimeException("USGS login-token ga ingen sesjonstoken");
        }
    }

    private function usgsLogout(): void
    {
        if (!$this->usgsToken) return;
        try {
            $this->usgsRequest('logout', []);
        } catch (RuntimeException $e) {
            // best-effort — en feilende logout skal aldri kaste videre
        }
        $this->usgsToken = null;
    }

    /** Returnerer array med ['date'=>'YYYY-MM-DD', 'cloud_cover'=>float, 'entity_id'=>string, 'acquired_at'=>ISO8601|null] */
    public function searchDatesLandsat(string $from, string $to): array
    {
        $aoi = $this->config['aoi'];

        $resp = $this->usgsRequest('scene-search', [
            'datasetName' => $this->config['usgs']['dataset'],
            'sceneFilter' => [
                'spatialFilter' => [
                    'filterType' => 'mbr',
                    'lowerLeft'  => ['latitude' => $aoi['south'], 'longitude' => $aoi['west']],
                    'upperRight' => ['latitude' => $aoi['north'], 'longitude' => $aoi['east']],
                ],
                'acquisitionFilter' => ['start' => $from, 'end' => $to],
            ],
            'maxResults'   => 100,
            // 'full' trengs for å få med det egentlige opptakstidspunktet — uten
            // dette gir temporalCoverage.startDate kun midnatt (ingen klokkeslett).
            'metadataType' => 'full',
        ]);

        $scenes = $resp['data']['results'] ?? [];

        // Dedup per dato — behold lavest skydekke (skydekke ER tilgjengelig her, i motsetning til S1)
        $byDate = [];
        foreach ($scenes as $s) {
            $date = substr($s['temporalCoverage']['startDate'] ?? '', 0, 10);
            if ($date === '') continue;
            $cloud = (float)($s['cloudCover'] ?? 100);
            if (!isset($byDate[$date]) || $cloud < $byDate[$date]['cloud_cover']) {
                $startTime = null;
                foreach ($s['metadata'] ?? [] as $m) {
                    if (($m['fieldName'] ?? '') === 'Start Time') {
                        $startTime = str_replace(' ', 'T', $m['value']) . 'Z'; // Landsat-opptakstider er UTC
                        break;
                    }
                }
                $byDate[$date] = [
                    'date'        => $date,
                    'cloud_cover' => round($cloud, 1),
                    'entity_id'   => $s['entityId'],
                    'acquired_at' => $startTime,
                ];
            }
        }

        krsort($byDate);
        return array_values($byDate);
    }

    /** Kjører en GDAL-kommando, kaster RuntimeException med stderr ved feil */
    private function runGdal(string $cmd): void
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException("Kunne ikke starte GDAL-kommando: $cmd");
        }
        // Drain stdout i stedet for å lukke den mens prosessen kjører — GDAL skriver
        // fremdriftspunktum til stdout som standard, og å lukke pipen tidlig kan gi
        // SIGPIPE/EPIPE på Linux (ikke reprodusert på Windows der pipelinen ble testet).
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            throw new RuntimeException("GDAL-kommando feilet (exit $exitCode): $cmd\n$stderr");
        }
    }

    /**
     * Henter Landsat SR-bånd via M2M, kjører full GDAL-pipeline
     * (gdalwarp reprojiser+beskjær → gdal_translate skaler til Byte →
     * gdal_calc.py alfamaske fra QA_PIXEL → gdal_merge.py RGBA → PNG),
     * returnerer PNG-bytes (samme kontrakt som fetchImageS1()).
     */
    public function fetchImageLandsat(string $date, string $entityId): string
    {
        $aoi  = $this->config['aoi'];
        $w    = $this->config['image_width'];
        $h    = $this->config['image_height'];
        $mode = $this->config['render_mode'] ?? 'true_color';

        if ($mode === 'false_color') {
            $bands = ['SR_B5', 'SR_B4', 'SR_B3']; // NIR, Rød, Grønn
            $gain  = 3.0;
        } else {
            $bands = ['SR_B4', 'SR_B3', 'SR_B2']; // Rød, Grønn, Blå
            $gain  = 3.5;
        }
        $wantedFiles = array_merge($bands, ['QA_PIXEL']);

        $doResp = $this->usgsRequest('download-options', [
            'datasetName' => $this->config['usgs']['dataset'],
            'entityIds'   => [$entityId],
        ]);

        $bandInfo = [];
        foreach ($doResp['data'] ?? [] as $bundle) {
            foreach ($bundle['secondaryDownloads'] ?? [] as $sub) {
                foreach ($wantedFiles as $band) {
                    if (str_ends_with($sub['displayId'] ?? '', "_{$band}.TIF")) {
                        $bandInfo[$band] = ['entityId' => $sub['entityId'], 'productId' => $sub['id']];
                    }
                }
            }
        }
        foreach ($wantedFiles as $band) {
            if (empty($bandInfo[$band])) {
                throw new RuntimeException("Fant ikke bånd $band i download-options for $entityId");
            }
        }

        // PID i mappenavnet: unngår kollisjon hvis to fetch.php-kjøringer (cron + manuell,
        // eller to overlappende cron-tikk) henter samme dato samtidig.
        $scratchDir = $this->config['data_dir'] . 'landsat_tmp/' . $date . '_' . getmypid() . '/';
        if (!is_dir($scratchDir)) mkdir($scratchDir, 0755, true);

        try {
            $localFiles = [];
            foreach ($wantedFiles as $band) {
                $info = $bandInfo[$band];
                $drResp = $this->usgsRequest('download-request', [
                    'downloads' => [['entityId' => $info['entityId'], 'productId' => $info['productId']]],
                    'label'     => 'sentinel-fetch',
                ]);

                $url = $drResp['data']['availableDownloads'][0]['url'] ?? null;
                if (!$url) {
                    // duplicateProducts: samme entity/product ble allerede forespurt i et
                    // fortsatt aktivt vindu (f.eks. en tidligere kjøring som ble avbrutt
                    // midt i bånd-nedlastingen) — polles på samme måte som preparingDownloads.
                    $downloadId = $drResp['data']['preparingDownloads'][0]['downloadId']
                        ?? $drResp['data']['duplicateProducts'][0]['downloadId']
                        ?? null;
                    if ($downloadId === null) {
                        throw new RuntimeException("Ingen nedlastings-URL for bånd $band ($entityId)");
                    }
                    for ($i = 0; $i < 10 && !$url; $i++) {
                        sleep(6);
                        $retResp = $this->usgsRequest('download-retrieve', ['label' => 'sentinel-fetch']);
                        foreach ($retResp['data']['available'] ?? [] as $a) {
                            if (($a['downloadId'] ?? null) == $downloadId) $url = $a['url'];
                        }
                    }
                    if (!$url) {
                        throw new RuntimeException("Nedlasting for bånd $band ($entityId) ble aldri klar");
                    }
                }

                $dest = $scratchDir . $band . '.TIF';
                $fp = fopen($dest, 'wb');
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_FILE           => $fp,
                    CURLOPT_TIMEOUT        => 180,
                    CURLOPT_FOLLOWLOCATION => true,
                ]);
                curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);
                fclose($fp);

                if ($code !== 200 || $err || !is_file($dest) || filesize($dest) === 0) {
                    throw new RuntimeException("Nedlasting av bånd $band feilet (HTTP $code)" . ($err ? " — $err" : ''));
                }
                $localFiles[$band] = $dest;
            }

            $gdalwarp      = $this->config['gdal']['gdalwarp_cmd'];
            $gdalTranslate = $this->config['gdal']['gdal_translate_cmd'];
            $gdalCalc      = $this->config['gdal']['gdal_calc_cmd'];
            $gdalBuildvrt  = $this->config['gdal']['gdalbuildvrt_cmd'];
            $bbox          = "{$aoi['west']} {$aoi['south']} {$aoi['east']} {$aoi['north']}";

            // 1. Reprojiser+beskjær reflektans-bånd til AOI (bilinear)
            foreach ($bands as $band) {
                $src = $localFiles[$band];
                $dst = $scratchDir . "{$band}_warp.tif";
                $this->runGdal("$gdalwarp -overwrite -t_srs EPSG:4326 -te $bbox -ts $w $h -r bilinear -srcnodata 0 -dstnodata 0 " . escapeshellarg($src) . ' ' . escapeshellarg($dst));
            }

            // 2. Reprojiser+beskjær QA_PIXEL (nearest-neighbor — bevarer bitmaske).
            //    -dstnodata 1 (ikke 0!): utenfor kildescenens dekning har gdalwarp ingen
            //    pikselverdi å hente og ville ellers fylt med rå 0 — men bit0=0 betyr "ekte
            //    data" i alfaformelen under, så det ville gitt en ugjennomsiktig kant der
            //    scenen faktisk ikke dekker AOI. QA_PIXEL sin egen fill-verdi har bit0=1,
            //    så 1 er riktig fyllverdi (ikke 0, som er en helt gyldig ekte skydekke-avlesning).
            $qaWarp = $scratchDir . 'QA_PIXEL_warp.tif';
            $this->runGdal("$gdalwarp -overwrite -t_srs EPSG:4326 -te $bbox -ts $w $h -r near -dstnodata 1 " . escapeshellarg($localFiles['QA_PIXEL']) . ' ' . escapeshellarg($qaWarp));

            // 3. DN → reflectance → gain, lineært til Byte 0-255
            //    reflectance = DN*0.0000275-0.2 ; byte = clip(reflectance*gain,0,1)*255
            $srcMin = 0.2 / 0.0000275;
            $srcMax = (1 / $gain + 0.2) / 0.0000275;
            foreach ($bands as $band) {
                $src = $scratchDir . "{$band}_warp.tif";
                $dst = $scratchDir . "{$band}_byte.tif";
                $this->runGdal("$gdalTranslate -ot Byte -scale $srcMin $srcMax 0 255 -a_nodata 0 " . escapeshellarg($src) . ' ' . escapeshellarg($dst));
            }

            // 4. Alfamaske fra QA_PIXEL fill-bit (bit 0): 255 der ekte data, 0 der fill
            $alpha = $scratchDir . 'alpha.tif';
            $this->runGdal("$gdalCalc -A " . escapeshellarg($qaWarp) . " --outfile=" . escapeshellarg($alpha) . ' --calc="255*((A&1)==0)" --type=Byte --overwrite');

            // 5. Slå sammen R/G/B + alfa → RGBA via VRT
            // (gdal_merge.py -separate har en kjent bug som nuller ut siste bånd i output —
            //  gdalbuildvrt (kjerne-GDAL, ikke python-utility) gjør det samme uten den feilen)
            $vrt = $scratchDir . 'stacked.vrt';
            $bandFiles = array_map(fn($b) => escapeshellarg($scratchDir . "{$b}_byte.tif"), $bands);
            $this->runGdal("$gdalBuildvrt -separate " . escapeshellarg($vrt) . ' ' . implode(' ', $bandFiles) . ' ' . escapeshellarg($alpha));

            // 6. Endelig PNG
            $png = $scratchDir . 'final.png';
            $this->runGdal("$gdalTranslate -of PNG " . escapeshellarg($vrt) . ' ' . escapeshellarg($png));

            $imageData = file_get_contents($png);
            if ($imageData === false || $imageData === '') {
                throw new RuntimeException("Kunne ikke lese ferdig Landsat-PNG (tom eller manglende fil): $png");
            }
            return $imageData;
        } finally {
            // Rydd opp scratch-mappen uansett utfall
            $files = glob($scratchDir . '*');
            if ($files) {
                foreach ($files as $f) unlink($f);
            }
            @rmdir($scratchDir);
        }
    }

    /**
     * Bygger "NETCDF:"path":var"-argumentet trygt på tvers av plattform.
     * PHP sin escapeshellarg() på Windows FJERNER anførselstegn inni strengen
     * i stedet for å escape dem (kjent plattformbegrensning), som ødelegger
     * GDALs NETCDF-undersett-syntaks fullstendig — bekreftet i praksis, se
     * BACKLOG.md. $path/$variable er alltid internt genererte strenger
     * (scratch-filnavn, faste variabelnavn), aldri brukerinput.
     */
    private function netcdfArg(string $path, string $variable): string
    {
        $spec = 'NETCDF:"' . $path . '":' . $variable;
        if (PHP_OS_FAMILY === 'Windows') {
            return '"' . str_replace('"', '\"', $spec) . '"';
        }
        return escapeshellarg($spec);
    }

    /**
     * Bygger en GEOLOCATION-VRT for én NetCDF-variabel (LST eller confidence-flagg)
     * og warper den til AOI-rutenettet. Sentinel-3 SLSTR er et swath-produkt —
     * bredde/lengdegrad per piksel ligger i en egen fil (geodetic_in.nc), ikke i
     * en enkel geotransform som Landsat — så vanlig gdalwarp uten -geoloc kan
     * ikke brukes her.
     */
    private function buildGeolocGridTif(
        string $ncPath, string $variable, string $lonUnscaled, string $latUnscaled,
        string $bbox, int $cols, int $rows, string $scratchDir, string $label
    ): string {
        $gdalwarp      = $this->config['gdal']['gdalwarp_cmd'];
        $gdalTranslate = $this->config['gdal']['gdal_translate_cmd'];

        $baseVrt = $scratchDir . "{$label}_base.vrt";
        $this->runGdal("$gdalTranslate -of VRT " . $this->netcdfArg($ncPath, $variable) . ' ' . escapeshellarg($baseVrt));

        // gdal_translate -of VRT bygger ikke selv inn en GEOLOCATION-metadata-domene
        // for kryss-fil-swath-data (lat/lon ligger i en ANNEN fil enn LST/flagg-
        // verdiene) — vi injiserer den manuelt i VRT-XML-en.
        $vrtXml = file_get_contents($baseVrt);
        $geoloc = "  <Metadata domain=\"GEOLOCATION\">\n" .
            '    <MDI key="X_DATASET">' . htmlspecialchars($lonUnscaled) . "</MDI>\n" .
            "    <MDI key=\"X_BAND\">1</MDI>\n" .
            '    <MDI key="Y_DATASET">' . htmlspecialchars($latUnscaled) . "</MDI>\n" .
            "    <MDI key=\"Y_BAND\">1</MDI>\n" .
            "    <MDI key=\"PIXEL_OFFSET\">0</MDI>\n" .
            "    <MDI key=\"LINE_OFFSET\">0</MDI>\n" .
            "    <MDI key=\"PIXEL_STEP\">1</MDI>\n" .
            "    <MDI key=\"LINE_STEP\">1</MDI>\n" .
            "  </Metadata>\n";
        $vrtXml = preg_replace('/(<VRTDataset[^>]*>\n)/', '$1' . $geoloc, $vrtXml, 1);
        $geolocVrt = $scratchDir . "{$label}_geoloc.vrt";
        file_put_contents($geolocVrt, $vrtXml);

        $outTif = $scratchDir . "{$label}_grid.tif";
        // -r near (ikke bilinear): vi vil ha en faktisk representativ pikselverdi
        // per rute, ikke en blanding av gyldig/ugyldig/sky-data over rutegrensen.
        $this->runGdal("$gdalwarp -overwrite -geoloc -t_srs EPSG:4326 -te $bbox -ts $cols $rows -r near " . escapeshellarg($geolocVrt) . ' ' . escapeshellarg($outTif));
        return $outTif;
    }

    /** Interpolerer en farge langs blå→grønn→oker→rød-skalaen (samme paletten som appens CSS-variabler) */
    private function lstColor($im, float $celsius, float $min, float $max): int
    {
        $stops = [
            [0x1A, 0x5F, 0x8F], // --blue
            [0x25, 0x6B, 0x43], // --green
            [0x8F, 0x64, 0x00], // --ochre
            [0xA9, 0x32, 0x26], // --red
        ];
        $t = max(0.0, min(1.0, ($celsius - $min) / ($max - $min)));
        $segments = count($stops) - 1;
        $pos = $t * $segments;
        $idx = (int)min(floor($pos), $segments - 1);
        $frac = $pos - $idx;
        $r = (int)round($stops[$idx][0] + ($stops[$idx + 1][0] - $stops[$idx][0]) * $frac);
        $g = (int)round($stops[$idx][1] + ($stops[$idx + 1][1] - $stops[$idx][1]) * $frac);
        $b = (int)round($stops[$idx][2] + ($stops[$idx + 1][2] - $stops[$idx][2]) * $frac);
        return imagecolorallocate($im, $r, $g, $b);
    }

    /** Fontfil brukt for alle klokkeslett-/temperaturetiketter (ekte TTF, kreves av GD sin imagettftext()) */
    private function labelFont(): string
    {
        return __DIR__ . '/assets/fonts/IBMPlexMono-Regular.ttf';
    }

    /**
     * Tegner en klokkeslett-etikett (papirfarget halvtransparent boks + trykksverte-
     * tekst, samme stil som temperaturtallenes bakgrunnsbokser) i nedre venstre
     * hjørne av et allerede åpent GD-bilde.
     */
    private function drawTimeLabel($im, string $acquiredAt, int $fontSize = 12): void
    {
        $font  = $this->labelFont();
        $label = date('H:i', strtotime($acquiredAt)) . ' UTC';
        $box   = imagettfbbox($fontSize, 0, $font, $label);
        $textW = $box[2] - $box[0];
        $textH = $box[1] - $box[5];
        $pad   = max(3, (int)round($fontSize * 0.4));

        $h  = imagesy($im);
        $tx = $pad + $pad;
        $ty = $h - $pad - $pad;

        $boxColor = imagecolorallocatealpha($im, 0xE7, 0xE3, 0xD6, 10);
        imagefilledrectangle($im, $tx - $pad, $ty - $textH - $pad, $tx + $textW + $pad, $ty + $pad, $boxColor);
        $inkColor = imagecolorallocate($im, 0x19, 0x1A, 0x1C);
        imagettftext($im, $fontSize, 0, $tx, $ty, $inkColor, $font, $label);
    }

    /**
     * Åpner ferdige PNG-bytes (fra Process API eller GDAL-pipelinen), stempler
     * klokkeslett for satellittpasseringen i nedre venstre hjørne, returnerer nye
     * PNG-bytes. Brukes for S2/S1/Landsat — S3 LST tegner sin egen etikett direkte
     * via drawTimeLabel() siden det allerede bygger bildet fra bunnen av med GD.
     */
    private function stampAcquisitionTime(string $pngData, ?string $acquiredAt): string
    {
        if (!$acquiredAt) return $pngData;
        $im = @imagecreatefromstring($pngData);
        if (!$im) return $pngData;
        imagesavealpha($im, true);
        $this->drawTimeLabel($im, $acquiredAt);
        ob_start();
        imagepng($im);
        $result = ob_get_clean();
        imagedestroy($im);
        return ($result !== false && $result !== '') ? $result : $pngData;
    }

    /**
     * Henter Sentinel-3 SLSTR L2 LST for én dato: laster ned hele SL_2_LST-produktet
     * (CDSE sin nedlastingsserver støtter ikke range-requests — bekreftet i praksis,
     * se BACKLOG.md — men produktet viste seg å være ~70MB, ikke 1.7-1.9GB som
     * OData-katalogen antydet for gamle 2016-arkivprodukter, så full nedlasting er
     * uproblematisk), ekstraherer kun de tre nødvendige NetCDF-filene, reprojiserer
     * LST-swathen til AOI-rutenettet via GDAL GEOLOCATION-VRT, maskerer bort
     * skydekte celler (confidence_in bit 16384 = summary_cloud), og tegner et
     * rutenett med fargede temperaturtall (PHP GD) — IKKE et kontinuerlig
     * varmekart. Returnerer PNG-bytes (samme kontrakt som fetchImageS1()).
     */
    public function fetchImageS3LST(string $date, string $productId, string $productName, ?string $acquiredAt = null): string
    {
        $aoi = $this->config['aoi'];
        $w   = $this->config['image_width'];
        $h   = $this->config['image_height'];
        $cfg = $this->config['s3_lst'];

        $scratchDir = $this->config['data_dir'] . 's3_tmp/' . $date . '_' . getmypid() . '/';
        if (!is_dir($scratchDir)) mkdir($scratchDir, 0755, true);

        try {
            // 1. Full nedlasting
            $token       = $this->getODataToken();
            $zipPath     = $scratchDir . 'product.zip';
            $downloadUrl = $this->config['cdse_odata']['download_host'] . "({$productId})/\$value";

            $fp = fopen($zipPath, 'wb');
            $ch = curl_init($downloadUrl);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
                CURLOPT_FILE           => $fp,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if ($code !== 200 || $err || !is_file($zipPath) || filesize($zipPath) === 0) {
                throw new RuntimeException("Nedlasting av S3-produkt $productName feilet (HTTP $code)" . ($err ? " — $err" : ''));
            }

            // 2. Ekstraher kun de tre nødvendige NetCDF-filene (flatt, -j) —
            //    unzip i stedet for PHP ZipArchive, siden ext-zip ikke er en
            //    garantert aktivert PHP-extension (samme filosofi som resten av
            //    pipelinen: skall ut til eksterne CLI-verktøy, ikke PHP-extensions).
            $wanted = ['LST_in.nc', 'geodetic_in.nc', 'flags_in.nc'];
            $patterns = implode(' ', array_map(fn($n) => escapeshellarg("*/$n"), $wanted));
            $this->runGdal('unzip -o -j ' . escapeshellarg($zipPath) . ' ' . $patterns . ' -d ' . escapeshellarg(rtrim($scratchDir, '/')));
            unlink($zipPath); // ikke behov for zip-en lenger, og den er ~70MB

            foreach ($wanted as $name) {
                if (!is_file($scratchDir . $name)) {
                    throw new RuntimeException("Fant ikke $name etter utpakking av $productName");
                }
            }
            $lstNc   = $scratchDir . 'LST_in.nc';
            $geoNc   = $scratchDir . 'geodetic_in.nc';
            $flagsNc = $scratchDir . 'flags_in.nc';

            $gdalTranslate = $this->config['gdal']['gdal_translate_cmd'];
            $bbox = "{$aoi['west']} {$aoi['south']} {$aoi['east']} {$aoi['north']}";

            // 3. latitude_in/longitude_in er CF-pakket (scale_factor=1e-6) — GDALs
            //    GEOLOCATION-mekanisme leser RÅ pikselverdier uten selv å pakke ut
            //    scale_factor/add_offset, så vi må materialisere ekte gradverdier
            //    først. Uten dette steget feiler gdalwarp -geoloc fullstendig
            //    ("unable to compute output bounds") — bekreftet i praksis.
            $lonUnscaled = $scratchDir . 'lon_unscaled.tif';
            $latUnscaled = $scratchDir . 'lat_unscaled.tif';
            $this->runGdal("$gdalTranslate -unscale -ot Float64 " . $this->netcdfArg($geoNc, 'longitude_in') . ' ' . escapeshellarg($lonUnscaled));
            $this->runGdal("$gdalTranslate -unscale -ot Float64 " . $this->netcdfArg($geoNc, 'latitude_in') . ' ' . escapeshellarg($latUnscaled));

            // 4. Rutenett-dimensjoner: ~grid_cell_km per rute, basert på AOI-utstrekning
            //    (matcher SLSTR sin naturlige ~1km oppløsning — ingen kunstig gruppering)
            $centerLat   = ($aoi['north'] + $aoi['south']) / 2;
            $kmPerDegLat = 111.32;
            $kmPerDegLon = 111.32 * cos(deg2rad($centerLat));
            $cols = max(1, (int)round((($aoi['east'] - $aoi['west']) * $kmPerDegLon) / $cfg['grid_cell_km']));
            $rows = max(1, (int)round((($aoi['north'] - $aoi['south']) * $kmPerDegLat) / $cfg['grid_cell_km']));

            // 5. Warp LST og skyflagg til samme rutenett over AOI
            $lstGrid   = $this->buildGeolocGridTif($lstNc, 'LST', $lonUnscaled, $latUnscaled, $bbox, $cols, $rows, $scratchDir, 'lst');
            $flagsGrid = $this->buildGeolocGridTif($flagsNc, 'confidence_in', $lonUnscaled, $latUnscaled, $bbox, $cols, $rows, $scratchDir, 'flags');

            // 6. Eksporter til XYZ ("lon lat verdi" per rad, enkelt å parse i PHP —
            //    ingen ekstra PHP-extension nødvendig for å lese rasterverdier)
            $lstXyz   = $scratchDir . 'lst.xyz';
            $flagsXyz = $scratchDir . 'flags.xyz';
            $this->runGdal("$gdalTranslate -of XYZ " . escapeshellarg($lstGrid) . ' ' . escapeshellarg($lstXyz));
            $this->runGdal("$gdalTranslate -of XYZ " . escapeshellarg($flagsGrid) . ' ' . escapeshellarg($flagsXyz));

            $lstRows   = file($lstXyz);
            $flagsRows = file($flagsXyz);
            if (!$lstRows || !$flagsRows || count($lstRows) !== count($flagsRows)) {
                throw new RuntimeException("LST- og skyflagg-rutenett har ulikt antall celler for $productName");
            }

            // 7. Tegn rutenett med fargede temperaturtall på transparent PNG, hver
            //    med en liten papirfarget bakgrunnsboks (samme halvtransparente
            //    papirfarge som app-ens .no-data-label/.coord-etiketter) — uten
            //    dette blir f.eks. rødt tall på rød vegetasjon (false_color) ulesbart.
            $im = imagecreatetruecolor($w, $h);
            imagesavealpha($im, true);
            $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
            imagefill($im, 0, 0, $transparent);

            $font = $this->labelFont();
            $fontSize = $cfg['font_size_px'];
            $boxColor = imagecolorallocatealpha($im, 0xE7, 0xE3, 0xD6, 10); // papir, ~92% dekkende

            $drawn = 0;
            for ($i = 0; $i < count($lstRows); $i++) {
                $lstParts = preg_split('/\s+/', trim($lstRows[$i]));
                $flagParts = preg_split('/\s+/', trim($flagsRows[$i]));
                if (count($lstParts) < 3 || count($flagParts) < 3) continue;

                $lon    = (float)$lstParts[0];
                $lat    = (float)$lstParts[1];
                $lstRaw = (float)$lstParts[2];
                $flagRaw = (int)(float)$flagParts[2];

                if ($lstRaw == -32768) continue;      // NODATA (utenfor swath/AOI-kant)
                if (($flagRaw & 16384) !== 0) continue; // summary_cloud-bit satt

                $celsius = (290 + $lstRaw * 0.0020000001) - 273.15;

                $px = ($lon - $aoi['west'])  / ($aoi['east']  - $aoi['west'])  * $w;
                $py = ($aoi['north'] - $lat) / ($aoi['north'] - $aoi['south']) * $h;

                $color = $this->lstColor($im, $celsius, (float)$cfg['temp_min_c'], (float)$cfg['temp_max_c']);
                $label = (string)(int)round($celsius);
                $box   = imagettfbbox($fontSize, 0, $font, $label);
                $textW = $box[2] - $box[0];
                $textH = $box[1] - $box[5];
                $tx = (int)round($px - $textW / 2);
                $ty = (int)round($py + $textH / 2);

                $pad = max(2, (int)round($fontSize * 0.3));
                imagefilledrectangle(
                    $im,
                    $tx - $pad, $ty - $textH - $pad,
                    $tx + $textW + $pad, $ty + $pad,
                    $boxColor
                );
                imagettftext($im, $fontSize, 0, $tx, $ty, $color, $font, $label);
                $drawn++;
            }

            if ($drawn === 0) {
                imagedestroy($im);
                throw new RuntimeException("Ingen sky-frie LST-celler for $productName (hele scenen skydekket over AOI)");
            }

            // Klokkeslett for selve målingen (satellittpasseringen) i hjørnet —
            // temperaturrutenettet er ferskt for et gitt tidspunkt på dagen, ikke
            // et døgngjennomsnitt, så dette er nødvendig kontekst.
            if ($acquiredAt) {
                $this->drawTimeLabel($im, $acquiredAt, max(9, (int)round($fontSize * 0.75)));
            }

            $pngPath = $scratchDir . 'final.png';
            imagepng($im, $pngPath);
            imagedestroy($im);

            $imageData = file_get_contents($pngPath);
            if ($imageData === false || $imageData === '') {
                throw new RuntimeException("Kunne ikke lese ferdig S3 LST-PNG (tom eller manglende fil): $pngPath");
            }
            return $imageData;
        } finally {
            // Rekursiv opprydding: en delvis/feilet zip-utpakking kan i sjeldne
            // tilfeller etterlate en undermappe (f.eks. hvis -j-flagget av en
            // eller annen grunn ikke ble respektert), i motsetning til Landsat-
            // pipelinen som alltid kun har flate filer i scratch-mappen.
            $this->rrmdir($scratchDir);
        }
    }

    /** Sletter en mappe rekursivt (filer + undermapper), stille ved feil */
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '*') as $f) {
            if (is_dir($f)) $this->rrmdir($f . '/');
            else @unlink($f);
        }
        @rmdir(rtrim($dir, '/'));
    }

    // ── Process API — hent rendret bilde ─────────────────────────────────────
    public function fetchImage(string $date): string
    {
        $token = $this->getToken();
        $aoi   = $this->config['aoi'];
        $w     = $this->config['image_width'];
        $h     = $this->config['image_height'];

        $mode = $this->config['render_mode'] ?? 'true_color';

        // 4. band = dataMask: 1 der satellitten har data, 0 (transparent) der den ikke har
        if ($mode === 'false_color') {
            $evalscript = <<<'JS'
//VERSION=3
function setup() {
  return { input: ["B08","B04","B03","dataMask"], output: { bands: 4 } };
}
function evaluatePixel(s) {
  var gain = 3.0;
  return [
    Math.min(1, s.B08 * gain),
    Math.min(1, s.B04 * gain),
    Math.min(1, s.B03 * gain),
    s.dataMask
  ];
}
JS;
        } else {
            $evalscript = <<<'JS'
//VERSION=3
function setup() {
  return { input: ["B04","B03","B02","dataMask"], output: { bands: 4 } };
}
function evaluatePixel(s) {
  var gain = 3.5;
  return [
    Math.min(1, s.B04 * gain),
    Math.min(1, s.B03 * gain),
    Math.min(1, s.B02 * gain),
    s.dataMask
  ];
}
JS;
        }

        $payload = [
            'input' => [
                'bounds' => [
                    'bbox' => [$aoi['west'], $aoi['south'], $aoi['east'], $aoi['north']],
                    'properties' => ['crs' => 'http://www.opengis.net/def/crs/OGC/1.3/CRS84'],
                ],
                'data' => [[
                    'type'       => 'sentinel-2-l2a',
                    'dataFilter' => [
                        'timeRange' => [
                            'from' => $date . 'T00:00:00Z',
                            'to'   => $date . 'T23:59:59Z',
                        ],
                        'maxCloudCoverage' => $this->config['max_cloud_cover'],
                    ],
                ]],
            ],
            'output' => [
                'width'  => $w,
                'height' => $h,
                'responses' => [[
                    'identifier' => 'default',
                    'format'     => ['type' => 'image/png'],
                ]],
            ],
            'evalscript' => $evalscript,
        ];

        $ch = curl_init($this->config['sh']['process_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
                'Accept: image/png',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $imageData   = curl_exec($ch);
        $code        = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($code !== 200) {
            $msg = is_string($imageData) ? substr($imageData, 0, 200) : '';
            throw new RuntimeException("Process API feilet (HTTP $code): $msg");
        }

        if (!str_contains((string)$contentType, 'image')) {
            throw new RuntimeException("Svar er ikke et bilde (Content-Type: $contentType)");
        }

        return $imageData;
    }

    // ── Kuldemengde (MET Norge Frost API) — bak kuldemengde_enabled-flagget ──
    // Sum av alle døgnmiddeltemperaturer under 0 °C siden sesongstart (1. okt),
    // per sted i frost.locations. Skrives til data/kuldemengde.json (atomisk,
    // samme mønster som saveMetadata) og leveres til frontend via ?action=list.

    // Sesongen dato D tilhører: okt–des → starter samme år, jan–mai → forrige
    // år, jun–sep → utenfor sesong (null). Ren MM-DD-strengsammenligning.
    private function kmSeasonFor(string $date): ?array
    {
        $startMD = $this->config['frost']['season_start'] ?? '10-01';
        $endMD   = $this->config['frost']['season_end']   ?? '05-31';
        $y  = (int)substr($date, 0, 4);
        $md = substr($date, 5);
        if ($md >= $startMD) return ['start' => "$y-$startMD",       'end' => ($y + 1) . "-$endMD"];
        if ($md <= $endMD)   return ['start' => ($y - 1) . "-$startMD", 'end' => "$y-$endMD"];
        return null;
    }

    private function frostRequest(array $query): array
    {
        $clientId = $this->config['frost']['client_id'] ?? '';
        if (empty($clientId)) {
            throw new RuntimeException(
                "Mangler FROST_CLIENT_ID for kuldemengde.\n" .
                "Skaff gratis klient-ID på https://frost.met.no/auth/requestCredentials.html\n" .
                "og lim inn FROST_CLIENT_ID i .sentinel.env (ett nivå opp fra webroot)."
            );
        }
        $url = ($this->config['frost']['base_url'] ?? 'https://frost.met.no/observations/v0.jsonld')
             . '?' . http_build_query($query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $clientId . ':',   // klient-ID som brukernavn, tomt passord
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException("Frost-forespørsel feilet: $err");
        return [$code, $body];
    }

    // Døgnmiddeltemperaturer for én stasjon → [dato => °C]. referencetime-intervallet
    // er slutt-eksklusivt hos Frost.
    private function fetchFrostDailyMeans(string $station, string $from, string $toExclusive): array
    {
        $element = $this->config['frost']['element'] ?? 'mean(air_temperature P1D)';
        $query = [
            'sources'       => $station,
            'elements'      => $element,
            'referencetime' => "$from/$toExclusive",
            // P1D-elementet finnes ofte både som PT0H (kalenderdøgn UTC) og PT6H
            // (tradisjonelt klimadøgn 06–06 UTC) — be kun om PT0H for å slippe duplikater
            'timeoffsets'   => 'PT0H',
        ];
        [$code, $body] = $this->frostRequest($query);
        if ($code === 412) {
            // Stasjonen mangler PT0H-serien — hent alt og dedup selv i stedet
            unset($query['timeoffsets']);
            [$code, $body] = $this->frostRequest($query);
        }
        if ($code === 404 || $code === 412) return [];   // ingen data i perioden
        if ($code === 401 || $code === 403) {
            throw new RuntimeException("Frost avviste forespørselen (HTTP $code) — sjekk FROST_CLIENT_ID i .sentinel.env");
        }
        if ($code !== 200) {
            throw new RuntimeException("Frost-forespørsel feilet (HTTP $code): " . substr((string)$body, 0, 300));
        }

        $data  = json_decode($body, true);
        $means = [];   // dato => ['value' => °C, 'rank' => timeoffset-prioritet]
        foreach ($data['data'] ?? [] as $item) {
            $date = substr($item['referenceTime'] ?? '', 0, 10);
            if ($date === '') continue;
            foreach ($item['observations'] ?? [] as $obs) {
                if (($obs['elementId'] ?? '') !== $element) continue;
                if ((int)($obs['qualityCode'] ?? 0) >= 6) continue;   // 6/7 = feilaktig måling
                if (!isset($obs['value']) || !is_numeric($obs['value'])) continue;
                $offset = $obs['timeOffset'] ?? '';
                $rank   = $offset === 'PT0H' ? 0 : ($offset === 'PT6H' ? 1 : 2);
                if (!isset($means[$date]) || $rank < $means[$date]['rank']) {
                    $means[$date] = ['value' => (float)$obs['value'], 'rank' => $rank];
                }
            }
        }
        return array_map(fn($m) => $m['value'], $means);
    }

    // Fyll indre datahull i en dato→døgnmiddel-serie: hver manglende dag mellom
    // to kjente døgn får medianen av nærmeste kjente døgn før og etter hullet
    // (for to verdier = snittet av dem). Kant-hull — før første eller etter
    // siste kjente døgn (typisk Frosts ~1 døgns publiseringsforsinkelse) —
    // fylles IKKE: ingen ekstrapolering.
    // Returnerer [fylt serie, liste over interpolerte datoer].
    private function fillFrostGaps(array $means): array
    {
        $dates = array_keys($means);
        sort($dates);
        $interpolated = [];
        for ($i = 1; $i < count($dates); $i++) {
            $prev = new DateTime($dates[$i - 1]);
            $next = new DateTime($dates[$i]);
            if ((int)$prev->diff($next)->days <= 1) continue;
            $fill = ($means[$dates[$i - 1]] + $means[$dates[$i]]) / 2;
            for ($day = (clone $prev)->modify('+1 day'); $day < $next; $day->modify('+1 day')) {
                $d = $day->format('Y-m-d');
                $means[$d] = $fill;
                $interpolated[] = $d;
            }
        }
        return [$means, $interpolated];
    }

    // Bygg og skriv data/kuldemengde.json for sesongen $asOf tilhører.
    // Idempotent: overskriver alltid hele filen. Utenfor sesong skrives en tom
    // locations-serie UTEN å kalle Frost — frontend skjuler da ❄-knappen.
    public function updateKuldemengde(?string $asOf = null): array
    {
        $asOf  = $asOf ?: date('Y-m-d');
        $frost = $this->config['frost'] ?? [];
        $file  = $frost['data_file'] ?? ($this->config['data_dir'] . 'kuldemengde.json');
        $locs  = $frost['locations'] ?? [];

        // Relevant sesong: dagens — eller, tidlig i oktober, sesongen som fortsatt
        // dekker de eldste slidene i keep_days-vinduet (de får bare ingen oppføring)
        $keepDays = $this->config['keep_days'] ?? 30;
        $season = $this->kmSeasonFor($asOf)
            ?? $this->kmSeasonFor(date('Y-m-d', strtotime("$asOf -{$keepDays} days")));

        // Ett Frost-kall per unik stasjon — flere steder kan dele stasjon.
        // Indre datahull interpoleres per stasjon (se fillFrostGaps()).
        $byStation       = [];
        $filledByStation = [];
        if ($season !== null) {
            $from = $season['start'];
            $toEx = date('Y-m-d', strtotime(min($asOf, $season['end']) . ' +1 day'));
            foreach (array_unique(array_column($locs, 'station')) as $station) {
                [$byStation[$station], $filledByStation[$station]] =
                    $this->fillFrostGaps($this->fetchFrostDailyMeans($station, $from, $toEx));
            }
        }

        $out = [
            'season_start' => $season['start'] ?? null,
            'season_end'   => $season['end']   ?? null,
            'unit'         => 'degC_days',
            'updated_at'   => date('c'),
            'locations'    => [],
        ];

        $days = 0;
        $missingCount = 0;
        $interpCount  = 0;
        foreach ($locs as $loc) {
            $means   = $byStation[$loc['station']] ?? [];
            $filled  = array_flip($filledByStation[$loc['station']] ?? []);
            $series  = [];
            $missing = [];
            $interp  = [];
            if ($season !== null) {
                $sum  = 0.0;
                $day  = new DateTime($season['start']);
                $last = new DateTime(min($asOf, $season['end']));
                while ($day <= $last) {
                    $d = $day->format('Y-m-d');
                    if (array_key_exists($d, $means)) {
                        // Kuldemengde regnes som positivt tall: −4 °C-døgn bidrar +4
                        $sum += max(0.0, -$means[$d]);
                        $series[$d] = ['mean' => round($means[$d], 1), 'km' => round($sum, 1)];
                        if (isset($filled[$d])) {
                            $series[$d]['interpolated'] = true;
                            $interp[] = $d;
                        }
                    } else {
                        // Ufylte kant-hull (før første/etter siste kjente døgn, typisk
                        // Frosts ~1 døgns publiseringsforsinkelse) bidrar 0 —
                        // frontendens «nyeste ≤ dato» brer over hullet
                        $missing[] = $d;
                    }
                    $day->modify('+1 day');
                }
            }
            $out['locations'][] = [
                'name'              => $loc['name'],
                'lat'               => $loc['lat'],
                'lon'               => $loc['lon'],
                'station'           => $loc['station'],
                'station_name'      => $loc['station_name'] ?? $loc['station'],
                'km_needed'         => $loc['km_needed'] ?? null,
                'missing_days'      => $missing,
                'interpolated_days' => $interp,
                // Tom serie må bli {} i JSON (ikke []) så frontend kan bruke Object.keys
                'series'            => $series === [] ? new stdClass() : $series,
            ];
            $days         = max($days, count($series));
            $missingCount = max($missingCount, count($missing));
            $interpCount  = max($interpCount, count($interp));
        }

        // Atomisk skriving — api.php leser filen samtidig
        $tmp = $file . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($tmp, $file);

        return [
            'season'       => $season ? "{$season['start']} → {$season['end']}" : 'utenfor sesong',
            'days'         => $days,
            'missing'      => $missingCount,
            'interpolated' => $interpCount,
        ];
    }

    // ── Metadata ──────────────────────────────────────────────────────────────
    public function loadMetadata(): array
    {
        $file = $this->config['metadata_file'];
        if (!file_exists($file)) return [];
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public function saveMetadata(array $data): void
    {
        // Atomisk skriving: samtidige lesninger fra api.php skal aldri se halvferdig JSON
        $file = $this->config['metadata_file'];
        $tmp  = $file . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($tmp, $file);
    }

    // ── Hoved-kjøring ─────────────────────────────────────────────────────────
    public function runRange(string $startDate, string $endDate): array
    {
        return $this->_run($startDate, $endDate);
    }

    public function run(?int $daysBack = null): array
    {
        $daysBack  = $daysBack ?? $this->config['days_to_search'];
        $endDate   = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$daysBack} days"));
        return $this->_run($startDate, $endDate);
    }

    private function _run(string $startDate, string $endDate): array
    {
        $this->log("=== Kjøring startet | {$this->config['aoi']['name']} | $startDate → $endDate ===");

        $stats = [
            'searched'      => 0, 'downloaded' => 0, 'skipped' => 0, 'errors' => [],
            's1_downloaded' => 0, 's1_skipped' => 0, 's1_errors' => [],
            'landsat_downloaded' => 0, 'landsat_skipped' => 0, 'landsat_errors' => [],
            's3_downloaded' => 0, 's3_skipped' => 0, 's3_errors' => [],
            'km_updated'    => false, 'km_errors' => [],
        ];

        $dates = $this->searchDates($startDate, $endDate);
        $stats['searched'] = count($dates);

        $metadata = $this->loadMetadata();

        $this->log("S2 katalogsøk: {$stats['searched']} dato(er) funnet");

        // Skip-liste: kun ekte S2-bilder (ikke kart-placeholders, ikke S1/Landsat)
        $existingS2Dates = [];
        foreach ($metadata as $m) {
            if (($m['type'] ?? '') !== 'map' && !in_array($m['sensor'] ?? '', ['S1', 'LANDSAT', 'S3'], true) && !empty($m['filename'])) {
                if (file_exists($this->config['images_dir'] . $m['filename'])) {
                    $existingS2Dates[] = $m['date'];
                }
            }
        }

        foreach ($dates as $entry) {
            $date  = $entry['date'];
            $cloud = $entry['cloud_cover'];

            if (in_array($date, $existingS2Dates, true)) {
                $stats['skipped']++;
                $this->log("S2 SKIP  $date  (allerede lagret)");
                continue;
            }

            $filename = $date . '.png';
            $savePath = $this->config['images_dir'] . $filename;

            try {
                $imageData = $this->fetchImage($date);
                $imageData = $this->stampAcquisitionTime($imageData, $entry['acquired_at'] ?? null);
                file_put_contents($savePath, $imageData);
                $kb = round(strlen($imageData) / 1024);

                $thumbFile = $date . '.jpg';
                $thumbPath = $this->config['thumbs_dir'] . $thumbFile;
                $thumbOk   = $this->generateThumb($savePath, $thumbPath);

                // Erstatt eventuell kart-placeholder for denne datoen
                $metadata = array_values(array_filter($metadata,
                    fn($m) => !($m['date'] === $date && ($m['type'] ?? '') === 'map')
                ));

                $metadata[] = [
                    'id'          => $date,
                    'date'        => $date,
                    'cloud_cover' => $cloud,
                    'filename'    => $filename,
                    'thumbnail'   => $thumbOk ? $thumbFile : null,
                    'acquired_at' => $entry['acquired_at'] ?? null,
                    'fetched_at'  => date('c'),
                ];

                $stats['downloaded']++;
                $thumb = $thumbOk ? "thumbnail: $thumbFile" : 'thumbnail: FEIL';
                $this->log("S2 OK    $date  →  $filename  ({$kb} KB  skydekke: {$cloud}%  $thumb)");
            } catch (RuntimeException $e) {
                $stats['errors'][] = "$date: " . $e->getMessage();
                $this->log("S2 FEIL  $date  " . $e->getMessage());
            }
        }

        // Legg til kart-placeholder for alle dager i perioden uten satellittbilde
        $metaDates = array_column($metadata, 'date');
        $day = new DateTime($startDate);
        $end = new DateTime($endDate);
        while ($day <= $end) {
            $d = $day->format('Y-m-d');
            if (!in_array($d, $metaDates, true)) {
                $metadata[] = [
                    'id'          => 'map_' . $d,
                    'date'        => $d,
                    'cloud_cover' => null,
                    'filename'    => null,
                    'type'        => 'map',
                    'fetched_at'  => date('c'),
                ];
                $this->log("KART     $d  (ingen satellittdata)");
            }
            $day->modify('+1 day');
        }

        // Lagre fortløpende: hvis kjøringen blir drept midt i den trege Landsat-pollingen
        // under, skal ikke S2-bilder som allerede er lastet ned i dette passet gå tapt.
        $this->saveMetadata($metadata);

        // ── S1 (kun når product === 'pro') ───────────────────────────────────
        if (($this->config['product'] ?? 'std') === 'pro') {
            $s1Dates = $this->searchDatesS1($startDate, $endDate);

            $this->log("S1 katalogsøk: " . count($s1Dates) . " dato(er) funnet");

            // Skip-liste: S1-entries der filen faktisk finnes på disk, med lagret
            // AOI-dekningsgrad. Manglende 'coverage'-felt (hentet før denne sjekken
            // fantes) behandles som 0 — så et bedre alternativ tas automatisk i
            // bruk én gang, uten at det senere fører til unødvendige re-nedlastinger
            // (samme scene gir samme dekningstall neste gang, aldri "bedre enn seg selv").
            $existingS1 = [];
            foreach ($metadata as $m) {
                if (($m['sensor'] ?? '') === 'S1' && !empty($m['filename'])) {
                    if (file_exists($this->config['images_dir'] . $m['filename'])) {
                        $existingS1[$m['date']] = (float)($m['coverage'] ?? 0.0);
                    }
                }
            }

            foreach ($s1Dates as $entry) {
                $date     = $entry['date'];
                $coverage = $entry['coverage'];

                if (isset($existingS1[$date]) && $coverage <= $existingS1[$date]) {
                    $stats['s1_skipped']++;
                    $this->log("S1 SKIP  $date  (allerede lagret, dekning " . round($existingS1[$date] * 100) . "%)");
                    continue;
                }

                // Fjern eventuell foreldret S1-metadata uten fil på disk
                $metadata = array_values(array_filter($metadata,
                    fn($m) => !(($m['sensor'] ?? '') === 'S1' && $m['date'] === $date)
                ));

                $filename = $date . '-s1.png';
                $savePath = $this->config['images_dir'] . $filename;

                try {
                    $imageData = $this->fetchImageS1($date);
                    $imageData = $this->stampAcquisitionTime($imageData, $entry['acquired_at'] ?? null);
                    file_put_contents($savePath, $imageData);
                    $kb = round(strlen($imageData) / 1024);

                    $thumbFile = $date . '-s1.jpg';
                    $thumbPath = $this->config['thumbs_dir'] . $thumbFile;
                    $thumbOk   = $this->generateThumb($savePath, $thumbPath);

                    $metadata[] = [
                        'id'          => 's1_' . $date,
                        'date'        => $date,
                        'sensor'      => 'S1',
                        'cloud_cover' => null,
                        'coverage'    => $coverage,
                        'filename'    => $filename,
                        'thumbnail'   => $thumbOk ? $thumbFile : null,
                        'type'        => 'radar',
                        'acquired_at' => $entry['acquired_at'] ?? null,
                        'fetched_at'  => date('c'),
                    ];

                    $stats['s1_downloaded']++;
                    $upgrade = isset($existingS1[$date])
                        ? " — oppgradert fra " . round($existingS1[$date] * 100) . "% dekning"
                        : '';
                    $thumb = $thumbOk ? "thumbnail: $thumbFile" : 'thumbnail: FEIL';
                    $this->log("S1 OK    $date  →  $filename  ({$kb} KB  dekning: " . round($coverage * 100) . "%  $thumb)$upgrade");
                } catch (RuntimeException $e) {
                    $stats['s1_errors'][] = "$date: " . $e->getMessage();
                    $this->log("S1 FEIL  $date  " . $e->getMessage());
                }
            }

            // Lagre fortløpende av samme grunn som over — S1 skal ikke gå tapt hvis
            // Landsat-pollingen under blir avbrutt.
            $this->saveMetadata($metadata);
        }

        // ── Landsat (kun når landsat_enabled === true) ───────────────────────
        if (($this->config['landsat_enabled'] ?? false) === true) {
            try {
                $this->usgsLogin();

                $landsatDates = $this->searchDatesLandsat($startDate, $endDate);
                $this->log("Landsat katalogsøk: " . count($landsatDates) . " dato(er) funnet");

                $existingLandsat = [];
                foreach ($metadata as $m) {
                    if (($m['sensor'] ?? '') === 'LANDSAT' && !empty($m['filename'])) {
                        if (file_exists($this->config['images_dir'] . $m['filename'])) {
                            $existingLandsat[] = $m['date'];
                        }
                    }
                }

                foreach ($landsatDates as $entry) {
                    $date  = $entry['date'];
                    $cloud = $entry['cloud_cover'];

                    if (in_array($date, $existingLandsat, true)) {
                        $stats['landsat_skipped']++;
                        $this->log("LANDSAT SKIP  $date  (allerede lagret)");
                        continue;
                    }

                    // Fjern eventuell foreldret Landsat-metadata uten fil på disk
                    $metadata = array_values(array_filter($metadata,
                        fn($m) => !(($m['sensor'] ?? '') === 'LANDSAT' && $m['date'] === $date)
                    ));

                    $filename = $date . '-landsat.png';
                    $savePath = $this->config['images_dir'] . $filename;

                    try {
                        $imageData = $this->fetchImageLandsat($date, $entry['entity_id']);
                        $imageData = $this->stampAcquisitionTime($imageData, $entry['acquired_at'] ?? null);
                        file_put_contents($savePath, $imageData);
                        $kb = round(strlen($imageData) / 1024);

                        $thumbFile = $date . '-landsat.jpg';
                        $thumbPath = $this->config['thumbs_dir'] . $thumbFile;
                        $thumbOk   = $this->generateThumb($savePath, $thumbPath);

                        $metadata[] = [
                            'id'          => 'landsat_' . $date,
                            'date'        => $date,
                            'sensor'      => 'LANDSAT',
                            'cloud_cover' => $cloud,
                            'filename'    => $filename,
                            'thumbnail'   => $thumbOk ? $thumbFile : null,
                            'type'        => 'landsat',
                            'acquired_at' => $entry['acquired_at'] ?? null,
                            'fetched_at'  => date('c'),
                        ];

                        $stats['landsat_downloaded']++;
                        $thumb = $thumbOk ? "thumbnail: $thumbFile" : 'thumbnail: FEIL';
                        $this->log("LANDSAT OK    $date  →  $filename  ({$kb} KB  skydekke: {$cloud}%  $thumb)");
                    } catch (RuntimeException $e) {
                        $stats['landsat_errors'][] = "$date: " . $e->getMessage();
                        $this->log("LANDSAT FEIL  $date  " . $e->getMessage());
                    }
                }
            } catch (RuntimeException $e) {
                $stats['landsat_errors'][] = $e->getMessage();
                $this->log("LANDSAT FEIL  " . $e->getMessage());
            } finally {
                $this->usgsLogout();
            }
        }

        // ── S3 SLSTR L2 LST (kun når s3_lst_enabled === true) ────────────────
        // Uavhengig pipeline på samme måte som Landsat — egen feilhåndtering,
        // påvirker aldri S2/S1/Landsat om noe her feiler.
        if (($this->config['s3_lst_enabled'] ?? false) === true) {
            try {
                $s3Dates = $this->searchDatesS3($startDate, $endDate);
                $this->log("S3 katalogsøk: " . count($s3Dates) . " dato(er) funnet");

                $existingS3 = [];
                foreach ($metadata as $m) {
                    if (($m['sensor'] ?? '') === 'S3' && !empty($m['filename'])) {
                        if (file_exists($this->config['images_dir'] . $m['filename'])) {
                            $existingS3[] = $m['date'];
                        }
                    }
                }

                foreach ($s3Dates as $entry) {
                    $date = $entry['date'];
                    if (in_array($date, $existingS3, true)) {
                        $stats['s3_skipped']++;
                        $this->log("S3 SKIP  $date  (allerede lagret)");
                        continue;
                    }

                    // Fjern eventuell foreldret S3-metadata uten fil på disk
                    $metadata = array_values(array_filter($metadata,
                        fn($m) => !(($m['sensor'] ?? '') === 'S3' && $m['date'] === $date)
                    ));

                    $filename = $date . '-s3lst.png';
                    $savePath = $this->config['images_dir'] . $filename;

                    try {
                        $imageData = $this->fetchImageS3LST($date, $entry['product_id'], $entry['product_name'], $entry['acquired_at'] ?? null);
                        file_put_contents($savePath, $imageData);
                        $kb = round(strlen($imageData) / 1024);

                        $thumbFile = $date . '-s3lst.jpg';
                        $thumbPath = $this->config['thumbs_dir'] . $thumbFile;
                        $thumbOk   = $this->generateThumb($savePath, $thumbPath);

                        $metadata[] = [
                            'id'          => 's3_' . $date,
                            'date'        => $date,
                            'sensor'      => 'S3',
                            'cloud_cover' => null,
                            'filename'    => $filename,
                            'thumbnail'   => $thumbOk ? $thumbFile : null,
                            'type'        => 'lst',
                            'acquired_at' => $entry['acquired_at'] ?? null,
                            'fetched_at'  => date('c'),
                        ];

                        $stats['s3_downloaded']++;
                        $thumb = $thumbOk ? "thumbnail: $thumbFile" : 'thumbnail: FEIL';
                        $this->log("S3 OK    $date  →  $filename  ({$kb} KB  $thumb)");
                    } catch (RuntimeException $e) {
                        $stats['s3_errors'][] = "$date: " . $e->getMessage();
                        $this->log("S3 FEIL  $date  " . $e->getMessage());
                    }
                }
            } catch (RuntimeException $e) {
                $stats['s3_errors'][] = $e->getMessage();
                $this->log("S3 FEIL  " . $e->getMessage());
            }
        }

        // ── Kuldemengde (kun når kuldemengde_enabled === true) ───────────────
        // Uavhengig av bildepipelinene over — en feilende Frost-kobling
        // påvirker aldri S2/S1/Landsat/S3.
        if (($this->config['kuldemengde_enabled'] ?? false) === true) {
            try {
                $km = $this->updateKuldemengde();
                $stats['km_updated'] = true;
                $this->log("KM OK    {$km['season']}  ({$km['days']} døgn, {$km['missing']} mangler, {$km['interpolated']} interpolert)");
            } catch (RuntimeException $e) {
                $stats['km_errors'][] = $e->getMessage();
                $this->log("KM FEIL  " . $e->getMessage());
            }
        }

        usort($metadata, fn($a, $b) => strcmp($b['date'], $a['date']));
        $this->saveMetadata($metadata);

        $stats['deleted'] = $this->purgeOldImages();

        $s2sum = "S2: {$stats['downloaded']} nedlastet / {$stats['skipped']} hoppet over / " . count($stats['errors']) . " feil";
        $s1sum = "S1: {$stats['s1_downloaded']} nedlastet / {$stats['s1_skipped']} hoppet over / " . count($stats['s1_errors']) . " feil";
        $lsum  = "Landsat: {$stats['landsat_downloaded']} nedlastet / {$stats['landsat_skipped']} hoppet over / " . count($stats['landsat_errors']) . " feil";
        $s3sum = "S3: {$stats['s3_downloaded']} nedlastet / {$stats['s3_skipped']} hoppet over / " . count($stats['s3_errors']) . " feil";
        $kmsum = ($this->config['kuldemengde_enabled'] ?? false)
            ? ('KM: ' . ($stats['km_updated'] ? 'oppdatert' : 'feilet'))
            : 'KM: av';
        $this->log("=== Ferdig — $s2sum | $s1sum | $lsum | $s3sum | $kmsum | {$stats['deleted']} slettet ===");

        return $stats;
    }

    // ── Slett bilder eldre enn keep_days ─────────────────────────────────────
    public function purgeOldImages(): int
    {
        $keepDays = $this->config['keep_days'] ?? 365;
        $cutoff   = date('Y-m-d', strtotime("-{$keepDays} days"));

        $metadata = $this->loadMetadata();
        $deleted  = 0;

        $metadata = array_filter($metadata, function ($m) use ($cutoff, $keepDays, &$deleted) {
            if ($m['date'] >= $cutoff) return true;

            if (!empty($m['filename'])) {
                $path = $this->config['images_dir'] . $m['filename'];
                $kb = file_exists($path) ? round(filesize($path) / 1024) : 0;
                if (file_exists($path)) unlink($path);

                $thumb = $m['thumbnail'] ?? null;
                if ($thumb) {
                    $tp = $this->config['thumbs_dir'] . $thumb;
                    if (file_exists($tp)) unlink($tp);
                }

                $sensor = match ($m['sensor'] ?? '') {
                    'S1'      => ' S1',
                    'LANDSAT' => ' LANDSAT',
                    'S3'      => ' S3',
                    default   => ' S2',
                };
                $file   = $m['filename'];
                $this->log("SLETTET {$m['date']}{$sensor}  →  $file  ({$kb} KB  eldre enn {$keepDays} dager)");
            }
            $deleted++;
            return false;
        });

        if ($deleted > 0) {
            $this->saveMetadata(array_values($metadata));
        }

        $this->purgeStaleLandsatScratch();
        $this->purgeStaleS3Scratch();

        return $deleted;
    }

    // ── Rydd opp landsat_tmp/-mapper som ble liggende igjen etter en avbrutt kjøring
    //    (f.eks. drept av PHP sin execution-time-limit midt i GDAL-pipelinen) ────────
    private function purgeStaleLandsatScratch(): void
    {
        $base = $this->config['data_dir'] . 'landsat_tmp/';
        if (!is_dir($base)) return;

        $cutoff = time() - 6 * 3600; // eldre enn én cron-syklus regnes som forlatt
        foreach (glob($base . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) >= $cutoff) continue;
            foreach (glob($dir . '/*') ?: [] as $f) unlink($f);
            @rmdir($dir);
        }
    }

    // ── Rydd opp s3_tmp/-mapper som ble liggende igjen etter en avbrutt kjøring ──
    private function purgeStaleS3Scratch(): void
    {
        $base = $this->config['data_dir'] . 's3_tmp/';
        if (!is_dir($base)) return;

        $cutoff = time() - 6 * 3600;
        foreach (glob($base . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) >= $cutoff) continue;
            $this->rrmdir($dir . '/');
        }
    }
}

// ── CLI ───────────────────────────────────────────────────────────────────────
// Kun når fetch.php kjøres direkte — ikke når den require_once'es som bibliotek
// (f.eks. fra generate_thumbs.php), ellers ville hver slik inkludering utilsiktet
// trigget en ekte henting mot Sentinel Hub-API-et.
if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
    $config  = require __DIR__ . '/config.php';
    $fetcher = new SentinelFetcher($config);

    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--(\w+)=(.+)$/', $arg, $m)) $args[$m[1]] = $m[2];
    }
    $from = $args['from'] ?? null;
    $to   = $args['to']   ?? null;

    // --kuldemengde=YYYY-MM-DD: oppdater kun kuldemengde-filen og avslutt.
    // Datoen styrer hvilken sesong som hentes — nyttig for testing utenfor
    // sesong (f.eks. --kuldemengde=2026-02-01 henter vinteren 2025/2026).
    if (isset($args['kuldemengde'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['kuldemengde'])) {
            echo "Bruk: php fetch.php --kuldemengde=YYYY-MM-DD\n";
            exit(1);
        }
        try {
            $km = $fetcher->updateKuldemengde($args['kuldemengde']);
            echo "Kuldemengde oppdatert: {$km['season']}  ({$km['days']} døgn, {$km['missing']} mangler, {$km['interpolated']} interpolert)\n";
            exit(0);
        } catch (RuntimeException $e) {
            echo "FEIL: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    if (($from && !$to) || (!$from && $to)) {
        echo "Bruk: php fetch.php --from=YYYY-MM-DD --to=YYYY-MM-DD\n";
        exit(1);
    }

    if ($from && $to) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            echo "Datoformat må være YYYY-MM-DD\n";
            exit(1);
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        try {
            $fetcher->runRange($from, $to);
        } catch (RuntimeException $e) {
            echo "FEIL: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        try {
            $fetcher->run();
        } catch (RuntimeException $e) {
            echo "FEIL: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}
