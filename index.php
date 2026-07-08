<?php $cfg = require __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vansjø · Sentinel-arkiv</title>
<meta name="description" content="Daglige Sentinel-2/1- og Landsat-satellittbilder av Vansjø, Moss — fullskjerm slideshow med skydekke-info og tidslinje.">
<meta property="og:type" content="website">
<meta property="og:title" content="Vansjø · Sentinel-satellittarkiv">
<meta property="og:description" content="Daglige satellittbilder av Vansjø, Moss, fra Sentinel-2/1 (ESA) og Landsat (USGS).">
<meta property="og:url" content="https://kart.vansjo.top/">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/fonts/fonts.css">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}

:root{
  --hdr-h:64px;
  --info-h:44px;
  --tl-h:106px;
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
  --accent:var(--blue);
  --font-mono:'IBM Plex Mono','Cascadia Code',Consolas,monospace;
  --font-display:'Big Shoulders Display','Arial Narrow',Impact,sans-serif;
}

html{color-scheme:light}
html,body{height:100%;overflow:hidden;background:var(--paper);color:var(--ink)}

/* ── HEADER (tittelfelt som på kartblad) ── */
.hdr{
  position:fixed;top:0;left:0;right:0;z-index:20;height:var(--hdr-h);
  display:flex;align-items:center;justify-content:space-between;gap:16px;
  padding:0 20px;
  background:var(--paper);
  border-bottom:1px solid var(--line);
}
.hdr-logo{display:flex;flex-direction:column;justify-content:center;gap:2px;min-width:0}
.hdr-title{
  font-family:var(--font-display);font-size:30px;font-weight:700;line-height:.9;
  letter-spacing:.06em;text-transform:uppercase;color:var(--ink);
}
.hdr-sub{
  font-family:var(--font-mono);font-size:9px;letter-spacing:.3em;
  text-transform:uppercase;color:var(--muted);white-space:nowrap;
}
.hdr-center{font-family:var(--font-mono);font-size:11px;color:var(--muted);letter-spacing:.16em;text-transform:uppercase}
.hdr-right{
  display:flex;align-items:center;gap:14px;
  font-family:var(--font-mono);font-size:11px;color:var(--muted);
}
#counter{color:var(--ink);letter-spacing:.08em}
.fetch-btn,.filter-btn,.lst-btn{
  background:transparent;border:1px solid var(--ink);color:var(--ink);
  padding:6px 12px;font-size:10px;letter-spacing:.18em;text-transform:uppercase;
  font-family:var(--font-mono);cursor:pointer;
  transition:background .15s,color .15s,border-color .15s;
}
.fetch-btn:hover,.filter-btn:hover,.lst-btn:hover{background:var(--ink);color:var(--paper)}
.fetch-btn:disabled{opacity:.4;cursor:not-allowed;background:transparent;color:var(--ink)}
.filter-btn.active{background:var(--accent);border-color:var(--accent);color:var(--paper)}
.lst-btn.active{background:var(--thermal);border-color:var(--thermal);color:var(--paper)}
.help-btn{
  background:transparent;border:1px solid var(--ink);color:var(--ink);
  width:29px;height:29px;display:flex;align-items:center;justify-content:center;
  font-family:var(--font-mono);font-size:12px;text-decoration:none;
  transition:background .15s,color .15s;
}
.help-btn:hover{background:var(--ink);color:var(--paper)}
.fetch-btn:focus-visible,.filter-btn:focus-visible,.pro-btn:focus-visible,.lst-btn:focus-visible,
.help-btn:focus-visible,.nav:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.next-badge{
  font-family:var(--font-mono);font-size:10px;letter-spacing:.06em;
  color:var(--muted);white-space:nowrap;
}
.next-badge.available{color:var(--green)}
.next-badge span{color:var(--ink)}

/* ── MAIN SLIDESHOW AREA ── */
.stage{
  position:fixed;top:var(--hdr-h);left:0;right:0;bottom:calc(var(--info-h) + var(--tl-h));
  display:flex;align-items:center;justify-content:center;
  overflow:hidden;
  background-image:url('mapbg.php?v=3');
  background-size:contain;
  background-position:center;
  background-repeat:no-repeat;
  background-color:var(--paper);
}

.slide{
  position:absolute;inset:0;
  display:flex;align-items:center;justify-content:center;
  opacity:0;transition:opacity .5s ease;pointer-events:none;
}
.slide.active{opacity:1;pointer-events:auto}

/* Plansje: bildet som trykt plate med sverteramme */
.img-frame{
  position:relative;
  display:inline-flex;
  max-width:100%;max-height:100%;
  border:1px solid var(--ink);
  background-color:transparent;
}

.img-frame img{
  display:block;
  max-width:100%;max-height:calc(100vh - var(--hdr-h) - var(--info-h) - var(--tl-h));
  object-fit:contain;
  image-rendering:auto;
  cursor:zoom-in;
  transition:transform .3s ease;
  will-change:transform;
}
.img-frame.zoomed{overflow:hidden}
.img-frame.zoomed img{cursor:grab;user-select:none}
.img-frame.zoomed .lake-overlay,.img-frame.zoomed .lst-overlay{display:none}
.lake-overlay{
  position:absolute;inset:0;
  width:100%;height:100%;
  object-fit:contain;
  pointer-events:none;
  z-index:3;
  transition:opacity .2s;
}
/* LST-temperaturoverlegg (Sentinel-3) — av/på via lst-btn, ikke knyttet til skydekke */
.lst-overlay{
  position:absolute;inset:0;
  width:100%;height:100%;
  object-fit:contain;
  pointer-events:none;
  z-index:4;
  opacity:0;
  transition:opacity .3s;
}
body.lst-on .lst-overlay{opacity:1}
.img-frame.zoomed img.panning{cursor:grabbing}

