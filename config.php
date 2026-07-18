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

    // ── GDAL-kommandoer (delt mellom Landsat- og S3 LST-pipelinen) ───────────
    // Standard antar gdalwarp/gdal_translate/gdal_calc.py/gdalbuildvrt på PATH
    // (produksjon: apt install gdal-bin python3-gdal — MERK: må ha netCDF-driver
    // for S3 LST, sjekk med `gdalinfo --formats | grep -i netcdf`). For lokal
    // Windows-testing, overstyr med fulle stier i en lokal config-override, f.eks.:
    //   'gdalwarp_cmd'       => '"C:/OSGeo4W/bin/gdalwarp.exe"',
    //   'gdal_translate_cmd' => '"C:/OSGeo4W/bin/gdal_translate.exe"',
    //   'gdal_calc_cmd'      => '"C:/OSGeo4W/apps/Python312/python.exe" "C:/OSGeo4W/apps/Python312/Scripts/gdal_calc.py"',
    //   'gdalbuildvrt_cmd'   => '"C:/OSGeo4W/bin/gdalbuildvrt.exe"',
    'gdal' => [
        'gdalwarp_cmd'       => 'gdalwarp',
        'gdal_translate_cmd' => 'gdal_translate',
        'gdal_calc_cmd'      => 'gdal_calc.py',
        'gdalbuildvrt_cmd'   => 'gdalbuildvrt',
    ],

    // ── USGS M2M (Landsat 8-9) — uavhengig av Sentinel Hub-blokken over ──────
    'usgs' => [
        'username' => $env['USGS_USERNAME']  ?? '',
        'token'    => $env['USGS_M2M_TOKEN'] ?? '',
        'base_url' => 'https://m2m.cr.usgs.gov/api/api/json/stable/',
        'dataset'  => 'landsat_ot_c2_l2',
    ],
    'landsat_enabled' => true,

    // ── Landsat termisk overlegg (TIRS ST_B10) — bak landsat_thermal_enabled ─
    // Samme USGS-scene som RGB-Landsat-bildet (samme entityId), så dette er en
    // ekstra bånd-henting på toppen av fetchImageLandsat(), ikke et eget
    // katalogsøk. Rutenett med fargede temperaturtall, samme visuelle stil og
    // lstColor()-fargeskala som s3_lst — men finere grid_cell_km siden Landsat
    // sin native oppløsning (30m) er langt bedre enn SLSTR sin (~1km). Krever
    // landsat_enabled === true. Se CLAUDE.md.
    'landsat_thermal_enabled' => false,  // verifisert lokalt — se BACKLOG.md; ikke rullet ut i produksjon ennå
    'landsat_thermal' => [
        'grid_cell_km' => 1.0,
        'temp_min_c'   => -20,
        'temp_max_c'   => 30,
        'font_size_px' => 16,
    ],

    // ── CDSE OData Products API (Sentinel-3 SL_2_LST-produktkatalog) ─────────
    // Nedlasting av selve produktet krever et annet tokenet enn Process API:
    // grant_type=password (ekte CDSE-brukernavn/passord) mot client_id=cdse-public,
    // IKKE client_credentials-klienten over. Se getODataToken() i fetch.php.
    'cdse_odata' => [
        'products_url'  => 'https://catalogue.dataspace.copernicus.eu/odata/v1/Products',
        'download_host' => 'https://zipper.dataspace.copernicus.eu/odata/v1/Products', // + "(<Id>)/$value"
        'product_type'  => 'SL_2_LST___',
        'username'      => $env['CDSE_USERNAME'] ?? '',
        'password'      => $env['CDSE_PASSWORD'] ?? '',
    ],

    // ── Sentinel-3 SLSTR L2 LST — bak s3_lst_enabled-flagget ─────────────────
    // Vises som et av/på-overlegg (rutenett med temperaturtall) oppå S2-bildet,
    // uavhengig av Std/Pro-modus. Se CLAUDE.md for hele pipelinen.
    's3_lst_enabled' => true,
    's3_lst' => [
        'grid_cell_km' => 2.5,   // ruteavstand — SLSTR sin naturlige oppløsning er ~1km,
                                  // men glisnere rutenett gir mer lesbare/større tall
        'temp_min_c'   => -20,   // fargeskala nedre grense (blå)
        'temp_max_c'   => 30,    // fargeskala øvre grense (rød)
        'font_size_px' => 16,
    ],

    // ── Kuldemengde (MET Norge Frost API) — bak kuldemengde_enabled-flagget ──
    // Sum av alle døgnmiddeltemperaturer under 0 °C siden sesongstart, per sted.
    // Vises som av/på-etiketter (❄-knapp) plassert på riktig punkt i bildet.
    // Vansjø er for stort for én felles verdi — hvert sted i locations får sin
    // egen etikett (og kan senere få sin egen målestasjon). Se CLAUDE.md.
    'kuldemengde_enabled' => true,    // krever FROST_CLIENT_ID i .sentinel.env — live i produksjon
    'frost' => [
        'client_id'    => $env['FROST_CLIENT_ID'] ?? '',
        'base_url'     => 'https://frost.met.no/observations/v0.jsonld',
        'element'      => 'mean(air_temperature P1D)',
        'season_start' => '10-01',    // MM-DD — kuldemengden nullstilles her
        'season_end'   => '05-31',    // MM-DD — jun–sep er utenfor sesong
        'data_file'    => __DIR__ . '/data/kuldemengde.json',
        // Temperaturvarsel (MET locationforecast) — projisert kuldemengde frem i
        // tid, vist som tabell under sesonggrafen. Hentes per steds lat/lon.
        // MET krever identifiserende User-Agent — derfor server-side, aldri fra nettleser.
        'forecast_url' => 'https://api.met.no/weatherapi/locationforecast/2.0/compact',
        'user_agent'   => 'sentinel-dashboard/1.0 https://kart.vansjo.top pal.svendsrud@gmail.com',
        // km_needed: kuldemengde (°C·døgn, positivt tall) som må til før isen
        // regnes som skøytbar på stedet — vises som «trengs/målt» i etiketten
        'locations'    => [
            ['name' => 'Lødengfjorden', 'lat' => 59.4857391, 'lon' => 10.7860853,
             'station' => 'SN17400', 'station_name' => 'FV120 Rødsund', 'km_needed' => 23],
            ['name' => 'Borgebunn', 'lat' => 59.3580611, 'lon' => 10.9224714,
             'station' => 'SN17150', 'station_name' => 'Rygge', 'km_needed' => 61],
            ['name' => 'Amundbukta', 'lat' => 59.3699087, 'lon' => 10.8408080,
             'station' => 'SN17150', 'station_name' => 'Rygge', 'km_needed' => 60],
            ['name' => 'Storefjorden', 'lat' => 59.3919000, 'lon' => 10.8544800,
             'station' => 'SN17150', 'station_name' => 'Rygge', 'km_needed' => 120],
            ['name' => 'Vaskeberget', 'lat' => 59.4021400, 'lon' => 10.7448200,
             'station' => 'SN17150', 'station_name' => 'Rygge', 'km_needed' => 35],
            ['name' => 'Vanemfjorden', 'lat' => 59.4404400, 'lon' => 10.7494300,
             'station' => 'SN17400', 'station_name' => 'FV120 Rødsund', 'km_needed' => 40],
            ['name' => 'Rosfjorden', 'lat' => 59.4436900, 'lon' => 10.8926300,
             'station' => 'SN17400', 'station_name' => 'FV120 Rødsund', 'km_needed' => 170],
        ],
    ],

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
    'days_to_search'  => 7,    // søk bakover N dager
    'keep_days'       => 30,   // slett bilder eldre enn N dager
    'image_width'     => 1024,
    'image_height'    => 1024,

    'images_dir'    => __DIR__ . '/images/',
    'thumbs_dir'    => __DIR__ . '/images/thumbs/',
    'data_dir'      => __DIR__ . '/data/',
    'metadata_file' => __DIR__ . '/data/images.json',
];
