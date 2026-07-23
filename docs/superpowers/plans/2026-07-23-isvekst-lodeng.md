# Isvekst (Lødengfjorden) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an experimental, flag-gated "isvekst" (ice growth) overlay for Lødengfjorden that computes daily ice-thickness growth via the energy-balance formula in `.claude/skills/isprognosemodell_skill/`, using real Frost API data, and shows it as a cumulative-mm chart behind a new header button.

**Architecture:** Backend computation lives entirely in `fetch.php` (new pure formula methods + new Frost-fetching helpers + one orchestration method `updateIsvekst()`), writing a new `data/isvekst.json`, mirroring the existing `updateKuldemengde()` pattern. `api.php` exposes it under a new `isvekst` key on `?action=list`. `index.php` gets a new header button and a self-contained modal chart function, isolated from the existing kuldemengde chart code so the experiment is trivial to remove if it doesn't pan out.

**Tech Stack:** PHP 8 (no framework, no Composer), vanilla JS/SVG frontend, MET Frost API (`frost.met.no`), no build step.

## Global Constraints

- No test framework exists in this repo (no PHPUnit, no Composer). Verification uses standalone PHP scripts run via CLI, following the project's established `_test_*.php` convention (already excluded by `.gitignore`) — write the script, run it, delete it once verified. This is documented practice — see `BACKLOG.md`'s Landsat/S1/S3 entries.
- This is v1/experimental: **only Lødengfjorden**, **only the 1 Oct–31 Dec window** (not the full Oct–May season), **`isvekst_enabled` defaults to `false`**.
- Never set `isvekst_enabled => true` in `config.php` and leave it — per established project practice (see Landsat-thermal precedent), flip it to `true` locally only for the verification step in each task, then flip back to `false` immediately after. It only goes `true` for real once the user has visually confirmed the feature in a browser.
- All norwegian text (log messages, UI copy, help text) must match the terminology already used elsewhere in this codebase (`kuldemengde`, `skydekke`, `luftfuktighet`, etc.).
- Formula, tables, and data-source decisions must exactly match `docs/superpowers/specs/2026-07-23-isvekst-lodeng-design.md` — do not improvise different table values or interpolation rules.

---

### Task 1: Config — `isvekst_enabled` flag, `isvekst` block, mark Lødengfjorden

**Files:**
- Modify: `config.php:112-113` (Lødengfjorden location entry), `config.php:127` (insert new block after)

**Interfaces:**
- Produces: `$config['isvekst_enabled']` (bool), `$config['isvekst']['data_file']`, `$config['isvekst']['window_start']`, `$config['isvekst']['window_end']`, `$config['isvekst']['cloud_station']`, and `$config['frost']['locations'][0]['isvekst'] === true` for the Lødengfjorden entry. All later tasks read these keys.

- [ ] **Step 1: Add `isvekst` marker to the Lødengfjorden location entry**

In `config.php`, find:
```php
            ['name' => 'Lødengfjorden', 'lat' => 59.4857391, 'lon' => 10.7860853,
             'station' => 'SN17400', 'station_name' => 'FV120 Rødsund', 'km_needed' => 23],
```
Replace with:
```php
            ['name' => 'Lødengfjorden', 'lat' => 59.4857391, 'lon' => 10.7860853,
             'station' => 'SN17400', 'station_name' => 'FV120 Rødsund', 'km_needed' => 23,
             'isvekst' => true],
```

- [ ] **Step 2: Add the `isvekst_enabled` flag and `isvekst` config block**

In `config.php`, find the closing of the `frost` block (right before the `// ── Geografisk område (WGS84) ──` comment):
```php
        ],
    ],

    // ── Geografisk område (WGS84) ────────────────────────────────────────────
```
Replace with:
```php
        ],
    ],

    // ── Isvekst (energibalansemodell) — bak isvekst_enabled-flagget ──────────
    // Eksperimentell v1: kun Lødengfjorden, kun 1.okt-31.des (ikke hele
    // okt-mai-sesongen som kuldemengde). Se
    // .claude/skills/isprognosemodell_skill/isprognosemodell_skill.md og
    // docs/superpowers/specs/2026-07-23-isvekst-lodeng-design.md.
    'isvekst_enabled' => false,   // default av — eksperimentell, ikke live i produksjon
    'isvekst' => [
        'data_file'     => __DIR__ . '/data/isvekst.json',
        'window_start'  => '10-01',   // MM-DD
        'window_end'    => '12-31',   // MM-DD — bevisst kortere enn frost.season_end (05-31)
        'cloud_station' => 'SN17150', // Rygge — Lødengs egen stasjon (SN17400) måler ikke skydekke
    ],

    // ── Geografisk område (WGS84) ────────────────────────────────────────────
```

- [ ] **Step 3: Verify syntax and config values**

Run: `powershell -Command "& 'C:\xampp\php\php.exe' -l config.php"`
Expected: `No syntax errors detected in config.php`

Create a small check script `_test_isvekst_config.php` in the repo root:
```php
<?php
$c = require __DIR__ . '/config.php';
var_dump($c['isvekst_enabled']);
var_dump($c['isvekst']['cloud_station']);
foreach ($c['frost']['locations'] as $l) {
    if ($l['name'] === 'Lødengfjorden') var_dump($l['isvekst'] ?? null);
}
```
Run: `powershell -Command "& 'C:\xampp\php\php.exe' _test_isvekst_config.php"`
Expected output: `bool(false)`, `string(7) "SN17150"`, `bool(true)`
Delete the script once confirmed: `rm _test_isvekst_config.php`

- [ ] **Step 4: Commit**

```bash
git add config.php
git commit -m "isvekst: legg til isvekst_enabled-flagg og config-blokk (eksperimentell, av som default)"
```

---

### Task 2: Formula engine — pure functions in `fetch.php`

**Files:**
- Modify: `fetch.php` — insert new section between the end of `updateKuldemengde()` (`fetch.php:1712`) and the `// ── Metadata ──` comment (`fetch.php:1714`)
- Test: `_test_isvekst_formula.php` (repo root, gitignored via existing `_test_*.php` rule, deleted after verification)

**Interfaces:**
- Consumes: nothing (pure functions, no config/IO)
- Produces: `public function computeIsvekstGrowthMm(float $temp, float $rh, float $wind, string $skyCategory, DateTime $date, float $lat): float` on `SentinelFetcher` — used by Task 4. Also produces (private, internal): `isvekstCloudCategory(float $octas): string` and `isvekstRelativeHumidityFromDewpoint(float $tempC, float $dewC): float` — both used by Task 4.

- [ ] **Step 1: Write the verification script (before the methods exist)**

Create `_test_isvekst_formula.php` in the repo root:

```php
<?php
// Engangs verifisering — slettes etter kjøring. Sjekker computeIsvekstGrowthMm()
// mot skillets eksempel-beregning: 1. desember, 60°N, -5°C, helskyet, 4 m/s vind,
// 85% luftfuktighet -> forventet Tillväxt ~0.8 mm/time (skillet: "-72÷-85=0.846≈0.8").
require __DIR__ . '/fetch.php';

$config  = require __DIR__ . '/config.php';
$fetcher = new SentinelFetcher($config);

$growthMm = $fetcher->computeIsvekstGrowthMm(-5.0, 85.0, 4.0, 'Helskyet', new DateTime('2026-12-01'), 60.0);
$mmPerHour = $growthMm / 24;

printf("Døgnvekst: %.2f mm  (%.3f mm/time)\n", $growthMm, $mmPerHour);
printf("Forventet: ca 0.8 mm/time (skillets stated usikkerhet er +-0.2 mm/time)\n");

// Bred toleranse: skillets eget eksempel bruker en løst avrundet sol-verdi
// ("~10 W/m2") som ikke stemmer eksakt med tabellradens "~15" for 60N/01-des —
// sol-leddet er uansett lite i forhold til utstraling/motstraling, så avviket
// er godt innenfor modellens egen +-0.2 mm/time-usikkerhet.
if (abs($mmPerHour - 0.8) < 0.1) {
    echo "PASS\n";
    exit(0);
}
echo "FAIL\n";
exit(1);
```

