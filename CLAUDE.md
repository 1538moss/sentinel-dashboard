# Sentinel Satellite Dashboard

## Prosjektbeskrivelse
En webløsning som automatisk henter daglige satellittbilder fra Sentinel-2 (ESA), lagrer dem, og viser dem som et interaktivt fullskjerm-slideshow med datomerking, skydekke-info og tidslinje. I Pro-modus vises i tillegg Sentinel-1 SAR-radar og (bak et eget flagg) Landsat 8-9 optisk (USGS). Bak et eget flagg kan man også slå på et av/på-overlegg med landoverflatetemperatur fra Sentinel-3 (SLSTR LST), vist som et rutenett med fargede temperaturtall oppå det optiske bildet — og bak enda et flagg et tilsvarende, finere rutenett fra Landsat sin egen varmesensor (TIRS ST_B10) oppå Landsat-bildet. Bak nok et flagg finnes et kuldemengde-overlegg (MET Frost API): stedsbaserte etiketter med akkumulert sum av døgnmiddeltemperaturer under 0 °C gjennom vintersesongen — for å vurdere skøytbar is.

**GitHub:** https://github.com/1538moss/sentinel-dashboard  
**Produksjon:** https://kart.vansjo.top  
**Server:** `ps1@51.120.69.99` — `/var/www/sentinel/`

---

## Filer

| Fil | Rolle |
|-----|-------|
| `config.php` | Konfigurasjon — leser hemmeligheter fra `.sentinel.env` utenfor webroot |
| `fetch.php` | `SentinelFetcher`-klassen — katalogsøk, bildehenting, thumbnail-generering, opprydding. CLI: `php fetch.php [--from=YYYY-MM-DD --to=YYYY-MM-DD]` eller `php fetch.php --kuldemengde=YYYY-MM-DD` (kun kuldemengde-oppdatering) |
| `api.php` | REST-endepunkt: `list`, `fetch` (token-beskyttet), `status`, `next` |
| `index.php` | Frontend — fullskjerm slideshow, tidslinje, zoom/pan, filter, datoflash |
| `help.php` | Bruksanvisning (lenket fra `?`-knappen i headeren) |
| `mapbg.php` | Henter og cacher OpenStreetMap-bakgrunnskart for AOI |
| `overlay.php` | Serverer SVG-konturoverlayer for Vansjø (vises ved >50 % skydekke, skjules ved zoom) |
| `placeholder.php` | Ikke lenger i aktiv bruk — kart vises i stedet |
| `cleanup.php` | CLI-script: slett bilder og metadata for spesifikke datoer. `php cleanup.php YYYY-MM-DD ...` |
| `generate_thumbs.php` | CLI-script: generer thumbnails for eksisterende bilder (kjøres én gang etter deploy) |
| `env.example` | Mal for `.sentinel.env` |
| `.gitignore` | Ekskluderer `images/`, `data/`, `*.env` og editor-filer |
| `.htaccess` | Blokkerer `config.php`, `fetch.php`, `cleanup.php`, `generate_thumbs.php`, `.env`-filer og `/data/` |
| `scripts/deploy.sh` | rsync + Apache reload til produksjonsserver |
| `scripts/setup_ubuntu.sh` | Engangsoppsett av Ubuntu-server (Apache, PHP, vhost på port 8082) |
| `data/images.json` | Metadata for alle bilder (dato, skydekke, filnavn, thumbnail, type) |
| `data/kuldemengde.json` | Kuldemengde-serie per sted for inneværende sesong (skrives av fetch.php, leveres via `?action=list`) |
| `data/isvekst.json` | Eksperimentell isvekst-serie (energibalansemodell) for Lødengfjorden, 1.okt-31.des (skrives av fetch.php, leveres via `?action=list`) |
| `data/lake_overlay.svg` | SVG-kontur av Vansjø |
| `images/` | PNG-satellittbilder (`YYYY-MM-DD.png`) |
| `images/thumbs/` | JPEG-thumbnails 136×136px (`YYYY-MM-DD.jpg`) for tidslinje |
| `assets/fonts/` | Selv-hostede fonter (woff2 til frontend, `IBMPlexMono-Regular.ttf` til GD-rendring av LST-rutenettet i `fetch.php`) |

---

## Sikkerhet

### `.sentinel.env` (utenfor webroot)
Hemmeligheter lagres **aldri** i koden. `config.php` leser fra:
- Lokalt: `C:\xampp\htdocs\.sentinel.env`
- Server: `/var/www/.sentinel.env`

