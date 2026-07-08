<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/fetch.php';
$config = require __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'list';

// Beskytt fetch-endepunktet: krever POST med token, maks én kjøring per 10. minutt.
// Tokenet er synlig i frontend-kildekoden, så rate-limiten er den reelle beskyttelsen
// mot kvote-misbruk. Cron bruker fetch.php via CLI og berøres ikke.
if ($action === 'fetch') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Bruk POST']);
        exit;
    }
    $expected = $config['fetch_token'] ?? '';
    $provided = $_POST['token'] ?? '';
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ikke tillatt']);
        exit;
    }

    if (!is_dir($config['data_dir'])) mkdir($config['data_dir'], 0755, true);
    $rateFile = $config['data_dir'] . 'fetch_last_run';
    $lastRun  = file_exists($rateFile) ? (int)file_get_contents($rateFile) : 0;
    $wait     = 600 - (time() - $lastRun);
    if ($wait > 0) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Nylig hentet — prøv igjen om ' . ceil($wait / 60) . ' min']);
        exit;
    }
    file_put_contents($rateFile, (string)time());
}

try {
    switch ($action) {

        case 'list':
            $fetcher  = new SentinelFetcher($config);
            $metadata = $fetcher->loadMetadata();
            $metadata = array_values(array_filter($metadata, function ($m) use ($config) {
                if (($m['type'] ?? '') === 'map') return true;
                return !empty($m['filename']) && file_exists($config['images_dir'] . $m['filename']);
            }));
            echo json_encode([
                'ok'     => true,
                'images' => $metadata,
                'aoi'    => $config['aoi']['name'],
                'count'  => count($metadata),
            ]);
            break;

        case 'fetch':
            set_time_limit(300);
            $fetcher = new SentinelFetcher($config);
            $stats   = $fetcher->run();
            if (($stats['downloaded'] ?? 0) > 0 || ($stats['s1_downloaded'] ?? 0) > 0 || ($stats['landsat_downloaded'] ?? 0) > 0 || ($stats['s3_downloaded'] ?? 0) > 0) {
                @unlink($config['data_dir'] . 'next_cache.json');
            }
            echo json_encode(['ok' => true, 'stats' => $stats]);
            break;

        case 'status':
            $file     = $config['metadata_file'];
            $metadata = file_exists($file)
                ? json_decode(file_get_contents($file), true) ?? []
                : [];
            // Tell kun ekte bilder (ikke kart-placeholders); latest = nyeste S2-bilde
            $real   = array_values(array_filter($metadata,
                fn($m) => ($m['type'] ?? '') !== 'map' && !empty($m['filename'])));
            $latest = null;
            foreach ($real as $m) {
                if (!in_array($m['sensor'] ?? '', ['S1', 'LANDSAT', 'S3'], true)) { $latest = $m; break; }
            }
            echo json_encode([
                'ok'          => true,
                'image_count' => count($real),
                'credentials' => !empty($config['sh']['client_id']),
                'aoi'         => $config['aoi'],
                'latest'      => $latest,
            ]);
            break;

        case 'next':
            // Cache svaret i 15 min — endepunktet er åpent, og katalogsøket
            // mot CDSE skal ikke kjøres på nytt for hver sidevisning
            $cacheFile = $config['data_dir'] . 'next_cache.json';
            if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 900) {
                echo file_get_contents($cacheFile);
                break;
            }

            $fetcher  = new SentinelFetcher($config);
            $metadata = $fetcher->loadMetadata();

            // Finn siste ekte S2-bilde (ikke kart, ikke S1-radar, ikke Landsat, ikke S3)
            $latestDate = null;
            foreach ($metadata as $m) {
                if (($m['type'] ?? '') === 'map') continue;
                if (in_array($m['sensor'] ?? '', ['S1', 'LANDSAT', 'S3'], true)) continue;
                if (!empty($m['filename'])) {
                    $latestDate = $m['date'];
                    break;
                }
            }

            if (!$latestDate) {
                echo json_encode(['ok' => true, 'status' => 'unknown']);
                break;
            }

            // Søk katalogen etter bilder nyere enn siste nedlastede
            $dayAfter = date('Y-m-d', strtotime($latestDate . ' +1 day'));
            $today    = date('Y-m-d');
            $available = ($dayAfter <= $today)
                ? $fetcher->searchDates($dayAfter, $today)
                : [];

            if (!empty($available)) {
                $json = json_encode([
                    'ok'          => true,
                    'status'      => 'available',
                    'date'        => $available[0]['date'],
                    'cloud_cover' => $available[0]['cloud_cover'],
                ]);
                file_put_contents($cacheFile, $json);
                echo $json;
                break;
            }

            $json = json_encode(['ok' => true, 'status' => 'unavailable', 'latest' => $latestDate]);
            file_put_contents($cacheFile, $json);
            echo $json;
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ukjent forespørsel']);
    }
} catch (Throwable $e) {
    error_log('[Sentinel] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'En feil oppstod på serveren']);
}