/* Kart-only slide (ingen satellittdata) */
.img-frame.map-only{
  width:min(100%, calc(100vh - var(--hdr-h) - var(--info-h) - var(--tl-h)));
  aspect-ratio:1;
}
.no-data-label{
  position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
  z-index:5;
  font-family:var(--font-mono);font-size:11px;letter-spacing:.18em;
  text-transform:uppercase;color:var(--ink);
  background:rgba(231,227,214,.92);padding:8px 16px;
  border:1px solid var(--ink);pointer-events:none;
}

/* Passmerker — trykktekniske hjørnemerker utenfor plansjen */
.corner{
  position:absolute;width:12px;height:12px;
  border-color:var(--ink);border-style:solid;
  opacity:.9;pointer-events:none;z-index:2;
}
.corner-tl{top:-8px;left:-8px;border-width:1px 0 0 1px}
.corner-tr{top:-8px;right:-8px;border-width:1px 1px 0 0}
.corner-bl{bottom:-8px;left:-8px;border-width:0 0 1px 1px}
.corner-br{bottom:-8px;right:-8px;border-width:0 1px 1px 0}

/* Graticule-etiketter — reelle AOI-koordinater i NV- og SØ-hjørnet */
.coord{
  position:absolute;z-index:2;pointer-events:none;
  font-family:var(--font-mono);font-size:9px;letter-spacing:.08em;
  color:var(--ink);background:rgba(231,227,214,.85);padding:2px 6px;
}
.coord-tl{top:0;left:0}
.coord-br{bottom:0;right:0}

/* ── INFO BAR (plansjetekst under bildet) ── */
.info-bar{
  position:fixed;bottom:var(--tl-h);left:0;right:0;z-index:20;height:var(--info-h);
  display:flex;align-items:center;gap:24px;
  padding:0 20px;
  background:var(--paper);
  border-top:3px double var(--ink);
  font-family:var(--font-mono);
  pointer-events:none;
}
.info-bar-date{
  font-family:var(--font-display);font-size:24px;font-weight:600;
  letter-spacing:.05em;text-transform:uppercase;color:var(--ink);
}
.info-bar-meta{margin-left:auto;font-size:10px;color:var(--muted);letter-spacing:.14em;text-transform:uppercase;display:flex;gap:20px}
.cloud-good{color:var(--green)}
.cloud-ok{color:var(--ochre)}
.cloud-bad{color:var(--red)}

/* Nav arrows */
.nav{
  position:absolute;top:50%;transform:translateY(-50%);z-index:10;
  width:42px;height:42px;
  background:rgba(231,227,214,.9);border:1px solid var(--ink);
  color:var(--ink);display:flex;align-items:center;justify-content:center;
  cursor:pointer;font-size:18px;
  transition:background .15s,color .15s;user-select:none;
}
.nav:hover{background:var(--ink);color:var(--paper)}
.nav:active{transform:translateY(-50%) scale(.95)}
.nav-prev{left:16px}
.nav-next{right:16px}
.nav:disabled,.nav.hidden{opacity:0;pointer-events:none}

/* ── DATE FLASH (stemplet etikett) ── */
.date-flash{
  position:fixed;
  top:50%;left:50%;
  transform:translate(-50%,-50%) scale(.97);
  z-index:30;
  font-family:var(--font-display);
  font-size:clamp(2rem, 7vw, 4.5rem);
  font-weight:600;
  letter-spacing:.08em;
  text-transform:uppercase;
  text-align:center;
  color:var(--ink);
  background:rgba(231,227,214,.94);
  border:1px solid var(--ink);
  padding:.25em .6em;
  pointer-events:none;
  white-space:nowrap;
  opacity:0;
  transition:opacity .15s ease,transform .15s ease;
}
.date-flash.show{opacity:1;transform:translate(-50%,-50%) scale(1)}
.date-flash span{font-family:var(--font-mono);font-weight:500;letter-spacing:.14em;text-transform:uppercase}

/* Empty / loading states */
.state-box{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  height:100%;gap:16px;text-align:center;padding:40px;
  font-family:var(--font-mono);
}
.state-box h2{font-size:14px;font-weight:600;color:var(--ink);letter-spacing:.12em;text-transform:uppercase}
.state-box p{font-size:12px;color:var(--muted);max-width:460px;line-height:1.7}
.state-box code{
  display:block;margin-top:4px;
  background:var(--paper-2);border:1px solid var(--hair);
  padding:10px 16px;font-size:11px;color:var(--ink);
  letter-spacing:.05em;text-align:left;max-width:500px;line-height:1.8;
}