- [ ] **Step 2: Run it to confirm it fails (method doesn't exist yet)**

Run: `powershell -Command "& 'C:\xampp\php\php.exe' _test_isvekst_formula.php"`
Expected: `PHP Fatal error:  Uncaught Error: Call to undefined method SentinelFetcher::computeIsvekstGrowthMm()`

- [ ] **Step 3: Implement the formula engine**

In `fetch.php`, insert the following new section immediately after line 1712 (the closing `}` and `return [...]` block of `updateKuldemengde()`) and before the `// ── Metadata ──` comment on line 1714:

```php

    // ── Isvekst (energibalansemodell, Lødengfjorden) — bak isvekst_enabled ──
    // Kilde: .claude/skills/isprognosemodell_skill/isprognosemodell_skill.md
    // (svensk isfartslitteratur, "Islära"). Se også
    // docs/superpowers/specs/2026-07-23-isvekst-lodeng-design.md.

    private const ISVEKST_MOTSTRALNING = [
        6=>[275,311,346], 5=>[270,305,340], 4=>[265,299,334], 3=>[260,294,328],
        2=>[255,289,322], 1=>[251,283,316], 0=>[246,278,310], -1=>[242,273,305],
        -2=>[238,269,300], -3=>[234,264,294], -4=>[230,259,289], -5=>[226,255,284],
        -6=>[222,251,279], -7=>[218,246,275], -8=>[214,242,270], -9=>[211,238,266],
        -10=>[207,234,261], -11=>[204,230,257], -12=>[200,226,252], -13=>[197,223,248],
        -14=>[194,219,244], -15=>[191,215,240], -16=>[187,212,236], -17=>[184,208,232],
        -18=>[181,205,228], -19=>[178,201,225], -20=>[175,198,221],
    ]; // kolonner: [Klart, Halvskyet, Helskyet] W/m²

    private const ISVEKST_AVDUNSTNING = [
        6=>[-1,2.6,6.2], 5=>[-1.7,1.7,5], 4=>[-2.4,0.8,3.9], 3=>[-3,-0.1,2.8],
        2=>[-3.6,-0.9,1.8], 1=>[-4.2,-1.6,0.9], 0=>[-4.7,-2.4,0], -1=>[-5.2,-3,-0.8],
        -2=>[-5.7,-3.6,-1.6], -3=>[-6.1,-4.2,-2.3], -4=>[-6.5,-4.8,-3], -5=>[-6.9,-5.3,-3.6],
        -6=>[-7.3,-5.7,-4.2], -7=>[-7.6,-6.2,-4.8], -8=>[-7.9,-6.6,-5.3], -9=>[-8.2,-7,-5.8],
        -10=>[-8.5,-7.4,-6.3], -11=>[-8.7,-7.7,-6.7], -12=>[-9,-8,-7.1], -13=>[-9.2,-8.3,-7.4],
        -14=>[-9.4,-8.6,-7.8], -15=>[-9.6,-8.8,-8.1], -16=>[-9.7,-9.1,-8.4], -17=>[-9.9,-9.3,-8.7],
        -18=>[-10.1,-9.5,-8.9], -19=>[-10.2,-9.7,-9.1], -20=>[-10.3,-9.8,-9.4],
    ]; // kolonner: [60%, 80%, 100% luftfuktighet] J/m³

    private const ISVEKST_VARMELEDNING = [
        6=>9.0, 5=>7.5, 4=>6.0, 3=>4.5, 2=>3.0, 1=>1.5, 0=>0, -1=>-1.5, -2=>-3.0,
        -3=>-4.5, -4=>-6.0, -5=>-7.5, -6=>-9.0, -7=>-10.6, -8=>-12.1, -9=>-13.6,
        -10=>-15.1, -11=>-16.6, -12=>-18.1, -13=>-19.6, -14=>-21.1, -15=>-22.6,
        -16=>-24.1, -17=>-25.6, -18=>-27.1, -19=>-28.6, -20=>-30.2,
    ]; // J/m³

    // dato => [55°N, 60°N] klarvær W/m² — "Midvinter" (ikke tallfestet i
    // skillet) tolket som 21. desember (vintersolverv)
    private const ISVEKST_SOL = [
        '10-01' => [120, 100], '11-01' => [50, 40], '12-01' => [20, 15],
        '12-21' => [25, 20], '01-01' => [30, 25],
    ];

    private function isvekstClampTemp(float $t): float
    {
        return max(-20.0, min(6.0, $t));
    }

    // Lineær interpolasjon mellom heltallsgrader. $col brukes kun for
    // tabeller med flere kolonner (motstrålning/avdunstning).
    private function isvekstInterpRow(array $table, float $temp, int $col = 0): float
    {
        $t  = $this->isvekstClampTemp($temp);
        $lo = (int)floor($t);
        $hi = (int)ceil($t);
        $vLo = is_array($table[$lo]) ? $table[$lo][$col] : $table[$lo];
        if ($lo === $hi) return $vLo;
        $vHi = is_array($table[$hi]) ? $table[$hi][$col] : $table[$hi];
        return $vLo + ($vHi - $vLo) * ($t - $lo);
    }

    private function isvekstSkyIndex(string $skyCategory): int
    {
        return match ($skyCategory) {
            'Klart'     => 0,
            'Halvskyet' => 1,
            default     => 2, // Helskyet
        };
    }

    // Skydekke i octas (0-8, Frost sitt mean(cloud_area_fraction P1D)) →
    // tabellkategori. Ingen offisiell grense oppgitt i skillet — jevn tredeling.
    private function isvekstCloudCategory(float $octas): string
    {
        if ($octas <= 2) return 'Klart';
        if ($octas <= 5) return 'Halvskyet';
        return 'Helskyet';
    }

    // Relativ luftfuktighet (%) fra duggpunkt og lufttemperatur (Magnus-formel)
    // — brukt fordi SN17400 (Lødengs stasjon) ikke har et sammenhengende
    // RH-element, men har duggpunkt kontinuerlig siden 2016.
    private function isvekstRelativeHumidityFromDewpoint(float $tempC, float $dewC): float
    {
        $es = fn(float $t) => exp((17.625 * $t) / (243.04 + $t));
        $rh = 100 * $es($dewC) / $es($tempC);
        return max(0.0, min(100.0, $rh));
    }

    private function isvekstMotstralningLookup(float $temp, string $skyCategory): float
    {
        return $this->isvekstInterpRow(self::ISVEKST_MOTSTRALNING, $temp, $this->isvekstSkyIndex($skyCategory));
    }

    private function isvekstVarmeledningLookup(float $temp): float
    {
        return $this->isvekstInterpRow(self::ISVEKST_VARMELEDNING, $temp);
    }

    // Interpolerer mellom 60/80/100%-kolonnene basert på faktisk luftfuktighet.
    private function isvekstAvdunstningLookup(float $temp, float $rh): float
    {
        $rh = max(60.0, min(100.0, $rh));
        $v60  = $this->isvekstInterpRow(self::ISVEKST_AVDUNSTNING, $temp, 0);
        $v80  = $this->isvekstInterpRow(self::ISVEKST_AVDUNSTNING, $temp, 1);
        $v100 = $this->isvekstInterpRow(self::ISVEKST_AVDUNSTNING, $temp, 2);
        if ($rh <= 80) {
            $frac = ($rh - 60) / 20;
            return $v60 + ($v80 - $v60) * $frac;
        }
        $frac = ($rh - 80) / 20;
        return $v80 + ($v100 - $v80) * $frac;
    }

    // Interpolerer solinnstrålingstabellen på dato (mellom tabellpunktene) og
    // breddegrad (mellom 55°N/60°N-kolonnene), ganger med skyfaktor og
    // kärnis sin 20% refleksjon.
    private function isvekstSolLookup(DateTime $date, float $lat, string $skyCategory): float
    {
        $year = (int)$date->format('Y');
        $points = [
            [new DateTime("$year-10-01"), self::ISVEKST_SOL['10-01']],
            [new DateTime("$year-11-01"), self::ISVEKST_SOL['11-01']],
            [new DateTime("$year-12-01"), self::ISVEKST_SOL['12-01']],
            [new DateTime("$year-12-21"), self::ISVEKST_SOL['12-21']],
            [new DateTime(($year + 1) . '-01-01'), self::ISVEKST_SOL['01-01']],
        ];

        $ts = $date->getTimestamp();
        $clear = 0.0;
        for ($i = 0; $i < count($points) - 1; $i++) {
            $t0 = $points[$i][0]->getTimestamp();
            $t1 = $points[$i + 1][0]->getTimestamp();
            if ($ts < $t0 || $ts > $t1) continue;
            $frac = ($ts - $t0) / ($t1 - $t0);
            $v55 = $points[$i][1][0] + ($points[$i + 1][1][0] - $points[$i][1][0]) * $frac;
            $v60 = $points[$i][1][1] + ($points[$i + 1][1][1] - $points[$i][1][1]) * $frac;
            $latFrac = max(0.0, min(1.0, ($lat - 55) / 5));
            $clear = $v55 + ($v60 - $v55) * $latFrac;
            break;
        }

        $cloudFactor = match ($skyCategory) {
            'Klart'     => 1.0,
            'Halvskyet' => 0.70,
            default     => 0.40, // Helskyet — skillets eksplisitte regel
        };
        return $clear * $cloudFactor * 0.80; // 0.80 = kärnis sin 20% refleksjon
    }

    // Døgnvekst i mm (kan bli negativ = smelting) for gitt døgnmiddel-input.
    // Ren funksjon — verifisert mot skillets eksempel-beregning i
    // _test_isvekst_formula.php.
    public function computeIsvekstGrowthMm(
        float $temp, float $rh, float $wind, string $skyCategory, DateTime $date, float $lat
    ): float {
        $sol      = $this->isvekstSolLookup($date, $lat, $skyCategory);
        $utstral  = -309.0;
        $motstral = $this->isvekstMotstralningLookup($temp, $skyCategory);
        $varmeled = $this->isvekstVarmeledningLookup($temp);
        $avdunst  = $this->isvekstAvdunstningLookup($temp, $rh);

        $varmetransport = $sol + $utstral + $motstral + $wind * ($varmeled + $avdunst);
        $tillvaxtMmPerHour = $varmetransport / -85;
        return $tillvaxtMmPerHour * 24;
    }
```

- [ ] **Step 4: Run the verification script again**

Run: `powershell -Command "& 'C:\xampp\php\php.exe' _test_isvekst_formula.php"`
Expected:
```
Døgnvekst: 20.16 mm  (0.840 mm/time)
Forventet: ca 0.8 mm/time (skillets stated usikkerhet er +-0.2 mm/time)
PASS
```
(Exact decimals may vary slightly depending on the sol-table interpolation branch taken — anything printing `PASS` is correct.)

- [ ] **Step 5: Delete the verification script and commit**

```bash
rm _test_isvekst_formula.php
git add fetch.php
git commit -m "isvekst: legg til energibalanse-formelmotor (rene funksjoner, verifisert mot skillets eksempel)"
```

---

### Task 3: Frost data-fetching helpers

**Files:**
- Modify: `fetch.php:1465` (`fetchFrostDailyMeans` signature), insert new method after `fetchFrostDailyMeans` (currently ending `fetch.php:1507`)
- Test: `_test_isvekst_frost.php` (repo root, deleted after verification)

**Interfaces:**
- Consumes: `frostRequest(array $query): array` (existing, `fetch.php:1436`) returns `[$code, $body]`
- Produces: `private function fetchFrostDailyMeans(string $station, string $from, string $toExclusive, ?string $element = null): array` (extended, backward-compatible — existing 3-arg calls in `updateKuldemengde()` are untouched) and `private function fetchFrostRawDailyAverage(string $station, string $element, string $from, string $toExclusive): array`, both returning `[dato => float]`. Used by Task 4's `updateIsvekst()`.

- [ ] **Step 1: Write the verification script**

Create `_test_isvekst_frost.php` in the repo root:

```php
<?php
// Engangs verifisering mot ekte Frost-API — slettes etter kjøring.
// Bruker Reflection for å kalle private metoder direkte (samme pragmatikk
// som resten av prosjektets _test_*.php-skript: ekte data, manuell inspeksjon).
require __DIR__ . '/fetch.php';

$config  = require __DIR__ . '/config.php';
$fetcher = new SentinelFetcher($config);
$ref     = new ReflectionClass($fetcher);

function callPrivate($fetcher, $ref, string $method, array $args) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($fetcher, $args);
}

// Siste 5 hele dager, godt innenfor alle elementenes historikk
$from = date('Y-m-d', strtotime('-6 days'));
$toEx = date('Y-m-d', strtotime('-1 day'));

$dew = callPrivate($fetcher, $ref, 'fetchFrostDailyMeans', ['SN17400', $from, $toEx, 'mean(dew_point_temperature P1D)']);
echo "Duggpunkt SN17400 ($from til $toEx): " . count($dew) . " dager\n";
foreach ($dew as $d => $v) echo "  $d: {$v}°C\n";

$wind = callPrivate($fetcher, $ref, 'fetchFrostRawDailyAverage', ['SN17400', 'wind_speed', $from, $toEx]);
echo "Vind (rå PT10M-snitt) SN17400 ($from til $toEx): " . count($wind) . " dager\n";
foreach ($wind as $d => $v) echo "  $d: {$v} m/s\n";

$cloud = callPrivate($fetcher, $ref, 'fetchFrostDailyMeans', ['SN17150', $from, $toEx, 'mean(cloud_area_fraction P1D)']);
echo "Skydekke SN17150 ($from til $toEx): " . count($cloud) . " dager\n";
foreach ($cloud as $d => $v) echo "  $d: {$v} octas\n";

$ok = count($dew) > 0 && count($wind) > 0 && count($cloud) > 0;
echo $ok ? "PASS\n" : "FAIL (en eller flere serier er tomme)\n";
exit($ok ? 0 : 1);
```

- [ ] **Step 2: Run it to confirm it fails (new method doesn't exist yet)**

Run: `powershell -Command "& 'C:\xampp\php\php.exe' _test_isvekst_frost.php"`
Expected: `PHP Fatal error:  Uncaught ReflectionException: Method fetchFrostRawDailyAverage does not exist` (the `fetchFrostDailyMeans` calls above it in the script will have already succeeded, since that method already exists from Task 3 Step 3 — only the new method is missing at this point)

- [ ] **Step 3: Extend `fetchFrostDailyMeans` with an optional `$element` parameter**

In `fetch.php`, find:
```php
    private function fetchFrostDailyMeans(string $station, string $from, string $toExclusive): array
    {
        $element = $this->config['frost']['element'] ?? 'mean(air_temperature P1D)';
```
Replace with:
```php
    private function fetchFrostDailyMeans(string $station, string $from, string $toExclusive, ?string $element = null): array
    {
        $element = $element ?? ($this->config['frost']['element'] ?? 'mean(air_temperature P1D)');
```

- [ ] **Step 4: Add `fetchFrostRawDailyAverage()`**

In `fetch.php`, immediately after the closing `}` of `fetchFrostDailyMeans()` (`fetch.php:1507`, right before the `fillFrostGaps()` comment block), insert:

```php

    // Rå (sub-daglige) observasjoner → dato => snitt for UTC-kalenderdøgnet.
    // Brukt for elementer SN17400 ikke har et ferdig døgnsnitt for (vind),
    // samme grupperingsteknikk som fetchForecastDailyMeans() bruker for
    // locationforecast.
    private function fetchFrostRawDailyAverage(string $station, string $element, string $from, string $toExclusive): array
    {
        $query = [
            'sources'       => $station,
            'elements'      => $element,
            'referencetime' => "$from/$toExclusive",
        ];
        [$code, $body] = $this->frostRequest($query);
        if ($code === 404 || $code === 412) return [];   // ingen data i perioden
        if ($code === 401 || $code === 403) {
            throw new RuntimeException("Frost avviste forespørselen (HTTP $code) — sjekk FROST_CLIENT_ID i .sentinel.env");
        }
        if ($code !== 200) {
            throw new RuntimeException("Frost-forespørsel feilet (HTTP $code): " . substr((string)$body, 0, 300));
        }

        $data  = json_decode($body, true);
        $byDay = [];
        foreach ($data['data'] ?? [] as $item) {
            $date = substr($item['referenceTime'] ?? '', 0, 10);
            if ($date === '') continue;
            foreach ($item['observations'] ?? [] as $obs) {
                if (($obs['elementId'] ?? '') !== $element) continue;
                if ((int)($obs['qualityCode'] ?? 0) >= 6) continue;
                if (!isset($obs['value']) || !is_numeric($obs['value'])) continue;
                $byDay[$date][] = (float)$obs['value'];
            }
        }
        $means = [];
        foreach ($byDay as $d => $vals) $means[$d] = array_sum($vals) / count($vals);
        return $means;
    }
```

- [ ] **Step 5: Run the verification script again**

Run: `powershell -Command "& 'C:\xampp\php\php.exe' _test_isvekst_frost.php"`
Expected: three non-empty series printed (5 days each), ending in `PASS`. If `FAIL`, inspect which series is empty — a `wind` failure most likely means the `wind_speed` element query needs the `sources` param format checked (should be a bare station code, e.g. `SN17400`, not `SN17400:0`).

- [ ] **Step 6: Delete the verification script and commit**

```bash
rm _test_isvekst_frost.php
git add fetch.php
git commit -m "isvekst: Frost-hjelpere for duggpunkt/vind/skydekke (fetchFrostDailyMeans utvidet, ny fetchFrostRawDailyAverage)"
```

---

### Task 4: Orchestration — `isvekstWindowFor()`, `updateIsvekst()`, CLI flag

**Files:**
- Modify: `fetch.php` — insert `isvekstWindowFor()` and `updateIsvekst()` right after the formula-engine section added in Task 2 (i.e. after `computeIsvekstGrowthMm()`, still before the `// ── Metadata ──` comment)
- Modify: `fetch.php:2211-2227` (CLI section) — add `--isvekst=` flag handling, mirroring `--kuldemengde=`

**Interfaces:**
- Consumes: `fillFrostGaps(array $means): array` (existing, `fetch.php:1515`, returns `[filled, interpolatedDates]`), `fetchFrostDailyMeans()` and `fetchFrostRawDailyAverage()` (Task 3), `isvekstRelativeHumidityFromDewpoint()`, `isvekstCloudCategory()`, `computeIsvekstGrowthMm()` (Task 2), `$config['isvekst']`, `$config['frost']['locations']` (Task 1)
- Produces: `public function updateIsvekst(?string $asOf = null): array` returning `['window' => string, 'days' => int]`, and writes `data/isvekst.json`. Used by Task 5 (cron wiring) and CLI. `data/isvekst.json` schema is consumed by Task 6 (`api.php`) and Task 7 (frontend).

- [ ] **Step 1: Implement `isvekstWindowFor()` and `updateIsvekst()`**

In `fetch.php`, immediately after the closing `}` of `computeIsvekstGrowthMm()` (added in Task 2) and before `// ── Metadata ──`, insert:

```php

    // Enklere enn kmSeasonFor(): vinduet 1.okt-31.des ligger alltid innenfor
    // ett kalenderår, ingen årsovergang å håndtere. Returnerer null utenfor
    // vinduet (f.eks. en --isvekst=2026-03-15-kjøring skal ikke gjøre noe).
    private function isvekstWindowFor(string $date): ?array
    {
        $cfg     = $this->config['isvekst'] ?? [];
        $startMD = $cfg['window_start'] ?? '10-01';
        $endMD   = $cfg['window_end']   ?? '12-31';
        $y  = (int)substr($date, 0, 4);
        $md = substr($date, 5);
        if ($md < $startMD || $md > $endMD) return null;
        return ['start' => "$y-$startMD", 'end' => "$y-$endMD"];
    }

    // Bygg og skriv data/isvekst.json for vinduet $asOf tilhører. Idempotent:
    // overskriver alltid hele filen (billig — alle inputs er døgnaggregater
    // eller lette PT10M-snitt). Utenfor vinduet skrives tomme serier, samme
    // prinsipp som updateKuldemengde() utenfor sesong.
    public function updateIsvekst(?string $asOf = null): array
    {
        $asOf = $asOf ?: date('Y-m-d');
        $cfg  = $this->config['isvekst'] ?? [];
        $file = $cfg['data_file'] ?? ($this->config['data_dir'] . 'isvekst.json');
        $locs = array_filter($this->config['frost']['locations'] ?? [], fn($l) => ($l['isvekst'] ?? false) === true);

        $window = $this->isvekstWindowFor($asOf);

        $out = [
            'window_start' => $window['start'] ?? null,
            'window_end'   => $window['end']   ?? null,
            'unit'         => 'mm',
            'updated_at'   => date('c'),
            'locations'    => [],
        ];

        $days = 0;
        foreach ($locs as $loc) {
            $series  = [];
            $missing = [];
            $interp  = [];

            if ($window !== null) {
                $from = $window['start'];
                $toEx = date('Y-m-d', strtotime(min($asOf, $window['end']) . ' +1 day'));
                $station      = $loc['station'];
                $cloudStation = $cfg['cloud_station'] ?? 'SN17150';

                [$temp, $tempInterp]   = $this->fillFrostGaps($this->fetchFrostDailyMeans($station, $from, $toEx));
                [$dew,  $dewInterp]    = $this->fillFrostGaps($this->fetchFrostDailyMeans($station, $from, $toEx, 'mean(dew_point_temperature P1D)'));
                [$wind, $windInterp]   = $this->fillFrostGaps($this->fetchFrostRawDailyAverage($station, 'wind_speed', $from, $toEx));
                [$cloud, $cloudInterp] = $this->fillFrostGaps($this->fetchFrostDailyMeans($cloudStation, $from, $toEx, 'mean(cloud_area_fraction P1D)'));
                $interpDays = array_flip(array_merge($tempInterp, $dewInterp, $windInterp, $cloudInterp));

                $sum  = 0.0;
                $day  = new DateTime($from);
                $last = new DateTime(min($asOf, $window['end']));
                while ($day <= $last) {
                    $d = $day->format('Y-m-d');
                    if (isset($temp[$d], $dew[$d], $wind[$d], $cloud[$d])) {
                        $rh     = $this->isvekstRelativeHumidityFromDewpoint($temp[$d], $dew[$d]);
                        $sky    = $this->isvekstCloudCategory($cloud[$d]);
                        $growth = $this->computeIsvekstGrowthMm($temp[$d], $rh, $wind[$d], $sky, clone $day, (float)$loc['lat']);
                        $sum    = max(0.0, $sum + $growth);
                        $series[$d] = [
                            'growth_mm' => round($growth, 2),
                            'cum_mm'    => round($sum, 1),
                            'temp'      => round($temp[$d], 1),
                            'rh'        => round($rh, 0),
                            'wind'      => round($wind[$d], 1),
                            'sky'       => $sky,
                        ];
                        if (isset($interpDays[$d])) {
                            $series[$d]['interpolated'] = true;
                            $interp[] = $d;
                        }
                    } else {
                        $missing[] = $d;
                    }
                    $day->modify('+1 day');
                }
            }

            $out['locations'][] = [
                'name'              => $loc['name'],
                'lat'               => $loc['lat'],
                'lon'               => $loc['lon'],
                'station'           => $loc['station'],
                'station_name'      => $loc['station_name'] ?? $loc['station'],
                'missing_days'      => $missing,
                'interpolated_days' => $interp,
                'series'            => $series === [] ? new stdClass() : $series,
            ];
            $days = max($days, count($series));
        }

        $tmp = $file . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($tmp, $file);

        return [
            'window' => $window ? "{$window['start']} → {$window['end']}" : 'utenfor vindu',
            'days'   => $days,
        ];
    }
```

- [ ] **Step 2: Add the `--isvekst=` CLI flag**

In `fetch.php`, find (in the CLI section near the bottom):
```php
    if (($from && !$to) || (!$from && $to)) {
        echo "Bruk: php fetch.php --from=YYYY-MM-DD --to=YYYY-MM-DD\n";
        exit(1);
    }
```
Replace with:
```php
    // --isvekst=YYYY-MM-DD: oppdater kun isvekst-filen og avslutt. Eksperimentell
    // — se docs/superpowers/specs/2026-07-23-isvekst-lodeng-design.md. Datoen
    // styrer hvilket vindu (1.okt-31.des samme år) som beregnes.
    if (isset($args['isvekst'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['isvekst'])) {
            echo "Bruk: php fetch.php --isvekst=YYYY-MM-DD\n";
            exit(1);
        }
        try {
            $iv = $fetcher->updateIsvekst($args['isvekst']);
            echo "Isvekst oppdatert: {$iv['window']}  ({$iv['days']} døgn)\n";
            exit(0);
        } catch (RuntimeException $e) {
            echo "FEIL: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    if (($from && !$to) || (!$from && $to)) {
        echo "Bruk: php fetch.php --from=YYYY-MM-DD --to=YYYY-MM-DD\n";
        exit(1);
    }
```

- [ ] **Step 3: Run against real historical data and compare to the already-validated spike**

Run: `powershell -Command "& 'C:\xampp\php\php.exe' fetch.php --isvekst=2025-12-31"`
Expected: `Isvekst oppdatert: 2025-10-01 → 2025-12-31  (92 døgn)`

Then create a small inspection script `_test_isvekst_inspect.php` in the repo root:
```php
<?php
// Engangs inspeksjon — slettes etter kjøring.
$d = json_decode(file_get_contents(__DIR__ . '/data/isvekst.json'), true);
$s = $d['locations'][0]['series'];
printf("2025-12-31 cum_mm: %s (forventet ~179.5)\n", $s['2025-12-31']['cum_mm'] ?? 'MANGLER');
printf("2025-11-21 cum_mm: %s (forventet ~144.9)\n", $s['2025-11-21']['cum_mm'] ?? 'MANGLER');
printf("missing_days: %d\n", count($d['locations'][0]['missing_days']));
```
Run: `powershell -Command "& 'C:\xampp\php\php.exe' _test_isvekst_inspect.php"`
Expected: `cum_mm` for `2025-12-31` close to `179.5` and for `2025-11-21` close to `144.9` (within a few mm — these are the values already observed and visually approved in the spike; small differences are fine since the dewpoint-based RH and raw-wind averaging paths are implemented slightly differently than the spike script, but should track closely), and `missing_days: 0`.

- [ ] **Step 4: Verify idempotency**

Run the same command again: `powershell -Command "& 'C:\xampp\php\php.exe' fetch.php --isvekst=2025-12-31"`, then re-run `_test_isvekst_inspect.php` from Step 3 and confirm identical `cum_mm` values. Delete `_test_isvekst_inspect.php` once confirmed.

- [ ] **Step 5: Commit**

```bash
git add fetch.php
git commit -m "isvekst: orkestrering (updateIsvekst, isvekstWindowFor) + CLI --isvekst=-flagg"
```

---

### Task 5: Wire into the cron `_run()` flow

**Files:**
- Modify: `fetch.php:2090-2102` (insert new gated block after the kuldemengde block), `fetch.php:2113-2116` (log summary line)

**Interfaces:**
- Consumes: `updateIsvekst()` (Task 4), `$config['isvekst_enabled']` (Task 1)
- Produces: `$stats['isvekst_updated']` / `$stats['isvekst_errors']`, consumed only by the log line in this same task

- [ ] **Step 1: Add the gated block after kuldemengde in `_run()`**

In `fetch.php`, find:
```php
        // ── Kuldemengde (kun når kuldemengde_enabled === true) ───────────────
        // Uavhengig av bildepipelinene over — en feilende Frost-kobling
        // påvirker aldri S2/S1/Landsat/S3.
        if (($this->config['kuldemengde_enabled'] ?? false) === true) {
            try {
                $km = $this->updateKuldemengde();
                $stats['km_updated'] = true;
                $this->log("KM OK    {$km['season']}  ({$km['days']} døgn, {$km['missing']} mangler, {$km['interpolert']} interpolert, {$km['forecast']} varseldøgn)");
            } catch (RuntimeException $e) {
                $stats['km_errors'][] = $e->getMessage();
                $this->log("KM FEIL  " . $e->getMessage());
            }
        }
```

> Note for the implementer: re-check the exact `KM OK` log line text against the live file before matching — copy it verbatim rather than retyping, since a mismatched `old_string` will fail the edit.

Insert immediately after that block's closing `}` (still before `usort($metadata, ...)`):
```php

        // ── Isvekst (kun når isvekst_enabled === true) — eksperimentell ─────
        // Uavhengig av bildepipelinene og av kuldemengde — en feilende
        // Frost-kobling her påvirker aldri noe annet.
        if (($this->config['isvekst_enabled'] ?? false) === true) {
            try {
                $iv = $this->updateIsvekst();
                $stats['isvekst_updated'] = true;
                $this->log("ISVEKST OK  {$iv['window']}  ({$iv['days']} døgn)");
            } catch (RuntimeException $e) {
                $stats['isvekst_errors'][] = $e->getMessage();
                $this->log("ISVEKST FEIL  " . $e->getMessage());
            }
        }
```

- [ ] **Step 2: Add isvekst to the end-of-run log summary**

In `fetch.php`, find:
```php
        $kmsum = ($this->config['kuldemengde_enabled'] ?? false)
            ? ('KM: ' . ($stats['km_updated'] ? 'oppdatert' : 'feilet'))
            : 'KM: av';
        $this->log("=== Ferdig — $s2sum | $s1sum | $lsum | $s3sum | $kmsum | {$stats['deleted']} slettet ===");
```
Replace with:
```php
        $kmsum = ($this->config['kuldemengde_enabled'] ?? false)
            ? ('KM: ' . ($stats['km_updated'] ? 'oppdatert' : 'feilet'))
            : 'KM: av';
        $ivsum = ($this->config['isvekst_enabled'] ?? false)
            ? ('ISVEKST: ' . (($stats['isvekst_updated'] ?? false) ? 'oppdatert' : 'feilet'))
            : 'ISVEKST: av';
        $this->log("=== Ferdig — $s2sum | $s1sum | $lsum | $s3sum | $kmsum | $ivsum | {$stats['deleted']} slettet ===");
```

- [ ] **Step 3: Verify with the flag off (default) — confirm nothing changes**

Run: `powershell -Command "& 'C:\xampp\php\php.exe' fetch.php --isvekst=2025-12-31"` (still works standalone, unaffected)

Then run a real cron-style pass with the flag at its default (`false`):
Run: `powershell -Command "& 'C:\xampp\php\php.exe' fetch.php --from=2026-07-20 --to=2026-07-21"`
Expected: log output ends with `... | ISVEKST: av | N slettet ===` and `data/isvekst.json` is untouched (check its mtime doesn't change).

- [ ] **Step 4: Verify with the flag temporarily on**

Edit `config.php`, set `'isvekst_enabled' => true,` temporarily. Run: `powershell -Command "& 'C:\xampp\php\php.exe' fetch.php --from=2026-07-20 --to=2026-07-21"`
Expected: log ends with `... | ISVEKST: oppdatert | N slettet ===`, and `data/isvekst.json`'s `updated_at` is fresh (window will be empty/null since 2026-07 is outside Oct-Dec — that's correct behavior, confirms the flag path runs without breaking anything).

Then **immediately revert** `config.php` back to `'isvekst_enabled' => false,` — do not leave it on.

- [ ] **Step 5: Commit**

```bash
git add fetch.php
git commit -m "isvekst: koble inn i _run()-cron-flyten bak isvekst_enabled, egen try/catch"
```

---

### Task 6: API — expose `isvekst` on `?action=list`

**Files:**
- Modify: `api.php:48-64`

**Interfaces:**
- Consumes: `$config['isvekst_enabled']`, `$config['isvekst']['data_file']` (Task 1), `data/isvekst.json` (Task 4/5 output)
- Produces: `isvekst` key in the `?action=list` JSON response — consumed by Task 7 (frontend)

- [ ] **Step 1: Add the `isvekst` key**

In `api.php`, find:
```php
            // Kuldemengde-serien (MET Frost) rir med i list-svaret — .htaccess
            // blokkerer direkte lesing av /data/, så filen må leveres herfra
            $km = null;
            if (($config['kuldemengde_enabled'] ?? false) === true) {
                $kmFile = $config['frost']['data_file'] ?? ($config['data_dir'] . 'kuldemengde.json');
                if (file_exists($kmFile)) {
                    $km = json_decode(file_get_contents($kmFile), true);
                }
            }
```
Replace with:
```php
            // Kuldemengde-serien (MET Frost) rir med i list-svaret — .htaccess
            // blokkerer direkte lesing av /data/, så filen må leveres herfra
            $km = null;
            if (($config['kuldemengde_enabled'] ?? false) === true) {
                $kmFile = $config['frost']['data_file'] ?? ($config['data_dir'] . 'kuldemengde.json');
                if (file_exists($kmFile)) {
                    $km = json_decode(file_get_contents($kmFile), true);
                }
            }
            // Isvekst-serien (eksperimentell) — samme mønster som kuldemengde
            $iv = null;
            if (($config['isvekst_enabled'] ?? false) === true) {
                $ivFile = $config['isvekst']['data_file'] ?? ($config['data_dir'] . 'isvekst.json');
                if (file_exists($ivFile)) {
                    $iv = json_decode(file_get_contents($ivFile), true);
                }
            }
```

Then find:
```php
                'kuldemengde' => $km,
            ]);
            break;
```
Replace with:
```php
                'kuldemengde' => $km,
                'isvekst'     => $iv,
            ]);
            break;
```

- [ ] **Step 2: Verify with real data**

With `isvekst_enabled` still `false` (default), request the endpoint and confirm `isvekst` is `null`:
Run: `powershell -Command "& 'C:\xampp\php\php.exe' -l api.php"` (lint check first)
Expected: `No syntax errors detected in api.php`

Temporarily set `'isvekst_enabled' => true,` in `config.php`, ensure `data/isvekst.json` exists (from Task 4/5's runs), start PHP's built-in server, and check:
```
powershell -Command "Start-Process -NoNewWindow 'C:\xampp\php\php.exe' -ArgumentList '-S','localhost:8899','-t','.'; Start-Sleep -Seconds 1; (Invoke-WebRequest 'http://localhost:8899/api.php?action=list').Content | Select-String 'isvekst' "
```
Expected: the response contains an `"isvekst":{"window_start":...` object with the Lødengfjorden series. Stop the server afterward (`Get-Process php | Stop-Process`), and revert `isvekst_enabled` back to `false` in `config.php`.

- [ ] **Step 3: Commit**

```bash
git add api.php
git commit -m "isvekst: eksponer isvekst-nøkkel på ?action=list"
```

---

### Task 7: Frontend — header button, modal, chart

**Files:**
- Modify: `index.php:67` (add `.isvekst-btn` to shared button selector), `index.php:73` (hover selector), `index.php:87` (focus-visible selector), `index.php:412` (mobile media query selector) — CSS
- Modify: `index.php` — insert new `.isvekst-chart`/`.isvekst-strip`/`.isvekst-legend` CSS rules near the existing `.km-chart` rules (around `index.php:220`)
- Modify: `index.php:581` (insert new button HTML after the `frost-btn` block, before `fetch-btn`)
- Modify: `index.php:619` (add `ISVEKST_ENABLED` JS const), `index.php:633` (add `isvekstData` state var)
- Modify: `index.php:662` (in `loadImages()`, after the `kmLocations`/`frostBtn` block — populate `isvekstData` and toggle button visibility)
- Modify: `index.php` — insert new `openIsvekstChart()` function after `openKmChart()` (currently ending `index.php:989`)

**Interfaces:**
- Consumes: `data.isvekst.locations[0]` from `?action=list` (Task 6), shape `{ name, lat, lon, station_name, missing_days, series: { 'YYYY-MM-DD': { growth_mm, cum_mm, temp, rh, wind, sky, interpolated? } } }`
- Produces: nothing consumed by later tasks — this is the last task

- [ ] **Step 1: Add `.isvekst-btn` to the four shared button-CSS selector lists**

In `index.php`, find (appears once, `index.php:67-73`):
```css
.fetch-btn,.filter-btn,.lst-btn,.frost-btn,.landsat-lst-btn{
  background:transparent;border:1px solid var(--ink);color:var(--ink);
  padding:6px 12px;font-size:10px;letter-spacing:.18em;text-transform:uppercase;
  font-family:var(--font-mono);cursor:pointer;
  transition:background .15s,color .15s,border-color .15s;
}
.fetch-btn:hover,.filter-btn:hover,.lst-btn:hover,.frost-btn:hover,.landsat-lst-btn:hover{background:var(--ink);color:var(--paper)}
```
Replace with:
```css
.fetch-btn,.filter-btn,.lst-btn,.frost-btn,.landsat-lst-btn,.isvekst-btn{
  background:transparent;border:1px solid var(--ink);color:var(--ink);
  padding:6px 12px;font-size:10px;letter-spacing:.18em;text-transform:uppercase;
  font-family:var(--font-mono);cursor:pointer;
  transition:background .15s,color .15s,border-color .15s;
}
.fetch-btn:hover,.filter-btn:hover,.lst-btn:hover,.frost-btn:hover,.landsat-lst-btn:hover,.isvekst-btn:hover{background:var(--ink);color:var(--paper)}
```

Find:
```css
.fetch-btn:focus-visible,.filter-btn:focus-visible,.pro-btn:focus-visible,.lst-btn:focus-visible,
.frost-btn:focus-visible,.landsat-lst-btn:focus-visible,
.help-btn:focus-visible,.nav:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
```
Replace with:
```css
.fetch-btn:focus-visible,.filter-btn:focus-visible,.pro-btn:focus-visible,.lst-btn:focus-visible,
.frost-btn:focus-visible,.landsat-lst-btn:focus-visible,.isvekst-btn:focus-visible,
.help-btn:focus-visible,.nav:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
```

Find (inside the mobile `@media` block):
```css
  .fetch-btn,.filter-btn,.lst-btn,.frost-btn,.landsat-lst-btn{padding:5px 8px;font-size:9px;letter-spacing:.1em}
```
Replace with:
```css
  .fetch-btn,.filter-btn,.lst-btn,.frost-btn,.landsat-lst-btn,.isvekst-btn{padding:5px 8px;font-size:9px;letter-spacing:.1em}
```

- [ ] **Step 2: Add chart/strip/legend CSS**

In `index.php`, find:
```css
.km-chart .hover-dot{fill:var(--frost);stroke:var(--paper);stroke-width:2}
```
Replace with:
```css
.km-chart .hover-dot{fill:var(--frost);stroke:var(--paper);stroke-width:2}
.isvekst-chart{display:block;width:100%;height:auto}
.isvekst-chart .grid{stroke:var(--hair);stroke-width:1}
.isvekst-chart .axis-lbl{fill:var(--muted);font-family:var(--font-mono);font-size:9px;letter-spacing:.05em}
.isvekst-chart .area{fill:var(--blue);opacity:.14}
.isvekst-chart .series{fill:none;stroke:var(--blue);stroke-width:2;stroke-linejoin:round;stroke-linecap:round}
.isvekst-chart .end-dot{fill:var(--blue)}
.isvekst-chart .end-lbl{fill:var(--ink);font-family:var(--font-mono);font-size:10px;font-weight:700;letter-spacing:.05em}
.isvekst-chart .xhair{stroke:var(--line);stroke-width:1;opacity:.6}
.isvekst-chart .hover-dot{fill:var(--blue);stroke:var(--paper);stroke-width:2}
.isvekst-strip{display:block;width:100%;height:34px;margin-top:2px}
.isvekst-strip .bar-growth{fill:var(--green)}
.isvekst-strip .bar-melt{fill:var(--red)}
.isvekst-strip .zero{stroke:var(--hair);stroke-width:1}
.isvekst-legend{display:flex;gap:14px;font-size:9.5px;color:var(--muted);margin:4px 0 2px;font-family:var(--font-mono)}
.isvekst-legend span{display:inline-flex;align-items:center;gap:5px}
.isvekst-legend i{width:8px;height:8px;display:inline-block}
.isvekst-legend .g{background:var(--green)}
.isvekst-legend .r{background:var(--red)}
```

- [ ] **Step 3: Add the header button**

In `index.php`, find:
```php
    <?php if ($cfg['kuldemengde_enabled'] ?? false): ?>
    <!-- Starter skjult — JS viser knappen kun når kuldemengde-serien har data (i sesong) -->
    <button class="frost-btn" id="frost-btn" onclick="toggleKmOverlay()" style="display:none"
            title="Vis kuldemengde (sum av døgnmiddeltemperaturer under 0 °C siden 1. oktober)">
      ❄ Kulde
    </button>
    <?php endif; ?>
    <button class="fetch-btn" id="fetch-btn" onclick="triggerFetch()" title="Hent nye bilder fra Copernicus">
```
Replace with:
```php
    <?php if ($cfg['kuldemengde_enabled'] ?? false): ?>
    <!-- Starter skjult — JS viser knappen kun når kuldemengde-serien har data (i sesong) -->
    <button class="frost-btn" id="frost-btn" onclick="toggleKmOverlay()" style="display:none"
            title="Vis kuldemengde (sum av døgnmiddeltemperaturer under 0 °C siden 1. oktober)">
      ❄ Kulde
    </button>
    <?php endif; ?>
    <?php if ($cfg['isvekst_enabled'] ?? false): ?>
    <!-- Starter skjult — JS viser knappen kun når isvekst-serien har data. Eksperimentell. -->
    <button class="isvekst-btn" id="isvekst-btn" onclick="openIsvekstChart()" style="display:none"
            title="Vis beregnet isvekst (energibalansemodell, eksperimentell — kun Lødengfjorden)">
      🧊 Isvekst
    </button>
    <?php endif; ?>
    <button class="fetch-btn" id="fetch-btn" onclick="triggerFetch()" title="Hent nye bilder fra Copernicus">
```

- [ ] **Step 4: Add JS consts and state**

In `index.php`, find:
```js
const KULDEMENGDE_ENABLED = <?= json_encode($cfg['kuldemengde_enabled'] ?? false) ?>;
const KM_WARN_FRACTION = 0.05;  // andel av terskelen som gjenstår → oransje tall
```
Replace with:
```js
const KULDEMENGDE_ENABLED = <?= json_encode($cfg['kuldemengde_enabled'] ?? false) ?>;
const ISVEKST_ENABLED = <?= json_encode($cfg['isvekst_enabled'] ?? false) ?>;
const KM_WARN_FRACTION = 0.05;  // andel av terskelen som gjenstår → oransje tall
```

Find:
```js
let kmActive = false;         // kuldemengde-overlegg — heller ikke persistert
let kmLocations = [];         // steder med ikke-tom kuldemengde-serie (fra ?action=list)
```
Replace with:
```js
let kmActive = false;         // kuldemengde-overlegg — heller ikke persistert
let kmLocations = [];         // steder med ikke-tom kuldemengde-serie (fra ?action=list)
let isvekstData = null;       // { name, series, ... } for Lødengfjorden, eller null
```

- [ ] **Step 5: Populate `isvekstData` in `loadImages()`**

In `index.php`, find:
```js
    kmLocations = ((KULDEMENGDE_ENABLED && data.kuldemengde?.locations) || [])
      .map(l => ({ ...l, dates: Object.keys(l.series || {}).sort() }))
      .filter(l => l.dates.length > 0);
    const frostBtn = document.getElementById('frost-btn');
    if (frostBtn) frostBtn.style.display = kmLocations.length ? '' : 'none';
```
Replace with:
```js
    kmLocations = ((KULDEMENGDE_ENABLED && data.kuldemengde?.locations) || [])
      .map(l => ({ ...l, dates: Object.keys(l.series || {}).sort() }))
      .filter(l => l.dates.length > 0);
    const frostBtn = document.getElementById('frost-btn');
    if (frostBtn) frostBtn.style.display = kmLocations.length ? '' : 'none';

    // Isvekst: eksperimentell, kun ett sted (Lødengfjorden) i v1
    const ivLoc = (ISVEKST_ENABLED && data.isvekst?.locations?.[0]) || null;
    isvekstData = (ivLoc && Object.keys(ivLoc.series || {}).length) ? ivLoc : null;
    const isvekstBtn = document.getElementById('isvekst-btn');
    if (isvekstBtn) isvekstBtn.style.display = isvekstData ? '' : 'none';
```

- [ ] **Step 6: Add `openIsvekstChart()`**

In `index.php`, find the end of `openKmChart()`:
```js
  chart.addEventListener('mouseleave', () => {
    xhair.setAttribute('visibility', 'hidden');
    hdot.setAttribute('visibility', 'hidden');
    tip.style.display = 'none';
  });
}

function buildNoDataFrame(label, date) {
```
Replace with:
```js
  chart.addEventListener('mouseleave', () => {
    xhair.setAttribute('visibility', 'hidden');
    hdot.setAttribute('visibility', 'hidden');
    tip.style.display = 'none';
  });
}

// Isvekst-graf (energibalansemodell, eksperimentell): kumulativ mm is
// 1.okt-31.des, med en daglig vekst/smelte-strip under. Egen, isolert
// funksjon (deler kun modal-chrome-CSS-klasser med openKmChart(), ikke
// tegnelogikk) — enkelt å fjerne igjen om eksperimentet forkastes.
function openIsvekstChart() {
  if (!isvekstData) return;
  const loc = isvekstData;
  const dates = Object.keys(loc.series).sort();
  if (!dates.length) return;
  const pts = dates.map(d => ({ d, ...loc.series[d] }));

  const W = 520, H = 220, ML = 36, MR = 14, MT = 12, MB = 24;
  const iw = W - ML - MR, ih = H - MT - MB;
  const start = new Date(dates[0] + 'T00:00:00Z');
  const end   = new Date(dates[dates.length - 1] + 'T00:00:00Z');
  const t0 = start.getTime(), t1 = end.getTime();
  const rawMax = Math.max(...pts.map(p => p.cum_mm), 10) * 1.1;
  const tq = rawMax / 4, tp = Math.pow(10, Math.floor(Math.log10(tq)));
  const step = tp * [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find(m => m * tp >= tq);
  const yMax = step * 4;
  const px = d => ML + iw * (new Date(d + 'T00:00:00Z').getTime() - t0) / (t1 - t0 || 1);
  const py = v => MT + ih * (1 - v / yMax);
  const fmtDM = d => `${d.slice(8,10)}.${d.slice(5,7)}`;
  const fmtMm = v => v.toFixed(1).replace('.', ',');

  let svg = '';
  for (let i = 1; i <= 4; i++) {
    const v = yMax * i / 4, y = py(v);
    svg += `<line class="grid" x1="${ML}" y1="${y}" x2="${W - MR}" y2="${y}"/>` +
           `<text class="axis-lbl" x="${ML - 5}" y="${y + 3}" text-anchor="end">${Math.round(v)}</text>`;
  }
  svg += `<line class="grid" x1="${ML}" y1="${py(0)}" x2="${W - MR}" y2="${py(0)}"/>`;
  const m = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth(), 1));
  if (m < start) m.setUTCMonth(m.getUTCMonth() + 1);
  for (; m <= end; m.setUTCMonth(m.getUTCMonth() + 1)) {
    const d = m.toISOString().slice(0, 10);
    svg += `<text class="axis-lbl" x="${px(d)}" y="${H - 8}" text-anchor="middle">${fmtDM(d)}</text>`;
  }
  const areaPath = `M${px(pts[0].d).toFixed(1)},${py(0).toFixed(1)} ` +
    pts.map(p => `L${px(p.d).toFixed(1)},${py(p.cum_mm).toFixed(1)}`).join(' ') +
    ` L${px(pts[pts.length - 1].d).toFixed(1)},${py(0).toFixed(1)} Z`;
  svg += `<path class="area" d="${areaPath}"/>`;
  svg += `<path class="series" d="${pts.map((p, i) => `${i ? 'L' : 'M'}${px(p.d).toFixed(1)} ${py(p.cum_mm).toFixed(1)}`).join(' ')}"/>`;
  const last = pts[pts.length - 1];
  svg += `<circle class="end-dot" cx="${px(last.d)}" cy="${py(last.cum_mm)}" r="3.5"/>` +
         `<text class="end-lbl" x="${px(last.d) - 6}" y="${py(last.cum_mm) - 8}" text-anchor="end">${fmtMm(last.cum_mm)} mm</text>`;
  svg += `<line class="xhair" y1="${MT}" y2="${MT + ih}" visibility="hidden"/>` +
         `<circle class="hover-dot" r="4" visibility="hidden"/>`;

  const maxAbs = Math.max(...pts.map(p => Math.abs(p.growth_mm)), 1);
  const bw = iw / pts.length;
  let stripSvg = `<line class="zero" x1="${ML}" y1="17" x2="${W - MR}" y2="17"/>`;
  pts.forEach((p, i) => {
    const x = ML + i * bw;
    const h = (Math.abs(p.growth_mm) / maxAbs) * 14;
    const y = p.growth_mm >= 0 ? 17 - h : 17;
    stripSvg += `<rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${Math.max(bw - 0.5, 0.5).toFixed(1)}" height="${h.toFixed(1)}" class="${p.growth_mm >= 0 ? 'bar-growth' : 'bar-melt'}"/>`;
  });

  const backdrop = document.createElement('div');
  backdrop.className = 'km-modal-backdrop';
  const modal = document.createElement('div');
  modal.className = 'km-modal';
  modal.innerHTML =
    `<button class="km-modal-close" title="Lukk">×</button>` +
    `<h3>🧊 ${loc.name}</h3>` +
    `<div class="km-modal-sub">Beregnet isvekst (mm) ${fmtDM(dates[0])}–${fmtDM(dates[dates.length - 1])}` +
    ` · eksperimentell energibalansemodell</div>` +
    `<svg class="isvekst-chart" viewBox="0 0 ${W} ${H}" role="img" aria-label="Beregnet istykkelse, ${loc.name}">${svg}</svg>` +
    `<svg class="isvekst-strip" viewBox="0 0 ${W} 34">${stripSvg}</svg>` +
    `<div class="isvekst-legend"><span><i class="g"></i>vekstdøgn</span><span><i class="r"></i>smeltedøgn</span></div>` +
    `<div class="km-tip"></div>`;
  backdrop.appendChild(modal);
  document.body.appendChild(backdrop);

  const close = () => { document.body.removeChild(backdrop); document.removeEventListener('keydown', onKey, true); };
  const onKey = e => { if (e.key !== 'Escape') return; e.stopPropagation(); close(); };
  backdrop.addEventListener('click', close);
  modal.addEventListener('click', e => e.stopPropagation());
  modal.querySelector('.km-modal-close').addEventListener('click', close);
  document.addEventListener('keydown', onKey, true);

  const chart = modal.querySelector('.isvekst-chart');
  const tip   = modal.querySelector('.km-tip');
  const xhair = chart.querySelector('.xhair');
  const hdot  = chart.querySelector('.hover-dot');
  chart.addEventListener('mousemove', e => {
    const r = chart.getBoundingClientRect();
    const mx = (e.clientX - r.left) * W / r.width;
    let best = pts[0], bd = Infinity;
    for (const p of pts) { const d = Math.abs(px(p.d) - mx); if (d < bd) { bd = d; best = p; } }
    const cx = px(best.d), cy = py(best.cum_mm);
    xhair.setAttribute('x1', cx); xhair.setAttribute('x2', cx);
    xhair.removeAttribute('visibility');
    hdot.setAttribute('cx', cx); hdot.setAttribute('cy', cy);
    hdot.removeAttribute('visibility');
    const sign = best.growth_mm >= 0 ? '+' : '';
    tip.textContent = `${fmtDM(best.d)} · ${fmtMm(best.cum_mm)} mm (${sign}${fmtMm(best.growth_mm)}) · ${best.temp}°C ${best.sky}`;
    tip.style.display = 'block';
    const mr = modal.getBoundingClientRect();
    tip.style.left = Math.min(e.clientX - mr.left + 12, mr.width - tip.offsetWidth - 8) + 'px';
    tip.style.top  = (e.clientY - mr.top - 28) + 'px';
  });
  chart.addEventListener('mouseleave', () => {
    xhair.setAttribute('visibility', 'hidden');
    hdot.setAttribute('visibility', 'hidden');
    tip.style.display = 'none';
  });
}

