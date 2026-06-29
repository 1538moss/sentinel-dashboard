# Sentinel Satellite Dashboard

## Prosjektbeskrivelse
En webløsning som automatisk henter daglige satellittbilder fra Sentinel-2 (ESA), lagrer dem, og viser dem som et interaktivt fullskjerm-slideshow med datomerking, skydekke-info og tidslinje.

**GitHub:** https://github.com/1538moss/sentinel-dashboard  
**Produksjon:** https://kart.vansjo.top  
**Server:** `ps1@51.120.69.99` — `/var/www/sentinel/`

---

## Filer

| Fil | Rolle |
|-----|-------|
| `config.php` | Konfigurasjon — leser hemmeligheter fra `.sentinel.env` utenfor webroot |
| `fetch.php` | `SentinelFetcher`-klassen — katalogsøk, bildehenting, thumbnail-generering, opprydding. CLI: `php fetch.php [--from=YYYY-MM-DD --to=YYYY-MM-DD]` |
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
| `data/lake_overlay.svg` | SVG-kontur av Vansjø |
| `images/` | PNG-satellittbilder (`YYYY-MM-DD.png`) |
| `images/thumbs/` | JPEG-thumbnails 136×136px (`YYYY-MM-DD.jpg`) for tidslinje |

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
```

### Fetch-token
`?action=fetch` krever `?token=<FETCH_TOKEN>` — frontend sender det automatisk (rendret server-side via PHP i `index.php`).

---

## Konfigurasjon (`config.php`)

```php
'sh'              => [client_id, client_secret, token_url, catalog_url, process_url]
'fetch_token'     => env('FETCH_TOKEN')
'aoi'             => [west, east, south, north, name]   // WGS84
'render_mode'     => 'false_color'   // eller 'true_color'
'max_cloud_cover' => 100
'days_to_search'  => 14
'keep_days'       => 365             // bilder eldre enn dette slettes automatisk
'image_width'     => 1024
'image_height'    => 1024
'images_dir'      => __DIR__ . '/images/'
'thumbs_dir'      => __DIR__ . '/images/thumbs/'
'data_dir'        => __DIR__ . '/data/'
'metadata_file'   => __DIR__ . '/data/images.json'
```

OAuth2-klient opprettes på: https://shapps.dataspace.copernicus.eu/dashboard/#/account/settings  
→ OAuth Clients → Create client → grant type: `client_credentials`

---

## API (`api.php`)

| Action | Beskrivelse |
|--------|-------------|
| `?action=list` | Alle bilder (kun de med fil på disk + kart-oppføringer) |
| `?action=fetch&token=X` | Henter nye bilder fra Copernicus — krever fetch-token |
| `?action=status` | Antall bilder, AOI-info, om credentials er satt |
| `?action=next` | Sjekker om nyere bilde er tilgjengelig i katalogen, eller estimerer neste dato |

---

## Dataflyt

1. `fetch.php` henter OAuth2-token fra CDSE
2. Katalogsøk finner tilgjengelige datoer med lavest skydekke per dag
3. Processing API returnerer rendret PNG
4. Bilde lagres i `images/YYYY-MM-DD.png`
5. Thumbnail (136×136 JPEG) genereres i `images/thumbs/YYYY-MM-DD.jpg`
6. Metadata oppdateres i `data/images.json`
7. Bilder eldre enn `keep_days` slettes automatisk
8. Dager uten satellittdata får `type: "map"` i metadata

---

## Render-modi

- `true_color` — B04/B03/B02, gain 3.5 — naturlig farge
- `false_color` — B08/B04/B03, gain 3.0 — vegetasjon rød, vann mørkt blått

Begge bruker `dataMask` som alpha-kanal (transparent der satellitten ikke har data).

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
| Neste bilde | Estimert neste dato vises i header |
| Mobil | Forenklet header under 640px |

### Tastaturnavigasjon

| Tast | Handling |
|------|---------|
| `←` / `→` | Eldre / nyere bilde |
| `Home` / `End` | Nyeste / eldste |
| `Escape` | Tilbakestill zoom |

---

## Deploy

```bash
bash scripts/deploy.sh
```

Ekskluderer: `images/`, `data/`, `scripts/`, `*.env`, `.git/`, `.claude/`

### Etter første deploy (engangs)
```bash
sudo php /var/www/sentinel/generate_thumbs.php
```

### Manuell bildeinnhenting
```bash
# Siste 14 dager
sudo php fetch.php

# Spesifikk periode
sudo php fetch.php --from=2026-01-01 --to=2026-01-14

# Slett spesifikke datoer
sudo php cleanup.php 2026-06-30 2026-07-01
```

---

## Cron (automatisk henting, kl. 07)

```
0 7 * * * php /var/www/sentinel/fetch.php >> /var/www/sentinel/data/fetch.log 2>&1
```

---

## Infrastruktur

- **Apache** på port 8082 (`/etc/apache2/sites-available/sentinel.conf`)
- **Traefik** på `4.219.1.35` ruter `kart.vansjo.top → 51.120.69.99:8082`
- Traefik-konfig: `~/docker/traefik/config.yaml` på Traefik-serveren
