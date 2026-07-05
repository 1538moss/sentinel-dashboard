<?php
/**
 * Generer thumbnails for alle eksisterende bilder.
 * Kjør én gang etter deploy: php generate_thumbs.php
 * Regenerer også eksisterende thumbnails (f.eks. etter en fiks i generateThumb()): php generate_thumbs.php --force
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$force = in_array('--force', $argv, true);

$config  = require __DIR__ . '/config.php';
$thumbsDir = $config['thumbs_dir'];
if (!is_dir($thumbsDir)) mkdir($thumbsDir, 0755, true);

require_once __DIR__ . '/fetch.php';
$fetcher = new SentinelFetcher($config);

$metadata = json_decode(file_get_contents($config['metadata_file']), true) ?? [];
$updated  = 0;
$skipped  = 0;

foreach ($metadata as &$m) {
    if (empty($m['filename'])) continue;

    $srcPath   = $config['images_dir'] . $m['filename'];
    $thumbFile = pathinfo($m['filename'], PATHINFO_FILENAME) . '.jpg';
    $thumbPath = $thumbsDir . $thumbFile;

    if (!file_exists($srcPath)) continue;

    if (!$force && file_exists($thumbPath)) {
        $m['thumbnail'] = $thumbFile;
        $skipped++;
        continue;
    }

    if ($fetcher->generateThumb($srcPath, $thumbPath)) {
        $m['thumbnail'] = $thumbFile;
        echo "  ✓ {$m['date']}\n";
        $updated++;
    } else {
        echo "  ✗ {$m['date']}  (feil ved generering)\n";
    }
}

file_put_contents(
    $config['metadata_file'],
    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\nFerdig. Generert: $updated  |  Allerede laget: $skipped\n";