.spinner{
  width:30px;height:30px;border:2px solid var(--hair);
  border-top-color:var(--accent);border-radius:50%;
  animation:spin .7s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── TIMELINE (arkivstrimmel — monterte miniatyrer) ── */
.timeline{
  position:fixed;bottom:0;left:0;right:0;z-index:19;
  height:var(--tl-h);
  background:var(--paper);border-top:1px solid var(--line);
  display:flex;align-items:center;
  padding:0 14px;gap:8px;
  overflow-x:auto;overflow-y:hidden;
  scrollbar-width:thin;scrollbar-color:var(--hair) transparent;
}
.timeline::-webkit-scrollbar{height:4px}
.timeline::-webkit-scrollbar-track{background:transparent}
.timeline::-webkit-scrollbar-thumb{background:var(--hair)}

.tl-item{
  flex-shrink:0;width:68px;height:80px;
  border:1px solid var(--hair);
  cursor:pointer;position:relative;overflow:hidden;
  transition:border-color .15s;
  background:var(--paper-2);
}
.tl-item:hover{border-color:var(--ink)}
.tl-item:focus-visible{outline:2px solid var(--accent);outline-offset:1px;border-color:var(--accent)}
.tl-item.active{border:2px solid var(--accent)}
.tl-item img{width:100%;height:62px;object-fit:cover;display:block}
.tl-placeholder{
  width:100%;height:62px;
  display:flex;align-items:center;justify-content:center;
  font-size:8px;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;
}
.tl-label{
  position:absolute;bottom:0;left:0;right:0;
  background:var(--paper);border-top:1px solid var(--hair);
  font-family:var(--font-mono);font-size:8px;
  color:var(--ink);text-align:center;
  padding:3px 2px 2px;letter-spacing:.06em;
}
.tl-item.active .tl-label{background:var(--accent);border-top-color:var(--accent);color:var(--paper)}
.tl-badges{position:absolute;top:3px;right:3px;display:flex;gap:2px}
.tl-badge{
  font-family:var(--font-mono);font-size:7px;font-weight:600;
  padding:1px 3px;line-height:1.4;letter-spacing:.03em;
}
.tl-badge-o{background:var(--ink);color:var(--paper)}
.tl-badge-r{background:var(--violet);color:var(--paper)}
.tl-badge-l{background:var(--landsat);color:var(--paper)}
.tl-badge-t{background:var(--thermal);color:var(--paper)}

/* ── MOBILE ── */
@media(max-width:640px){
  :root{--hdr-h:56px;--info-h:38px}
  .hdr{padding:0 12px}
  .hdr-title{font-size:22px}
  .hdr-sub{display:none}
  .hdr-center{display:none}
  .next-badge{display:none}
  #counter{display:none}
  .hdr-right{gap:8px}
  .fetch-btn,.filter-btn,.lst-btn{padding:5px 8px;font-size:9px;letter-spacing:.1em}
  .info-bar{padding:0 12px;gap:12px}
  .info-bar-date{font-size:18px}
  .info-bar-meta{gap:10px;font-size:9px}
  .coord{display:none}
}

/* Redusert bevegelse */
@media(prefers-reduced-motion:reduce){
  .slide{transition:none}
  .date-flash{transition:opacity .01s;transform:translate(-50%,-50%)}
  .img-frame img{transition:none}
}

/* Notification */
.notif{
  position:fixed;top:calc(var(--hdr-h) + 10px);right:16px;z-index:50;
  font-family:var(--font-mono);font-size:11px;letter-spacing:.08em;
  padding:10px 16px;border:1px solid var(--ink);border-left:4px solid var(--accent);
  background:var(--paper);color:var(--ink);
  opacity:0;transform:translateY(-8px);
  transition:opacity .25s,transform .25s;pointer-events:none;max-width:340px;
}
.notif.show{opacity:1;transform:translateY(0)}
.notif.error{border-left-color:var(--red);color:var(--red)}

/* ── PRO MODE TEMA ── */
body.pro-mode{
  --accent:var(--violet);
}

/* ── PRO TOGGLE-KNAPP ── */
.pro-btn{
  background:transparent;border:1px solid var(--ink);color:var(--ink);
  padding:6px 12px;font-size:10px;letter-spacing:.18em;text-transform:uppercase;
  font-family:var(--font-mono);cursor:pointer;
  transition:background .15s,color .15s,border-color .15s;
}
.pro-btn:hover{background:var(--ink);color:var(--paper)}
.pro-btn.active{background:var(--violet);border-color:var(--violet);color:var(--paper)}
@media(max-width:640px){.pro-btn{padding:5px 8px;font-size:9px;letter-spacing:.1em}}
/* ── MODE-INDIKATOR I LOGO ── */
.mode-badge{
  font-family:var(--font-mono);font-size:9px;letter-spacing:.15em;text-transform:uppercase;
  color:var(--accent);margin-left:6px;white-space:nowrap;
}
@media(max-width:640px){.mode-badge{display:none}}

/* ── PRO SPLIT-VISNING ── */
.pro-split{
  display:flex;align-items:stretch;gap:2px;
  width:100%;height:100%;
  border:1px solid var(--ink);
  background:var(--paper-2);
}
.pro-panel{
  position:relative;flex:1 1 0;min-width:0;
  display:flex;flex-direction:column;
  overflow:hidden;
  background-color:var(--paper);
}
.pro-panel-image{
  flex:1 1 auto;min-height:0;
  display:flex;align-items:center;justify-content:center;
  overflow:hidden;
  background-image:url('mapbg.php?v=3');
  background-size:contain;
  background-position:center;
  background-repeat:no-repeat;
}
/* Bilder i paneler: fjern original ramme-styling, la img fylle panelet */
.pro-panel .img-frame{
  border:none;box-shadow:none;
  display:flex;align-items:center;justify-content:center;
  width:100%;height:100%;max-width:none;max-height:none;
}
.pro-panel .corner,.pro-panel .coord{display:none}
.pro-panel .img-frame img{
  max-width:100%;max-height:100%;
  width:auto;height:auto;object-fit:contain;
}
/* Overlay må dekke hele rammen (ikke arve auto-størrelse fra regelen over),
   ellers ankres den mot venstre kant og havner forskjøvet vest for innsjøen */
.pro-panel .img-frame img.lake-overlay,.pro-panel .img-frame img.lst-overlay{
  width:100%;height:100%;
}
/* Bildetekst under panelet: etikett til venstre, kreditering (Landsat) til høyre */
.pro-caption{
  flex:0 0 auto;
  display:flex;align-items:center;justify-content:space-between;gap:8px;
  padding:5px 8px;
  border-top:1px solid var(--hair);
  background:var(--paper);
  font-family:var(--font-mono);font-size:9px;letter-spacing:.18em;text-transform:uppercase;
  color:var(--ink);
}
.pro-caption-label{
  border:1px solid var(--ink);padding:2px 7px;
}
.pro-caption-radar .pro-caption-label{border-color:var(--violet);color:var(--violet)}
.pro-caption-landsat .pro-caption-label{border-color:var(--landsat);color:var(--landsat)}
.pro-caption-credit{
  font-size:8px;letter-spacing:.08em;text-transform:none;color:var(--muted);
}
/* Overlay-etiketter brukt når Landsat vises som fullskjerm-erstatning i Std-modus */
.pro-label{
  position:absolute;top:8px;left:8px;z-index:4;
  font-family:var(--font-mono);font-size:9px;letter-spacing:.18em;text-transform:uppercase;
  color:var(--ink);background:rgba(231,227,214,.92);
  padding:3px 8px;border:1px solid var(--ink);pointer-events:none;
}
.pro-label-landsat{border-color:var(--landsat);color:var(--landsat);top:auto;bottom:8px;right:8px;left:auto}
.usgs-credit{
  position:absolute;bottom:8px;left:8px;z-index:4;
  font-family:var(--font-mono);font-size:8px;letter-spacing:.08em;
  color:var(--muted);background:rgba(231,227,214,.92);
  padding:2px 6px;pointer-events:none;
}

/* Mobil: fullbredde kvadratiske paneler som ruller vertikalt,
   slik at bildene ikke krymper til halv høyde */
@media(max-width:640px){
  .pro-split{
    flex-direction:column;gap:8px;
    border:none;background:transparent;
    overflow-y:auto;overscroll-behavior:contain;
    -webkit-overflow-scrolling:touch;
  }
  .pro-panel{
    flex:0 0 auto;width:100%;aspect-ratio:1;
    border:1px solid var(--ink);
  }
}
</style>
</head>
<body>

<!-- HEADER -->
<header class="hdr">
  <div class="hdr-logo">
    <div class="hdr-title">Vansjø</div>
    <div class="hdr-sub">Sentinel-satellittarkiv<span class="mode-badge" id="mode-badge">[STD mode]</span></div>
  </div>
  <div class="hdr-center" id="aoi-label">—</div>
  <div class="hdr-right">
    <div class="next-badge" id="next-badge"></div>
    <span id="counter">— / —</span>
    <button class="pro-btn" id="pro-btn" onclick="toggleProMode()">Pro</button>
    <button class="filter-btn" id="filter-btn" onclick="toggleFilter()" title="Vis kun bilder med under 50 % skydekke">
      ☁ &lt;50%
    </button>
    <?php if ($cfg['s3_lst_enabled'] ?? false): ?>
    <button class="lst-btn" id="lst-btn" onclick="toggleLstOverlay()" title="Vis landoverflatetemperatur (Sentinel-3) som overlegg">
      🌡 °C
    </button>
    <?php endif; ?>
    <button class="fetch-btn" id="fetch-btn" onclick="triggerFetch()" title="Hent nye bilder fra Copernicus">
      ↓ Hent
    </button>
    <a class="help-btn" href="help.php" title="Bruksanvisning">?</a>
  </div>
</header>

<!-- STAGE -->
<div class="stage" id="stage">
  <div class="state-box">
    <div class="spinner"></div>
    <p>Laster satellittbilder…</p>
  </div>
</div>

<!-- INFO BAR -->
<div class="info-bar" id="info-bar">
  <div class="info-bar-date" id="info-date">—</div>
  <div class="info-bar-meta" id="info-meta"></div>
</div>

<!-- TIMELINE -->
<div class="timeline" id="timeline"></div>

<!-- DATE FLASH -->
<div class="date-flash" id="date-flash"></div>

<!-- NOTIFICATION -->
<div class="notif" id="notif"></div>

<script>
const API = 'api.php';
const FETCH_TOKEN = <?= json_encode($cfg['fetch_token'] ?? '') ?>;
const AOI = <?= json_encode($cfg['aoi'] ?? null) ?>;
const LANDSAT_ENABLED = <?= json_encode($cfg['landsat_enabled'] ?? false) ?>;
const S3_LST_ENABLED = <?= json_encode($cfg['s3_lst_enabled'] ?? false) ?>;
let allImages = [];
let primaryImages = [];
let s1ByDate = {};
let landsatByDate = {};
let s3ByDate = {};
let images = [];
let idx = 0;
let flashTimer = null;
let filterActive = false;
let lstOverlayActive = false; // ikke lagret i localStorage — nullstilles ved hver sideinnlasting
let proMode = localStorage.getItem('proMode') === '1';

// ── Data ────────────────────────────────────────────────────────────────────
async function loadImages() {
  try {
    const res = await fetch(`${API}?action=list`);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);

    document.getElementById('aoi-label').textContent = data.aoi || '';
    allImages = data.images || [];

    s1ByDate = {};
    landsatByDate = {};
    s3ByDate = {};
    primaryImages = [];
    for (const img of allImages) {
      if (img.sensor === 'S1' || img.type === 'radar') s1ByDate[img.date] = img;
      else if (img.sensor === 'LANDSAT' || img.type === 'landsat') landsatByDate[img.date] = img;
      else if (img.sensor === 'S3' || img.type === 'lst') s3ByDate[img.date] = img;
      else primaryImages.push(img);
    }
    images = filterActive ? applyFilter(primaryImages) : primaryImages;

    if (images.length === 0) {
      showEmptyState();
    } else {
      buildSlides();
      buildTimeline();
      goTo(0);
    }
  } catch (e) {
    showErrorState(e.message);
  }
}

