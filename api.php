<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/fetch.php';
$config = require __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'list';

// Beskytt fetch-endepunktet med token
if ($action === 'fetch') {
    $expected = $config['fetch_token'] ?? '';
    $provided = $_GET['token'] ?? '';
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ikke tillatt']);
        exit;
    }
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
            set_time_limit(120);
            $fetcher = new SentinelFetcher($config);
            $stats   = $fetcher->run();
            echo json_encode(['ok' => true, 'stats' => $stats]);
            break;

        case 'status':
            $file     = $config['metadata_file'];
            $metadata = file_exists($file)
                ? json_decode(file_get_contents($file), true) ?? []
                : [];
            echo json_encode([
                'ok'          => true,
                'image_count' => count($metadata),
                'credentials' => !empty($config['sh']['client_id']),
                'aoi'         => $config['aoi'],
                'latest'      => $metadata[0] ?? null,
            ]);
            break;

        case 'next':
            $fetcher  = new SentinelFetcher($config);
            $metadata = $fetcher->loadMetadata();

            // Finn siste ekte S2-bilde (ikke kart, ikke S1-radar)
            $latestDate = null;
            $imageDates = [];
            foreach ($metadata as $m) {
                if (($m['type'] ?? '') === 'map') continue;
                if (($m['sensor'] ?? '') === 'S1') continue;
                if (!empty($m['filename'])) {
                    if ($latestDate === null) $latestDate = $m['date'];
                    $imageDates[] = $m['date'];
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
                echo json_encode([
                    'ok'          => true,
                    'status'      => 'available',
                    'date'        => $available[0]['date'],
                    'cloud_cover' => $available[0]['cloud_cover'],
                ]);
                break;
            }

            // Estimer neste dato basert på gjennomsnittlig intervall mellom bilder
            $estimated = null;
            if (count($imageDates) >= 2) {
                $intervals = [];
                for ($i = 0; $i < min(6, count($imageDates) - 1); $i++) {
                    $intervals[] = (strtotime($imageDates[$i]) - strtotime($imageDates[$i + 1])) / 86400;
                }
                $avgDays   = round(array_sum($intervals) / count($intervals));
                $estimated = date('Y-m-d', strtotime($latestDate . " +{$avgDays} days"));
            } else {
                $estimated = date('Y-m-d', strtotime($latestDate . ' +3 days'));
            }

            echo json_encode([
                'ok'        => true,
                'status'    => 'estimated',
                'latest'    => $latestDate,
                'estimated' => $estimated,
                'days_left' => max(0, (strtotime($estimated) - strtotime($today)) / 86400),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ukjent forespørsel']);
    }
} catch (RuntimeException $e) {
    error_log('[Sentinel] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'En feil oppstod på serveren']);
}