```ini
SH_CLIENT_ID=...
SH_CLIENT_SECRET=...
FETCH_TOKEN=...   # Generer: php -r "echo bin2hex(random_bytes(24));"
USGS_USERNAME=...      # EROS-brukernavn, for Landsat (M2M) — se https://ers.cr.usgs.gov/profile/access
USGS_M2M_TOKEN=...
CDSE_USERNAME=...      # Ekte CDSE-kontopassord (IKKE SH_CLIENT_ID/SECRET), for S3 LST-nedlasting — se avsnitt under
CDSE_PASSWORD=...      # Kontoen må ikke ha 2FA/TOTP slått på (kjøres uten tilsyn i cron). Bruk anførselstegn ved spesialtegn.
FROST_CLIENT_ID=...    # MET Frost API (kuldemengde) — gratis klient-ID fra https://frost.met.no/auth/requestCredentials.html
```

### Fetch-token
`?action=fetch` krever POST med `token=<FETCH_TOKEN>` i body — frontend sender det automatisk (rendret server-side via PHP i `index.php`). Tokenet er synlig i frontend-kildekoden, så den reelle beskyttelsen mot kvote-misbruk er rate-limiten: maks én web-utløst henting per 10. minutt (`data/fetch_last_run`). Cron via CLI berøres ikke.

---

## Konfigurasjon (`config.php`)

```php
'sh'              => [client_id, client_secret, token_url, catalog_url, process_url]
'fetch_token'     => env('FETCH_TOKEN')
'aoi'             => [west, east, south, north, name]   // WGS84
'render_mode'     => 'false_color'   // eller 'true_color'
'product'         => 'pro'           // 'std' = kun S2 optisk | 'pro' = S2 + S1 SAR-radar
'max_cloud_cover' => 100
'days_to_search'  => 7
'keep_days'       => 30              // bilder eldre enn dette slettes automatisk
'image_width'     => 1024
'image_height'    => 1024
'images_dir'      => __DIR__ . '/images/'
'thumbs_dir'      => __DIR__ . '/images/thumbs/'
'data_dir'        => __DIR__ . '/data/'
'metadata_file'   => __DIR__ . '/data/images.json'

'gdal'            => [gdalwarp_cmd, gdal_translate_cmd, gdal_calc_cmd, gdalbuildvrt_cmd]  // delt mellom Landsat- og S3-pipelinen
'usgs'            => [username, token, base_url, dataset]
'landsat_enabled' => true              // slår på Landsat-henting i fetch.php (krever gdal-bin/python3-gdal på serveren) — live i produksjon

'landsat_thermal_enabled' => false     // slår på TIRS ST_B10-overlegget (samme scene som Landsat-bildet) — verifisert lokalt, ikke rullet ut i produksjon ennå (se BACKLOG.md)
'landsat_thermal'         => [grid_cell_km, temp_min_c, temp_max_c, font_size_px]

'cdse_odata'      => [products_url, download_host, product_type, username, password]  // S3 LST-nedlasting (OData, IKKE Process API)
's3_lst_enabled'  => true              // slår på S3 LST-henting (krever gdal-bin med netCDF-driver — se eget avsnitt) — live i produksjon
's3_lst'          => [grid_cell_km, temp_min_c, temp_max_c, font_size_px]

'kuldemengde_enabled' => true          // slår på kuldemengde-henting (krever FROST_CLIENT_ID) — live i produksjon
'frost'           => [client_id, base_url, element, season_start, season_end, data_file, locations]
```

OAuth2-klient opprettes på: https://shapps.dataspace.copernicus.eu/dashboard/#/account/settings  
→ OAuth Clients → Create client → grant type: `client_credentials`

---

## API (`api.php`)

| Action | Beskrivelse |
|--------|-------------|
| `?action=list` | Alle bilder (kun de med fil på disk + kart-oppføringer) + `kuldemengde`-nøkkel med innholdet i `data/kuldemengde.json` (null når flagget er av) |
| `?action=fetch` | Henter nye bilder fra Copernicus — krever POST med `token` i body, rate-limitet til én kjøring per 10. min |
| `?action=status` | Antall ekte bilder, AOI-info, om credentials er satt, nyeste S2-bilde (S1/Landsat/S3 hoppes over) |
| `?action=next` | Sjekker om nyere bilde er tilgjengelig i katalogen, eller estimerer neste dato — svaret caches i 15 min (`data/next_cache.json`) |

---

## Dataflyt