async function loadNext() {
  try {
    const res  = await fetch(`${API}?action=next`);
    const data = await res.json();
    if (!data.ok) return;

    const el = document.getElementById('next-badge');
    if (data.status === 'available') {
      el.className = 'next-badge available';
      el.innerHTML = `⬇ Nytt bilde klart: <span>${formatDate(data.date)}</span>` +
        (data.cloud_cover !== null ? ` <span>(${data.cloud_cover}%)</span>` : '');
    }
  } catch (_) {}
}

async function triggerFetch() {
  const btn = document.getElementById('fetch-btn');
  btn.disabled = true;
  btn.textContent = '…';
  notify('Henter bilder fra Copernicus…');

  try {
    const res = await fetch(`${API}?action=fetch`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'token=' + encodeURIComponent(FETCH_TOKEN),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);

    const s = data.stats;
    notify(`Nedlastet: ${s.downloaded}  |  Hoppet over: ${s.skipped}  |  Feil: ${s.errors.length}`, s.errors.length > 0);

    if (s.downloaded > 0) {
      setTimeout(loadImages, 800);
    }
  } catch (e) {
    notify(e.message, true);
  } finally {
    btn.disabled = false;
    btn.textContent = '↓ Hent';
  }
}

// ── Filter ───────────────────────────────────────────────────────────────────
function applyFilter(list) {
  return list.filter(img => {
    if (img.type === 'map') {
      const landsat = !proMode ? landsatByDate[img.date] : null;
      return !!landsat && landsat.cloud_cover !== null && landsat.cloud_cover < 50;
    }
    return img.cloud_cover !== null && img.cloud_cover < 50;
  });
}

