<?php
declare(strict_types=1);

/**
 * Foraging map.
 *
 * One select: genus groupings first, then individual species. It defaults to
 * apples - which is four species names in this data (Crabapple, Prairifire
 * Crab Apple, Crabapple Harvest Gold, Paradise Apple), so the default is the
 * Malus group rather than any single one of them.
 *
 * The Google Maps key comes from GOOGLE_MAPS_API_KEY (checked in getenv,
 * $_SERVER and $_ENV, since Apache SetEnv and nginx fastcgi_param only reach
 * $_SERVER) or from maps-key.txt beside this file.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

function cleanKey(string $raw): string
{
    $v = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;   // BOM
    $v = trim(trim($v), "\"' \t\r\n");                        // stray quotes
    return preg_replace('/\s+/', '', $v) ?? $v;
}

function mapsKey(): string
{
    $n = 'GOOGLE_MAPS_API_KEY';
    foreach ([getenv($n), $_SERVER[$n] ?? null, $_ENV[$n] ?? null] as $v) {
        if (is_string($v) && cleanKey($v) !== '') {
            return cleanKey($v);
        }
    }
    $f = __DIR__ . '/maps-key.txt';
    return is_readable($f) ? cleanKey((string) file_get_contents($f)) : '';
}

$key     = mapsKey();
$default = (string) ($_GET['species'] ?? 'genus:Malus');

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Foraging map</title>
<style>
  :root { --ink:#22201d; --dim:#6f6a63; --line:#d9d3c8; --bg:#f6f3ec; --panel:#fff; --accent:#7a8b3c; }
  * { box-sizing:border-box; }
  html, body { height:100%; }
  body { margin:0; background:var(--bg); color:var(--ink); display:flex; flex-direction:column;
         font:15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; }
  header { padding:.7rem 1rem; background:var(--panel); border-bottom:1px solid var(--line);
           display:flex; gap:1rem; align-items:center; flex-wrap:wrap; }
  h1 { font-size:1rem; margin:0; white-space:nowrap; }
  label { font-size:.68rem; text-transform:uppercase; letter-spacing:.07em; color:var(--dim); }
  select { font:inherit; font-size:.88rem; padding:.28rem .45rem; border:1px solid var(--line);
           border-radius:5px; background:#fff; color:var(--ink); max-width:26rem; }
  button { font:inherit; font-size:.85rem; padding:.28rem .9rem; border:1px solid var(--accent);
           background:var(--accent); color:#fff; border-radius:5px; cursor:pointer; }
  .field { display:flex; gap:.4rem; align-items:center; }
  #status { font-size:.82rem; color:var(--dim); margin-left:auto; }
  #status b { color:var(--ink); }
  #panes { flex:1 1 auto; display:flex; min-height:24rem; }
  #map { flex:1 1 auto; min-height:24rem; }
  #pano { flex:0 0 40%; min-width:18rem; display:none; position:relative;
          border-left:1px solid var(--line); }
  #pano.on { display:block; }
  #panoView { position:absolute; inset:0; }
  #panoMsg { position:absolute; inset:0; display:flex; align-items:center;
             justify-content:center; text-align:center; padding:1.5rem;
             color:var(--dim); font-size:.88rem; background:var(--panel); }
  #panoClose { position:absolute; top:.5rem; right:.5rem; z-index:5; font-size:.8rem;
               padding:.15rem .6rem; background:#fff; color:var(--ink);
               border:1px solid var(--line); border-radius:4px; cursor:pointer; }
  #panoLabel { position:absolute; left:.5rem; bottom:.5rem; z-index:5; font-size:.78rem;
               background:#ffffffdd; padding:.2rem .5rem; border-radius:4px; }
  @media (max-width: 46rem) {
    #panes { flex-direction:column; }
    #pano { flex:0 0 45%; border-left:none; border-top:1px solid var(--line); }
  }
  .warn { background:#fff5d6; border-bottom:1px solid #e0cf8a; color:#5d4b12;
          padding:.75rem 1rem; font-size:.88rem; }
  .warn code { background:#00000010; padding:.05rem .3rem; border-radius:3px; }
  .iw { font:13px/1.45 -apple-system, system-ui, sans-serif; max-width:18rem; }
  .iw h3 { margin:0 0 .15rem; font-size:.95rem; }
  .iw .where { color:#6f6a63; margin:0 0 .35rem; }
  .iw .notes { margin:.35rem 0 0; color:#4a453e; font-size:.8rem; }
  .iw .links { margin:.5rem 0 0; font-size:.82rem; }
  .iw .links a { color:#5c6b28; }
</style>
</head>
<body>

<?php if ($key === ''): ?>
  <div class="warn">
    <strong>No Google Maps key.</strong> Put one in <code>maps-key.txt</code> or set
    <code>GOOGLE_MAPS_API_KEY</code>. See <a href="?keycheck=1">?keycheck=1</a>.
  </div>
<?php endif; ?>

<header>
  <h1>Foraging map</h1>
  <div class="field">
    <label for="species">Species</label>
    <select id="species"><option value="">loading&hellip;</option></select>
  </div>
  <button id="fit">Zoom to fit</button>
  <div id="status">loading&hellip;</div>
</header>

<div id="panes">
  <div id="map"></div>
  <div id="pano">
    <div id="panoView"></div>
    <div id="panoMsg"></div>
    <button id="panoClose" type="button">close</button>
    <div id="panoLabel"></div>
  </div>
</div>
<div id="authfail" class="warn" style="display:none"></div>

<script>
const CONSOLE_LOG = [];
['error', 'warn'].forEach(level => {
  const original = console[level].bind(console);
  console[level] = (...a) => {
    try { CONSOLE_LOG.push(level + ': ' + a.map(String).join(' ')); } catch (e) {}
    original(...a);
  };
});

const START_SPECIES = <?= json_encode($default) ?>;
const KITCHENER = { lat: 43.4516, lng: -80.4925 };

let map, cluster, infoWindow, markers = [], token = 0, mapReady = false;
const $ = id => document.getElementById(id);
const setStatus = html => { $('status').innerHTML = html; };

function showDiagnostics(headline) {
  const el = $('authfail');
  el.style.display = 'block';
  el.innerHTML = '<strong>' + headline + '</strong>'
    + '<ul style="margin:.4rem 0 0 1.1rem;padding:0">'
    + '<li><code>BillingNotEnabledMapError</code> &mdash; no billing account on the project.</li>'
    + '<li><code>RefererNotAllowedMapError</code> &mdash; add <code>' + location.host + '/*</code> to the key.</li>'
    + '<li><code>ApiNotActivatedMapError</code> &mdash; enable Maps JavaScript API.</li>'
    + '</ul>'
    + (CONSOLE_LOG.length
        ? '<pre style="white-space:pre-wrap;font-size:.78rem">' + CONSOLE_LOG.join('\n') + '</pre>'
        : '');
}
window.gm_authFailure = () => showDiagnostics('Google rejected the API key.');
setTimeout(() => { if (!mapReady) showDiagnostics('The map did not initialise.'); }, 8000);

/** One select: genus groupings first, then every individual species. */
async function loadSpecies() {
  const res = await fetch('api.php?facets=1');
  const raw = await res.text();
  let j;
  try { j = JSON.parse(raw); }
  catch (e) { setStatus('<b>bad response from api.php</b>'); console.error(raw.slice(0, 600)); return; }
  if (!j.ok) { setStatus('<b>error:</b> ' + j.error); return; }

  const sel = $('species');
  sel.innerHTML = '';

  const all = document.createElement('option');
  all.value = '';
  all.textContent = `everything (${j.total})`;
  sel.appendChild(all);

  if (j.groups.length) {
    const og = document.createElement('optgroup');
    og.label = 'Groups';
    j.groups.forEach(g => {
      const o = document.createElement('option');
      o.value = 'genus:' + g.genus;
      o.textContent = `${g.genus} — all (${g.n})`;
      og.appendChild(o);
    });
    sel.appendChild(og);
  }

  const os = document.createElement('optgroup');
  os.label = 'Species';
  j.species.forEach(s => {
    const o = document.createElement('option');
    o.value = s.species;
    o.textContent = `${s.species} (${s.n})`;
    os.appendChild(o);
  });
  sel.appendChild(os);

  sel.value = START_SPECIES;
  if (!sel.value) sel.value = '';       // fall back if the default is absent
}