1. `fetch.php` henter OAuth2-token fra CDSE
2. Katalogsøk finner tilgjengelige datoer med lavest skydekke per dag
3. Processing API returnerer rendret PNG
3b. Klokkeslett for satellittpasseringen (ikke nedlastingstidspunktet — det er `fetched_at`) stemples i nedre venstre hjørne av alle bilder (S2/S1/Landsat/S3), via delt `stampAcquisitionTime()`/`drawTimeLabel()` i `fetch.php` (samme papirfarget-boks-stil som brukes for LST-tallene). Lagres også som `acquired_at` i metadata. For Landsat krever dette `metadataType: 'full'` i M2M-søket — standardsvaret har kun midnatt, ikke ekte klokkeslett.
4. Bilde lagres i `images/YYYY-MM-DD.png`
5. Thumbnail (136×136 JPEG) genereres i `images/thumbs/YYYY-MM-DD.jpg`
6. Metadata oppdateres i `data/images.json`
7. Bilder eldre enn `keep_days` slettes automatisk
8. Dager uten satellittdata får `type: "map"` i metadata
9. Når `landsat_enabled` er `true`: samme flyt for Landsat 8-9 via USGS M2M (se eget avsnitt under), uavhengig av S2/S1 — en feilende M2M-kobling logges og hopper over Landsat for hele kjøringen, uten å påvirke S2/S1
9b. Når i tillegg `landsat_thermal_enabled` er `true`: rett etter et vellykket Landsat-bilde hentes samme scenes TIRS-bånd (ST_B10) og rutenett-overlegget genereres (se eget avsnitt under) — egen try/catch per dato, en feilende thermal-henting påvirker aldri det allerede lagrede Landsat-bildet
10. Når `s3_lst_enabled` er `true`: uavhengig pipeline for Sentinel-3 LST (se eget avsnitt under) — påvirker aldri S2/S1/Landsat om noe feiler
11. Når `kuldemengde_enabled` er `true`: uavhengig Frost-oppdatering av `data/kuldemengde.json` (se eget avsnitt under) — påvirker aldri bildepipelinene eller kuldemengde om noe feiler
12. Når `isvekst_enabled` er `true`: uavhengig, eksperimentell Frost-basert isvekst-beregning for Lødengfjorden (se eget avsnitt under) — påvirker aldri bildepipelinene eller kuldemengde om noe feiler

---

## Sentinel-1 (SAR-radar) — dekningsbasert scenevalg

SAR-data har ingen skydekke-prosent å rangere etter, så dedup i `searchDatesS1()` fungerer annerledes enn S2/Landsat: når flere scener (ulike baner/passeringer) finnes for samme dato, estimeres hvor stor andel av AOI-et hver scene faktisk dekker (`estimateAoiCoverage()` — punktteste et 5×5-rutenett over AOI-boksen mot scenens GeoJSON-footprint med ray-casting, ingen ekstern geometri-bibliotek nødvendig), og scenen med høyest dekning velges. Noen dager har rett og slett ikke en scene med full dekning tilgjengelig — det er normalt.

Dekningstallet lagres i metadata (`coverage`), så `_run()` sin skip-logikk sammenligner mot lagret verdi i stedet for bare å sjekke "finnes fil for denne datoen" — dukker det opp en scene med bedre dekning enn det som allerede er lagret (f.eks. en ny bane samme dag, eller retrospektivt for eldre entries som mangler `coverage`-feltet fra før denne funksjonen fantes), lastes den ned og erstatter den gamle. Idempotent: samme scene gir samme dekningstall neste kjøring, aldri "bedre enn seg selv".

---

## Render-modi

- `true_color` — B04/B03/B02, gain 3.5 — naturlig farge
- `false_color` — B08/B04/B03, gain 3.0 — vegetasjon rød, vann mørkt blått

Begge bruker `dataMask` som alpha-kanal (transparent der satellitten ikke har data).

---

## Landsat (USGS M2M) — bak `landsat_enabled`-flagget

Tredje PRO-panel, uavhengig kilde fra S2/S1. Bruker USGS sitt M2M API (`https://m2m.cr.usgs.gov/api/api/json/stable/`) mot datasettet `landsat_ot_c2_l2` (Landsat 8-9 Collection 2 Level-2).

I motsetning til Sentinel Hub har M2M **ingen** ferdig-rendret bilde-API — kun rå GeoTIFF SR-bånd. `fetchImageLandsat()` i `fetch.php` bygger derfor en full GDAL-pipeline (krever `gdal-bin`/`python3-gdal` — se `scripts/setup_ubuntu.sh`):

1. `download-options` → identifiser bånd (`SR_B5`/`SR_B4`/`SR_B3` for `false_color`, `SR_B4`/`SR_B3`/`SR_B2` for `true_color`, pluss `QA_PIXEL` for datamaske)
2. `download-request` per bånd (synkron i praksis, med polling-fallback via `download-retrieve`)
3. `gdalwarp` — reprojiser til EPSG:4326, beskjær til AOI, resample til `image_width`×`image_height` (bilinear for reflektans-bånd, nearest-neighbor for `QA_PIXEL` for å bevare bitmasken)
4. `gdal_translate -ot Byte -scale` — DN → reflektans (`DN*0.0000275-0.2`) → gain (3.0/3.5, matcher S2-panelet), lineært til 0-255
5. `gdal_calc.py` — alfamaske fra `QA_PIXEL` fill-bit (bit 0)
6. `gdalbuildvrt -separate` → RGBA VRT → `gdal_translate -of PNG` → endelig PNG
   (bevisst valgt fremfor `gdal_merge.py -separate`, som har en reprodusert bug der siste
   bånd i output nulles ut uansett hvilken fil som ligger sist — se `BACKLOG.md`)