function toggleFilter() {
  filterActive = !filterActive;
  document.getElementById('filter-btn').classList.toggle('active', filterActive);
  images = filterActive ? applyFilter(primaryImages) : primaryImages;
  if (images.length === 0) {
    showEmptyState();
    return;
  }
  buildSlides();
  buildTimeline();
  goTo(0);
}

// ── LST-overlegg (Sentinel-3 landoverflatetemperatur) ────────────────────────
function toggleLstOverlay() {
  lstOverlayActive = !lstOverlayActive;
  document.getElementById('lst-btn').classList.toggle('active', lstOverlayActive);
  document.body.classList.toggle('lst-on', lstOverlayActive);
}

// ── Pro mode toggle ──────────────────────────────────────────────────────────
function toggleProMode() {
  proMode = !proMode;
  localStorage.setItem('proMode', proMode ? '1' : '0');
  document.body.classList.toggle('pro-mode', proMode);
  updateProBtn();
  buildSlides();
  buildTimeline();
  if (images.length > 0) goTo(idx);
}

function updateProBtn() {
  const btn = document.getElementById('pro-btn');
  btn.textContent = proMode ? 'Std' : 'Pro';
  btn.classList.toggle('active', proMode);
  document.getElementById('mode-badge').textContent = proMode ? '[PRO mode]' : '[STD mode]';
}

// ── Build slides ─────────────────────────────────────────────────────────────
// Cache-busting: bilder caches lenge av nettleseren (.htaccess: immutable, 30 dager)
// siden filnavnet normalt aldri gjenbrukes for en annen dato — men enkelte kilder
// (f.eks. S3 LST) kan i praksis bli regenerert for samme dato/filnavn (reprosessert
// NT-variant, manuell rekjøring). fetched_at i URL-en sikrer at nettleseren ser
// oppdateringen med én gang i stedet for å vise en 30 dager gammel utgave.
function versioned(path, obj) {
  return obj?.fetched_at ? `${path}?t=${encodeURIComponent(obj.fetched_at)}` : path;
}

// Graticule-etiketter: reelle AOI-koordinater trykt i NV- og SØ-hjørnet av plansjen
function addCoords(frame) {
  if (!AOI) return;
  const fmt = (v, ax) => Math.abs(v).toFixed(2) + '°' + (ax === 'lat' ? (v >= 0 ? 'N' : 'S') : (v >= 0 ? 'Ø' : 'V'));
  const tl = document.createElement('span');
  tl.className = 'coord coord-tl';
  tl.textContent = `${fmt(AOI.north,'lat')} ${fmt(AOI.west,'lon')}`;
  const br = document.createElement('span');
  br.className = 'coord coord-br';
  br.textContent = `${fmt(AOI.south,'lat')} ${fmt(AOI.east,'lon')}`;
  frame.append(tl, br);
}

function buildNoDataFrame(label, date) {
  const frame = document.createElement('div');
  frame.className = 'img-frame map-only';
  ['tl','tr','bl','br'].forEach(pos => {
    const c = document.createElement('span');
    c.className = `corner corner-${pos}`;
    frame.appendChild(c);
  });
  addCoords(frame);
  const lbl = document.createElement('div');
  lbl.className = 'no-data-label';
  lbl.textContent = label;
  frame.appendChild(lbl);

  // Mangler både S2 og Landsat: legg LST-rutenettet oppå bakgrunnskartet i stedet
  // for at det bare forsvinner sammen med det manglende optiske bildet.
  if (S3_LST_ENABLED && date) {
    const lst = s3ByDate[date];
    if (lst?.filename) {
      const ov = document.createElement('img');
      ov.src       = versioned(`images/${lst.filename}`, lst);
      ov.className = 'lst-overlay';
      ov.alt       = '';
      ov.onerror   = () => ov.remove();
      frame.appendChild(ov);
    }
  }
  return frame;
}

function buildImgFrame(img, i) {
  if (img.type === 'map') {
    const landsat = !proMode ? landsatByDate[img.date] : null;
    if (landsat?.filename) return buildLandsatFrame(landsat, true);
    return buildNoDataFrame('Ingen satellittbilde', img.date);
  }

  const frame = document.createElement('div');
  frame.className = 'img-frame';
  ['tl','tr','bl','br'].forEach(pos => {
    const c = document.createElement('span');
    c.className = `corner corner-${pos}`;
    frame.appendChild(c);
  });
  addCoords(frame);

  const el = document.createElement('img');
  el.src = versioned(`images/${img.filename}`, img);
  el.alt = img.date;
  el.loading = i === 0 ? 'eager' : 'lazy';
  el.addEventListener('click', e => { e.stopPropagation(); if (zoomState?.dragMoved) return; toggleZoom(el, frame, e); });
  frame.appendChild(el);

  if (img.cloud_cover !== null && img.cloud_cover > 50) {
    const ov = document.createElement('img');
    ov.src       = 'assets/lake_overlay.png';
    ov.className = 'lake-overlay';
    ov.alt       = '';
    ov.onerror   = () => ov.remove();
    frame.appendChild(ov);
  }

  if (S3_LST_ENABLED) {
    const lst = s3ByDate[img.date];
    if (lst?.filename) {
      const ov = document.createElement('img');
      ov.src       = versioned(`images/${lst.filename}`, lst);
      ov.className = 'lst-overlay';
      ov.alt       = '';
      ov.onerror   = () => ov.remove();
      frame.appendChild(ov);
    }
  }
  return frame;
}