let panorama, svService;

/**
 * Show the tree in Street View.
 *
 * Two things stop this being useful if you skip them. The nearest panorama is
 * usually somewhere out in the road rather than at the tree, so we ask the
 * service for the closest one within 60 m. And having found it, the camera has
 * to be turned to face the tree - computeHeading from the panorama's real
 * position to the tree's - or you get whatever direction the car happened to be
 * pointing. Pitch is nudged up because trees are taller than cars.
 */
function showStreetView(t) {
  const target = new google.maps.LatLng(+t.lat, +t.lng);
  const pane   = $('pano');
  const msg    = $('panoMsg');

  pane.classList.add('on');
  google.maps.event.trigger(map, 'resize');
  msg.style.display = 'flex';
  msg.textContent = 'looking for a nearby panorama\u2026';
  $('panoLabel').textContent = [t.species, t.name].filter(Boolean).join(' \u00b7 ');

  svService.getPanorama({ location: target, radius: 60, source: 'outdoor' }, (data, status) => {
    if (status !== 'OK' || !data || !data.location) {
      msg.style.display = 'flex';
      msg.innerHTML = 'No Street View coverage within 60&nbsp;m.<br>'
        + 'Parks and back lots often have none. '
        + `<a target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=${t.lat},${t.lng}">`
        + 'Open in Google Maps</a>';
      return;
    }

    const from    = data.location.latLng;
    const heading = google.maps.geometry.spherical.computeHeading(from, target);
    const away    = google.maps.geometry.spherical.computeDistanceBetween(from, target);

    msg.style.display = 'none';
    if (!panorama) {
      panorama = new google.maps.StreetViewPanorama($('panoView'), {
        addressControl: false, fullscreenControl: true, motionTracking: false,
        motionTrackingControl: false,
      });
    }
    panorama.setPano(data.location.pano);
    panorama.setPov({ heading, pitch: 10 });
    panorama.setVisible(true);
    $('panoLabel').textContent =
      [t.species, t.name].filter(Boolean).join(' \u00b7 ') + ` \u00b7 ${Math.round(away)} m away`;
  });
}

