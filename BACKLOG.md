# Backlog

## Isvekst (mm nydannet is siste døgn) — idé, ikke startet

Utvide kuldemengde-overlegget med et anslag på hvor mange **mm ny is** som ble dannet foregående døgn (i tillegg til/ved siden av dagens kuldemengde-sum), for hvert av `frost.locations`-stedene. Skal undersøkes 2026-07-23.

**Mulig datakilde: MET Norway API (`api.met.no`)** — trukket frem som enklest/mest stabile norske kilde for skyparametre, relevant fordi skydekke påvirker utstråling/isvekst om natten (klar himmel gir mer varmetap → raskere isvekst enn overskyet):
- `cloud_area_fraction` — totalt skydekke i %
- `cloud_area_fraction_low` / `_medium` / `_high` — skydekke per skylag
- `cloud_base_height` — skyhøyde
- `cloud_type` — skytype

**Ikke avklart ennå:** hvilken isvekst-formell som skal brukes (Stefan-type gradfrost-formel med kvadratrot av kuldemengden, ikke lineær — sjekket og forkastet en lineær variant 2026-07-23 — vs. noe som også vekter skydekke/vind), om `api.met.no` sitt skydekke skal kombineres med det eksisterende Frost-lufttemperaturgrunnlaget eller stå som egen kilde, og hvordan/om dette skal vises i UI (egen etikettlinje? eget tall i grafmodalen?). Bruker ser på en annen formel, kommer tilbake.

**Referanse — dagens `km_needed`-terskler per sted** (fra `data/kuldemengde.json`/`config.php` sin `frost.locations`, °C·døgn for skøytbar is):

| Sted | Stasjon | km_needed |
|------|---------|-----------|
| Lødengfjorden | SN17400 (FV120 Rødsund) | 23 |
| Vanemfjorden | SN17400 (FV120 Rødsund) | 40 |
| Rosfjorden | SN17400 (FV120 Rødsund) | 170 |
| Borgebunn | SN17150 (Rygge) | 61 |
| Amundbukta | SN17150 (Rygge) | 60 |
| Vaskeberget | SN17150 (Rygge) | 35 |
| Storefjorden | SN17150 (Rygge) | 120 |

(`data/kuldemengde.json` er tom utenfor sesong — ingen faktisk akkumulert serie å referere til før sesongen 2026/2027 starter 1. oktober.)

---

## Landsat termisk overlegg — TIRS ST_B10 (implementert, verifisert lokalt — klar for prod-rollout)

Rutenett med fargede temperaturtall fra Landsat sin egen varmesensor (TIRS), samme visuelle idé som Sentinel-3 LST-overlegget men vist oppå Landsat-bildet i stedet for S2, bak `landsat_thermal_enabled`-flagget (default `false`). Siden `ST_B10` kommer fra nøyaktig samme USGS-scene/`entityId` som RGB-Landsat-bildet (ikke et eget katalogsøk), lagres resultatet som `thermal_filename`/`thermal_thumbnail`-felt **på den eksisterende Landsat-metadataoppføringen**, ikke som en egen `sensor`/`type`-entry slik S3 er modellert.

**Status:** All kode implementert (`fetchImageLandsatThermal()` i `fetch.php`, konfig, frontend-knapp/overlegg, dokumentasjon). Kjørt ende-til-ende lokalt mot ekte USGS M2M-data 2026-07-18, med `landsat_thermal_enabled` midlertidig satt til `true` og umiddelbart tilbakestilt til `false` etter verifisering (aldri liggende på i config mellom økter). Gjenstår: bruker bekrefter visuelt i nettleser, deretter `landsat_thermal_enabled => true` i produksjons-config.

