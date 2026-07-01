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
                "Lim inn client_id og client_secret i config.php under 'sh'."
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

    // ── Catalog search ────────────────────────────────────────────────────────
    /** Returnerer array med ['date'=>'YYYY-MM-DD', 'cloud_cover'=>float] */
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
                $byDate[$date] = ['date' => $date, 'cloud_cover' => round($cloud, 1)];
            }
        }

        // Nyeste først
        krsort($byDate);
        return array_values($byDate);
    }

    // ── Sentinel-1 catalog search ─────────────────────────────────────────────
    /** Returnerer array med ['date'=>'YYYY-MM-DD', 'cloud_cover'=>null] */
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

        // Dedup: én entry per dato, beholder første påtrufne
        $byDate = [];
        foreach ($features as $f) {
            $date = substr($f['properties']['datetime'], 0, 10);
            if (!isset($byDate[$date])) {
                $byDate[$date] = ['date' => $date, 'cloud_cover' => null];
            }
        }

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
        file_put_contents(
            $this->config['metadata_file'],
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
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
        ];

        $dates = $this->searchDates($startDate, $endDate);
        $stats['searched'] = count($dates);

        $metadata = $this->loadMetadata();

        // Skip-liste: kun ekte S2-bilder (ikke kart-placeholders, ikke S1)
        $existingS2Dates = [];
        foreach ($metadata as $m) {
            if (($m['type'] ?? '') !== 'map' && ($m['sensor'] ?? '') !== 'S1' && !empty($m['filename'])) {
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
                continue;
            }

            $filename = $date . '.png';
            $savePath = $this->config['images_dir'] . $filename;

            try {
                $imageData = $this->fetchImage($date);
                file_put_contents($savePath, $imageData);

                $thumbFile = $date . '.jpg';
                $thumbPath = $this->config['thumbs_dir'] . $thumbFile;
                $this->generateThumb($savePath, $thumbPath);

                // Erstatt eventuell kart-placeholder for denne datoen
                $metadata = array_values(array_filter($metadata,
                    fn($m) => !($m['date'] === $date && ($m['type'] ?? '') === 'map')
                ));

                $metadata[] = [
                    'id'          => $date,
                    'date'        => $date,
                    'cloud_cover' => $cloud,
                    'filename'    => $filename,
                    'thumbnail'   => file_exists($thumbPath) ? $thumbFile : null,
                    'fetched_at'  => date('c'),
                ];

                $stats['downloaded']++;
                $this->log("S2 OK    $date  (skydekke: {$cloud}%)");
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

        // ── S1 (kun når product === 'pro') ───────────────────────────────────
        if (($this->config['product'] ?? 'std') === 'pro') {
            $s1Dates = $this->searchDatesS1($startDate, $endDate);

            // Skip-liste: S1-entries der filen faktisk finnes på disk
            $existingS1 = [];
            foreach ($metadata as $m) {
                if (($m['sensor'] ?? '') === 'S1' && !empty($m['filename'])) {
                    if (file_exists($this->config['images_dir'] . $m['filename'])) {
                        $existingS1[] = $m['date'];
                    }
                }
            }

            foreach ($s1Dates as $entry) {
                $date = $entry['date'];
                if (in_array($date, $existingS1, true)) {
                    $stats['s1_skipped']++;
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
                    file_put_contents($savePath, $imageData);

                    $thumbFile = $date . '-s1.jpg';
                    $thumbPath = $this->config['thumbs_dir'] . $thumbFile;
                    $this->generateThumb($savePath, $thumbPath);

                    $metadata[] = [
                        'id'          => 's1_' . $date,
                        'date'        => $date,
                        'sensor'      => 'S1',
                        'cloud_cover' => null,
                        'filename'    => $filename,
                        'thumbnail'   => file_exists($thumbPath) ? $thumbFile : null,
                        'type'        => 'radar',
                        'fetched_at'  => date('c'),
                    ];

                    $stats['s1_downloaded']++;
                    $this->log("S1 OK    $date");
                } catch (RuntimeException $e) {
                    $stats['s1_errors'][] = "$date: " . $e->getMessage();
                    $this->log("S1 FEIL  $date  " . $e->getMessage());
                }
            }
        }

        usort($metadata, fn($a, $b) => strcmp($b['date'], $a['date']));
        $this->saveMetadata($metadata);

        $stats['deleted'] = $this->purgeOldImages();

        $s2sum = "S2 {$stats['downloaded']} ned / {$stats['skipped']} skip / " . count($stats['errors']) . " feil";
        $s1sum = "S1 {$stats['s1_downloaded']} ned / {$stats['s1_skipped']} skip / " . count($stats['s1_errors']) . " feil";
        $this->log("=== Ferdig: $s2sum | $s1sum | {$stats['deleted']} slettet ===");

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
                if (file_exists($path)) unlink($path);

                $thumb = $m['thumbnail'] ?? null;
                if ($thumb) {
                    $tp = $this->config['thumbs_dir'] . $thumb;
                    if (file_exists($tp)) unlink($tp);
                }

                $sensor = ($m['sensor'] ?? '') === 'S1' ? ' (S1)' : '';
                $this->log("SLETTET  {$m['date']}{$sensor}  (eldre enn {$keepDays} dager)");
            }
            $deleted++;
            return false;
        });

        if ($deleted > 0) {
            $this->saveMetadata(array_values($metadata));
        }

        return $deleted;
    }
}

// ── CLI ───────────────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli') {
    $config  = require __DIR__ . '/config.php';
    $fetcher = new SentinelFetcher($config);

    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--(\w+)=(.+)$/', $arg, $m)) $args[$m[1]] = $m[2];
    }
    $from = $args['from'] ?? null;
    $to   = $args['to']   ?? null;

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
