# Backlog

## Landsat som tredje PRO-panel (BLOKKERT — venter på USGS-tilgang)

Legg til Landsat 8-9 (USGS M2M API) som en tredje bildekilde i PRO-modus, ved siden av S2 optisk og S1 radar (S2 | S1 | Landsat). Landsat-bilder skal ha en synlig, separat attribusjon: **"Credit: U.S. Geological Survey"**.

**Status:** Søknad om M2M-nedlastingstilgang sendt til USGS (konto `1538moss` har i dag kun `user`-rettighet, `download-options` gir HTTP 403). Venter på godkjenning fra USGS (`https://ers.cr.usgs.gov/profile/access`, "Machine-to-Machine (M2M)" access type) før arbeidet kan fortsette.

**Bekreftet så langt:**
- Login (`login-token`) og `scene-search` fungerer mot ekte credentials — 20 Landsat 8/9-scener funnet over Vansjø-AOI (2023–2026), både path/row 018 og 019.
- Offisiell USGS-attribusjonstekst bekreftet fra scene-metadata: *"Data available from the U.S. Geological Survey."*
- `download-options` blokkert (HTTP 403 — HTML-login-side, ikke JSON) inntil M2M-tilgang er godkjent.

**Nøkkelbeslutning (tatt):** Full GDAL-pipeline (last ned SR-bånd → `gdalwarp` reprojiser+beskjær til AOI → komponer RGB+alpha-PNG), ikke browse-JPEG-snarvei — gir et panel som er pikselvis sammenlignbart med S2/S1 (samme 1024×1024 AOI-ramme, stemmer med innsjø-overlay og graticule-koordinater). GDAL 3.12 er tilgjengelig lokalt via `C:/OSGeo4W/bin` for prototyping/testing før noe rulles ut til produksjonsserveren (som i dag kun har `php-gd`, ikke GDAL).

**Full implementasjonsplan:** se `C:\Users\p\.claude\plans\vast-beaming-shore.md` (lokal Claude Code-planfil, ikke i git) — detaljerte kodeendringer per fil er beskrevet der.

**Gjenstående steg:**
1. ~~Verifiser M2M-tilgang med standalone testscript~~ — ferdig (se over)
2. Last ned testbånd og valider GDAL-pipeline lokalt (blokkert — venter på USGS-godkjenning)
3. Legg til USGS-config og env-nøkler (`config.php`: `usgs`-block + `landsat_enabled` flagg, `env.example`)
4. Implementer Landsat-henting i `fetch.php` (`usgsLogin`/`usgsLogout`, `searchDatesLandsat`, `fetchImageLandsat` via GDAL, gated blokk i `_run()`)
5. Oppdater `api.php` for `LANDSAT`-sensor (utvid S1-skip-conditionals i `status`/`next`)
6. Bygg tredje PRO-panel i `index.php` (`landsatByDate`, `buildLandsatFrame`, L-badge, ny aksentfarge, USGS-attribusjon)
7. Oppdater `help.php`, `cleanup.php`, `scripts/setup_ubuntu.sh` (gdal-bin), `CLAUDE.md`
8. Verifiser end-to-end lokalt, slett `_test_m2m.php`, aktiver `landsat_enabled` i produksjon

**Testscript liggende klart (ikke committet):** `_test_m2m.php` i repo-roten — kjør på nytt med `php _test_m2m.php` så snart USGS godkjenner tilgangen, for å bekrefte at `download-options`/`download-request` faktisk returnerer bånddata.