**Implementert:**
1. `config.php`: `landsat_thermal_enabled`-flagg + `landsat_thermal`-blokk (`grid_cell_km: 1.0` — finere enn S3 sine 2.5km siden Landsat sin native oppløsning er 30m mot SLSTR sine ~1km — `temp_min_c`/`temp_max_c`/`font_size_px`, samme fargebånd som `s3_lst` for visuell konsistens).
2. `fetch.php`: `fetchImageLandsatThermal()` — henter `ST_B10`+`QA_PIXEL` for samme `entityId` (egen `download-options`/`download-request`/`download-retrieve`-runde, siden thermal-hentingen kjører i sin egen scratch-mappe/try-catch), warper direkte til et rutenett via ordinær `gdalwarp` (ikke swath/GEOLOCATION-VRT som S3 — Landsat har vanlig geotransform), DN→Celsius via Landsat C2 L2 sin offisielle `ST_B10`-skala (`Kelvin = DN*0.00341802 + 149.0`), maskerer QA_PIXEL-bit fill/dilated-cloud/cloud/cloud-shadow, tegner med gjenbrukt `lstColor()` og samme papirboks-stil som S3. Kalt fra `_run()` rett etter et vellykket Landsat-bilde, egen try/catch (en feilende thermal-henting påvirker aldri det allerede lagrede RGB-bildet).
3. `index.php`: `🌡 Landsat`-knapp (gated på `landsat_thermal_enabled && landsat_enabled`), `.landsat-lst-overlay`-CSS/toggle, overlegg lagt til i `buildLandsatFrame()` (både Pro-panelet og Std-modus sin Landsat-fallback).
4. `cleanup.php`, `generate_thumbs.php`, `help.php`, `CLAUDE.md` — alle oppdatert.

**Design-avklaring med bruker før implementering:** valgte eksplisitt rutenett-med-tall (samme stil som S3 LST) fremfor et kontinuerlig fargekart, til tross for at Landsat sin 30m-oppløsning ville tillatt et mye mer detaljert varmekamera-aktig overlegg — for visuell konsistens med det eksisterende LST-overlegget og minst mulig ny kode (gjenbruker `lstColor()`/`imagettftext`/`imagefilledrectangle`-mønsteret direkte).

**Fullstendig verifisert ende-til-ende lokalt** (`php fetch.php --from=2026-07-02 --to=2026-07-18`, ekte USGS M2M-data, OSGeo4W GDAL-stier lagt til PATH kun for denne kjøringen — ingen endring i `config.php` sine `gdal`-kommandoer):
- 4 ekte Landsat-scener hentet (2026-07-02, -08, -09, -10, skydekke 16-83%) — `LANDSAT-TEMP OK` logget for **alle fire**, ingen feil.
- Genererte PNG-er verifisert: 1024×1024, truecolor, filstørrelse varierer fornuftig med skydekke (20KB ved 83% sky → 211KB ved 16% sky — færre rutenettceller tegnes når mer er skydekket, som forventet).
- Visuell inspeksjon av `2026-07-09-landsattemp.png` (16% skydekke): tett, lesbart rutenett av fargede tall (19-37°C, korrekt oransje/oker for sommertemperaturer), papirfargede bakgrunnsbokser, klokkeslett-etikett («12:24 UTC») i nedre venstre hjørne — visuelt konsistent med S3-overlegget, men merkbart finere rutenett som forventet av 1km-innstillingen.
- S2/S1/S3/kuldemengde-pipelinene kjørte upåvirket i samme kjøring (isolasjonen fungerer i praksis, ikke bare i teorien).

**Gjenstående steg:**
1. Bruker bekrefter visuelt i nettleser (Std + Pro)
2. Sett `landsat_thermal_enabled => true` i produksjons-config

---

## Klokkeslett for satellittpassering stemplet på alle bilder (implementert, verifisert)

Alle bilder (S2, S1, Landsat — S3 LST hadde dette fra før) stemples nå med opptakstidspunktet (UTC) i nedre venstre hjørne, samme papirfarget-boks-stil som LST-tallene. Presisert med brukeren først: dette er passeringstidspunktet (når satellitten faktisk tok bildet), ikke `fetched_at` (når fetch.php lastet det ned — kan være timer/dager senere).

**Implementert:**
- Delt `stampAcquisitionTime()`/`drawTimeLabel()`/`labelFont()` i `fetch.php` — laster ferdige PNG-bytes (fra Process API eller GDAL) med `imagecreatefromstring()`, tegner etiketten, returnerer nye PNG-bytes. S3 LST sin eksisterende inline-kode refaktorert til å bruke samme `drawTimeLabel()` (bygger bildet fra bunnen av med GD, så trenger ikke omveien om PNG-bytes).
- `searchDates()` (S2), `searchDatesS1()`, `searchDatesLandsat()` henter nå alle `acquired_at` (ISO8601) i tillegg til dato, lagres i metadata.
- `_run()` stempler tiden på bildet rett før lagring, for alle tre pipelines.