function buildS1Frame(s1) {
  const frame = document.createElement('div');
  frame.className = 'img-frame';
  ['tl','tr','bl','br'].forEach(pos => {
    const c = document.createElement('span');
    c.className = `corner corner-${pos}`;
    frame.appendChild(c);
  });
  const el = document.createElement('img');
  el.src     = versioned(`images/${s1.filename}`, s1);
  el.alt     = s1.date + ' SAR';
  el.loading = 'lazy';
  el.addEventListener('click', e => { e.stopPropagation(); if (zoomState?.dragMoved) return; toggleZoom(el, frame, e); });
  frame.appendChild(el);
  return frame;
}

function buildLandsatFrame(landsat, standalone = false) {
  const frame = document.createElement('div');
  frame.className = 'img-frame';
  ['tl','tr','bl','br'].forEach(pos => {
    const c = document.createElement('span');
    c.className = `corner corner-${pos}`;
    frame.appendChild(c);
  });
  if (standalone) addCoords(frame);
  const el = document.createElement('img');
  el.src     = versioned(`images/${landsat.filename}`, landsat);
  el.alt     = landsat.date + ' Landsat';
  el.loading = 'lazy';
  el.addEventListener('click', e => { e.stopPropagation(); if (zoomState?.dragMoved) return; toggleZoom(el, frame, e); });
  frame.appendChild(el);

  if (landsat.cloud_cover !== null && landsat.cloud_cover > 50) {
    const ov = document.createElement('img');
    ov.src       = 'assets/lake_overlay.png';
    ov.className = 'lake-overlay';
    ov.alt       = '';
    ov.onerror   = () => ov.remove();
    frame.appendChild(ov);
  }

  if (standalone) {
    const label = document.createElement('div');
    label.className = 'pro-label pro-label-landsat';
    label.textContent = 'Landsat (erstatning)';
    frame.appendChild(label);

    const credit = document.createElement('div');
    credit.className = 'usgs-credit';
    credit.textContent = 'Credit: U.S. Geological Survey';
    frame.appendChild(credit);
  }
  return frame;
}

// Pro-panel: bilde øverst, bildetekst (etikett + ev. kreditering) i en rad under
function buildProPanel(labelText, captionClass, frameEl, creditText) {
  const panel = document.createElement('div');
  panel.className = 'pro-panel';

  const imgWrap = document.createElement('div');
  imgWrap.className = 'pro-panel-image';
  imgWrap.appendChild(frameEl);
  panel.appendChild(imgWrap);

  const caption = document.createElement('div');
  caption.className = 'pro-caption' + (captionClass ? ' ' + captionClass : '');
  const label = document.createElement('span');
  label.className = 'pro-caption-label';
  label.textContent = labelText;
  caption.appendChild(label);
  if (creditText) {
    const credit = document.createElement('span');
    credit.className = 'pro-caption-credit';
    credit.textContent = creditText;
    caption.appendChild(credit);
  }
  panel.appendChild(caption);
  return panel;
}

function buildSlides() {
  const stage = document.getElementById('stage');
  stage.innerHTML = '';

  images.forEach((img, i) => {
    const slide = document.createElement('div');
    slide.className = 'slide';
    slide.dataset.idx = i;

    if (proMode) {
      const split = document.createElement('div');
      split.className = 'pro-split';

      const lp = buildProPanel('Optisk', '', buildImgFrame(img, i));

      const s1 = s1ByDate[img.date];
      const rp = buildProPanel('Radar', 'pro-caption-radar',
        s1?.filename ? buildS1Frame(s1) : buildNoDataFrame('Ingen radardata'));

      split.appendChild(lp);
      split.appendChild(rp);

      if (LANDSAT_ENABLED) {
        const landsat = landsatByDate[img.date];
        const lsp = buildProPanel('Landsat', 'pro-caption-landsat',
          landsat?.filename ? buildLandsatFrame(landsat) : buildNoDataFrame('Ingen Landsat-data'),
          'Credit: U.S. Geological Survey');
        split.appendChild(lsp);
      }

      slide.appendChild(split);
    } else {
      slide.appendChild(buildImgFrame(img, i));
    }

    slide.appendChild(navBtn('‹', 'nav nav-prev', () => goTo(idx - 1), 'Nyere bilde'));
    slide.appendChild(navBtn('›', 'nav nav-next', () => goTo(idx + 1), 'Eldre bilde'));
    stage.appendChild(slide);
  });
}

function navBtn(label, cls, fn, ariaLabel) {
  const b = document.createElement('button');
  b.className = cls;
  b.textContent = label;
  b.setAttribute('aria-label', ariaLabel);
  b.onclick = fn;
  return b;
}

