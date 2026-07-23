# Isvekst (Lødengfjorden) — energibalansemodell, spike → v1

**Status:** Spec — validert mot ekte historiske data (se «Spike-verifisering»), ikke implementert.

## Bakgrunn

`BACKLOG.md` hadde lenge en åpen idé om et isvekst-anslag (mm ny is per døgn) som
supplement til det eksisterende kuldemengde-overlegget. Formelspørsmålet stod
uavklart mellom en enkel gradfrost-tilnærming og noe som også vekter skydekke/vind.
`.claude/skills/isprognosemodell_skill/isprognosemodell_skill.md` (lagt til av
brukeren 2026-07-23) avklarer dette: en fullstendig energibalansemodell fra svensk
isfartslitteratur (*Islära*), som regner ut varmetransport (W/m²) fra sol,
utstråling, motstråling, vind, varmeledning og avdunstning, og konverterer til
istilvekst (mm/time) via en empirisk konstant.

Dette dokumentet spesifiserer en **første, eksperimentell versjon** — kun for
**Lødengfjorden**, kun vinduet **1. oktober–31. desember**, bak et eget flagg
(default av). Målet er å se om modellen gir troverdige resultater i praksis før
den eventuelt utvides til flere steder og hele sesongen (okt–mai, som
kuldemengde).

## Spike-verifisering

Kjørt lokalt mot ekte Frost-data for 1.okt–31.des **2025** (helt i fortiden, så
hele vinduet har reelle observasjoner) — se samtalehistorikk, ikke en del av
repoet. Resultat: ~18 cm akkumulert is ved nyttår, med et troverdig
fryse/tine-sagtann-mønster (kraftig oppbygning i midten av november, smeltet
tilbake til 0 i en mildvær-periode sent i november/tidlig desember, bygget opp
igjen gjennom desember) — mønsteret er ikke hardkodet, det faller ut av de
faktiske værdataene. Bruker har visuelt godkjent resultatet.

## Datakilder og antakelser

Lødengfjordens egen målestasjon (**SN17400**, FV120 Rødsund) mangler flere
elementer modellen trenger. Løsningene under er bevisste tilnærminger — ikke
optimale, men praktiske, og verdt å revurdere når vi «ser hvordan det går»:

| Input | Frost-element | Stasjon | Merknad |
|---|---|---|---|
| Lufttemperatur | `mean(air_temperature P1D)` | SN17400 | Samme som kuldemengde bruker allerede |
| Luftfuktighet | `mean(dew_point_temperature P1D)` → RH% via Magnus-formel | SN17400 | SN17400 har ikke et sammenhengende RH-element (kun `mean(relative_humidity P1D)` 2020–2021, avviklet). Duggpunkt er derimot kontinuerlig tilgjengelig fra 2016. |
| Vind | rå `wind_speed` (PT10M), snitt av døgnets observasjoner | SN17400 | SN17400 har intet ferdig døgnsnitt for vind. `max(wind_speed PT1H)` fantes som alternativ, men **validFrom 2026-06-24** — ingen historikk før det, uegnet selv for fremtidig produksjonsbruk før stasjonen har bygget opp historikk. Rå PT10M er tyngre å hente (~2 MB/måned) men har vært tilgjengelig siden 2013. |
| Skydekke | `mean(cloud_area_fraction P1D)` (octas) | **SN17150** (Rygge) | SN17400 har ingen skydekke-måling i det hele tatt. Lånt fra Rygge, ~12 km unna — skydekke varierer mindre lokalt enn temperatur, så regnet som en akseptabel tilnærming. |
| Solinnstråling | Tabellverdier i skillet, interpolert på dato og breddegrad (59,4°N, mellom 55°N/60°N-kolonnene) | — | Ingen Frost-data nødvendig |

**Skydekke → kategori:** octas 0–2 → Klart, 3–5 → Halvskyet, 6–8 → Helskyet (ingen
offisiell grense oppgitt i skillet — valgt jevn tredeling).

**Sol → skyfaktor:** Klart × 1,0, Halvskyet × 0,70 (interpolert), Helskyet × 0,40
(skillets eksplisitte regel) — deretter × 0,80 for kärnis sin 20 % refleksjon.