Lagres som `images/YYYY-MM-DD-landsat.png` + thumbnail, metadata med `sensor: "LANDSAT"`, `type: "landsat"`. Attribusjon **"Credit: U.S. Geological Survey"** vises i panelet og i `help.php`, separat fra ESA/Copernicus-attribusjonen.

`gdal.gdalwarp_cmd`/`gdal_translate_cmd`/`gdal_calc_cmd`/`gdalbuildvrt_cmd` i `config.php` peker som standard på PATH-kommandoer (produksjon). For lokal Windows-testing overstyres disse med fulle stier til OSGeo4W (se kommentar i `config.php`).

---

## Landsat termisk (TIRS ST_B10) — bak `landsat_thermal_enabled`-flagget

Av/på-overlegg (ikke et eget panel) — samme idé som Sentinel-3 LST-overlegget, men for Landsat sin egen varmesensor (TIRS), vist oppå Landsat-bildet (Pro-panelet, eller Std-modus sin Landsat-fallback). Slått av som standard; styres med `🌡 Landsat`-knappen i headeren (vises kun når både `landsat_thermal_enabled` og `landsat_enabled` er på), tilstanden lagres ikke mellom sideinnlastinger.

I motsetning til Sentinel-3 er dette **ikke** et eget katalogsøk — `fetchImageLandsatThermal()` i `fetch.php` kjøres rett etter et vellykket `fetchImageLandsat()`-kall for samme `entityId` (samme scene, samme passering), og henter kun det ekstra båndet `ST_B10` (pluss `QA_PIXEL` på nytt, siden thermal-hentingen kjører i sin egen scratch-mappe/try-catch):

1. `download-options`/`download-request`/`download-retrieve` for `ST_B10`+`QA_PIXEL` — identisk mønster som i `fetchImageLandsat()`.
2. I motsetning til RGB-pipelinen, som warper til full `image_width`×`image_height` og komponerer en RGBA-PNG, warpes `ST_B10` (og `QA_PIXEL`) direkte til et **rutenett** (`landsat_thermal.grid_cell_km`, standard 1 km — finere enn S3 sine 2,5 km siden Landsat sin native oppløsning er 30 m mot SLSTR sine ~1 km) via ordinær `gdalwarp` (nearest-neighbor). Landsat er, i motsetning til Sentinel-3 SLSTR, **ikke** et swath-produkt, så ingen `GEOLOCATION`-VRT/`buildGeolocGridTif()` er nødvendig her — rutenett-dimensjonene beregnes med samme km→grader-formel som i `fetchImageS3LST()`.
3. Rutenettet eksporteres til XYZ og parses i PHP, samme teknikk som S3. DN → Celsius: Landsat Collection 2 Level-2 sin offisielle skala for `ST_B10`, `Kelvin = DN*0.00341802 + 149.0`.
4. Celler hoppes over ved `QA_PIXEL`-bit fill(0)/dilated cloud(1)/cloud(3)/cloud shadow(4) — cirrus(2) utelates bevisst (fortsatt meningsfullt tall), men skytopp-temperaturer ville vært villedende.
5. Tegnes med PHP GD, samme `lstColor()`-fargeskala og papirboks-stil som S3 (gjenbrukt direkte, ikke duplisert) — egne `temp_min_c`/`temp_max_c`/`font_size_px` i `landsat_thermal`-configen, men samme fargeverdier som `s3_lst` som standard for visuell konsistens mellom de to overleggene.

Lagres som `images/YYYY-MM-DD-landsattemp.png` + thumbnail, som `thermal_filename`/`thermal_thumbnail`-felt **på den eksisterende Landsat-metadataoppføringen** (ikke en egen `sensor`/`type`-entry slik S3 er modellert) — riktig datamodell her, siden dette alltid er nøyaktig samme scene/akkvisisjonstidspunkt som RGB-Landsat-bildet, ikke en uavhengig kilde som tilfeldigvis deler dato. Feltene er `null` når flagget er av eller thermal-hentingen feiler; RGB-Landsat-bildet lagres uansett. **Ingen retroaktiv backfill**: eksisterende Landsat-oppføringer fra før flagget ble slått på får ikke thermal-data automatisk (skip-logikken i `_run()` er filnavn-basert, ikke felt-basert) — kun nye Landsat-scener hentet etter aktivering får overlegget.

