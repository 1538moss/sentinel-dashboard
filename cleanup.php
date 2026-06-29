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

// Slett bildefiler
foreach ($dates as $d) {
    $path = $config['images_dir'] . $d . '.png';
    if (file_exists($path)) {
        unlink($path);
        echo "  🗑  Slettet fil: $d.png\n";
    } else {
        echo "  –   Ingen fil:  $d.png\n";
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
