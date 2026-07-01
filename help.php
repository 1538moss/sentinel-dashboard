<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bruksanvisning — Sentinel Dashboard</title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}

:root{
  --bg:#07070f;
  --surface:rgba(255,255,255,.04);
  --border:rgba(100,180,255,.15);
  --accent:#38bdf8;
  --accent-glow:rgba(56,189,248,.25);
  --text:#cdd9e8;
  --muted:rgba(205,217,232,.45);
  --font-mono:'SF Mono','Fira Code','Cascadia Code',monospace;
  --font-ui:system-ui,-apple-system,sans-serif;
}

html,body{min-height:100%;background:var(--bg);color:var(--text);font-family:var(--font-ui)}

.hdr{
  position:sticky;top:0;z-index:10;
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 24px;
  background:rgba(7,7,15,.92);
  border-bottom:1px solid var(--border);
  backdrop-filter:blur(8px);
}
.hdr-logo{
  display:flex;align-items:center;gap:10px;
  font-family:var(--font-mono);font-size:11px;font-weight:600;
  letter-spacing:.35em;text-transform:uppercase;color:var(--accent);
}
.pulse{
  width:7px;height:7px;border-radius:50%;background:var(--accent);
  box-shadow:0 0 6px var(--accent);
  animation:pulse 2s ease-in-out infinite;
}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.85)}}

.back-btn{
  font-family:var(--font-mono);font-size:10px;letter-spacing:.2em;
  text-transform:uppercase;color:var(--accent);text-decoration:none;
  border:1px solid var(--border);padding:5px 12px;
  transition:all .2s;
}
.back-btn:hover{background:var(--accent-glow);border-color:var(--accent)}

.content{max-width:680px;margin:0 auto;padding:48px 24px 80px}

h1{
  font-family:var(--font-mono);font-size:13px;font-weight:600;
  letter-spacing:.25em;text-transform:uppercase;color:var(--accent);
  margin-bottom:40px;
}

section{margin-bottom:40px}

h2{
  font-family:var(--font-mono);font-size:10px;font-weight:600;
  letter-spacing:.3em;text-transform:uppercase;color:var(--muted);
  border-bottom:1px solid var(--border);padding-bottom:10px;margin-bottom:20px;
}

.row{
  display:flex;align-items:baseline;gap:16px;
  padding:10px 0;border-bottom:1px solid rgba(255,255,255,.04);
}
.row:last-child{border-bottom:none}

.key{
  flex-shrink:0;min-width:110px;
  font-family:var(--font-mono);font-size:11px;color:var(--accent);
}
kbd{
  display:inline-block;
  background:rgba(56,189,248,.08);border:1px solid var(--border);
  padding:2px 7px;font-family:var(--font-mono);font-size:10px;
  color:var(--accent);border-radius:2px;white-space:nowrap;
}
.desc{font-size:13px;color:var(--text);line-height:1.6}
.desc small{color:var(--muted);font-size:12px}

.note{
  margin-top:12px;
  background:rgba(56,189,248,.05);border-left:2px solid var(--accent);
  padding:12px 16px;font-size:12px;color:var(--muted);line-height:1.7;
}
.note strong{color:var(--text)}

.note-pro{
  margin-top:12px;
  background:rgba(168,85,247,.05);border-left:2px solid #a855f7;
  padding:12px 16px;font-size:12px;color:var(--muted);line-height:1.7;
}
.note-pro strong{color:var(--text)}