---

## Sentinel-3 SLSTR LST — bak `s3_lst_enabled`-flagget

Av/på-overlegg (ikke et eget panel) — et rutenett med fargede temperaturtall oppå det optiske S2-bildet, uavhengig av Std/Pro-modus. Slått av som standard; brukeren styrer det selv med `🌡 °C`-knappen i headeren (tilstanden lagres ikke mellom sideinnlastinger).

Sentinel-3 sitt ferdigprosesserte LST-produkt (`SL_2_LST___`) finnes **ikke** via Sentinel Hub Process API (kun rå L1B-stråling/lysstyrke-temperatur er tilgjengelig der) — det må søkes opp og lastes ned via CDSE sin generelle **OData Products-katalog** i stedet, og krever en **annen tokentype** enn Process API:

- **Søk**: `searchDatesS3()` bruker samme `getToken()` (client_credentials) som S2/S1 mot `catalogue.dataspace.copernicus.eu/odata/v1/Products`, med et romlig `OData.CSC.Intersects`-filter mot AOI-polygonet. Hver overflygning finnes typisk i to varianter — `_NR_` (Near Real Time) og `_NT_` (Non-Time-Critical, reprosessert et par dager senere med bedre kalibrering) — `_NT_` foretrekkes ved dedup.
- **Nedlasting**: krever `grant_type=password` (ekte CDSE-kontopassord, klient `cdse-public`) — client_credentials-tokenet blir avvist med `"Token audience not allowed"` av nedlastingsserveren (`zipper.dataspace.copernicus.eu`). Egen metode `getODataToken()` cacher dette separat. Range-requests (delvis nedlasting) er **ikke støttet** av serveren (bekreftet i praksis — returnerer alltid full HTTP 200, aldri 206), men produktet er kun ~70MB (ikke 1.7-1.9GB som gamle 2016-arkivprodukter i katalogen), så full nedlasting er uproblematisk.
- **Utpakking**: kun `LST_in.nc`/`geodetic_in.nc`/`flags_in.nc` pakkes ut fra zip-en (via `unzip -j`, ikke PHP sin `ZipArchive`-extension — samme filosofi som resten av pipelinen: skall ut til eksterne CLI-verktøy).
- **Reprojisering**: Sentinel-3 SLSTR er et **swath-produkt** (bredde-/lengdegrad ligger i en egen fil, `geodetic_in.nc`, ikke en enkel geotransform som Landsat). `buildGeolocGridTif()` bygger en VRT med `GEOLOCATION`-metadata-domene og kjører `gdalwarp -geoloc`. To fallgruver funnet i praksis:
  1. `latitude_in`/`longitude_in` er CF-pakket (`scale_factor=1e-6`) — GDALs GEOLOCATION-mekanisme leser råverdier UTEN å selv pakke ut scale/offset, så disse må først materialiseres til ekte gradverdier med `gdal_translate -unscale` — ellers feiler warpen fullstendig.
  2. PHP sin `escapeshellarg()` på Windows **fjerner** anførselstegn i stedet for å escape dem, som ødelegger `NETCDF:"fil":variabel`-syntaksen fullstendig. `netcdfArg()` bygger derfor argumentet manuelt på Windows (`PHP_OS_FAMILY === 'Windows'`).
- **Rutenett i stedet for kontinuerlig varmekart**: warpes til et rutenett på `grid_cell_km` (standard 2.5km — glissent nok til at tallene er lesbare; SLSTR sin native oppløsning er ~1km, men det ga for tett/smått rutenett i praksis), eksporteres til XYZ-tekstformat og parses direkte i PHP (ingen ekstra PHP-extension). Skydekte ruter (`confidence_in`-flagget, bit 16384 = `summary_cloud`) hopper over uten å tegne noe. Gjenværende ruter tegnes med PHP GD (`imagettftext`, font `assets/fonts/IBMPlexMono-Regular.ttf`) som fargede tall (avrundet °C), hver med en liten papirfarget halvtransparent bakgrunnsboks (`imagefilledrectangle`, samme farge som `.no-data-label`/`.coord`-etikettene i frontend) — nødvendig for lesbarhet, siden ren farget tekst kan bli ulesbar mot en lignende bakgrunnsfarge i selve satellittbildet (f.eks. rødt tall på rød vegetasjon i false_color). Fargen interpolert langs samme blå→grønn→oker→rød-skala som appens CSS-paletter, mellom `temp_min_c`/`temp_max_c`. Klokkeslettet for målingen (UTC, fra `acquired_at`) stemples i nedre venstre hjørne, siden rutenettet er et øyeblikksbilde, ikke et døgngjennomsnitt.