// ── Build timeline ───────────────────────────────────────────────────────────
function buildTimeline() {
  const tl = document.getElementById('timeline');
  tl.innerHTML = '';

  images.forEach((img, i) => {
    const item = document.createElement('div');
    item.className = 'tl-item';
    item.dataset.idx = i;
    item.setAttribute('role', 'button');
    item.setAttribute('tabindex', '0');
    const landsatFallback = (!proMode && img.type === 'map') ? landsatByDate[img.date] : null;

    item.setAttribute('aria-label', formatDate(img.date) + (img.type === 'map' && !landsatFallback ? ' — ingen satellittbilde' : ''));
    item.onclick = () => goTo(i);
    item.onkeydown = e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); goTo(i); }
    };

    if (img.thumbnail || img.filename) {
      const thumb = document.createElement('img');
      thumb.src     = img.thumbnail ? versioned(`images/thumbs/${img.thumbnail}`, img) : versioned(`images/${img.filename}`, img);
      thumb.alt     = img.date;
      thumb.loading = 'lazy';
      thumb.onerror = () => {
        thumb.onerror = () => { thumb.style.display = 'none'; };
        if (img.filename) thumb.src = versioned(`images/${img.filename}`, img);
        else thumb.style.display = 'none';
      };
      item.appendChild(thumb);
    } else if (landsatFallback && (landsatFallback.thumbnail || landsatFallback.filename)) {
      const thumb = document.createElement('img');
      thumb.src     = landsatFallback.thumbnail ? versioned(`images/thumbs/${landsatFallback.thumbnail}`, landsatFallback) : versioned(`images/${landsatFallback.filename}`, landsatFallback);
      thumb.alt     = img.date + ' Landsat';
      thumb.loading = 'lazy';
      thumb.onerror = () => { thumb.style.display = 'none'; };
      item.appendChild(thumb);
    } else {
      const ph = document.createElement('div');
      ph.className = 'tl-placeholder';
      ph.textContent = 'kart';
      item.appendChild(ph);
    }

    const label = document.createElement('div');
    label.className = 'tl-label';
    label.textContent = formatDateShort(img.date);
    item.appendChild(label);

    const hasT = S3_LST_ENABLED && !!s3ByDate[img.date]?.filename;

    if (proMode) {
      const hasO = img.type !== 'map' && !!img.filename;
      const hasR = !!s1ByDate[img.date]?.filename;
      const hasL = !!landsatByDate[img.date]?.filename;
      if (hasO || hasR || hasL || hasT) {
        const badges = document.createElement('div');
        badges.className = 'tl-badges';
        if (hasO) { const b = document.createElement('span'); b.className = 'tl-badge tl-badge-o'; b.textContent = 'O'; badges.appendChild(b); }
        if (hasR) { const b = document.createElement('span'); b.className = 'tl-badge tl-badge-r'; b.textContent = 'R'; badges.appendChild(b); }
        if (hasL) { const b = document.createElement('span'); b.className = 'tl-badge tl-badge-l'; b.textContent = 'L'; badges.appendChild(b); }
        if (hasT) { const b = document.createElement('span'); b.className = 'tl-badge tl-badge-t'; b.textContent = 'T'; badges.appendChild(b); }
        item.appendChild(badges);
      }
    } else if (landsatFallback || hasT) {
      const badges = document.createElement('div');
      badges.className = 'tl-badges';
      if (landsatFallback) { const b = document.createElement('span'); b.className = 'tl-badge tl-badge-l'; b.textContent = 'L'; badges.appendChild(b); }
      if (hasT) { const b = document.createElement('span'); b.className = 'tl-badge tl-badge-t'; b.textContent = 'T'; badges.appendChild(b); }
      item.appendChild(badges);
    }

    tl.appendChild(item);
  });
}

// ── Navigation ───────────────────────────────────────────────────────────────
function goTo(i) {
  if (i < 0 || i >= images.length) return;
  resetZoom();

  // Deactivate old
  document.querySelectorAll('.slide.active').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.tl-item.active').forEach(t => t.classList.remove('active'));

  idx = i;

  // Activate new
  const slide = document.querySelector(`.slide[data-idx="${i}"]`);
  const tlItem = document.querySelector(`.tl-item[data-idx="${i}"]`);

  if (slide) slide.classList.add('active');
  if (tlItem) {
    tlItem.classList.add('active');
    tlItem.scrollIntoView({behavior:'smooth', inline:'center', block:'nearest'});
  }

  // Counter
  document.getElementById('counter').textContent = `${i + 1} / ${images.length}`;

  const img = images[i];
  const landsatFallback = (!proMode && img.type === 'map') ? landsatByDate[img.date] : null;
  const cc = landsatFallback ? landsatFallback.cloud_cover : img.cloud_cover;
  const isNoData = img.type === 'map' && !landsatFallback;

  // Date flash
  const flashEl = document.getElementById('date-flash');
  const ccColor = cc === null ? '' : cc < 20 ? '#256B43' : cc < 50 ? '#8F6400' : '#A93226';
  const ccHtml = isNoData
    ? `<span style="font-size:.45em;color:var(--muted);display:block;margin-top:.2em;text-align:center">Ingen satellittdata</span>`
    : cc !== null
      ? `<span style="font-size:.45em;color:${ccColor};display:block;margin-top:.2em;text-align:center">${cc}% skydekke</span>`
      : '';
  flashEl.innerHTML = formatDate(img.date) + ccHtml;
  flashEl.classList.add('show');
  clearTimeout(flashTimer);
  flashTimer = setTimeout(() => flashEl.classList.remove('show'), 1000);

  // Info bar
  document.getElementById('info-date').textContent = formatDate(img.date);
  const ccClass = cc === null ? '' : cc < 20 ? 'cloud-good' : cc < 50 ? 'cloud-ok' : 'cloud-bad';
  const ccText  = isNoData ? 'Ingen satellittdata — viser kart'
                : cc !== null ? cc + '% skydekke' : 'Ukjent skydekke';
  document.getElementById('info-meta').innerHTML =
    `<span class="${ccClass}">${ccText}</span>` +
    (landsatFallback ? `<span>Credit: U.S. Geological Survey</span>`
      : img.type !== 'map' ? `<span>Contains modified Copernicus Sentinel data ${img.date.slice(0,4)}</span>` : '');
}

// ── Zoom ──────────────────────────────────────────────────────────────────────
let zoomState = null;
const ZOOM_SCALE = 4.0;

function applyZoomTransform() {
  if (!zoomState) return;
  const { imgEl, tx, ty, panX, panY } = zoomState;
  imgEl.style.transform = `translate(${tx + panX}px, ${ty + panY}px) scale(${ZOOM_SCALE})`;
}

function toggleZoom(imgEl, frameEl, e) {
  if (frameEl.classList.contains('zoomed')) { resetZoom(); return; }
  const rect = imgEl.getBoundingClientRect();
  const clickX = e.clientX - rect.left - rect.width  / 2;
  const clickY = e.clientY - rect.top  - rect.height / 2;
  imgEl.style.transformOrigin = '50% 50%';
  zoomState = {
    imgEl, frameEl,
    tx: clickX * (1 - ZOOM_SCALE), ty: clickY * (1 - ZOOM_SCALE),
    panX: 0, panY: 0,
    isPanning: false, dragMoved: false,
    startX: 0, startY: 0, dragStartX: 0, dragStartY: 0,
  };
  applyZoomTransform();
  frameEl.classList.add('zoomed');
}