.cloud-good{color:#34d399}
.cloud-ok{color:#fbbf24}
.cloud-bad{color:#f87171}
.pro-tag{
  display:inline-block;
  font-family:var(--font-mono);font-size:9px;letter-spacing:.15em;text-transform:uppercase;
  color:#a855f7;border:1px solid rgba(168,85,247,.4);padding:1px 6px;
  vertical-align:middle;margin-left:4px;
}
</style>
</head>
<body>

<header class="hdr">
  <div class="hdr-logo">
    <div class="pulse"></div>
    Sentinel Dashboard
  </div>
  <a class="back-btn" href="index.php">← Tilbake</a>
</header>

<div class="content">
  <h1>Bruksanvisning</h1>

  <section>
    <h2>Navigasjon</h2>
    <div class="row">
      <div class="key"><kbd>‹</kbd> <kbd>›</kbd> knapper</div>
      <div class="desc">Bla ett bilde frem eller tilbake i tid</div>
    </div>
    <div class="row">
      <div class="key"><kbd>←</kbd> <kbd>→</kbd> piltaster</div>
      <div class="desc">Samme som knappene — fungerer på tastatur</div>
    </div>
    <div class="row">
      <div class="key"><kbd>Home</kbd></div>
      <div class="desc">Hopp til nyeste bilde</div>
    </div>
    <div class="row">
      <div class="key"><kbd>End</kbd></div>
      <div class="desc">Hopp til eldste bilde</div>
    </div>
    <div class="row">
      <div class="key">Tidslinje</div>
      <div class="desc">Klikk eller trykk på et miniatyrbilde i bunnen for å hoppe direkte til den datoen</div>
    </div>
  </section>

  <section>
    <h2>Visningsmodus</h2>
    <div class="row">
      <div class="key">Std-modus</div>
      <div class="desc">
        Standard visning — ett fullskjermbilde per dag fra <strong>Sentinel-2</strong> (optisk kamera).
        Header-temaet er <span style="color:#38bdf8">cyan</span>.
      </div>
    </div>
    <div class="row">
      <div class="key">Pro-modus <span class="pro-tag">Pro</span></div>
      <div class="desc">
        To bilder side om side per dag — <strong>Optisk</strong> (Sentinel-2) til venstre og
        <strong>Radar</strong> (Sentinel-1 SAR) til høyre.
        Header-temaet skifter til <span style="color:#a855f7">lilla</span> som visuell indikator.
        På mobil stables bildene vertikalt.
      </div>
    </div>
    <div class="row">
      <div class="key">Pro-knappen</div>
      <div class="desc">
        Klikk <kbd>Pro</kbd> for å aktivere pro-modus. Knappen viser da <kbd>Std</kbd> for å gå tilbake.
        Aktuell modus vises også ved siden av logonavnet: <strong>[PRO mode]</strong> eller <strong>[STD mode]</strong>.
        Valget huskes i nettleseren.
      </div>
    </div>
    <div class="row">
      <div class="key">Ingen data</div>
      <div class="desc">
        Mangler optisk bilde for en dato vises bakgrunnskartet med teksten «Ingen satellittbilde».
        Mangler radardata vises tilsvarende «Ingen radardata» i høyre panel.
      </div>
    </div>
  </section>

  <section>
    <h2>Zoom og panorering</h2>
    <div class="row">
      <div class="key">Klikk / trykk</div>
      <div class="desc">Zoom 4× inn på det stedet du klikket. Klikk igjen for å zoome ut.</div>
    </div>
    <div class="row">
      <div class="key">Dra</div>
      <div class="desc">Når bildet er zoomet inn, dra for å panorere — fungerer med både mus og touch</div>
    </div>
    <div class="row">
      <div class="key"><kbd>Escape</kbd></div>
      <div class="desc">Tilbakestill zoom</div>
    </div>
  </section>

  <section>
    <h2>Skydekke</h2>
    <div class="row">
      <div class="key"><span class="cloud-good">● Grønn</span></div>
      <div class="desc">Under 20 % skydekke — godt bilde</div>
    </div>
    <div class="row">
      <div class="key"><span class="cloud-ok">● Gul</span></div>
      <div class="desc">20–50 % skydekke — delvis skyet</div>
    </div>
    <div class="row">
      <div class="key"><span class="cloud-bad">● Rød</span></div>
      <div class="desc">
        Over 50 % skydekke — mye skyer i bildet.
        Ved høyt skydekke vises en konturoverlayer av <strong>Vansjø</strong> oppå bildet som referanse.
      </div>
    </div>
    <div class="row">
      <div class="key">Kart</div>
      <div class="desc">Dager uten satellittbilde vises som «Ingen satellittbilde» over bakgrunnskartet</div>
    </div>
    <div class="note">
      <strong>Merk:</strong> Skydekke-prosenten rapporteres av Sentinel for hele satellittscenen —
      ikke bare Vansjø-området spesifikt. Et bilde kan vise høyt skydekke selv om akkurat
      innsjøen er relativt klar, og omvendt.
      Sentinel-1 radar er skyuavhengig og leverer alltid data uansett vær.
    </div>
  </section>

  <section>
    <h2>Bildetyper</h2>
    <div class="row">
      <div class="key">Falskt fargebilde</div>
      <div class="desc">
        Nær-infrarød kombinasjon (S2) — vegetasjon vises <strong style="color:#f87171">rød</strong>,
        vann <strong style="color:#38bdf8">mørkt blått</strong>.
        <br><small>Egnet for å se plantevekst og snødekke tydelig</small>
      </div>
    </div>
    <div class="row">
      <div class="key">Naturlig farge</div>
      <div class="desc">
        Synlig lys RGB (S2) — ser ut som et vanlig luftfoto
      </div>
    </div>
    <div class="row">
      <div class="key">SAR-radar <span class="pro-tag">Pro</span></div>
      <div class="desc">
        Sentinel-1 SAR-radardata (VV-polarisering) vist med <strong>jet-fargeskala</strong> —
        mørk blå ved lavt tilbakespredningssignal (glatt vannoverflate), grønt og rødt
        ved høyere signal (vegetasjon, bebyggelse).
        <br><small>Fungerer i all slags vær, også gjennom skyer og om natten</small>
      </div>
    </div>
    <div class="note-pro">
      <strong>Jet-fargeskala:</strong> blå → cyan → grønn → gul → oransje → rød.
      Vansjø vil typisk vises i mørk blå siden vannoverflaten gir lite tilbakespredning.
      Skog og bebyggelse rundt innsjøen vises i varmere farger.
    </div>
  </section>

  <section>
    <h2>Neste bilde</h2>
    <div class="row">
      <div class="key">Header-tekst</div>
      <div class="desc">
        Øverst til høyre vises en estimert dato for neste Sentinel-2-satellittbilde.
        Hvis et nyere bilde allerede er tilgjengelig for nedlasting, vises det i
        <span class="cloud-good">grønt</span> med dato og skydekke.
        Estimatet baserer seg på gjennomsnittlig intervall mellom de siste bildene.
      </div>
    </div>
  </section>

  <section>
    <h2>Filtrering</h2>
    <div class="row">
      <div class="key">☁ &lt;50%-knappen</div>
      <div class="desc">
        Viser kun satellittbilder med <strong>under 50 % skydekke</strong>.
        Kartplaceholders og skydekte dager skjules.
        Knappen lyser grønt når filteret er aktivt — klikk igjen for å vise alle datoer.
        <br><small>I pro-modus følger radar-panelet alltid det optiske bildet for samme dato</small>
      </div>
    </div>
  </section>

  <section>
    <h2>Om Sentinel-satellittene</h2>
    <div class="row">
      <div class="key">Sentinel-2 (optisk)</div>
      <div class="desc">
        To søstersatellitter — 2A og 2B — som fotograferer jordoverflaten i synlig og
        nær-infrarødt lys. Over Norge passerer en av dem omtrent <strong>hvert 2–3. dag</strong>,
        men skydekke avgjør om bildet er brukbart.
      </div>
    </div>
    <div class="row">
      <div class="key">Sentinel-1 (radar) <span class="pro-tag">Pro</span></div>
      <div class="desc">
        SAR-radar (Synthetic Aperture Radar) som sender ut mikrobølger og måler tilbakespredning
        fra overflaten. Uavhengig av skydekke og dagslys — leverer data hele døgnet, hele året.
        Over Norge er typisk opptaksfrekvens <strong>hvert 6. dag</strong>.
      </div>
    </div>
    <div class="note">
      Dager uten bilde (verken optisk eller radar) vises som kart med teksten «Ingen satellittbilde».
      Alle data fra European Space Agency (ESA) / Copernicus Data Space.
      Inneholder modifiserte Copernicus Sentinel-data.
    </div>
  </section>

  <section>
    <h2>Hent nye bilder</h2>
    <div class="row">
      <div class="key">↓ Hent-knappen</div>
      <div class="desc">
        Henter tilgjengelige bilder fra Copernicus for de siste 14 dagene —
        både Sentinel-2 optisk og Sentinel-1 radar (i pro-modus).
        Nye bilder lastes ned automatisk hver dag kl. 07 via en planlagt jobb på serveren.
        <br><small>Knappen er nyttig for å trigge en manuell oppdatering</small>
      </div>
    </div>
    <div class="row">
      <div class="key">Automatisk rydding</div>
      <div class="desc">
        Bilder og thumbnails eldre enn <strong>30 dager</strong> slettes automatisk
        hver gang nye bilder hentes — gjelder både S2 og S1.
      </div>
    </div>
    <div class="row">
      <div class="key">Hente-logg</div>
      <div class="desc">
        Alle kjøringer loggføres automatisk i <code style="font-family:var(--font-mono);font-size:11px;color:var(--accent)">data/fetch.log</code>
        med tidsstempel, hvilke bilder som ble lastet ned, eventuelle feil og hva som ble slettet.
      </div>
    </div>
  </section>
</div>

</body>
</html>