Lagres som `images/YYYY-MM-DD-s3lst.png` + thumbnail, metadata med `sensor: "S3"`, `type: "lst"`, `acquired_at`. Siden Sentinel-3 er del av Copernicus (samme som S2/S1), trengs ingen egen USGS-lignende kreditering. **Viktig presisering i `help.php`**: dette er bakke-/overflatetemperatur (Land Surface Temperature), ikke lufttemperatur i skygge slik f.eks. YR.no viser — kan avvike mye på solvarmede flater.

---

## Kuldemengde (MET Frost API) — bak `kuldemengde_enabled`-flagget

Av/på-overlegg med **stedsbaserte etiketter** (ikke rutenett) — kuldemengde er en løpende sum siden sesongstart (1. oktober), **regnet som positivt tall**: kalde døgn bygger opp (−4 °C-døgn bidrar +4), milde døgn tærer (+3 °C-døgn trekker fra 3), men summen **kan aldri gå under null** — en indikator for skøytbar is. Vansjø er for stort for én felles verdi, så hvert sted i `frost.locations` (navn, lat/lon, målestasjon, `km_needed` — Lødengfjorden, Vanemfjorden og Rosfjorden på FV120 Rødsund-stasjonen SN17400 (veiværstasjon ved Rødsundbrua, ~1 km unna — fanger kaldluftsdrenering ved innsjøen som Rygge ikke ser), Borgebunn, Amundbukta, Storefjorden og Vaskeberget på Rygge SN17150) får sin egen papirboks-etikett plassert på riktig geografisk punkt i bildet: `left% = (lon−west)/(east−west)`, `top% = (north−lat)/(north−south)` — fungerer fordi `.img-frame` krymper rundt det kvadratiske bildet. Etiketten (hvit gjennomsiktig bakgrunn, ingen ramme) har to linjer: stedsnavn, så «trengs/målt» (f.eks. «23/47,3») med **store tall i statusfargen** — **grønn** når kuldemengden har passert stedets skøytbar-is-terskel `km_needed`, **oransje** når det gjenstår ≤ 5 % av terskelen (`KM_WARN_FRACTION` i `index.php`), ellers **rød**. Klikk på etiketten åpner en **modal med sesonggraf** (`openKmChart()` i `index.php`): SVG-linjediagram med hele seriens forløp fra 1. oktober t.o.m. slidedatoen, stiplet rød terskellinje («trengs N»), månedlige x-akse-merker (ukentlige for serier ≤ 45 dager), krysshår + tooltip ved hover — lukkes med ×, klikk utenfor eller Escape.

- **Kilde**: `mean(air_temperature P1D)` fra `frost.met.no/observations/v0.jsonld`, HTTP Basic med klient-ID som brukernavn og tomt passord. `timeoffsets=PT0H` bes om eksplisitt (elementet finnes ofte også som PT6H-klimadøgn → duplikater); HTTP 412 → retry uten filter og dedup i PHP (PT0H foretrekkes). `qualityCode >= 6` forkastes.
- **Sesonglogikk** (`kmSeasonFor()`): okt–des → sesong som starter samme år, jan–mai → forrige år, jun–sep → utenfor sesong. Utenfor sesong skrives en tom `locations`-serie **uten** Frost-kall, og frontend skjuler ❄-knappen — den selvaktiveres etter første cron-kjøring på/etter 1. oktober.
- **Frost-lag**: døgnmiddel publiseres med ~1 døgns forsinkelse, så frontend bruker nyeste serieoppføring ≤ slidedatoen (verdien kan altså gjelde dagen før slidedatoen — vises ikke i etiketten). Indre datahull interpoleres (`fillFrostGaps()`): manglende døgn får medianen av nærmeste kjente døgn før og etter hullet, flagges `interpolated: true` i serien og listes i `interpolated_days`. Kant-hull (før første/etter siste kjente døgn — typisk publiseringsforsinkelsen) ekstrapoleres aldri: de bidrar 0 og listes i `missing_days`.
- **Idempotent**: `updateKuldemengde()` regenererer hele `data/kuldemengde.json` (atomisk temp+rename) per kjøring — ett Frost-kall per unik stasjon, uansett antall steder. Fanger også opp Frost sine retroaktive korreksjoner.
- **Temperaturvarsel** (`fetchForecastDailyMeans()`): MET locationforecast 2.0 (`/compact`, JSON) hentes per steds **egne** lat/lon (ikke stasjonens — brukerens valg), med identifiserende User-Agent fra `frost.user_agent` (MET-krav — derfor alltid server-side). Døgnmiddel = snitt av alle instant-temperaturer per Europe/Oslo-kalenderdag; dager med < 4 datapunkter forkastes (halepunkter). Projisert kuldemengde regnes videre fra siste **målte** km med samme formel, lagres per sted som `forecast: {dato: {mean, km}}` i kuldemengde.json. Hentes kun når dagens dato ligger i sesongen som genereres (juli-testing med `--kuldemengde=2026-02-01` blander ikke sommertemperaturer inn); varselfeil velter aldri den målte oppdateringen (egen try/catch per sted). I grafmodalen vises varselet som horisontal tabell under grafen («Varsel (yr.no)») med KM-tall (ikke temperatur) i samme trafikklys-koding som etikettene — delt `kmState()`-hjelper i `index.php`, men i papir-palettens farger (`--green/--ochre/--red`) siden etikettvariantene er lysnet for mørk bildebunn; varslet døgnmiddel som tooltip per celle. Tabellen viser alltid varselet «akkurat nå», uavhengig av slidedato.
- **CLI**: `php fetch.php --kuldemengde=YYYY-MM-DD` oppdaterer kun kuldemengde-filen — datoen styrer hvilken sesong som hentes (nyttig for testing om sommeren: `--kuldemengde=2026-02-01` henter vinteren 2025/2026).
- **Attribusjon**: «Værdata fra Meteorologisk institutt (Frost API), CC BY 4.0» i `help.php`. Viktig presisering der: dette er **lufttemperatur** 2 m over bakken (samme som YR) — i motsetning til LST-overlegget.

