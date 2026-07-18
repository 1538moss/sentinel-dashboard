<?php
/**
 * Slett bilder og metadata for spesifikke datoer.
 * Bruk: php cleanup.php 2026-06-30 2026-07-01
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$dates = array_slice($argv, 1);

if (empty($dates)) {
    echo "Bruk: php cleanup.php YYYY-MM-DD [YYYY-MM-DD ...]\n";
    exit(1);
}

foreach ($dates as $d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        echo "Ugyldig datoformat: $d (bruk YYYY-MM-DD)\n";
        exit(1);
    }
}

$config = require __DIR__ . '/config.php';

// Slett bildefiler, thumbnails og S1-radarfiler
foreach ($dates as $d) {
    $targets = [
        $config['images_dir'] . $d . '.png',
        $config['images_dir'] . $d . '-s1.png',
        $config['images_dir'] . $d . '-landsat.png',
        $config['images_dir'] . $d . '-landsattemp.png',
        $config['images_dir'] . $d . '-s3lst.png',
        $config['thumbs_dir'] . $d . '.jpg',
        $config['thumbs_dir'] . $d . '-s1.jpg',
        $config['thumbs_dir'] . $d . '-landsat.jpg',
        $config['thumbs_dir'] . $d . '-landsattemp.jpg',
        $config['thumbs_dir'] . $d . '-s3lst.jpg',
    ];
    $found = false;
    foreach ($targets as $path) {
        if (file_exists($path)) {
            unlink($path);
            echo "  🗑  Slettet fil: " . basename($path) . "\n";
            $found = true;
        }
    }
    if (!$found) {
        echo "  –   Ingen filer: $d\n";
    }
}

// Oppdater metadata
$metadata = json_decode(file_get_contents($config['metadata_file']), true) ?? [];
$before   = count($metadata);
$metadata = array_values(array_filter($metadata, fn($m) => !in_array($m['date'], $dates)));
$removed  = $before - count($metadata);

file_put_contents(
    $config['metadata_file'],
    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\nFerdig. Fjernet $removed oppføring(er) fra metadata.\n";
