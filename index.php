<?php
/**
 * LudoHealt - Dashboard salute braccialetto H59.
 * - Mostra i dati salvati nel database MySQL `ludohealt`.
 * - Bottoni per sincronizzare/misurare dal braccialetto (lancia collect.py).
 *
 * Avvio consigliato (da Terminale, per i permessi Bluetooth):
 *   cd LudoHealt && php -S 127.0.0.1:8080
 * poi apri http://127.0.0.1:8080
 */

/* ---------- Config da .env (vedi .env.example) ---------- */
function load_env(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        if (getenv($k) === false) putenv("$k=" . trim($v));
    }
}
load_env(__DIR__ . '/.env');

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'ludohealt');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

function db(): PDO {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

/* ---------- Endpoint AJAX: lancia la sincronizzazione col braccialetto ---------- */
if (($_GET['action'] ?? '') === 'sync') {
    set_time_limit(0);                       // la misura puo' durare alcuni minuti
    ini_set('max_execution_time', '0');
    ignore_user_abort(true);
    header('Content-Type: application/json');
    $mode = in_array($_GET['mode'] ?? '', ['quick', 'full', 'history']) ? $_GET['mode'] : 'quick';
    $dir = __DIR__;
    $py = $dir . '/.venv/bin/python';
    $cmd = 'cd ' . escapeshellarg($dir) . ' && '
         . escapeshellarg($py) . ' collect.py --mode ' . escapeshellarg($mode)
         . ' 2>> ' . escapeshellarg($dir . '/sync.log');
    $out = [];
    exec($cmd, $out, $code);
    $json = trim(end($out) ?: '');
    if ($json === '' || $json[0] !== '{') {
        echo json_encode(['ok' => false, 'errors' => ['Nessuna risposta dal collettore. Vedi sync.log'], 'raw' => $json]);
    } else {
        echo $json;
    }
    exit;
}

/* ---------- Periodo selezionato ---------- */
$RANGES = ['today' => 'Oggi', '24h' => 'Ultime 24h', '7d' => '7 giorni', '30d' => '30 giorni', 'custom' => 'Personalizzato'];
$range = array_key_exists($_GET['range'] ?? '', $RANGES) ? $_GET['range'] : '24h';
$TZL = new DateTimeZone('Europe/Rome');
$UTC = new DateTimeZone('UTC');
$now = new DateTime('now', $UTC);
$start = null; $end = null;
switch ($range) {
    case 'today':
        $d = new DateTime('now', $TZL); $d->setTime(0, 0, 0); $d->setTimezone($UTC);
        $start = $d->format('Y-m-d H:i:s'); break;
    case '7d':  $start = (clone $now)->modify('-7 day')->format('Y-m-d H:i:s'); break;
    case '30d': $start = (clone $now)->modify('-30 day')->format('Y-m-d H:i:s'); break;
    case 'custom':
        $f = DateTime::createFromFormat('Y-m-d', $_GET['from'] ?? '', $TZL) ?: new DateTime('now', $TZL);
        $t = DateTime::createFromFormat('Y-m-d', $_GET['to'] ?? '', $TZL) ?: new DateTime('now', $TZL);
        $f->setTime(0, 0, 0); $t->setTime(23, 59, 59);
        $f->setTimezone($UTC); $t->setTimezone($UTC);
        $start = $f->format('Y-m-d H:i:s'); $end = $t->format('Y-m-d H:i:s'); break;
    case '24h': default:
        $start = (clone $now)->modify('-24 hour')->format('Y-m-d H:i:s'); break;
}
// condizione SQL sul periodo (valori generati da noi, non da input grezzo)
$cond = "ts >= '$start'" . ($end ? " AND ts <= '$end'" : "");
$longRange = !in_array($range, ['today', '24h']);  // etichette con la data per periodi lunghi
$custom_from = $_GET['from'] ?? (new DateTime('now', $TZL))->format('Y-m-d');
$custom_to   = $_GET['to']   ?? (new DateTime('now', $TZL))->format('Y-m-d');

function tlabel($ts, $long = false) {
    // timestamp UTC dal DB -> etichetta locale (Europe/Rome)
    $d = new DateTime($ts, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('Europe/Rome'));
    return $d->format($long ? 'd/m H:i' : 'H:i');
}

function tlocal($ts) {
    // timestamp UTC dal DB -> data e ora locale completa (Europe/Rome)
    $d = new DateTime($ts, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('Europe/Rome'));
    return $d->format('d/m/Y H:i:s');
}

/* ---------- Lettura dati per la dashboard ---------- */
$err = null;
$latest = []; $hr = []; $steps = []; $recent = []; $stressHist = []; $hrv = [];
$latestStress = false; $latestHrv = false;
$series = ['spo2' => [], 'blood_pressure' => []];
try {
    $pdo = db();
    $q = $pdo->query("SELECT m.metric, m.value, m.value2, m.unit, m.ts
                      FROM measurements m
                      JOIN (SELECT metric, MAX(id) id FROM measurements GROUP BY metric) x
                        ON m.id = x.id");
    foreach ($q as $r) { $latest[$r['metric']] = $r; }

    foreach ($pdo->query("SELECT ts, bpm FROM hr_samples WHERE $cond ORDER BY ts") as $r) $hr[] = $r;
    foreach ($pdo->query("SELECT ts, steps FROM step_samples WHERE $cond ORDER BY ts") as $r) $steps[] = $r;
    foreach ($pdo->query("SELECT ts, score FROM stress_samples WHERE $cond ORDER BY ts") as $r) $stressHist[] = $r;
    foreach ($pdo->query("SELECT ts, ms FROM hrv_samples WHERE $cond ORDER BY ts") as $r) $hrv[] = $r;
    $latestStress = $pdo->query("SELECT score FROM stress_samples ORDER BY ts DESC LIMIT 1")->fetchColumn();
    $latestHrv    = $pdo->query("SELECT ms FROM hrv_samples ORDER BY ts DESC LIMIT 1")->fetchColumn();
    foreach (array_keys($series) as $metric) {
        $st = $pdo->prepare("SELECT ts, value, value2 FROM measurements
                             WHERE metric = ? AND $cond ORDER BY ts");
        $st->execute([$metric]);
        $series[$metric] = $st->fetchAll();
    }
    $recent = $pdo->query("SELECT ts, metric, value, value2, unit FROM measurements
                           ORDER BY id DESC LIMIT 30")->fetchAll();
} catch (Throwable $e) {
    $err = $e->getMessage();
}

function card_val($latest, $key, $suffix = '') {
    if (!isset($latest[$key])) return '—';
    $r = $latest[$key];
    if ($key === 'blood_pressure') return intval($r['value']) . '/' . intval($r['value2']);
    return rtrim(rtrim(number_format($r['value'], 1, '.', ''), '0'), '.') . $suffix;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LudoHealt</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<style>
  :root { --bg:#0f1419; --card:#1a2129; --acc:#46d39a; --txt:#e6edf3; --mut:#8b98a5; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,system-ui,Segoe UI,Roboto,sans-serif;
         background:var(--bg); color:var(--txt); }
  header { padding:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
  h1 { margin:0; font-size:22px; } h1 span { color:var(--acc); }
  .wrap { max-width:1000px; margin:0 auto; padding:0 16px 60px; }
  .cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin:8px 0 22px; }
  .card { background:var(--card); border-radius:14px; padding:16px; }
  .card .lbl { color:var(--mut); font-size:13px; } .card .big { font-size:28px; font-weight:600; margin-top:4px; }
  .card .u { font-size:14px; color:var(--mut); }
  .panel { background:var(--card); border-radius:14px; padding:18px; margin-bottom:18px; }
  .panel h2 { margin:0 0 12px; font-size:16px; }
  .btns { display:flex; gap:10px; flex-wrap:wrap; }
  button { background:var(--acc); color:#06281c; border:0; border-radius:10px; padding:12px 16px;
           font-size:15px; font-weight:600; cursor:pointer; }
  button.alt { background:#2a3540; color:var(--txt); }
  .rbtn { display:inline-block; background:#2a3540; color:var(--txt); text-decoration:none;
          padding:10px 14px; border-radius:10px; font-size:14px; font-weight:600; }
  .rbtn.on { background:var(--acc); color:#06281c; }
  .customrange { margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; color:var(--mut); font-size:14px; }
  .customrange input[type=date] { background:#0f1419; color:var(--txt); border:1px solid #2a3540;
          border-radius:8px; padding:8px; }
  .grid2 { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:18px; }
  button:disabled { opacity:.5; cursor:wait; }
  #status { margin-top:12px; color:var(--mut); font-size:14px; white-space:pre-wrap; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  td,th { text-align:left; padding:7px 8px; border-bottom:1px solid #232c36; }
  th { color:var(--mut); font-weight:500; }
  .err { background:#3a1f24; color:#ffb4bd; padding:12px 16px; border-radius:10px; margin:10px 0; }
  .hint { color:var(--mut); font-size:13px; margin-top:6px; }
</style>
</head>
<body>
<header class="wrap">
  <h1>Ludo<span>Healt</span></h1>
  <div class="hint">DB: ludohealt · MariaDB/MySQL su 127.0.0.1</div>
</header>

<div class="wrap">

<?php if ($err): ?>
  <div class="err">Errore database: <?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<div class="cards">
  <div class="card"><div class="lbl">Battito</div><div class="big"><?= card_val($latest,'heart_rate') ?><span class="u"> bpm</span></div></div>
  <div class="card"><div class="lbl">SpO2</div><div class="big"><?= card_val($latest,'spo2') ?><span class="u"> %</span></div></div>
  <div class="card"><div class="lbl">Pressione</div><div class="big"><?= card_val($latest,'blood_pressure') ?><span class="u"> mmHg</span></div></div>
  <div class="card"><div class="lbl">Stress</div><div class="big"><?= $latestStress !== false ? intval($latestStress) : card_val($latest,'stress') ?></div></div>
  <div class="card"><div class="lbl">HRV</div><div class="big"><?= $latestHrv !== false ? intval($latestHrv) : '—' ?><span class="u"> ms</span></div></div>
  <div class="card"><div class="lbl">Batteria</div><div class="big"><?= card_val($latest,'battery') ?><span class="u"> %</span></div></div>
</div>

<div class="panel">
  <h2>Periodo</h2>
  <div class="btns">
    <?php foreach (['today','24h','7d','30d'] as $rk): ?>
      <a class="rbtn<?= $range===$rk ? ' on' : '' ?>" href="?range=<?= $rk ?>"><?= $RANGES[$rk] ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="customrange">
    <input type="hidden" name="range" value="custom">
    <span>Personalizzato:</span>
    <label>Da <input type="date" name="from" value="<?= htmlspecialchars($custom_from) ?>"></label>
    <label>A <input type="date" name="to" value="<?= htmlspecialchars($custom_to) ?>"></label>
    <button<?= $range==='custom' ? ' style="background:var(--acc)"' : '' ?>>Applica</button>
  </form>
</div>

<div class="panel">
  <h2>Sincronizza col braccialetto</h2>
  <div class="btns">
    <button onclick="sync('quick')">Misura veloce</button>
    <button class="alt" onclick="sync('full')">Misura completa</button>
    <button class="alt" onclick="sync('history')">Solo storico</button>
  </div>
  <div class="hint">Indossa il braccialetto e tieni il Bluetooth del telefono spento. "Veloce" ≈ 1 min, "Completa" (con pressione e stress) ≈ 3 min.</div>
  <div id="status"></div>
</div>

<?php $pl = $RANGES[$range]; ?>
<div class="panel">
  <h2>Battito · <?= htmlspecialchars($pl) ?></h2>
  <canvas id="hrChart" height="110"></canvas>
</div>

<div class="panel">
  <h2>Passi · <?= htmlspecialchars($pl) ?></h2>
  <canvas id="stepChart" height="110"></canvas>
</div>

<div class="grid2">
  <div class="panel">
    <h2>Pressione · <?= htmlspecialchars($pl) ?></h2>
    <canvas id="bpChart" height="160"></canvas>
  </div>
  <div class="panel">
    <h2>SpO2 · <?= htmlspecialchars($pl) ?></h2>
    <canvas id="spo2Chart" height="160"></canvas>
  </div>
  <div class="panel">
    <h2>Stress · <?= htmlspecialchars($pl) ?></h2>
    <canvas id="stressChart" height="160"></canvas>
  </div>
  <div class="panel">
    <h2>HRV · <?= htmlspecialchars($pl) ?></h2>
    <canvas id="hrvChart" height="160"></canvas>
  </div>
</div>

<div class="panel">
  <h2>Misure recenti</h2>
  <table>
    <tr><th>Quando</th><th>Metrica</th><th>Valore</th></tr>
    <?php foreach ($recent as $r):
        $v = $r['metric'] === 'blood_pressure'
            ? intval($r['value']) . '/' . intval($r['value2'])
            : rtrim(rtrim(number_format($r['value'],1,'.',''),'0'),'.');
    ?>
      <tr><td><?= htmlspecialchars(tlocal($r['ts'])) ?></td><td><?= htmlspecialchars($r['metric']) ?></td>
          <td><?= htmlspecialchars($v) ?> <?= htmlspecialchars($r['unit']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="3" style="color:var(--mut)">Nessuna misura ancora. Premi "Misura veloce".</td></tr><?php endif; ?>
  </table>
</div>

</div>

<script>
<?php $LR = $longRange; ?>
const hrLabels    = <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $hr)) ?>;
const hrVals      = <?= json_encode(array_map(fn($r)=>(int)$r['bpm'], $hr)) ?>;
const stepLabels  = <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $steps)) ?>;
const stepVals    = <?= json_encode(array_map(fn($r)=>(int)$r['steps'], $steps)) ?>;
const bpLabels    = <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $series['blood_pressure'])) ?>;
const bpSys       = <?= json_encode(array_map(fn($r)=>(int)$r['value'], $series['blood_pressure'])) ?>;
const bpDia       = <?= json_encode(array_map(fn($r)=>(int)$r['value2'], $series['blood_pressure'])) ?>;
const spo2Labels  = <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $series['spo2'])) ?>;
const spo2Vals    = <?= json_encode(array_map(fn($r)=>(int)$r['value'], $series['spo2'])) ?>;
const stressLabels= <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $stressHist)) ?>;
const stressVals  = <?= json_encode(array_map(fn($r)=>(int)$r['score'], $stressHist)) ?>;
const hrvLabels   = <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $hrv)) ?>;
const hrvVals     = <?= json_encode(array_map(fn($r)=>(int)$r['ms'], $hrv)) ?>;