---

## Frontend (`index.php`)

| Funksjon | Detalj |
|----------|--------|
| Slideshow | Fullskjerm med fade-overgang |
| Zoom | 4× ved klikk/trykk på bilde |
| Pan | Dra med mus eller touch når zoomet inn |
| Datoflash | Dato + skydekke vises i 1 sek ved bildeskift |
| Tidslinje | Thumbnail-basert (JPEG) for lav båndbredde |
| Filter | `☁ <50%`-knapp skjuler skydekte dager og kart |
| Neste bilde | Klart-badge vises i header når nytt bilde er tilgjengelig (ingen estimert-dato lenger) |
| Landsat-fallback (Std) | I Std-modus (ikke Pro): mangler S2-bilde for en dato, men finnes Landsat-bilde for samme dato → vises Landsat-bildet i hovedvisningen i stedet for kart, merket «Landsat (erstatning)» + USGS-kreditering. Pro-modus er upåvirket (viser alltid egen Landsat-panel uavhengig av S2). |
| LST-overlegg | `🌡 °C`-knapp (kun synlig når `s3_lst_enabled`) slår av/på et rutenett med fargede temperaturtall (papirfarget bakgrunnsboks per tall + klokkeslett for målingen) oppå S2-bildet — fungerer i både Std- og Pro-modus (Pro-modus sitt "Optisk"-panel), tilstanden nullstilles ved sideinnlasting |
| Landsat-termisk-overlegg | `🌡 Landsat`-knapp (kun synlig når `landsat_thermal_enabled` og `landsat_enabled`) slår av/på et tilsvarende, finere rutenett fra Landsat sin TIRS-sensor — kun oppå Landsat-bildet (Pro-panelet eller Std-modus sin Landsat-fallback), kun de dagene et Landsat-bilde finnes, tilstanden nullstilles ved sideinnlasting |
| Kuldemengde-overlegg | `❄ Kulde`-knapp (rendres når `kuldemengde_enabled`, men vises av JS kun når sesongens serie har data) slår av/på stedsbaserte etiketter med akkumulert kuldemengde per slidedato — to linjer på hvit gjennomsiktig bakgrunn: «❄ Lødengfjorden» og «23/47,3» med store tall i statusfargen (grønn når terskelen `km_needed` er passert, oransje når ≤ 5 % av terskelen gjenstår, ellers rød) — plassert på stedets koordinat, skjules ved zoom, nullstilles ved sideinnlasting; klikk på etiketten åpner sesonggraf-modal (se kuldemengde-avsnittet) |
| Isvekst-graf | `🧊 Isvekst`-knapp (rendres når `isvekst_enabled`, vises av JS kun når serien har data) åpner en modal med en kumulativ mm-graf (1.okt-31.des) pluss en daglig vekst/smelte-strip — eksperimentell, kun Lødengfjorden i v1, se eget avsnitt |
| Mobil | Forenklet header under 640px |

### Tastaturnavigasjon

| Tast | Handling |
|------|---------|
| `←` / `→` | Nyere / eldre bilde |
| `Home` / `End` | Nyeste / eldste |
| `Escape` | Tilbakestill zoom |

---

## Deploy

```bash
bash scripts/deploy.sh
```

Ekskluderer: `images/`, `data/`, `scripts/`, `*.env`, `.git/`, `.claude/`