**Ikke-opplagt funn — Landsat mangler klokkeslett i standardsvaret:** USGS M2M sitt `scene-search` gir kun `temporalCoverage.startDate` = midnatt (`"2026-07-01 00:00:00"`) uten `metadataType: 'full'` i requesten. Med det flagget satt dukker et ekte `Start Time`-felt opp i scenens `metadata`-array (f.eks. `"2026-07-01 10:24:31"`), som konverteres til ISO8601 (`str_replace(' ', 'T', ...) . 'Z'` — Landsat-opptakstider er UTC).

**Verifisert:** Kjørt mot ekte data for alle tre sensortyper (1.-8. juli 2026) — regenererte eksisterende bilder viser korrekt klokkeslett (f.eks. S2 `12:54 UTC`, S1 `07:30 UTC`, Landsat `12:24 UTC`), stilen matcher LST-overleggets tidsstempel.

---

## Sentinel-1: velg scene med best AOI-dekning i stedet for første treff (implementert, verifisert)

`searchDatesS1()` valgte tidligere bare den FØRSTE S1-scenen som dukket opp i katalogsøket for en gitt dato — uten å sjekke om scenen faktisk dekket hele Vansjø-AOI-et. Siden SAR-scener (ulike baner/passeringer) ikke alltid har identisk footprint, kunne dette gi bilder med transparente hull der satellittsvaden bare delvis krysset AOI-et, selv om en annen scene samme dag hadde full dekning.

**Løsning:** Ny `estimateAoiCoverage()`/`pointInGeometry()`/`pointInRing()` — punktteste et 5×5-rutenett over AOI-boksen mot hver scenes GeoJSON-footprint (ray-casting, ren PHP, ingen GEOS/geometri-bibliotek nødvendig). `searchDatesS1()` velger nå scenen med høyest dekning per dato i stedet for første treff. Dekningstallet (`coverage`, 0.0-1.0) lagres i metadata, og `_run()` sin S1-skip-logikk sammenligner mot lagret verdi — dukker en bedre-dekkende scene opp senere (eller for eldre entries som mangler feltet fra før), lastes den ned og erstatter den gamle automatisk.

**Verifisert i praksis:** Kjørt mot ekte data (1.-8. juli 2026) — fant reelle dekningsforskjeller (64-100%, to datoer manglet faktisk full dekning uansett hvilken scene som ble valgt — det er en reell begrensning i tilgjengelig data, ikke en bug). Alle 4 eksisterende S1-bilder for perioden ble automatisk oppgradert ved første kjøring (manglet `coverage`-felt fra før), og en umiddelbar re-kjøring bekreftet idempotens (0 nedlastet, alle 4 hoppet over med korrekt lagret dekningsprosent — ingen unødvendig gjentatt nedlasting).

---

## Sentinel-3 SLSTR LST-overlegg (implementert, verifisert, live i produksjon)

Landoverflatetemperatur fra Sentinel-3 som et av/på-overlegg (rutenett med fargede temperaturtall) oppå S2-bildet, bak `s3_lst_enabled`-flagget. Ikke et eget Pro-panel som Landsat — brukerens eksplisitte ønske var et overlegg direkte på det optiske bildet, med tallverdier per rute (glissent nok til å være lesbare — se justeringer under) i stedet for et kontinuerlig fargekart.

**Status:** Ferdig utrullet 2026-07-08. Kode implementert, kjørt ende-til-ende mot ekte CDSE-data lokalt, bruker har bekreftet visuelt i nettleser (Std + Pro), deployet til produksjon, `CDSE_USERNAME`/`CDSE_PASSWORD` lagt til i `/var/www/.sentinel.env`, netCDF-driver bekreftet på produksjonsserveren, og `s3_lst_enabled => true` kjører der nå.