function resetZoom() {
  if (!zoomState) return;
  const { imgEl, frameEl } = zoomState;
  frameEl.classList.remove('zoomed');
  imgEl.style.transform = '';
  imgEl.style.transformOrigin = '';
  zoomState = null;
}

document.addEventListener('mousedown', e => {
  if (!zoomState || !e.target.closest('.img-frame.zoomed')) return;
  zoomState.isPanning  = true;
  zoomState.dragMoved  = false;
  zoomState.dragStartX = e.clientX;
  zoomState.dragStartY = e.clientY;
  zoomState.startX     = e.clientX - zoomState.panX;
  zoomState.startY     = e.clientY - zoomState.panY;
  zoomState.imgEl.classList.add('panning');
});

document.addEventListener('mousemove', e => {
  if (!zoomState?.isPanning) return;
  if (Math.abs(e.clientX - zoomState.dragStartX) > 4 || Math.abs(e.clientY - zoomState.dragStartY) > 4)
    zoomState.dragMoved = true;
  zoomState.panX = e.clientX - zoomState.startX;
  zoomState.panY = e.clientY - zoomState.startY;
  applyZoomTransform();
});

document.addEventListener('mouseup', () => {
  if (!zoomState) return;
  zoomState.isPanning = false;
  zoomState.imgEl.classList.remove('panning');
});

let swipeStart = null;

document.addEventListener('touchstart', e => {
  if (e.touches.length !== 1) return;
  const t = e.touches[0];
  if (zoomState && e.target.closest('.img-frame.zoomed')) {
    zoomState.isPanning  = true;
    zoomState.dragMoved  = false;
    zoomState.dragStartX = t.clientX;
    zoomState.dragStartY = t.clientY;
    zoomState.startX     = t.clientX - zoomState.panX;
    zoomState.startY     = t.clientY - zoomState.panY;
    zoomState.imgEl.classList.add('panning');
  } else if (!zoomState) {
    swipeStart = { x: t.clientX, y: t.clientY };
  }
}, { passive: true });

document.addEventListener('touchmove', e => {
  if (!zoomState?.isPanning) return;
  if (e.touches.length !== 1) return;
  e.preventDefault();
  const t = e.touches[0];
  if (Math.abs(t.clientX - zoomState.dragStartX) > 4 || Math.abs(t.clientY - zoomState.dragStartY) > 4)
    zoomState.dragMoved = true;
  zoomState.panX = t.clientX - zoomState.startX;
  zoomState.panY = t.clientY - zoomState.startY;
  applyZoomTransform();
}, { passive: false });

document.addEventListener('touchend', e => {
  if (zoomState) {
    zoomState.isPanning = false;
    zoomState.imgEl.classList.remove('panning');
  } else if (swipeStart) {
    const t = e.changedTouches[0];
    const dx = t.clientX - swipeStart.x;
    const dy = t.clientY - swipeStart.y;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
      if (dx < 0) goTo(idx + 1); // swipe venstre = eldre bilde
      else         goTo(idx - 1); // swipe høyre = nyere bilde
    }
    swipeStart = null;
  }
});

// ── Keyboard ─────────────────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.key === 'Escape')     resetZoom();
  if (e.key === 'ArrowLeft')  goTo(idx - 1); // nyere
  if (e.key === 'ArrowRight') goTo(idx + 1); // eldre
  if (e.key === 'Home') goTo(0);
  if (e.key === 'End')  goTo(images.length - 1);
});

// ── Empty state ───────────────────────────────────────────────────────────────
function showEmptyState() {
  document.getElementById('stage').innerHTML = `
    <div class="state-box">
      <h2>Ingen bilder lagret ennå</h2>
      <p>Legg CDSE-legitimasjon i <strong>.sentinel.env</strong> (ett nivå opp fra webroot), deretter hent bilder.</p>
      <code>
; .sentinel.env<br>
SH_CLIENT_ID=...<br>
SH_CLIENT_SECRET=...<br>
FETCH_TOKEN=...
      </code>
      <p style="margin-top:8px">
        Opprett OAuth-klient (grant: client_credentials) på
        <strong>shapps.dataspace.copernicus.eu</strong> → Account settings → OAuth Clients
        — klikk deretter <em>↓ Hent</em> oppe til høyre.
      </p>
    </div>`;
  document.getElementById('timeline').innerHTML = '';
  document.getElementById('counter').textContent = '0 bilder';
}

function showErrorState(msg) {
  const stage = document.getElementById('stage');
  stage.innerHTML = `
    <div class="state-box">
      <h2>Feil ved lasting</h2>
      <p></p>
    </div>`;
  stage.querySelector('p').textContent = msg;
}

// ── Notification ──────────────────────────────────────────────────────────────
let notifTimer;
function notify(msg, isError = false) {
  const el = document.getElementById('notif');
  el.textContent = msg;
  el.className = 'notif show' + (isError ? ' error' : '');
  clearTimeout(notifTimer);
  notifTimer = setTimeout(() => el.classList.remove('show'), 4000);
}

// ── Format dates ──────────────────────────────────────────────────────────────
function formatDate(dateStr) {
  const [y, m, d] = dateStr.split('-');
  const months = ['januar','februar','mars','april','mai','juni',
                  'juli','august','september','oktober','november','desember'];
  return `${parseInt(d)}. ${months[parseInt(m)-1]} ${y}`;
}

function formatDateShort(dateStr) {
  const [y, m, d] = dateStr.split('-');
  return `${d}.${m}.${y.slice(2)}`;
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.body.classList.toggle('pro-mode', proMode);
updateProBtn();
loadImages();
loadNext();
</script>
</body>
</html>
