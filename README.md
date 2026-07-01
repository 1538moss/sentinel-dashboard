# Sentinel Dashboard

Interaktivt fullskjerm-dashboard som automatisk henter og viser daglige satellittbilder over **Vansjø, Moss** fra ESA Copernicus.

**Produksjon:** https://kart.vansjo.top

---

## Funksjoner

| Funksjon | Beskrivelse |
|----------|-------------|
| **Std-modus** | Fullskjerm slideshow med Sentinel-2 optiske bilder, tidslinje og datoflash |
| **Pro-modus** | Side-om-side visning av S2 optisk og S1 SAR-radar per dag |
| Fargetema | Cyan (Std) / Lilla (Pro) — visuell modus-indikator |
| Zoom / pan | 4× zoom ved klikk, dra for panorering, touch-støtte |
| Skydekke-filter | Skjul dager med >50 % skydekke |
| Tidslinje | JPEG-thumbnails (136×136) for lav båndbredde |
| Automatisk henting | Cron kl. 07 henter nye bilder fra Copernicus |
| Fetch-logg | Alle kjøringer logges til `data/fetch.log` med tidsstempel |

---

## Visningsmodi

### Std
Én fullskjerm-slide per dag med Sentinel-2 optisk bilde (falskt fargebilde eller naturlig farge).

### Pro
To paneler side om side:
- **Venstre** — Sentinel-2 optisk (S2)
- **Høyre** — Sentinel-1 SAR-radar (S1) med jet-fargeskala

Mobil (<640px): panelene stables vertikalt. Radar-etiketten vises nederst til venstre.

---

## Teknisk oversikt

```
config.php          Konfigurasjon — leser hemmeligheter fra .sentinel.env
fetch.php           SentinelFetcher-klassen — S2 + S1 henting, logging, opprydding
api.php             REST-endepunkt: list, fetch (token-beskyttet), status, next
index.php           Frontend — slideshow, pro-modus, zoom/pan, filter, tidslinje
help.php            Bruksanvisning
mapbg.php           Cacher OpenStreetMap-bakgrunnskart for AOI
overlay.php         SVG-kontur av Vansjø (vises ved >50 % skydekke)
cleanup.php         CLI: slett bilder for spesifikke datoer
generate_thumbs.php CLI: generer thumbnails for eksisterende bilder
data/fetch.log      Automatisk logg over alle hente-kjøringer
```

---

## Oppsett

### Krav
- PHP 8.1+ med GD-utvidelse
- Apache med mod_rewrite
- OAuth2-klient fra [CDSE-dashbordet](https://shapps.dataspace.copernicus.eu/dashboard/#/account/settings)

### Konfigurasjon

Kopier `env.example` og fyll inn:

```ini
# C:\xampp\htdocs\.sentinel.env  (lokalt)
# /var/www/.sentinel.env          (server)

SH_CLIENT_ID=...
SH_CLIENT_SECRET=...
FETCH_TOKEN=...   # php -r "echo bin2hex(random_bytes(24));"
```

### Deploy

```bash
bash scripts/deploy.sh
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

### Cron (automatisk, kl. 07)

```
0 7 * * * php /var/www/sentinel/fetch.php
```

---

## Render-modi (S2)

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
- `.htaccess` blokkerer `config.php`, `fetch.php`, `cleanup.php`, `.env`-filer og `/data/`

---

## Datakilde

Inneholder modifiserte Copernicus Sentinel-data (2024–).
European Space Agency (ESA) / [Copernicus Data Space Ecosystem](https://dataspace.copernicus.eu).