**Justeringer etter første visuelle test:**
- Rutenettet var i utgangspunktet for tett (~1km/rute, matchet SLSTR sin native oppløsning) og tallene for små til å lese — økt til `grid_cell_km: 2.5` og `font_size_px: 16`.
- Fargede tall direkte på det optiske bildet var ulesbare der fargen traff en lignende bakgrunnsfarge (f.eks. rødt tall på rød vegetasjon i false_color). Prøvde først en mørk halo/kontur rundt tallene (kartografi-triks), men brukeren ba om en bakgrunnsboks i stedet — endret til en papirfarget halvtransparent boks bak hvert tall (samme stil som `.no-data-label`/`.coord`-etikettene), som løste det helt.
- Lagt til klokkeslett for målingen (UTC) i nedre venstre hjørne av kartet, siden rutenettet er et øyeblikksbilde og ikke et døgngjennomsnitt.
- Knappeteksten endret to ganger etter tilbakemelding: `🌡 LST` → `🌡 TMP` → `🌡 °C` (endte opp med å matche stilen til `☁ <50%`-knappen: symbol + kort verdi, ikke et ord).
- `help.php` fikk en presisering om at dette er bakke-/overflatetemperatur (Land Surface Temperature), ikke lufttemperatur i skygge slik f.eks. YR.no viser — kan avvike mye på solvarmede flater.
- Et cache-relatert forvirringsmoment underveis: `.htaccess` sin `immutable`-cache (lagt til tidligere samme dag, se eget avsnitt lenger ned) gjorde at regenererte bilder med samme filnavn ikke ble hentet på nytt av nettleseren. Løst permanent med et `?t=<fetched_at>`-query-param i alle bilde-URL-er (`versioned()`-hjelpefunksjon i `index.php`), ikke bare en engangs hard-refresh.

