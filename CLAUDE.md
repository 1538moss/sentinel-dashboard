# Sentinel Satellite Dashboard - Daily Slideshow

## Prosjektbeskrivelse
En webløsning som automatisk henter daglige satellittbilder fra Sentinel-satellittene (ESA), lagrer dem, og viser dem som et interaktivt slideshow med datomerking og skydekke-informasjon.

---

## Kravspesifikasjon
- **Automatisk henting**: Hver dag hentes et nytt satellittbilde for et forhåndsdefinert geografisk område
- **Lagring**: Alle bilder lagres lokalt for historisk visning
- **Slideshow**: Brukeren kan bla fremover/bakover i tid
- **Datovisning**: Dato vises tydelig på hvert bilde
- **Skydekke**: Prosentvis skydekke vises sammen med datoen
- **Fallback**: Dager uten satellittbilde vises som kart-placeholder (OpenStreetMap)

---

## Systemarkitektur

### Filer

| Fil | Rolle |
|-----|-------|
| `config.php` | All konfigurasjon: OAuth2-klient, AOI, render-modus, stier |
| `fetch.php` | `SentinelFetcher`-klassen — katalogsøk, bildehenting, metadata. Kan kjøres som CLI: `php fetch.php` |
| `api.php` | REST-endepunkt for frontend: `?action=list`, `?action=fetch`, `?action=status` |
| `index.php` | Frontend — fullskjerm slideshow med tidslinje, zoom, tastaturnavigasjon |
| `mapbg.php` | Henter og cacher OpenStreetMap-bakgrunnskart for AOI-området |
| `placeholder.php` | Genererer PNG-placeholder med GD for dager uten satellittdata (brukes ikke lenger direkte — kart vises i stedet) |
| `.htaccess` | Blokkerer direkte tilgang til `config.php`, `fetch.php` og `/data/`-mappen |
| `data/images.json` | Metadata for alle bilder (dato, skydekke, filnavn, type) |
| `images/` | Lagrede PNG-satellittbilder, navngitt `YYYY-MM-DD.png` |
| `data/map_bg.png` | Cachet OpenStreetMap-bakgrunnskart |

### Dataflyt
1. `fetch.php` henter OAuth2-token fra CDSE (`identity.dataspace.copernicus.eu`)
2. Katalogsøk mot Sentinel Hub finner tilgjengelige datoer med skydekke-info
3. Processing API returnerer rendret PNG (true color eller false color)
4. Bilde lagres i `images/YYYY-MM-DD.png`, metadata i `data/images.json`
5. Dager uten satellittbilde får en kart-placeholder-oppføring (`type: "map"`) i metadata
6. `api.php?action=list` filtrerer bort oppføringer der bildefilen mangler på disk (kart-oppføringer sendes alltid gjennom)
7. `index.php` viser bilder som slideshow med tidslinje

---

## Konfigurasjon (`config.php`)

```php
'sh' => [
    'client_id'     => '...',   // OAuth2 client ID fra CDSE-dashbordet
    'client_secret' => '...',   // OAuth2 client secret
    'token_url'     => '...',
    'catalog_url'   => '...',
    'process_url'   => '...',
]
'aoi' => [
    'west', 'east', 'south', 'north',  // WGS84 bounding box
    'name'                              // Visningsnavn
]
'render_mode'     => 'false_color'  // 'true_color' eller 'false_color'
'max_cloud_cover' => 100            // 100 = hent alle uansett skydekke
'days_to_search'  => 14            // søk bakover N dager
'image_width'     => 1024
'image_height'    => 1024
```

OAuth2-klient opprettes på: https://shapps.dataspace.copernicus.eu/dashboard/#/account/settings  
→ OAuth Clients → Create client → grant type: `client_credentials`

---

## API-endepunkt (`api.php`)

| Action | Beskrivelse |
|--------|-------------|
| `?action=list` | Returnerer alle bilder fra metadata (kun de med eksisterende fil, pluss kart-oppføringer) |
| `?action=fetch` | Kjører `SentinelFetcher->run()` — henter nye bilder fra Copernicus |
| `?action=status` | Returnerer antall bilder, AOI-info og om credentials er satt |

---

## Kart-placeholder-logikk

Dager uten satellittdata får oppføring i `images.json` med `"type": "map"` og `"filename": null`.  
Frontend viser da en tom ramme med teksten "Ingen satellittbilde" over kartet i bakgrunnen.  
`api.php` sin `list`-filter sender alltid kart-oppføringer gjennom (sjekker `type === 'map'` eksplisitt).

---

## Render-modi

- `true_color` — B04/B03/B02, gain 3.5 — naturlig farge
- `false_color` — B08/B04/B03, gain 3.0 — vegetasjon rød, vann mørkt blått

Begge bruker `dataMask` som alpha-kanal (4. band) slik at områder uten satellittdekning blir transparente.

---

## Tastaturnavigasjon (frontend)

| Tast | Handling |
|------|---------|
| `←` | Eldre bilde |
| `→` | Nyere bilde |
| `Home` | Nyeste bilde |
| `End` | Eldste bilde |
| `Escape` | Tilbakestill zoom |

Klikk på bilde: zoom 2× inn på klikk-punkt. Klikk igjen: zoom ut.

---

## Cron (automatisk henting)

```
0 7 * * * php /path/to/fetch.php >> /path/to/fetch.log 2>&1
```