**«Midvinter»-datoen i sol-tabellen** er ikke tallfestet i skillet — tolket som
**21. desember** (vintersolverv) for interpolasjonsformålet, siden det er det
naturlige tidspunktet raden representerer.

## Formel (fra skillet, uendret)

```
Värmetransport (W/m²) = Sol + Utsrålning + Motstrålning + Vind × (Värmeledning + Avdunstning)
Tillväxt (mm/h)        = Värmetransport ÷ (−85)
Døgnvekst (mm)          = Tillväxt (mm/h) × 24
```

- `Utsrålning` = konstant −309 W/m² (tynn kärnis)
- `Motstrålning`, `Värmeledning`, `Avdunstning` = tabelloppslag på lufttemperatur
  (og skydekke/luftfuktighet der aktuelt), lineært interpolert mellom
  heltallsgrader og mellom 60/80/100 %-kolonnene for luftfuktighet
- Døgnvekst kan bli **negativ** (issmelting) — akkumulert istykkelse klemmes til
  aldri under 0, samme prinsipp som kuldemengde

**Ingen startbetingelse**: formelen kjøres fra 1. oktober uansett værforhold —
tidlige høstdager gir store negative verdier (smelting), som bare holder
akkumulert tykkelse på 0 inntil temperaturen faktisk går under frysepunktet.

## Datavindu og lagring

- **Kun 1. oktober–31. desember** for v1 (ikke hele okt–mai-sesongen som
  kuldemengde) — enklere, matcher eksplisitt det som skal evalueres nå.
- Ny fil `data/isvekst.json`, full rebuild av hele vinduet ved hver kjøring
  (idempotent, samme filosofi som `kuldemengde.json`) — alle inputs er
  døgnaggregater (eller lette PT10M-snitt), så gjenutregning er billig.
- Struktur (kun ett sted i `locations`-lista foreløpig, men strukturert for flere
  senere):

```json
{
  "window_start": "2026-10-01",
  "window_end": "2026-12-31",
  "unit": "mm",
  "updated_at": "2026-10-15T06:00:03+00:00",
  "locations": [
    {
      "name": "Lødengfjorden",
      "lat": 59.4857391,
      "lon": 10.7860853,
      "series": {
        "2026-10-01": { "growth_mm": -20.7, "cum_mm": 0, "temp": 7.0, "rh": 94, "wind": 1.0, "sky": "Halvskyet" },
        "...": {}
      },
      "missing_days": [],
      "interpolated_days": []
    }
  ]
}
```

`missing_days`/`interpolated_days` speiler kuldemengde sitt mønster: indre
datahull fylles med `fillFrostGaps()` (gjenbrukt per delserie — temp, duggpunkt,
vind, skydekke fylles hver for seg før de kombineres), kanthull (typisk Frosts
publiseringsforsinkelse) ekstrapoleres ikke og listes i `missing_days`.

## Config (`config.php`)

```php
'isvekst_enabled' => false,   // eksperimentell — default av
'isvekst' => [
    'data_file'   => __DIR__ . '/data/isvekst.json',
    'window_start' => '10-01',
    'window_end'   => '12-31',   // IKKE frost.season_end (05-31) — bevisst kortere vindu for v1
],
```

`frost.locations`-oppføringen for Lødengfjorden får et nytt felt
`'isvekst' => true` — `updateIsvekst()` filtrerer på dette i stedet for å
duplisere lokasjonsdata. Ingen andre steder får dette feltet i v1.

## Backend (`fetch.php`)

Nye private funksjoner (plassert nær de eksisterende Frost-hjelperne):

- `fetchFrostDailyMeansGeneric(station, element, from, toEx)` — generalisering av
  eksisterende `fetchFrostDailyMeans()` til å ta `$element` som parameter
  (defaulter til `frost.element` for bakoverkompatibilitet med kuldemengde-kallet).
- `fetchFrostRawDailyAverage(station, element, from, toEx)` — henter rå
  observasjoner (PT10M) og grupperer/snitter per UTC-kalenderdag, samme teknikk
  som `fetchForecastDailyMeans()` allerede bruker for locationforecast.