`deploy.sh` sjekker og installerer automatisk `gdal-bin`/`python3-gdal` på serveren hvis de mangler (kreves for Landsat-pipeline før `landsat_enabled` kan slås på).

**Før `s3_lst_enabled` slås på i produksjon**: bekreft at GDAL-installasjonen faktisk har netCDF-driveren (ikke gitt av `gdal-bin` alene, avhenger av hvordan pakken er bygget mot `libnetcdf`):
```bash
gdalinfo --formats | grep -i netcdf
```
Mangler den, må en GDAL-variant bygd med netCDF-støtte installeres før S3-pipelinen kan fungere.

### Etter første deploy (engangs)
```bash
sudo -u www-data php /var/www/sentinel/generate_thumbs.php
```

### Manuell bildeinnhenting
Kjør som `www-data` (samme bruker som cron), ellers blir nye bilde-/thumbnail-filer eid av `root` i stedet for `www-data` — funker for visning (world-readable), men er en unødvendig inkonsistens.
```bash
# Siste 7 dager (days_to_search)
sudo -u www-data php fetch.php

# Spesifikk periode
sudo -u www-data php fetch.php --from=2026-01-01 --to=2026-01-14

# Slett spesifikke datoer
sudo -u www-data php cleanup.php 2026-06-30 2026-07-01
```

---

## Cron (automatisk henting, hver 6. time)

Registrert i `www-data` sin crontab (`sudo crontab -u www-data -e`), slik at bilder/thumbnails opprettes med samme eierskap som Apache selv — se merknaden under manuell bildeinnhenting.

```
0 */6 * * * php /var/www/sentinel/fetch.php >/dev/null 2>>/var/www/sentinel/data/fetch.log
```

Stdout kastes fordi `log()` i `fetch.php` allerede skriver hver linje til `fetch.log` selv (echo + `>>` ville gitt doble linjer); stderr appendes fortsatt slik at PHP-fatals/warnings utenom `log()` havner i loggen.

Kjører kl. 00, 06, 12, 18. Trygt å kjøre oftere enn én gang daglig — `fetch.php` hopper over datoer som allerede er lastet ned (skip-liste basert på fil-på-disk), så hyppigere kjøring fanger bare opp nye S2/S1/Landsat/S3-scener raskere.

---

## Isvekst (energibalansemodell) — bak `isvekst_enabled`-flagget

Eksperimentell v1: et anslag på beregnet istykkelse (mm) for **kun Lødengfjorden**, **kun vinduet 1. oktober–31. desember** (ikke hele okt–mai-sesongen som kuldemengde) — bevisst begrenset omfang inntil resultatene er evaluert over en reell sesong. Formelen er en full energibalansemodell fra svensk isfartslitteratur (`.claude/skills/isprognosemodell_skill/isprognosemodell_skill.md`, *Islära*): varmetransport (W/m²) fra sol, utstråling, motstrålning, vind, varmeledning og avdunstning, konvertert til mm istilvekst/time via en empirisk konstant. Full utledning og datakilde-antakelser i `docs/superpowers/specs/2026-07-23-isvekst-lodeng-design.md`.

**Datakilder (alt fra Frost, samme mønster som kuldemengde):** lufttemperatur og duggpunkt (→ luftfuktighet via Magnus-formel) fra Lødengs egen stasjon SN17400, vind som døgnsnitt av rå 10-minutters observasjoner (SN17400 har intet ferdig døgnsnitt for vind), skydekke lånt fra **SN17150** (Rygge) siden SN17400 ikke måler det. Disse er bevisste tilnærmelser, ikke optimale — verdt å revurdere etter at modellen har kjørt en reell sesong.

**`updateIsvekst()`** i `fetch.php` regner ut og skriver hele 1.okt–31.des-vinduet på nytt ved hver kjøring (idempotent, billig siden alle inputs er døgnaggregater eller lette snitt), lagres i `data/isvekst.json`. Akkumulert istykkelse klemmes til aldri under 0, samme prinsipp som kuldemengde. CLI: `php fetch.php --isvekst=YYYY-MM-DD`.

**Ingen retroaktiv backfill utover det som allerede er implementert**: siden hele vinduet regnes på nytt hver gang (i motsetning til Landsat-termisk sin filnavn-baserte skip-logikk), får en sesong automatisk fullt historikk fra 1. oktober så snart flagget slås på og cron kjører — det er ikke noe eget backfill-steg å tenke på her.

---

## Infrastruktur

- **Apache** på port 8082 (`/etc/apache2/sites-available/sentinel.conf`)
- **Traefik** på `4.219.1.35` ruter `kart.vansjo.top → 51.120.69.99:8082`
- Traefik-konfig: `~/docker/traefik/config.yaml` på Traefik-serveren