const baseOpts = { responsive:true, plugins:{legend:{display:false}},
  scales:{ x:{ ticks:{color:'#8b98a5', maxTicksLimit:12}, grid:{color:'#232c36'} },
           y:{ ticks:{color:'#8b98a5'}, grid:{color:'#232c36'}, beginAtZero:false } } };

function noData(id){
  const c = document.getElementById(id);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#8b98a5'; ctx.font = '14px sans-serif'; ctx.textAlign='center';
  ctx.fillText('Nessun dato nel periodo', c.width/2, c.height/2);
}

function lineChart(id, labels, datasets, zero=false){
  if(!labels.length){ noData(id); return; }
  new Chart(document.getElementById(id), { type:'line', data:{labels, datasets},
    options:{ ...baseOpts, plugins:{legend:{display:datasets.length>1, labels:{color:'#e6edf3'}}},
      scales:{ ...baseOpts.scales, y:{ ...baseOpts.scales.y, beginAtZero:zero } } } });
}

lineChart('hrChart', hrLabels, [{ label:'bpm', data:hrVals, borderColor:'#46d39a',
    backgroundColor:'rgba(70,211,154,.15)', fill:true, tension:.3, pointRadius:2, borderWidth:2 }]);

if(stepLabels.length){
  new Chart(document.getElementById('stepChart'), { type:'bar',
    data:{ labels:stepLabels, datasets:[{ label:'passi', data:stepVals, backgroundColor:'#46d39a' }]},
    options:{ ...baseOpts, scales:{ ...baseOpts.scales, y:{ ...baseOpts.scales.y, beginAtZero:true } } } });
} else noData('stepChart');