function buildNoDataFrame(label, date) {
```

- [ ] **Step 7: Visual verification in a browser**

Ensure `data/isvekst.json` has real data (from Task 4/5's `--isvekst=2025-12-31` run). Temporarily set `'isvekst_enabled' => true,` in `config.php`. Confirm Apache/XAMPP is running, then open `http://localhost/sentinel/index.php` (adjust path to match the local vhost).

Check:
1. The `🧊 Isvekst` button appears in the header.
2. Clicking it opens a modal with the cumulative-mm line chart and the growth/melt strip below it.
3. Hovering the chart shows a tooltip with date, mm, temp, and sky category.
4. Escape, clicking outside, and the × button all close the modal.
5. Resize the browser to a narrow (mobile) width and confirm the button doesn't overflow the header.

Then **revert** `'isvekst_enabled' => false,` in `config.php`.

- [ ] **Step 8: Commit**

```bash
git add index.php
git commit -m "isvekst: header-knapp + isolert modal-graf (kumulativ mm + vekst/smelte-strip)"
```

---

### Task 8: Documentation — `help.php` and `CLAUDE.md`

**Files:**
- Modify: `help.php` — insert new `.row`/`.note` after the existing kuldemengde section (currently ending `help.php:330`, before `</section>` at `help.php:331`)
- Modify: `CLAUDE.md` — add Filer table row, Dataflyt step, new `## Isvekst` section, frontend table row

**Interfaces:**
- Consumes: nothing (documentation only)
- Produces: nothing (final task)

- [ ] **Step 1: Add the isvekst row to `help.php`**

In `help.php`, find:
```php
    <div class="note">
      I motsetning til 🌡&nbsp;°C-overlegget er dette <strong>lufttemperatur</strong> målt 2 meter over
      bakken (samme som YR.no), fra nærmeste målestasjon (FV120 Rødsund ved Rødsundbrua for
      Lødengfjorden, Rygge for de øvrige stedene) — ikke satellittmålt overflatetemperatur.
    </div>
  </section>
```
Replace with:
```php
    <div class="note">
      I motsetning til 🌡&nbsp;°C-overlegget er dette <strong>lufttemperatur</strong> målt 2 meter over
      bakken (samme som YR.no), fra nærmeste målestasjon (FV120 Rødsund ved Rødsundbrua for
      Lødengfjorden, Rygge for de øvrige stedene) — ikke satellittmålt overflatetemperatur.
    </div>
    <div class="row">
      <div class="key">🧊 Isvekst</div>
      <div class="desc">
        Eksperimentell — vises kun bak et eget flagg, foreløpig kun for Lødengfjorden. Et anslag
        på hvor tykk isen har blitt siden 1.&nbsp;oktober, beregnet med en energibalansemodell
        (sol, utstråling, motstråling, vind, varmeledning og avdunstning — fra svensk
        isfartslitteratur) i stedet for bare en temperatursum. Klikk <kbd>🧊&nbsp;Isvekst</kbd>-knappen
        i headeren for å åpne en graf over beregnet istykkelse (mm) fra 1.&nbsp;oktober til
        31.&nbsp;desember, med en daglig vekst-/smeltestripe under grafen.
      </div>
    </div>
    <div class="note">
      Skydekke i modellen er lånt fra Rygge-stasjonen (Lødengs egen målestasjon måler det ikke),
      luftfuktighet er utledet fra duggpunkt, og vind er et døgnsnitt av rå observasjoner —
      tallene er derfor et <strong>anslag</strong>, ikke en presis måling.
    </div>
  </section>
```

- [ ] **Step 2: Verify `help.php` syntax**

Run: `powershell -Command "& 'C:\xampp\php\php.exe' -l help.php"`
Expected: `No syntax errors detected in help.php`

- [ ] **Step 3: Update `CLAUDE.md`**

In `CLAUDE.md`, find:
```
| `data/kuldemengde.json` | Kuldemengde-serie per sted for inneværende sesong (skrives av fetch.php, leveres via `?action=list`) |
```
Replace with:
```
| `data/kuldemengde.json` | Kuldemengde-serie per sted for inneværende sesong (skrives av fetch.php, leveres via `?action=list`) |
| `data/isvekst.json` | Eksperimentell isvekst-serie (energibalansemodell) for Lødengfjorden, 1.okt-31.des (skrives av fetch.php, leveres via `?action=list`) |
```

Find:
```
11. Når `kuldemengde_enabled` er `true`: uavhengig Frost-oppdatering av `data/kuldemengde.json` (se eget avsnitt under) — påvirker aldri bildepipelinene om noe feiler
```
Replace with:
```
11. Når `kuldemengde_enabled` er `true`: uavhengig Frost-oppdatering av `data/kuldemengde.json` (se eget avsnitt under) — påvirker aldri bildepipelinene om noe feiler
12. Når `isvekst_enabled` er `true`: uavhengig, eksperimentell Frost-basert isvekst-beregning for Lødengfjorden (se eget avsnitt under) — påvirker aldri bildepipelinene eller kuldemengde om noe feiler
```

Find:
```
| Kuldemengde-overlegg | `❄ Kulde`-knapp (rendres når `kuldemengde_enabled`, men vises av JS kun når sesongens serie har data) slår av/på stedsbaserte etiketter med akkumulert kuldemengde per slidedato — to linjer på hvit gjennomsiktig bakgrunn: «❄ Lødengfjorden» og «23/47,3» med store tall i statusfargen (grønn når terskelen `km_needed` er passert, oransje når ≤ 5 % av terskelen gjenstår, ellers rød) — plassert på stedets koordinat, skjules ved zoom, nullstilles ved sideinnlasting; klikk på etiketten åpner sesonggraf-modal (se kuldemengde-avsnittet) |
```
Replace with:
```
| Kuldemengde-overlegg | `❄ Kulde`-knapp (rendres når `kuldemengde_enabled`, men vises av JS kun når sesongens serie har data) slår av/på stedsbaserte etiketter med akkumulert kuldemengde per slidedato — to linjer på hvit gjennomsiktig bakgrunn: «❄ Lødengfjorden» og «23/47,3» med store tall i statusfargen (grønn når terskelen `km_needed` er passert, oransje når ≤ 5 % av terskelen gjenstår, ellers rød) — plassert på stedets koordinat, skjules ved zoom, nullstilles ved sideinnlasting; klikk på etiketten åpner sesonggraf-modal (se kuldemengde-avsnittet) |
| Isvekst-graf | `🧊 Isvekst`-knapp (rendres når `isvekst_enabled`, vises av JS kun når serien har data) åpner en modal med en kumulativ mm-graf (1.okt-31.des) pluss en daglig vekst/smelte-strip — eksperimentell, kun Lødengfjorden i v1, se eget avsnitt |
```

Then append a new documentation section at the end of `CLAUDE.md`, after the final `## Cron` section content (i.e. immediately before `## Infrastruktur`):

Find:
```
Kjører kl. 00, 06, 12, 18. Trygt å kjøre oftere enn én gang daglig — `fetch.php` hopper over datoer som allerede er lastet ned (skip-liste basert på fil-på-disk), så hyppigere kjøring fanger bare opp nye S2/S1/Landsat/S3-scener raskere.

---

## Infrastruktur
```
Replace with:
```
Kjører kl. 00, 06, 12, 18. Trygt å kjøre oftere enn én gang daglig — `fetch.php` hopper over datoer som allerede er lastet ned (skip-liste basert på fil-på-disk), så hyppigere kjøring fanger bare opp nye S2/S1/Landsat/S3-scener raskere.

---

## Isvekst (energibalansemodell) — bak `isvekst_enabled`-flagget

Eksperimentell v1: et anslag på beregnet istykkelse (mm) for **kun Lødengfjorden**, **kun vinduet 1. oktober–31. desember** (ikke hele okt–mai-sesongen som kuldemengde) — bevisst begrenset omfang inntil resultatene er evaluert over en reell sesong. Formelen er en full energibalansemodell fra svensk isfartslitteratur (`.claude/skills/isprognosemodell_skill/isprognosemodell_skill.md`, *Islära*): varmetransport (W/m²) fra sol, utstråling, motstrålning, vind, varmeledning og avdunstning, konvertert til mm istilvekst/time via en empirisk konstant. Full utledning og datakilde-antakelser i `docs/superpowers/specs/2026-07-23-isvekst-lodeng-design.md`.

**Datakilder (alt fra Frost, samme mønster som kuldemengde):** lufttemperatur og duggpunkt (→ luftfuktighet via Magnus-formel) fra Lødengs egen stasjon SN17400, vind som døgnsnitt av rå 10-minutters observasjoner (SN17400 har intet ferdig døgnsnitt for vind), skydekke lånt fra **SN17150** (Rygge) siden SN17400 ikke måler det. Disse er bevisste tilnærminger, ikke optimale — verdt å revurdere etter at modellen har kjørt en reell sesong.

**`updateIsvekst()`** i `fetch.php` regner ut og skriver hele 1.okt–31.des-vinduet på nytt ved hver kjøring (idempotent, billig siden alle inputs er døgnaggregater eller lette snitt), lagres i `data/isvekst.json`. Akkumulert istykkelse klemmes til aldri under 0, samme prinsipp som kuldemengde. CLI: `php fetch.php --isvekst=YYYY-MM-DD`.

**Ingen retroaktiv backfill utover det som allerede er implementert**: siden hele vinduet regnes på nytt hver gang (i motsetning til Landsat-termisk sin filnavn-baserte skip-logikk), får en sesong automatisk fullt historikk fra 1. oktober så snart flagget slås på og cron kjører — det er ikke noe eget backfill-steg å tenke på her.

---

## Infrastruktur
```

- [ ] **Step 4: Commit**

```bash
git add help.php CLAUDE.md
git commit -m "isvekst: dokumenter i help.php og CLAUDE.md"
```
