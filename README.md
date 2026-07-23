# Sentinel Dashboard

Interaktivt fullskjerm-dashboard som automatisk henter og viser daglige satellittbilder over **Vansjø, Moss** — Sentinel-2 optisk, Sentinel-1 SAR-radar og Landsat 8-9 optisk (Pro-modus), pluss et sett bak-flagg-overlegg for landoverflatetemperatur og kuldemengde.

**Produksjon:** https://kart.vansjo.top

---

## Funksjoner

| Funksjon | Beskrivelse |
|----------|-------------|
| **Std-modus** | Fullskjerm slideshow med Sentinel-2 optiske bilder, tidslinje og datoflash |
| **Pro-modus** | Tre paneler side om side: S2 optisk, S1 SAR-radar, Landsat 8-9 optisk |
| Fargetema | Cyan (Std) / Lilla (Pro) — visuell modus-indikator |
| Zoom / pan | 4× zoom ved klikk, dra for panorering, touch-støtte |
| Skydekke-filter | Skjul dager med >50 % skydekke |
| LST-overlegg | Av/på-rutenett med landoverflatetemperatur (Sentinel-3 SLSTR) oppå S2-bildet — bak `s3_lst_enabled` |
| Landsat-termisk-overlegg | Tilsvarende, finere rutenett fra Landsat sin TIRS-sensor oppå Landsat-bildet — bak `landsat_thermal_enabled` |
| Kuldemengde-overlegg | Stedsbaserte etiketter med akkumulert kuldemengde (MET Frost API) for vurdering av skøytbar is, med sesonggraf ved klikk — bak `kuldemengde_enabled` |
| Isvekst-graf | Eksperimentelt anslag på beregnet istykkelse (mm) for Lødengfjorden via energibalansemodell, med kumulativ graf ved klikk — bak `isvekst_enabled`, kun 1.okt–31.des i v1 |
| Tidslinje | JPEG-thumbnails (136×136) med badges per sensor |
| Automatisk henting | Cron hver 6. time henter nye bilder fra Copernicus/USGS/MET |
| Fetch-logg | Alle kjøringer logges til `data/fetch.log` med tidsstempel og filnavn |

Detaljert teknisk dokumentasjon av alle overlegg og pipelines står i `CLAUDE.md`.

---

## Visningsmodi

### Std
Én fullskjerm-slide per dag med Sentinel-2 optisk bilde (falskt fargebilde eller naturlig farge). Mangler S2-bilde for en dato, men finnes Landsat-bilde for samme dato → Landsat vises i stedet for kart, merket «Landsat (erstatning)».

### Pro
Tre paneler side om side:
- **S2** — Sentinel-2 optisk
- **S1** — Sentinel-1 SAR-radar (jet-fargeskala)
- **Landsat** — Landsat 8-9 optisk (USGS), separat kreditering

Mobil (<640px): panelene stables vertikalt.

Thumbnails i tidslinjen viser badges når data finnes for datoen:
- `O` (cyan) — Sentinel-2 optisk
- `R` (lilla) — Sentinel-1 radar
- `L` (brent oransje) — Landsat
- `T` — Sentinel-3 LST

---

## Teknisk oversikt

```
config.php          Konfigurasjon — leser hemmeligheter fra .sentinel.env
fetch.php           SentinelFetcher-klassen — S2/S1/Landsat/S3/kuldemengde henting, logging, opprydding
api.php             REST-endepunkt: list, fetch (token-beskyttet), status, next
index.php           Frontend — slideshow, pro-modus, overlegg, zoom/pan, filter, tidslinje
help.php            Bruksanvisning
mapbg.php           Cacher OpenStreetMap-bakgrunnskart for AOI
overlay.php         SVG-kontur av Vansjø (vises ved >50 % skydekke)
cleanup.php         CLI: slett bilder og metadata for spesifikke datoer
generate_thumbs.php CLI: generer thumbnails for eksisterende bilder
BACKLOG.md          Idéer og pågående arbeid
data/fetch.log      Automatisk logg over alle hente-kjøringer
```

---

## Oppsett

### Krav
- PHP 8.1+ med GD-utvidelse
- Apache med mod_rewrite
- `gdal-bin`/`python3-gdal` med netCDF-driver (Landsat- og S3 LST-pipeline)
- OAuth2-klient fra [CDSE-dashbordet](https://shapps.dataspace.copernicus.eu/dashboard/#/account/settings)

### Konfigurasjon

Kopier `env.example` og fyll inn:

```ini
# C:\xampp\htdocs\.sentinel.env  (lokalt)
# /var/www/.sentinel.env          (server)

SH_CLIENT_ID=...
SH_CLIENT_SECRET=...
FETCH_TOKEN=...        # php -r "echo bin2hex(random_bytes(24));"
USGS_USERNAME=...      # for Landsat (M2M)
USGS_M2M_TOKEN=...
CDSE_USERNAME=...      # ekte CDSE-kontopassord, for S3 LST-nedlasting
CDSE_PASSWORD=...
FROST_CLIENT_ID=...    # MET Frost API, for kuldemengde
```

Se `CLAUDE.md` for full forklaring av hvert flagg i `config.php`.

### Deploy

```bash
bash scripts/deploy.sh
```

### Manuell bildeinnhenting

Kjør som `www-data` (samme bruker som cron), ellers blir nye bilde-/thumbnail-filer eid av `root`:

```bash
# Siste 7 dager (days_to_search)
sudo -u www-data php fetch.php

# Spesifikk periode
sudo -u www-data php fetch.php --from=2026-01-01 --to=2026-01-14

# Slett spesifikke datoer
sudo -u www-data php cleanup.php 2026-06-30 2026-07-01
```

### Cron (automatisk, hver 6. time)

```
0 */6 * * * php /var/www/sentinel/fetch.php >/dev/null 2>>/var/www/sentinel/data/fetch.log
```

Registrert i `www-data` sin crontab (`sudo crontab -u www-data -e`), slik at bilder/thumbnails opprettes med samme eierskap som Apache selv.

---

## Render-modi (S2 / Landsat)

| Modus | Bånd | Beskrivelse |
|-------|------|-------------|
| `false_color` | B08/B04/B03 | Vegetasjon rød, vann mørkt blått |
| `true_color` | B04/B03/B02 | Naturlig farge |

## Fargeskala (S1)

Sentinel-1 SAR-bilder vises med **jet-fargeskala** basert på VV-polarisering:
blå (lavt signal / vann) → cyan → grønn → gul → rød (høyt signal / bebyggelse).

---

## Infrastruktur

- **Apache** på port 8082 (`/etc/apache2/sites-available/sentinel.conf`)
- **Traefik** på `4.219.1.35` ruter `kart.vansjo.top → 51.120.69.99:8082`
- `.htaccess` blokkerer `config.php`, `fetch.php`, `cleanup.php`, `generate_thumbs.php`, `.env`-filer og `/data/`

---

## Datakilde

Inneholder modifiserte Copernicus Sentinel-data (2024–).
European Space Agency (ESA) / [Copernicus Data Space Ecosystem](https://dataspace.copernicus.eu).

Landsat 8-9-bilder: **Credit: U.S. Geological Survey**.
Værdata (kuldemengde): Meteorologisk institutt (Frost API), CC BY 4.0.