lineChart('bpChart', bpLabels, [
  { label:'sistolica', data:bpSys, borderColor:'#ff6b6b', backgroundColor:'rgba(255,107,107,.12)', fill:false, tension:.3, pointRadius:3, borderWidth:2 },
  { label:'diastolica', data:bpDia, borderColor:'#5a9bff', backgroundColor:'rgba(90,155,255,.12)', fill:false, tension:.3, pointRadius:3, borderWidth:2 }
]);
lineChart('spo2Chart', spo2Labels, [{ label:'SpO2 %', data:spo2Vals, borderColor:'#46d39a',
    backgroundColor:'rgba(70,211,154,.15)', fill:true, tension:.3, pointRadius:3, borderWidth:2 }]);
lineChart('stressChart', stressLabels, [{ label:'stress', data:stressVals, borderColor:'#f5b73b',
    backgroundColor:'rgba(245,183,59,.15)', fill:true, tension:.3, pointRadius:2, borderWidth:2 }]);
lineChart('hrvChart', hrvLabels, [{ label:'HRV ms', data:hrvVals, borderColor:'#b07bff',
    backgroundColor:'rgba(176,123,255,.15)', fill:true, tension:.3, pointRadius:2, borderWidth:2 }]);

async function sync(mode){
  const btns = document.querySelectorAll('button'); btns.forEach(b=>b.disabled=true);
  const s = document.getElementById('status');
  s.textContent = 'Connessione al braccialetto e misura in corso... (può richiedere qualche minuto)';
  try{
    const r = await fetch('?action=sync&mode='+mode, {method:'POST'});
    const j = await r.json();
    if(j.ok){
      let msg = '✓ Sincronizzato. ';
      if(j.battery) msg += 'Batteria '+j.battery.level+'%. ';
      if(j.measurements && j.measurements.length) msg += j.measurements.map(m=>
          m.metric==='blood_pressure' ? ('pressione '+m.value+'/'+m.value2) : (m.metric+' '+m.value)).join(', ')+'. ';
      msg += 'Storico: '+j.hr_points+' punti battito, '+j.step_points+' slot passi, '
           + (j.stress_points||0)+' stress, '+(j.hrv_points||0)+' HRV.';
      if(j.errors && j.errors.length) msg += '\nNote: '+j.errors.join(' | ');
      s.textContent = msg + '\nAggiorno i grafici...';
      setTimeout(()=>location.reload(), 1200);
    } else {
      s.textContent = '✗ Errore: '+((j.errors||['sconosciuto']).join(' | '));
    }
  }catch(e){ s.textContent = '✗ Errore di rete: '+e; }
  finally{ btns.forEach(b=>b.disabled=false); }
}
</script>
</body>
</html>