**Implementert:**
1. `config.php`: GDAL-kommandoene hoistet ut av `usgs`-blokken til en ny delt `gdal`-blokk (brukes nå av både Landsat- og S3-pipelinen). Ny `cdse_odata`-blokk (products_url, download_host, product_type, username/password fra env). Ny `s3_lst_enabled`-flagg + `s3_lst`-blokk (grid_cell_km, temp_min_c, temp_max_c, font_size_px). `env.example` oppdatert med `CDSE_USERNAME`/`CDSE_PASSWORD`.
2. `fetch.php`: `getODataToken()` (password-grant, cachet separat fra Process API-tokenet), `searchDatesS3()` (OData Products-søk, dedup med `_NT_` foretrukket over `_NR_`), `netcdfArg()` (plattform-trygg NETCDF-argument-bygging), `buildGeolocGridTif()` (GEOLOCATION-VRT + gdalwarp -geoloc), `lstColor()` (4-trinns fargeinterpolasjon), `fetchImageS3LST()` (full pipeline: nedlasting → unzip → unscale lat/lon → geoloc-warp → XYZ-eksport → PHP GD-rendring av tallrutenett), `rrmdir()` (rekursiv scratch-opprydding), gated blokk i `_run()`, `purgeStaleS3Scratch()`.
3. `api.php`: `status`/`next` hopper over `S3` samme som `S1`/`LANDSAT`; `fetch` sin cache-invalidering sjekker `s3_downloaded`.
4. `index.php`: `S3_LST_ENABLED`-konstant, `s3ByDate`-map, `.lst-overlay` (speiler `.lake-overlay`-mønsteret), `🌡 °C`-knapp (av/på, IKKE persistert i localStorage — nullstilles ved sideinnlasting, i motsetning til `proMode`), `toggleLstOverlay()`, `tl-badge-t` i tidslinjen (vises i BÅDE Std- og Pro-modus, i motsetning til O/R/L som er Pro-only), `--thermal`-aksentfarge (#C1440E), `versioned()` cache-busting-hjelper for alle bilde-URL-er.
5. `help.php`, `cleanup.php` (`-s3lst.png`/`.jpg`-targets), `CLAUDE.md` — alle oppdatert med eget avsnitt.
6. Font: `assets/fonts/IBMPlexMono-Regular.ttf` lastet ned (ekte TrueType, ikke woff2) for GD sin `imagettftext()`.

**Fire ikke-opplagte problemer funnet og fikset under spike/implementering:**

1. **Nedlasting krever en helt annen tokentype enn antatt.** Første antakelse var at samme `client_credentials`-token som Process API bruker ville fungere mot OData `$value`-nedlastingsendepunktet også (samme CDSE-identity-server). Faktisk resultat: `zipper.dataspace.copernicus.eu` avviser dette tokenet med `{"code":"DAT-ZIP-609","message":"Token audience not allowed"}`. Reell løsning (bekreftet via CDSE-dokumentasjon): nedlasting krever `grant_type=password` med et ekte CDSE-kontopassord mot den offentlige klienten `cdse-public` — en helt annen credential-type enn OAuth-klienten (client_id/secret), og kontoen kan ikke ha 2FA/TOTP siden dette kjøres uten tilsyn i cron.

2. **Range-requests støttes ikke, men det er uproblematisk.** Planen var å optimalisere med HTTP range-requests for å unngå å laste ned hele produktet (fryktet 1.7-1.9GB per fil, basert på gamle 2016-arkivprodukter i katalogen). I praksis: `zipper`-serveren ignorerer `Range`-header fullstendig og returnerer alltid full HTTP 200 (aldri 206) — men det aktuelle produktet var kun ~70MB, så full nedlasting er helt uproblematisk. Kompleks zip-central-directory-range-parsing ble droppet til fordel for enkel full nedlasting + `unzip -j`.

3. **`latitude_in`/`longitude_in` må "unscales" før GEOLOCATION-VRT fungerer.** Disse variablene er CF-pakket (`scale_factor=1e-6`, rå verdier som `56423711` i stedet for `56.42`). GDALs `-geoloc`-mekanisme leser rå pikselverdier fra GEOLOCATION-domenets `X_DATASET`/`Y_DATASET` UTEN selv å pakke ut scale/offset — med rå verdier feiler `gdalwarp -geoloc` fullstendig med `"Too many points failed to transform, unable to compute output bounds"` (fordi de tolkes som groteskt ugyldige gradverdier). Fiks: `gdal_translate -unscale -ot Float64` på lat/lon FØR de refereres i GEOLOCATION-VRT-en.

4. **PHP sin `escapeshellarg()` på Windows ødelegger `NETCDF:"fil":variabel`-syntaksen.** Windows-implementasjonen fjerner anførselstegn inni strengen i stedet for å escape dem (`NETCDF:"fil":LST` → `NETCDF: fil :LST` — ugyldig for GDAL). Bekreftet at dette er Windows-spesifikt (Linux sin `escapeshellarg()` bruker enkle anførselstegn og bevarer doble anførselstegn intakt). Fiks: `netcdfArg()` bygger argumentet manuelt på Windows (`"NETCDF:\"fil\":var"` — backslash-escapede anførselstegn, korrekt for `CommandLineToArgvW`-parsing), verifisert direkte mot ekte `gdal_translate.exe` før det ble bygget inn i pipelinen.

Mindre triviell feil underveis: `unzip` krever at alle flagg (`-j` for flat utpakking) står FØR zip-filnavnet i argumentlisten — plassert etter ga `caution: filename not matched: -j` siden `-j` da tolkes som et filnavnmønster.

**Fullstendig verifisert ende-til-ende lokalt** (ad-hoc PHP-scripts som kalte `SentinelFetcher`-metodene direkte med `s3_lst_enabled` og lokale OSGeo4W GDAL-stier overstyrt, deretter slettet — ingen `_test_*.php` liggende igjen):
- Ekte OData-søk fant 20 SL_2_LST-produkter for Vansjø-AOI-et i løpet av 3 dager (S3A+S3B kombinert).
- Full pipeline kjørt mot et ekte produkt (2026-06-10): 53545 byte PNG med et realistisk temperaturrutenett (17-26°C, riktig fargekodet blå→grønn→oker→rød), skydekte områder korrekt utelatt (visuelt bekreftet at et annet testprodukt fra 2026-06-01 korrekt ga null tegnede ruter fordi HELE scenen var skydekket over land — ikke en bug, `confidence_in`-flagget stemte med rådata).
- `gdalinfo`-inspeksjon bekreftet eksakte variabelnavn (`LST_in.nc:LST`, `geodetic_in.nc:latitude_in/longitude_in`, `flags_in.nc:confidence_in` med `flag_meanings` inkl. `summary_cloud` = bit 16384).

**Gjenstående steg:**
1. ~~Bruker bekrefter visuelt i nettleser~~ — bekreftet, inkl. justeringene over
2. ~~Sett `s3_lst_enabled => true` i produksjons-config og `CDSE_USERNAME`/`CDSE_PASSWORD` i `/var/www/.sentinel.env`~~ — gjort, kjører i produksjon
3. ~~Bekreft at produksjonsserverens GDAL har netCDF-driveren~~ — bekreftet OK (`gdalinfo --formats | grep -i netcdf`)

---

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
3. ~~Installer gdal-bin/python3-gdal på produksjonsserveren~~ — `scripts/deploy.sh` sjekker og installerer nå automatisk ved hver deploy
4. ~~Sett `landsat_enabled => true` i produksjons-config~~ — gjort, kjører i produksjon