function clearMarkers() {
  if (cluster) cluster.clearMarkers();
  markers.forEach(m => m.setMap(null));
  markers = [];
}

/**
 * Trees currently on the map, so the info window's links can find their row.
 * The window's content is an HTML string, so there is nothing to bind a
 * listener to until it opens - a lookup by id is simpler than a domready hook.
 */
const treeIndex = new Map();

window.streetViewFor = function (id) {
  const t = treeIndex.get(String(id));
  if (t) showStreetView(t);
  return false;
};

function popup(t) {
  const notes = (t.notes || '').replace(/\s*\[kt:\d+\]\.?/, '').trim();
  const gmaps = `https://www.google.com/maps/search/?api=1&query=${t.lat},${t.lng}`;
  return `<div class="iw"><h3>${t.species || 'Unknown'}</h3>`
       + (t.name ? `<p class="where">${t.name}${t.source ? ', ' + t.source : ''}</p>` : '')
       + (t.date ? `<p class="where">seen ${t.date}</p>` : '')
       + (notes ? `<p class="notes">${notes}</p>` : '')
       + `<p class="links">`
       + `<a href="#" onclick="return streetViewFor('${t.id}')">Street view</a>`
       + ` &middot; <a href="${gmaps}" target="_blank" rel="noopener">Google Maps</a>`
       + `</p></div>`;
}

async function load(useBounds) {
  const mine = ++token;
  const p = new URLSearchParams();
  if ($('species').value) p.set('species', $('species').value);
  if (useBounds && map) {
    const b = map.getBounds();
    if (b) {
      const ne = b.getNorthEast(), sw = b.getSouthWest();
      p.set('n', ne.lat()); p.set('e', ne.lng()); p.set('s', sw.lat()); p.set('w', sw.lng());
    }
  }

  setStatus('loading&hellip;');
  let j;
  try {
    const res = await fetch('api.php?' + p.toString());
    const raw = await res.text();
    try { j = JSON.parse(raw); }
    catch (e) { setStatus('<b>bad response</b>'); console.error(raw.slice(0, 600)); return; }
  } catch (e) { setStatus('<b>request failed</b>'); return; }

  if (mine !== token) return;
  if (!j.ok) { setStatus('<b>error:</b> ' + j.error); return; }

  clearMarkers();
  treeIndex.clear();
  j.trees.forEach(t => treeIndex.set(String(t.id), t));
  markers = j.trees.map(t => {
    const m = new google.maps.Marker({ position: { lat: +t.lat, lng: +t.lng } });
    m.addListener('click', () => {
      infoWindow.setContent(popup(t));
      infoWindow.open(map, m);
    });
    return m;
  });
  if (cluster) cluster.addMarkers(markers);
  else markers.forEach(m => m.setMap(map));

  setStatus(j.total === 0
    ? '<b>0</b> matching'
    : `<b>${j.shown}</b> of <b>${j.total}</b>` + (j.capped ? ' &mdash; capped, zoom in' : ''));
  return j;
}

function fitTo(j) {
  if (!j || !j.bounds || j.bounds.n === null) return;
  map.fitBounds(new google.maps.LatLngBounds(
    { lat: +j.bounds.s, lng: +j.bounds.w },
    { lat: +j.bounds.n, lng: +j.bounds.e }
  ));
}

async function initMap() {
  mapReady = true;
  map = new google.maps.Map($('map'), {
    center: KITCHENER, zoom: 12, streetViewControl: false,
  });
  infoWindow = new google.maps.InfoWindow();
  svService  = new google.maps.StreetViewService();
  if (window.markerClusterer) cluster = new markerClusterer.MarkerClusterer({ map });

  $('panoClose').addEventListener('click', () => {
    $('pano').classList.remove('on');
    google.maps.event.trigger(map, 'resize');
  });

  await loadSpecies();
  fitTo(await load(false));

  let idle = null;
  map.addListener('idle', () => { clearTimeout(idle); idle = setTimeout(() => load(true), 250); });
  $('species').addEventListener('change', async () => fitTo(await load(false)));
  $('fit').addEventListener('click', async () => fitTo(await load(false)));
}
window.initMap = initMap;
</script>

<script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js"></script>
<?php if ($key !== ''): ?>
<script async
  src="https://maps.googleapis.com/maps/api/js?key=<?= h(rawurlencode($key)) ?>&amp;callback=initMap&amp;loading=async&amp;libraries=geometry"
  onerror="showDiagnostics('The Google Maps script could not be fetched.')"></script>
<?php endif; ?>
</body>
</html>
