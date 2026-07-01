<?php
$envFile = dirname(__DIR__) . '/.sentinel.env';
if (!file_exists($envFile)) {
    throw new RuntimeException(
        "Mangler .sentinel.env — kopier env.example til " .
        dirname(__DIR__) . DIRECTORY_SEPARATOR . ".sentinel.env og fyll inn verdier."
    );
}
$env = parse_ini_file($envFile);
if ($env === false) {
    throw new RuntimeException("Kunne ikke lese .sentinel.env — sjekk filrettigheter og format.");
}

return [
    // ── Sentinel Hub via CDSE (Processing API) ───────────────────────────────
    'sh' => [
        'client_id'     => $env['SH_CLIENT_ID']    ?? '',
        'client_secret' => $env['SH_CLIENT_SECRET'] ?? '',
        'token_url'     => 'https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token',
        'catalog_url'   => 'https://sh.dataspace.copernicus.eu/api/v1/catalog/1.0.0',
        'process_url'   => 'https://sh.dataspace.copernicus.eu/api/v1/process',
    ],

    // ── Hent-token (beskytter ?action=fetch mot misbruk) ─────────────────────
    'fetch_token' => $env['FETCH_TOKEN'] ?? '',

    // ── Geografisk område (WGS84) ────────────────────────────────────────────
    'aoi' => [
        'west'  => 10.60,
        'east'  => 11.00,
        'south' => 59.33,
        'north' => 59.55,
        'name'  => 'Vansjø, Moss',
    ],

    // 'true_color'  → B04/B03/B02  — naturlig farge
    // 'false_color' → B08/B04/B03  — vegetasjon rød, vann mørkt blått
    'render_mode'     => 'false_color',

    // 'std' = kun Sentinel-2 optisk  |  'pro' = S2 + S1 SAR-radar
    'product'         => 'pro',

    'max_cloud_cover' => 100,  // prosent (100 = hent alle uansett skydekke)
    'days_to_search'  => 14,   // søk bakover N dager
    'keep_days'       => 30,   // slett bilder eldre enn N dager
    'image_width'     => 1024,
    'image_height'    => 1024,

    'images_dir'    => __DIR__ . '/images/',
    'thumbs_dir'    => __DIR__ . '/images/thumbs/',
    'data_dir'      => __DIR__ . '/data/',
    'metadata_file' => __DIR__ . '/data/images.json',
];
