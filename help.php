<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bruksanvisning — Vansjø · Sentinel-arkiv</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/fonts/fonts.css">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}

:root{
  --paper:#E7E3D6;      /* arkivpapir */
  --paper-2:#DDD7C6;    /* mørkere papir */
  --ink:#191A1C;        /* trykksverte */
  --muted:rgba(25,26,28,.55);
  --line:rgba(25,26,28,.8);
  --hair:rgba(25,26,28,.25);
  --blue:#1A5F8F;       /* kartblå */
  --green:#256B43;
  --ochre:#8F6400;
  --red:#A93226;
  --violet:#5F4494;     /* radar/PRO */
  --landsat:#B5651D;    /* Landsat/USGS — brent oransje */
  --thermal:#C1440E;    /* LST-temperaturoverlegg (Sentinel-3) */
  --font-mono:'IBM Plex Mono','Cascadia Code',Consolas,monospace;
  --font-display:'Big Shoulders Display','Arial Narrow',Impact,sans-serif;
  --font-ui:system-ui,-apple-system,sans-serif;
}

html{color-scheme:light}
html,body{min-height:100%;background:var(--paper);color:var(--ink);font-family:var(--font-ui)}

.hdr{
  position:sticky;top:0;z-index:10;
  display:flex;align-items:center;justify-content:space-between;gap:16px;
  padding:12px 20px;
  background:var(--paper);
  border-bottom:1px solid var(--line);
}
.hdr-logo{display:flex;flex-direction:column;gap:2px}
.hdr-title{
  font-family:var(--font-display);font-size:26px;font-weight:700;line-height:.9;
  letter-spacing:.06em;text-transform:uppercase;color:var(--ink);
}
.hdr-sub{
  font-family:var(--font-mono);font-size:9px;letter-spacing:.3em;
  text-transform:uppercase;color:var(--muted);white-space:nowrap;
}

.back-btn{
  font-family:var(--font-mono);font-size:10px;letter-spacing:.18em;
  text-transform:uppercase;color:var(--ink);text-decoration:none;
  border:1px solid var(--ink);padding:6px 12px;
  transition:background .15s,color .15s;
}
.back-btn:hover{background:var(--ink);color:var(--paper)}
.back-btn:focus-visible{outline:2px solid var(--blue);outline-offset:2px}

.content{max-width:680px;margin:0 auto;padding:48px 24px 80px}

h1{
  font-family:var(--font-display);font-size:34px;font-weight:600;
  letter-spacing:.06em;text-transform:uppercase;color:var(--ink);
  border-bottom:3px double var(--ink);padding-bottom:12px;
  margin-bottom:40px;
}

section{margin-bottom:40px}

h2{
  font-family:var(--font-mono);font-size:10px;font-weight:600;
  letter-spacing:.3em;text-transform:uppercase;color:var(--muted);
  border-bottom:1px solid var(--line);padding-bottom:10px;margin-bottom:20px;
}

.row{
  display:flex;align-items:baseline;gap:16px;
  padding:10px 0;border-bottom:1px solid var(--hair);
}
.row:last-child{border-bottom:none}

.key{
  flex-shrink:0;min-width:110px;
  font-family:var(--font-mono);font-size:11px;font-weight:500;color:var(--blue);
}
kbd{
  display:inline-block;
  background:var(--paper-2);border:1px solid var(--hair);
  border-bottom-color:var(--line);
  padding:2px 7px;font-family:var(--font-mono);font-size:10px;
  color:var(--ink);border-radius:2px;white-space:nowrap;
}
.desc{font-size:13px;color:var(--ink);line-height:1.6}
.desc small{color:var(--muted);font-size:12px}

.note{
  margin-top:12px;
  background:var(--paper-2);border-left:4px solid var(--blue);
  padding:12px 16px;font-size:12px;color:var(--muted);line-height:1.7;
}
.note strong{color:var(--ink)}

.note-pro{
  margin-top:12px;
  background:var(--paper-2);border-left:4px solid var(--violet);
  padding:12px 16px;font-size:12px;color:var(--muted);line-height:1.7;
}
.note-pro strong{color:var(--ink)}

.cloud-good{color:var(--green)}
.cloud-ok{color:var(--ochre)}
.cloud-bad{color:var(--red)}
.pro-tag{
  display:inline-block;
  font-family:var(--font-mono);font-size:9px;letter-spacing:.15em;text-transform:uppercase;
  color:var(--violet);border:1px solid var(--violet);padding:1px 6px;
  vertical-align:middle;margin-left:4px;
}
</style>
</head>
<body>

