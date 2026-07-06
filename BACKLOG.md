# Backlog

## Landsat som tredje PRO-panel (implementert og verifisert — klar for prod-rollout)

Landsat 8-9 (USGS M2M API) som tredje bildekilde i PRO-modus, ved siden av S2 optisk og S1 radar (S2 | S1 | Landsat). Landsat-bilder har en synlig, separat attribusjon: **"Credit: U.S. Geological Survey"**.

**Status:** USGS godkjente Machine-to-Machine-tilgang 2026-07-06 (konto `1538moss`). All kode er implementert, bakend er verifisert ende-til-ende mot ekte data, og bruker har visuelt bekreftet at det tredje panelet vises korrekt i nettleser (2026-07-06). Testscriptene er slettet. Gjenstår kun: installer `gdal-bin`/`python3-gdal` på produksjonsserveren, og aktiver `landsat_enabled` i produksjons-config.

**Implementert:**
1. `config.php`: `usgs`-block (username, token, base_url, dataset, GDAL-kommando-overstyringer) + `landsat_enabled` flagg (default `false`). `env.example` oppdatert med `USGS_USERNAME`/`USGS_M2M_TOKEN`.
2. `fetch.php`: `usgsLogin`/`usgsLogout`/`usgsRequest`, `searchDatesLandsat` (scene-search + dedup laveste skydekke), `runGdal` (proc_open-wrapper), `fetchImageLandsat` (full GDAL-pipeline), gated blokk i `_run()` (kun når `landsat_enabled`), `purgeOldImages()` og S2-skip-listen utvidet for `LANDSAT`-sensor.
3. `api.php`: `status`/`next` hopper over `LANDSAT` samme som `S1`; `fetch` sin cache-invalidering sjekker `landsat_downloaded`.
4. `index.php`: `landsatByDate`, `buildLandsatFrame()` (speiler `buildS1Frame` + USGS-credit-element), tredje `.pro-panel` i `buildSlides()`, `tl-badge-l` i tidslinjen, `--landsat` aksentfarge (#B5651D, brent oransje), `.pro-label-landsat`/`.usgs-credit` CSS.
5. `help.php`, `cleanup.php` (`-landsat.png`/`.jpg`-targets), `scripts/setup_ubuntu.sh` (`gdal-bin python3-gdal`), `CLAUDE.md` — alle oppdatert.
6. `.gitignore`: `_test_*.php` lagt til slik at engangs-testscript aldri committes ved uhell.

**Kritisk bug funnet og fikset under ende-til-ende-verifisering:**
`gdal_merge.py -separate` har en reprodusert bug der **siste bånd i output blir nullet ut** (alpha=0 overalt), uansett hvilken fil som faktisk ligger sist i argumentlisten (bekreftet ved å bytte rekkefølge — det er alltid siste posisjon som rammes, ikke et bestemt bånd/fil). Dette gjorde at det første virkelige produksjonskjørte Landsat-bildet (2026-07-01, sky 12%) ble **helt transparent** — usynlig i nettleser, men usynlig-feilen ble ikke fanget opp av `_test_gdal_pipeline.php`s visuelle sjekk fordi det interne bildeviser-verktøyet som ble brukt til å inspisere PNG-en ignorerer alfakanalen og viser RGB uansett. Roten ble funnet ved å sjekke faktiske pikselverdier med `gdalinfo -stats`/Python-GDAL (band 4: min=0 max=0 mean=0) og bekreftet ved å reprodusere bugen isolert med identiske input-filer.

**Fiks:** Erstattet `gdal_merge.py -separate` med `gdalbuildvrt -separate` (kjerne-GDAL-verktøy, ikke python-utility-scriptet) → `gdal_translate` fra VRT til PNG. Verifisert korrekt: alpha nå `min=0 max=255 mean=242.5` (riktig variert maske, ikke konstant). `gdal_merge_cmd`-konfignøkkelen er fjernet, erstattet med `gdalbuildvrt_cmd` (default `gdalbuildvrt`, på PATH via `gdal-bin` i produksjon).

**Fullstendig verifisert ende-til-ende lokalt (`_test_landsat_e2e.php`, kaller `SentinelFetcher::runRange()` direkte med `landsat_enabled` og lokale Windows GDAL-stier overstyrt, uten å røre `config.php`):**
- `php fetch.php`-ekvivalent kjøring mot ekte USGS M2M + Sentinel Hub API i samme pass — `LANDSAT OK 2026-07-01 → 2026-07-01-landsat.png (1536 KB skydekke: 12%)`, S2/S1 upåvirket.
- Metadata-skjema stemmer nøyaktig med planen (`sensor:"LANDSAT"`, `type:"landsat"`).
- `?action=status`/`?action=next` hopper korrekt over LANDSAT-entryen.
- Bildet og thumbnailen serveres korrekt over HTTP (200, byte-for-byte match mot fil på disk).
- Etter fiksen: alle 4 bånd (R/G/B/alfa) har riktig variert data — visuelt sammenlignet mot `images/2026-07-04.png`: samme innsjøform/kystlinje/veigrid på samme sted, pluss korrekt gjennomsiktig hjørne der satellittsvaden ikke dekker AOI.

**Gjenstående steg:**
1. ~~Bruker bekrefter visuelt i nettleser~~ — bekreftet 2026-07-06 (tredje panel, USGS-credit, L-badge, brent oransje "Landsat"-tag vises korrekt)
2. ~~Slett testscript~~ — `_test_m2m.php`, `_test_gdal_pipeline.php`, `_test_landsat_e2e.php` fjernet
3. Installer `gdal-bin python3-gdal` på produksjonsserveren (`scripts/setup_ubuntu.sh`, kjøres manuelt via ssh)
4. Sett `landsat_enabled => true` i produksjons-config først etter punkt 3 er bekreftet