- `relativeHumidityFromDewpoint(tempC, dewC)` — Magnus-formel.
- `cloudOctasToCategory(octas)` — 0–2/3–5/6–8 → Klart/Halvskyet/Helskyet.
- `solLookup(date, lat, skyCategory)` — interpolerer skillets sol-tabell på dato
  (lineær mellom tabellpunktene 01-okt/01-nov/01-des/midvinter/01-jan) og
  breddegrad (mellom 55°N/60°N-kolonnene), ganger med skyfaktor og
  kärnis-refleksjon.
- `motstralningLookup`, `varmeledningLookup`, `avdunstningLookup` — tabelloppslag
  med lineær interpolasjon på lufttemperatur (og luftfuktighet for avdunstning).
- `computeIsvekstGrowthMm(temp, rh, wind, skyCategory, date, lat)` — kombinerer
  alle ledd til én dags vekst i mm, ren funksjon (ingen I/O), lett å
  enhetsteste isolert mot eksempel-beregningen i skillet.
- `isvekstWindowFor(asOf)` — enklere variant av `kmSeasonFor()`: ingen
  årsovergang (vinduet 1.okt–31.des ligger alltid innenfor ett kalenderår),
  returnerer `null` når `asOf` ligger utenfor okt–des (slik at f.eks. en
  `--isvekst=2026-03-15`-kjøring midt i vinteren ikke gjør noe i stedet for å
  feile eller skrive tom fil).
- `updateIsvekst(?asOf = null)` — orkestrerer: henter/fyller de fire delseriene,
  looper dag for dag fra `window_start` til `min(asOf, window_end)`, kaller
  `computeIsvekstGrowthMm()`, akkumulerer (klemt til ≥0), skriver
  `data/isvekst.json` (atomisk temp+rename, som `updateKuldemengde()`).

**CLI:** `php fetch.php --isvekst=YYYY-MM-DD` — speiler `--kuldemengde=`, kjører
kun `updateIsvekst($date)`.

**`_run()`:** ny gated blokk bak `isvekst_enabled`, kjørt etter kuldemengde-
blokken, egen try/catch — en feilende isvekst-oppdatering påvirker aldri
bildepipelinene eller kuldemengde.

## API (`api.php`)

`?action=list` får en ny `isvekst`-nøkkel med innholdet i `data/isvekst.json`
(`null` når flagget er av eller filen mangler) — identisk mønster som
`kuldemengde`-nøkkelen.

## Frontend (`index.php`)

- Ny `ISVEKST_ENABLED`-konstant fra config, sendt til JS som de andre flaggene.
- Ny header-knapp (`🧊 Isvekst`), synlig kun når `ISVEKST_ENABLED` **og**
  `isvekst.locations[0].series` faktisk har data (samme synlighetsmønster som
  ❄-knappen).
- Klikk åpner en **ny, egen** modal-funksjon (`openIsvekstChart()`) — bevisst
  **ikke** en generalisering av eksisterende `openKmChart()`, for å holde
  eksperimentet isolert og trivielt å fjerne dersom det forkastes. SVG-linjegraf:
  kumulativ mm, 1.okt→31.des, månedsmerker på x-aksen, hover-krysshår + tooltip
  (dato, temp, RH, vind, skykategori, døgnvekst, akkumulert — samme detaljnivå
  som ble validert i spiken). Ingen terskellinje (intet `km_needed`-konsept for
  isvekst i v1).
- `help.php`: kort avsnitt om at dette er en eksperimentell modell, med de
  samme antakelsene som er listet over.

## Ute av scope for v1

- Flere steder enn Lødengfjorden.
- Sesongvindu utover 31. desember (dvs. ikke januar–mai).
- Kobling mot `km_needed`/skøytbar-is-terskel.
- Retroaktiv backfill av tidligere sesonger i produksjon (kun fremover fra
  aktivering, evt. manuell `--isvekst=`-kjøring for historiske datoer ved behov).

## Testing

- `computeIsvekstGrowthMm()` er en ren funksjon — enhetstestbar direkte mot
  skillets eksempel-beregning (1. desember, Stockholm, 60°N, −5 °C, helskyet,
  4 m/s, 85 % fuktighet → forventet ≈0,8 mm/time).
- `updateIsvekst()` verifiseres manuelt mot ekte Frost-data, samme fremgangsmåte
  som spiken (pretend-dato i fortiden med kjent utfall), før flagget vurderes
  slått på i produksjon.