<header class="hdr">
  <div class="hdr-logo">
    <div class="hdr-title">Vansjø</div>
    <div class="hdr-sub">Sentinel-satellittarkiv</div>
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
      <div class="desc">
        Klikk eller trykk på et miniatyrbilde i bunnen for å hoppe direkte til den datoen.
        I pro-modus vises små bokstaver øverst til høyre på miniatyrbildene:
        <span style="background:var(--ink);color:var(--paper);font-family:var(--font-mono);font-size:9px;font-weight:600;padding:1px 4px">O</span> = optisk bilde (Sentinel-2) &nbsp;
        <span style="background:var(--violet);color:var(--paper);font-family:var(--font-mono);font-size:9px;font-weight:600;padding:1px 4px">R</span> = radarbilde (Sentinel-1) &nbsp;
        <span style="background:var(--landsat);color:var(--paper);font-family:var(--font-mono);font-size:9px;font-weight:600;padding:1px 4px">L</span> = Landsat 8-9 &nbsp;
        <span style="background:var(--thermal);color:var(--paper);font-family:var(--font-mono);font-size:9px;font-weight:600;padding:1px 4px">T</span> = landoverflatetemperatur (Sentinel-3)
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
    <h2>Std og Pro-modus</h2>
    <div class="row">
      <div class="key">Std</div>
      <div class="desc">
        Standard visning — ett fullskjermbilde per dag fra <strong>Sentinel-2</strong> (optisk kamera).
        Fargetemaet er <span style="color:var(--blue);font-weight:600">kartblått</span>.
      </div>
    </div>
    <div class="row">
      <div class="key">Pro <span class="pro-tag">Pro</span></div>
      <div class="desc">
        Tre bilder side om side — <strong>optisk</strong> (Sentinel-2),
        <strong>radar</strong> (Sentinel-1 SAR) og <strong>Landsat</strong> (Landsat 8-9).
        Fargetemaet skifter til <span style="color:var(--violet);font-weight:600">fiolett</span>.
        På mobil vises bildene under hverandre.
      </div>
    </div>
    <div class="row">
      <div class="key">Bytte modus</div>
      <div class="desc">
        Klikk <kbd>Pro</kbd> for å aktivere pro-modus — knappen viser da <kbd>Std</kbd> for å gå tilbake.
        Valget huskes til neste gang du åpner siden.
      </div>
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
        Ved høyt skydekke vises en kontur av <strong>Vansjø</strong> oppå bildet som referanse.
      </div>
    </div>
    <div class="row">
      <div class="key">☁ &lt;50%-knappen</div>
      <div class="desc">
        Skjuler dager med over 50 % skydekke og dager uten satellittbilde.
        Knappen fylles med kartblått når filteret er aktivt — klikk igjen for å vise alle datoer.
      </div>
    </div>
    <div class="note">
      <strong>Merk:</strong> Skydekke-prosenten gjelder hele satellittscenen, ikke bare Vansjø-området.
      Et bilde kan vise høyt skydekke selv om akkurat innsjøen er klar, og omvendt.
      Sentinel-1 radar er <strong>ikke påvirket av skydekke</strong> og leverer data i all slags vær.
    </div>
  </section>

  <section>
    <h2>Bildetyper</h2>
    <div class="row">
      <div class="key">Falskt fargebilde</div>
      <div class="desc">
        Nær-infrarød kombinasjon — vegetasjon vises <strong style="color:var(--red)">rød</strong>,
        vann <strong style="color:var(--blue)">mørkt blått</strong>.
        Tydelig for å følge plantevekst og snødekke gjennom sesongen.
      </div>
    </div>
    <div class="row">
      <div class="key">Naturlig farge</div>
      <div class="desc">
        Synlig lys (RGB) — ser ut som et vanlig luftfoto
      </div>
    </div>
    <div class="row">
      <div class="key">SAR-radar <span class="pro-tag">Pro</span></div>
      <div class="desc">
        Sentinel-1 radarbilde vist med jet-fargeskala.
        <strong style="color:var(--blue)">Blå</strong> = glatt overflate / vann (lavt signal),
        <strong style="color:var(--green)">grønn</strong> og <strong style="color:var(--red)">rød</strong> = vegetasjon og bebyggelse (høyt signal).
        Fungerer gjennom skyer og om natten.
      </div>
    </div>
    <div class="row">
      <div class="key">Landsat <span class="pro-tag">Pro</span></div>
      <div class="desc">
        Landsat 8-9 optisk bilde, samme fargemodus (falskt/naturlig) som Sentinel-2-panelet.
        Lavere oppløsning og sjeldnere passeringer, men en uavhengig andreopinion på samme dato.
      </div>
    </div>
    <div class="row">
      <div class="key">🌡 °C-overlegg</div>
      <div class="desc">
        Landoverflatetemperatur fra Sentinel-3, slått av som standard — klikk <kbd>🌡 °C</kbd>-knappen
        i headeren for å legge et rutenett med temperaturtall (°C) oppå det optiske bildet.
        Klokkeslettet for målingen (UTC) vises i nedre venstre hjørne. Tallene fargelegges fra
        <strong style="color:var(--blue)">blått</strong> (kaldt) til <strong style="color:var(--red)">rødt</strong> (varmt).
        Skydekte ruter viser ikke tall — det er normalt, ikke en feil.
        Fungerer i både Std- og Pro-modus, og huskes ikke mellom sideinnlastinger.
      </div>
    </div>
    <div class="note">
      <strong>Merk:</strong> Dette er <strong>bakkens/overflatens</strong> egen temperatur (Land Surface
      Temperature), ikke lufttemperaturen i skygge 2 meter over bakken som f.eks. YR.no viser.
      En solvarmet asfaltflate eller åker kan derfor vise en helt annen — ofte mye høyere — temperatur
      enn det som meldes som «dagens temperatur».
    </div>
    <div class="row">
      <div class="key">❄ Kuldemengde</div>
      <div class="desc">
        Kuldemengde er en løpende sum av døgnmiddeltemperaturer siden 1.&nbsp;oktober, regnet som
        positivt tall: kalde døgn bygger opp (et døgn på −4&nbsp;°C bidrar +4), milde døgn tærer
        (et døgn på +3&nbsp;°C trekker fra 3), men kuldemengden kan aldri bli mindre enn null —
        et mål på den samlede kulden gjennom sesongen, og en viktig forutsetning for å vurdere om
        det kan danne seg stabil, skøytbar is. Klikk <kbd>❄ Kulde</kbd>-knappen i headeren for å vise etiketter på utvalgte
        steder i Vansjø (Lødengfjorden, Borgebunn og Amundbukta) med akkumulert kuldemengde
        <strong>per bildedato</strong> — blar du i tidslinjen ser du hvordan kulden bygger seg opp.
        Etiketten viser stedsnavnet og «trengs/målt» med store tall, f.eks. «23/47,3»: første tall
        er kuldemengden stedet trenger før isen erfaringsmessig er skøytbar, andre tall er målt
        kuldemengde — tallene er <strong>grønne</strong> når terskelen er passert,
        <strong>oransje</strong> når det gjenstår 5&nbsp;°C·døgn eller mindre,
        <strong>røde</strong> ellers, så statusen kan leses på avstand. Klikk på etiketten for å
        åpne en graf over kuldemengdens utvikling gjennom sesongen (fra 1.&nbsp;oktober), med
        terskelen markert som stiplet rød linje. Døgnmiddelet publiseres
        med omtrent ett døgns forsinkelse, så verdien kan gjelde dagen før slidedatoen.
        Knappen vises kun i sesongen (oktober–mai), og huskes ikke mellom sideinnlastinger.
      </div>
    </div>
    <div class="note">
      I motsetning til 🌡&nbsp;°C-overlegget er dette <strong>lufttemperatur</strong> målt 2 meter over
      bakken (samme som YR.no), fra nærmeste målestasjon (FV120 Rødsund ved Rødsundbrua for
      Lødengfjorden, Rygge for de øvrige stedene) — ikke satellittmålt overflatetemperatur.
    </div>
  </section>

  <section>
    <h2>Om satellittene</h2>
    <div class="row">
      <div class="key">Sentinel-2</div>
      <div class="desc">
        Optisk kamera som fotograferer i synlig og nær-infrarødt lys.
        Passerer over Norge omtrent <strong>hvert 2–3. dag</strong>,
        men skydekke avgjør om bildet er brukbart.
      </div>
    </div>
    <div class="row">
      <div class="key">Sentinel-1 <span class="pro-tag">Pro</span></div>
      <div class="desc">
        SAR-radar som sender ut mikrobølger og måler tilbakespredning fra overflaten.
        Uavhengig av skydekke og dagslys — passerer over Norge omtrent <strong>hvert 6. dag</strong>.
      </div>
    </div>
    <div class="row">
      <div class="key">Landsat 8-9 <span class="pro-tag">Pro</span></div>
      <div class="desc">
        Optisk kamera drevet av USGS/NASA. Passerer over Norge omtrent <strong>hvert 16. dag</strong> per satellitt,
        så panelet viser «Ingen Landsat-data» de fleste dager — det er forventet, ikke en feil.
      </div>
    </div>
    <div class="row">
      <div class="key">Sentinel-3</div>
      <div class="desc">
        SLSTR-instrumentet måler landoverflatetemperatur (LST). To satellitter (S3A/S3B) gir flere
        passeringer i døgnet over Norge, men skydekke maskerer bort ruter der temperaturen
        ikke kan avleses pålitelig — akkurat som med optiske bilder.
      </div>
    </div>
    <div class="row">
      <div class="key">Ingen bilde</div>
      <div class="desc">
        Dager uten tilgjengelig satellittbilde vises med bakgrunnskartet og teksten «Ingen satellittbilde».
        Dette er normalt og skyldes at satellitten fotograferte et annet område den dagen.
      </div>
    </div>
    <div class="note">
      Data fra European Space Agency (ESA) / Copernicus Data Space.
      Inneholder modifiserte Copernicus Sentinel-data.
    </div>
    <div class="note">
      <strong>Credit: U.S. Geological Survey.</strong> Landsat-bilder er levert av USGS/NASA og er ikke en del av Copernicus-programmet.
    </div>
    <div class="note">
      Værdata (kuldemengde) fra Meteorologisk institutt (Frost API), lisensiert under CC BY 4.0.
    </div>
  </section>

</div>
</body>
</html>
