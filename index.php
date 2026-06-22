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
define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '');
define('OPENROUTER_MODEL', getenv('OPENROUTER_MODEL') ?: 'deepseek/deepseek-v4-pro');

function db(): PDO {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

/* ---------- AI: tabella report + chiamata a OpenRouter ---------- */
function ensure_ai_report(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_report (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        ts            DATETIME NOT NULL,
        model         VARCHAR(64),
        days          INT,
        prompt        MEDIUMTEXT,
        report_short  MEDIUMTEXT,
        report        MEDIUMTEXT,
        report_diet   MEDIUMTEXT,
        tokens_in     INT,
        tokens_out    INT,
        INDEX (ts)
    ) CHARACTER SET utf8mb4");
    // migrazioni per tabelle create prima dell'aggiunta delle nuove colonne
    foreach (['report_short' => 'days', 'report_diet' => 'report'] as $colName => $after) {
        if (!$pdo->query("SHOW COLUMNS FROM ai_report LIKE '$colName'")->fetch()) {
            $pdo->exec("ALTER TABLE ai_report ADD COLUMN $colName MEDIUMTEXT AFTER $after");
        }
    }
}

/* Tabella delle note personali per-giorno (creata pigra: funziona anche senza collect.py). */
function ensure_day_notes(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS day_notes (
        note_date   DATE NOT NULL PRIMARY KEY,
        note        TEXT,
        updated_at  DATETIME NOT NULL
    ) CHARACTER SET utf8mb4");
}

/* Ripulisce l'HTML prodotto dal modello prima di salvarlo/mostrarlo:
 * toglie eventuali fence Markdown, il wrapper di documento e i tag/attributi non sicuri. */
function clean_ai_html(string $s): string {
    $s = trim($s);
    $s = preg_replace('/^```[a-zA-Z]*\s*/', '', $s);   // ```html iniziale
    $s = preg_replace('/\s*```$/', '', $s);            // ``` finale
    if (preg_match('/<body[^>]*>(.*)<\/body>/is', $s, $m)) $s = $m[1];  // tiene solo il body
    $s = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $s);   // via script/style
    $s = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $s); // via handler inline on*=
    return trim($s);
}

/* Alcuni modelli (es. DeepSeek) inseriscono newline/tab/CR *letterali* dentro i valori
 * stringa del JSON invece di escaparli (\n, \t, \r): lo standard li vieta e json_decode
 * fallisce. Qui li ri-escapiamo, ma SOLO quando ci troviamo dentro una stringa, lasciando
 * intatta la struttura. Sicuro su UTF-8: agiamo solo su byte di controllo ASCII. */
function json_fix_ctrl(string $s): string {
    $out = '';
    $in = false;   // siamo dentro una stringa JSON?
    $esc = false;  // il carattere precedente era un backslash di escape?
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $ch = $s[$i];
        if ($in) {
            if ($esc)            { $out .= $ch; $esc = false; continue; }
            if ($ch === '\\')    { $out .= $ch; $esc = true;  continue; }
            if ($ch === '"')     { $out .= $ch; $in = false;  continue; }
            if ($ch === "\n")    { $out .= '\\n'; continue; }
            if ($ch === "\r")    { $out .= '\\r'; continue; }
            if ($ch === "\t")    { $out .= '\\t'; continue; }
            $out .= $ch;
        } else {
            if ($ch === '"') $in = true;
            $out .= $ch;
        }
    }
    return $out;
}

function openrouter_chat(string $prompt, bool $json = false): array {
    if (OPENROUTER_API_KEY === '') {
        throw new RuntimeException('OPENROUTER_API_KEY mancante: aggiungila al file .env');
    }
    $body = [
        'model'    => OPENROUTER_MODEL,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ];
    if ($json) $body['response_format'] = ['type' => 'json_object'];
    $payload = json_encode($body);
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'Content-Type: application/json',
            'HTTP-Referer: http://127.0.0.1:8080',
            'X-Title: LudoHealt',
        ],
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        throw new RuntimeException('Errore di rete verso OpenRouter: ' . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $j = json_decode($resp, true);
    if ($code !== 200) {
        throw new RuntimeException('OpenRouter ha risposto con errore: ' . ($j['error']['message'] ?? ('HTTP ' . $code)));
    }
    $content = $j['choices'][0]['message']['content'] ?? '';
    if ($content === '') {
        throw new RuntimeException('Risposta vuota da OpenRouter');
    }
    return [
        'content'    => $content,
        'tokens_in'  => $j['usage']['prompt_tokens'] ?? null,
        'tokens_out' => $j['usage']['completion_tokens'] ?? null,
    ];
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

/* ---------- Endpoint AJAX: salva/aggiorna la nota personale di un giorno ----------
 * Riceve `date` (YYYY-MM-DD) e `note`. Nota vuota = cancella la nota del giorno.
 * Le note sono contesto qualitativo che viene poi incluso nel prompt dell'analisi AI. */
if (($_GET['action'] ?? '') === 'save_note') {
    header('Content-Type: application/json');
    try {
        $date = (string)($_POST['date'] ?? '');
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new RuntimeException('Data non valida');
        }
        $note = trim((string)($_POST['note'] ?? ''));
        if (function_exists('mb_substr')) $note = mb_substr($note, 0, 2000);  // cap: non gonfiare il prompt
        $pdo = db();
        ensure_day_notes($pdo);
        if ($note === '') {
            $pdo->prepare("DELETE FROM day_notes WHERE note_date=?")->execute([$date]);
        } else {
            $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $st = $pdo->prepare("INSERT INTO day_notes (note_date, note, updated_at) VALUES (?,?,?)
                                 ON DUPLICATE KEY UPDATE note=VALUES(note), updated_at=VALUES(updated_at)");
            $st->execute([$date, $note, $now]);
        }
        echo json_encode(['ok' => true, 'date' => $date, 'has' => $note !== '', 'note' => $note]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'errors' => [$e->getMessage()]]);
    }
    exit;
}

/* ---------- Endpoint AJAX: analisi AI dei trend sanitari (ultimi 6 mesi) ----------
 * Aggrega i dati di salute degli ultimi 6 mesi e costruisce un prompt in italiano
 * a due livelli — dettaglio completo sugli ultimi 7 giorni + recap giornaliero
 * sintetico sui mesi precedenti — lo invia a OpenRouter e salva report e prompt
 * nella tabella ai_report. Una copia del prompt resta in prompt.txt per debug. */
if (($_GET['action'] ?? '') === 'ai_prompt') {
    set_time_limit(0);                 // la risposta del modello puo' richiedere diversi secondi
    ignore_user_abort(true);
    header('Content-Type: application/json');
    try {
        $pdo = db();
        $startUtc  = (new DateTime('now', new DateTimeZone('UTC')))->modify('-6 month')->format('Y-m-d H:i:s');
        $startDate = (new DateTime('now', new DateTimeZone('Europe/Rome')))->modify('-6 month')->format('Y-m-d');

        // Raggruppamento per giorno locale (Europe/Rome) se le tabelle del fuso orario sono
        // caricate in MySQL, altrimenti ripiego sul giorno UTC.
        $tzOk = $pdo->query("SELECT CONVERT_TZ('2024-06-01 12:00:00','+00:00','Europe/Rome')")->fetchColumn() !== null;
        $LD = $tzOk ? "DATE(CONVERT_TZ(ts,'+00:00','Europe/Rome'))" : "DATE(ts)";

        $days = [];  // 'YYYY-MM-DD' => ['hr'=>..., 'spo2'=>..., 'sleep'=>..., ...]
        $put = function (string $d, string $key, $val) use (&$days) {
            if (!isset($days[$d])) $days[$d] = [];
            $days[$d][$key] = $val;
        };
        $agg = function (string $sql) use ($pdo, $startUtc) {
            $st = $pdo->prepare($sql);
            $st->execute([$startUtc]);
            return $st;
        };

        // Battito: min/max giornalieri robusti al 5°/95° percentile, così un singolo
        // glitch del sensore (es. un campione isolato a 40 bpm) non definisce gli estremi
        // del giorno. Sotto i 12 campioni il giorno è poco affidabile → MIN/MAX grezzi.
        foreach ($agg(
            "SELECT d, ROUND(AVG(bpm)) a,
                    ROUND(CASE WHEN COUNT(*) >= 12 THEN MIN(CASE WHEN pr >= 0.05 THEN bpm END) ELSE MIN(bpm) END) mn,
                    ROUND(CASE WHEN COUNT(*) >= 12 THEN MAX(CASE WHEN pr <= 0.95 THEN bpm END) ELSE MAX(bpm) END) mx
               FROM (SELECT $LD d, bpm, PERCENT_RANK() OVER (PARTITION BY $LD ORDER BY bpm) pr
                       FROM hr_samples WHERE ts >= ?) s
              GROUP BY d") as $r) $put($r['d'], 'hr', $r);
        // SpO2: conta il minimo (valore d'allarme), ma robusto al 5° percentile per
        // scartare i singoli cali dovuti a scarso contatto del sensore.
        foreach ($agg(
            "SELECT d, ROUND(AVG(spo2),1) a,
                    ROUND(CASE WHEN COUNT(*) >= 12 THEN MIN(CASE WHEN pr >= 0.05 THEN spo2 END) ELSE MIN(spo2) END) mn
               FROM (SELECT $LD d, spo2, PERCENT_RANK() OVER (PARTITION BY $LD ORDER BY spo2) pr
                       FROM spo2_samples WHERE ts >= ?) s
              GROUP BY d") as $r) $put($r['d'], 'spo2', $r);
        // Stress: conta il picco, robusto al 95° percentile per scartare i singoli picchi spuri.
        foreach ($agg(
            "SELECT d, ROUND(AVG(score)) a,
                    ROUND(CASE WHEN COUNT(*) >= 12 THEN MAX(CASE WHEN pr <= 0.95 THEN score END) ELSE MAX(score) END) mx
               FROM (SELECT $LD d, score, PERCENT_RANK() OVER (PARTITION BY $LD ORDER BY score) pr
                       FROM stress_samples WHERE ts >= ?) s
              GROUP BY d") as $r) $put($r['d'], 'stress', $r);
        foreach ($agg("SELECT $LD d, ROUND(AVG(ms)) a FROM hrv_samples WHERE ts >= ? GROUP BY d") as $r) $put($r['d'], 'hrv', $r);
        foreach ($agg("SELECT $LD d, SUM(steps) steps, SUM(calories) cal, SUM(distance) dist FROM step_samples WHERE ts >= ? GROUP BY d") as $r) $put($r['d'], 'steps', $r);
        foreach ($agg("SELECT $LD d, ROUND(AVG(value)) sys, ROUND(AVG(value2)) dia FROM measurements WHERE metric='blood_pressure' AND ts >= ? GROUP BY d") as $r) $put($r['d'], 'bp', $r);

        // Sonno: sleep_date è già una data locale; sommo i minuti per ciascuna fase.
        $st = $pdo->prepare("SELECT sleep_date d, stage, SUM(minutes) m FROM sleep_segments WHERE sleep_date >= ? GROUP BY sleep_date, stage");
        $st->execute([$startDate]);
        foreach ($st as $r) {
            $d = $r['d'];
            if (!isset($days[$d]['sleep'])) $days[$d]['sleep'] = ['light'=>0,'deep'=>0,'rem'=>0,'awake'=>0,'total'=>0];
            $days[$d]['sleep'][$r['stage']] = (int)$r['m'];
            $days[$d]['sleep']['total'] += (int)$r['m'];
        }
        ksort($days);

        // Note personali scritte dall'utente sui singoli giorni (contesto qualitativo).
        ensure_day_notes($pdo);
        $noteSt = $pdo->prepare("SELECT note_date, note FROM day_notes WHERE note_date >= ? ORDER BY note_date");
        $noteSt->execute([$startDate]);
        $userNotes = [];
        foreach ($noteSt as $r) {
            $n = trim(preg_replace('/\s+/u', ' ', (string)$r['note']));   // su una riga sola nel prompt
            if ($n !== '') $userNotes[$r['note_date']] = $n;
        }

        // Helper per le statistiche annuali e per le celle del CSV.
        $col = function (string $grp, string $key) use ($days) {
            $out = [];
            foreach ($days as $d) if (isset($d[$grp][$key]) && $d[$grp][$key] !== null) $out[] = (float)$d[$grp][$key];
            return $out;
        };
        $num = fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
        $avg = fn(array $a) => $a ? $num(array_sum($a) / count($a)) : '—';
        $mn  = fn(array $a) => $a ? $num(min($a)) : '—';
        $mx  = fn(array $a) => $a ? $num(max($a)) : '—';
        $sum = fn(array $a) => $a ? $num(array_sum($a)) : '—';
        $g   = function (array $row, string $grp, string $key) use ($num) {
            return isset($row[$grp][$key]) && $row[$grp][$key] !== null ? $num($row[$grp][$key]) : '';
        };

        $first = $days ? array_key_first($days) : '—';
        $last  = $days ? array_key_last($days)  : '—';
        $sleepTotals = $col('sleep', 'total');

        // ----- Costruzione del prompt -----
        $P  = "SEI UN MEDICO E ANALISTA DI DATI SANITARI.\n\n";
        $P .= "Analizza i dati di salute raccolti da un braccialetto fitness (modello H59) relativi a un singolo utente negli ultimi 6 mesi. Valuta i dati esclusivamente dal punto di vista sanitario.\n\n";
        $P .= "== ISTRUZIONI ==\n";
        $P .= "1. Riassumi lo stato di salute generale che emerge dai dati.\n";
        $P .= "2. Individua le tendenze nel tempo (miglioramenti o peggioramenti) per ciascuna metrica.\n";
        $P .= "3. Segnala valori anomali o potenziali campanelli d'allarme (es. SpO2 bassa, battito a riposo elevato, HRV in calo, sonno insufficiente, pressione alta), distinguendo le anomalie PERSISTENTI dai singoli valori isolati che possono essere artefatti del sensore (vedi NOTA SULLA QUALITÀ DEI DATI).\n";
        $P .= "4. Evidenzia possibili correlazioni tra le metriche (es. stress alto ↔ HRV basso ↔ sonno scarso).\n";
        $P .= "5. Fornisci consigli pratici e personalizzati per migliorare i parametri.\n";
        $P .= "6. Indica chiaramente quando sarebbe opportuno consultare un medico.\n";
        $P .= "7. Tieni conto delle NOTE PERSONALI scritte dall'utente (sezione dedicata più sotto): usale per interpretare i valori osservati (es. attività fisica, viaggi, alimentazione, stress, malesseri) e per rendere analisi e consigli più pertinenti alla sua vita reale. Distingui ciò che è spiegato da una nota (atteso) da ciò che resta inspiegato (da approfondire).\n";
        $P .= "Rispondi in italiano, in modo chiaro e comprensibile anche a un non esperto.\n";
        $P .= "IMPORTANTE: questa è un'analisi informativa e NON sostituisce il parere di un medico.\n\n";
        $P .= "== FORMATO DELLA RISPOSTA ==\n";
        $P .= "Rispondi ESCLUSIVAMENTE con un oggetto JSON valido (nessun testo fuori dal JSON, nessun fence ```), con esattamente queste tre chiavi:\n";
        $P .= "{\"analisi_veloce\": \"...\", \"analisi_completa\": \"...\", \"consigli_alimentari\": \"...\"}\n";
        $P .= "- \"analisi_veloce\": riassunto SINTETICO (massimo ~120 parole) con i 2-4 punti più importanti ed eventuali campanelli d'allarme.\n";
        $P .= "- \"analisi_completa\": analisi dettagliata che copre tutti i 6 punti delle istruzioni qui sopra.\n";
        $P .= "- \"consigli_alimentari\": consigli pratici di alimentazione ed eventuali integratori, basati SPECIFICAMENTE sui dati di questo utente (es. HRV, stress, sonno, battito, attività fisica). Per ogni alimento/integratore spiega brevemente a cosa serve in relazione ai dati osservati. Ricorda che gli integratori vanno assunti solo dopo aver consultato un medico e non sostituiscono una dieta equilibrata.\n";
        $P .= "Il valore di TUTTE e tre le chiavi deve essere HTML vanilla (niente Markdown), usando solo i tag: <h3>, <h4>, <p>, <ul>, <ol>, <li>, <strong>, <em>, <br>, <table>, <thead>, <tbody>, <tr>, <th>, <td>.\n";
        $P .= "Nella completa usa <h3> per ciascuna sezione e <table> per i confronti numerici.\n";
        $P .= "NON includere CSS inline, attributi style, i tag <html>/<head>/<body>/<style>/<script>, immagini o link esterni.\n";
        $P .= "Fai l'escape delle virgolette interne all'HTML come richiesto dal formato JSON. Tutto il testo in italiano.\n\n";
        $P .= "== METRICHE E UNITÀ ==\n";
        $P .= "- Battito (HR): battiti al minuto (bpm). A riposo tipico 60-100.\n";
        $P .= "- SpO2: saturazione dell'ossigeno nel sangue (%). Normale >= 95%.\n";
        $P .= "- Stress: indice 0-100 (più alto = più stress).\n";
        $P .= "- HRV: variabilità della frequenza cardiaca (ms). Più alto = generalmente migliore.\n";
        $P .= "- Passi / Calorie (kcal) / Distanza (m): attività fisica giornaliera.\n";
        $P .= "- Sonno: minuti totali e suddivisione in fasi (leggero, profondo, REM, sveglio).\n";
        $P .= "- Pressione (PA): sistolica/diastolica (mmHg). Riferimento ~120/80.\n\n";
        $P .= "== NOTA SULLA QUALITÀ DEI DATI ==\n";
        $P .= "I dati provengono da un sensore ottico da polso (H59) che può occasionalmente produrre letture errate ISOLATE (cali o picchi momentanei non fisiologici). Per limitarne l'effetto, i valori min/max giornalieri qui riportati NON sono il minimo/massimo assoluto del giorno ma il 5°/95° percentile: quindi hr_min ≈ battito a riposo e hr_max ≈ picco sotto sforzo, già ripuliti dai singoli glitch.\n";
        $P .= "Di conseguenza:\n";
        $P .= "- Considera attendibili soprattutto le anomalie PERSISTENTI o RICORRENTI (su più ore o più giorni), non i singoli valori fuori scala.\n";
        $P .= "- Se un valore appare clinicamente implausibile e non è confermato dal contesto (giorni vicini, altre metriche correlate), trattalo come probabile artefatto del sensore e segnalalo come tale, senza darlo per certo né costruirci sopra un allarme.\n\n";
        $P .= "== RIEPILOGO PERIODO ==\n";
        $P .= "Intervallo: dal $first al $last — " . count($days) . " giorni con dati.\n";
        $P .= "Battito: media {$avg($col('hr','a'))} bpm (min giornaliero {$mn($col('hr','mn'))}, picco {$mx($col('hr','mx'))}).\n";
        $P .= "SpO2: media {$avg($col('spo2','a'))}% (minimo {$mn($col('spo2','mn'))}%).\n";
        $P .= "Stress: media {$avg($col('stress','a'))} (picco {$mx($col('stress','mx'))}).\n";
        $P .= "HRV: media {$avg($col('hrv','a'))} ms.\n";
        $P .= "Passi: totale {$sum($col('steps','steps'))}, media {$avg($col('steps','steps'))}/giorno.\n";
        $P .= "Pressione: media {$avg($col('bp','sys'))}/{$avg($col('bp','dia'))} mmHg.\n";
        $sleepAvgMin = $sleepTotals ? array_sum($sleepTotals) / count($sleepTotals) : null;
        $P .= "Sonno: media " . ($sleepAvgMin !== null ? round($sleepAvgMin / 60, 1) . "h" : "—") . " a notte (" . count($sleepTotals) . " notti registrate).\n\n";
        // Dati giornalieri su DUE livelli: dettaglio completo sugli ultimi 7 giorni
        // (per consigli azionabili) e recap sintetico sui mesi precedenti (per i trend).
        // I 7 giorni recenti sono ESCLUSI dal recap per non duplicare gli stessi giorni.
        $cut7   = (new DateTime('now', new DateTimeZone('Europe/Rome')))->modify('-7 day')->format('Y-m-d');
        $recent = array_filter($days, fn($d) => $d >  $cut7, ARRAY_FILTER_USE_KEY);
        $older  = array_filter($days, fn($d) => $d <= $cut7, ARRAY_FILTER_USE_KEY);

        $P .= "I dati giornalieri sono su DUE livelli: gli ULTIMI 7 GIORNI in dettaglio completo (usali per i consigli azionabili) e i MESI PRECEDENTI come recap sintetico (usalo per i trend di lungo periodo). I 7 giorni recenti NON sono ripetuti nel recap.\n\n";

        $P .= "== ULTIMI 7 GIORNI (dettaglio, CSV) ==\n";
        $P .= "data,hr_med,hr_min,hr_max,spo2_med,spo2_min,stress_med,stress_max,hrv_med,passi,kcal,dist_m,sonno_tot_min,sonno_leggero,sonno_profondo,sonno_rem,sonno_sveglio,pa_sist,pa_diast\n";
        foreach ($recent as $d => $row) {
            $sl = $row['sleep'] ?? null;
            $P .= implode(',', [
                $d,
                $g($row,'hr','a'), $g($row,'hr','mn'), $g($row,'hr','mx'),
                $g($row,'spo2','a'), $g($row,'spo2','mn'),
                $g($row,'stress','a'), $g($row,'stress','mx'),
                $g($row,'hrv','a'),
                $g($row,'steps','steps'), $g($row,'steps','cal'), $g($row,'steps','dist'),
                $sl ? $sl['total'] : '', $sl ? $sl['light'] : '', $sl ? $sl['deep'] : '', $sl ? $sl['rem'] : '', $sl ? $sl['awake'] : '',
                $g($row,'bp','sys'), $g($row,'bp','dia'),
            ]) . "\n";
        }
        if (!$recent) $P .= "(nessun dato negli ultimi 7 giorni)\n";

        $P .= "\n== MESI PRECEDENTI (recap giornaliero, CSV) ==\n";
        $P .= "data,hr_med,hr_min,hr_max,spo2_med,spo2_min,stress_med,hrv_med,passi,sonno_tot_min\n";
        foreach ($older as $d => $row) {
            $sl = $row['sleep'] ?? null;
            $P .= implode(',', [
                $d,
                $g($row,'hr','a'), $g($row,'hr','mn'), $g($row,'hr','mx'),
                $g($row,'spo2','a'), $g($row,'spo2','mn'),
                $g($row,'stress','a'),
                $g($row,'hrv','a'),
                $g($row,'steps','steps'),
                $sl ? $sl['total'] : '',
            ]) . "\n";
        }
        if (!$older) $P .= "(nessun dato nei mesi precedenti)\n";

        $P .= "\n== NOTE PERSONALI DELL'UTENTE (contesto qualitativo) ==\n";
        $P .= "Annotazioni scritte dall'utente per spiegare cosa è successo in certi giorni o fasce orarie (attività fisica, eventi, viaggi, alimentazione, malesseri...). NON sono dati clinici ma contesto reale: usale per interpretare i numeri e personalizzare consigli e segnalazioni (es. molti passi e battito alto in un giorno con nota \"corsa\" sono attesi; un sonno scarso con nota \"viaggio\" non è un campanello d'allarme). Formato: una riga per giorno, \"data: nota\".\n";
        if ($userNotes) {
            foreach ($userNotes as $d => $n) $P .= "$d: $n\n";
        } else {
            $P .= "(nessuna nota inserita dall'utente)\n";
        }

        $P .= "== FINE DATI ==\n\nProcedi ora con l'analisi sanitaria seguendo le istruzioni qui sopra.\n";

        @file_put_contents(__DIR__ . '/prompt.txt', $P);   // copia di debug del prompt inviato

        // Chiamata al modello: attesa un JSON con analisi_veloce + analisi_completa.
        $ai = openrouter_chat($P, true);
        $content = preg_replace('/^```[a-zA-Z]*\s*/', '', trim($ai['content']));
        $content = preg_replace('/\s*```$/', '', $content);
        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {                          // secondo tentativo: ri-escapa i caratteri di controllo letterali
            $parsed = json_decode(json_fix_ctrl($content), true);
        }
        if (is_array($parsed) && isset($parsed['analisi_veloce'], $parsed['analisi_completa'])) {
            $short = clean_ai_html((string)$parsed['analisi_veloce']);
            $full  = clean_ai_html((string)$parsed['analisi_completa']);
            $diet  = clean_ai_html((string)($parsed['consigli_alimentari'] ?? ''));
        } else {
            // fallback: il modello non ha restituito il JSON atteso
            $full  = clean_ai_html($content);
            $short = $full;
            $diet  = '';
        }

        ensure_ai_report($pdo);
        $st = $pdo->prepare("INSERT INTO ai_report (ts, model, days, prompt, report_short, report, report_diet, tokens_in, tokens_out)
                             VALUES (?,?,?,?,?,?,?,?,?)");
        $st->execute([
            (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            OPENROUTER_MODEL, count($days), $P, $short, $full, $diet, $ai['tokens_in'], $ai['tokens_out'],
        ]);
        echo json_encode([
            'ok'         => true,
            'id'         => (int)$pdo->lastInsertId(),
            'model'      => OPENROUTER_MODEL,
            'days'       => count($days),
            'tokens_in'  => $ai['tokens_in'],
            'tokens_out' => $ai['tokens_out'],
            'short'      => $short,
            'full'       => $full,
            'diet'       => $diet,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'errors' => [$e->getMessage()]]);
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
$latest = []; $hr = []; $steps = []; $stepsByDay = []; $recent = []; $stressHist = []; $hrv = []; $allrows = [];
$spo2hist = []; $sleepSegs = []; $sleepDate = null; $sleepStart = null; $sleepDays = [];
$latestStress = false; $latestHrv = false; $latestSpo2 = false;
$series = ['spo2' => [], 'blood_pressure' => []];
$daysWithData = []; $dayNotes = [];   // diario: giorni con dati + note personali
try {
    $pdo = db();
    // Giorno locale (Europe/Rome) per i raggruppamenti, con fallback a UTC se le
    // tabelle del fuso orario non sono caricate in MySQL.
    $tzOk = $pdo->query("SELECT CONVERT_TZ('2024-06-01 12:00:00','+00:00','Europe/Rome')")->fetchColumn() !== null;
    $LD = $tzOk ? "DATE(CONVERT_TZ(ts,'+00:00','Europe/Rome'))" : "DATE(ts)";
    $q = $pdo->query("SELECT m.metric, m.value, m.value2, m.unit, m.ts
                      FROM measurements m
                      JOIN (SELECT metric, MAX(id) id FROM measurements GROUP BY metric) x
                        ON m.id = x.id");
    foreach ($q as $r) { $latest[$r['metric']] = $r; }

    foreach ($pdo->query("SELECT ts, bpm FROM hr_samples WHERE $cond ORDER BY ts") as $r) $hr[] = $r;
    foreach ($pdo->query("SELECT ts, steps FROM step_samples WHERE $cond ORDER BY ts") as $r) $steps[] = $r;
    // Passi sommati per giorno locale: usato dal grafico quando il periodo copre più giorni.
    foreach ($pdo->query("SELECT $LD d, SUM(steps) s FROM step_samples WHERE $cond GROUP BY d ORDER BY d") as $r)
        $stepsByDay[] = ['d' => $r['d'], 's' => (int)$r['s']];
    foreach ($pdo->query("SELECT ts, score FROM stress_samples WHERE $cond ORDER BY ts") as $r) $stressHist[] = $r;
    foreach ($pdo->query("SELECT ts, ms FROM hrv_samples WHERE $cond ORDER BY ts") as $r) $hrv[] = $r;
    foreach ($pdo->query("SELECT ts, spo2 FROM spo2_samples WHERE $cond ORDER BY ts") as $r) $spo2hist[] = $r;
    $latestStress = $pdo->query("SELECT score FROM stress_samples ORDER BY ts DESC LIMIT 1")->fetchColumn();
    $latestHrv    = $pdo->query("SELECT ms FROM hrv_samples ORDER BY ts DESC LIMIT 1")->fetchColumn();
    $latestSpo2   = $pdo->query("SELECT spo2 FROM spo2_samples ORDER BY ts DESC LIMIT 1")->fetchColumn();
    // sonno: ultimo giorno disponibile (le fasi sono per-giorno, non per-periodo)
    $sleepDate = $pdo->query("SELECT MAX(sleep_date) FROM sleep_segments")->fetchColumn();
    if ($sleepDate) {
        $st = $pdo->prepare("SELECT idx, stage, minutes FROM sleep_segments WHERE sleep_date=? ORDER BY idx");
        $st->execute([$sleepDate]);
        $sleepSegs = $st->fetchAll();
        $ss = $pdo->prepare("SELECT start_ts FROM sleep_sessions WHERE sleep_date=?");
        $ss->execute([$sleepDate]);
        $sleepStart = $ss->fetchColumn() ?: null;
    }
    // sonno: tutte le notti del periodo selezionato, per la verifica giorno-per-giorno.
    // sleep_date e' una data locale; mappo i bordi UTC del range a date locali.
    $sleepFrom = (new DateTime($start, $UTC))->setTimezone($TZL)->format('Y-m-d');
    $sleepTo   = (new DateTime($end ?: 'now', $end ? $UTC : $TZL))->setTimezone($TZL)->format('Y-m-d');
    $sleepDays = [];
    $st = $pdo->prepare("SELECT s.sleep_date d, s.idx, s.stage, s.minutes, ss.start_ts
                         FROM sleep_segments s
                         LEFT JOIN sleep_sessions ss ON ss.sleep_date = s.sleep_date
                         WHERE s.sleep_date BETWEEN ? AND ?
                         ORDER BY s.sleep_date, s.idx");
    $st->execute([$sleepFrom, $sleepTo]);
    foreach ($st as $r) {
        $d = $r['d'];
        if (!isset($sleepDays[$d])) $sleepDays[$d] = ['date' => $d, 'start' => $r['start_ts'], 'segs' => []];
        $sleepDays[$d]['segs'][] = ['idx' => $r['idx'], 'stage' => $r['stage'], 'minutes' => $r['minutes']];
    }
    foreach (array_keys($series) as $metric) {
        $st = $pdo->prepare("SELECT ts, value, value2 FROM measurements
                             WHERE metric = ? AND $cond ORDER BY ts");
        $st->execute([$metric]);
        $series[$metric] = $st->fetchAll();
    }
    $recent = $pdo->query("SELECT ts, metric, value, value2, unit FROM measurements
                           ORDER BY id DESC LIMIT 30")->fetchAll();
    // tabella unica: tutti i dati salvati (storico + misure) del periodo, dal piu' recente
    $allrows = $pdo->query(
        "SELECT ts, 'Battito' tipo, bpm v1, NULL v2, NULL v3, 'bpm' unit FROM hr_samples WHERE $cond
         UNION ALL SELECT ts, 'Passi', steps, calories, distance, 'passi' FROM step_samples WHERE $cond
         UNION ALL SELECT ts, 'Stress', score, NULL, NULL, '' FROM stress_samples WHERE $cond
         UNION ALL SELECT ts, 'HRV', ms, NULL, NULL, 'ms' FROM hrv_samples WHERE $cond
         UNION ALL SELECT ts, metric, value, value2, NULL, unit FROM measurements WHERE $cond
         ORDER BY ts DESC LIMIT 1000")->fetchAll();

    // ----- Diario: giorni con almeno un dato (ultimi 6 mesi) + note personali -----
    // Indipendente dal periodo selezionato sopra: il diario alimenta l'analisi AI (6 mesi).
    // Raggruppa per giorno locale ($LD, definito sopra) come fa il prompt AI.
    $noteStartUtc  = (new DateTime('now', $UTC))->modify('-6 month')->format('Y-m-d H:i:s');
    $noteStartDate = (new DateTime('now', $TZL))->modify('-6 month')->format('Y-m-d');
    foreach ([['hr_samples','Battito'], ['step_samples','Passi'], ['stress_samples','Stress'],
              ['hrv_samples','HRV'], ['spo2_samples','SpO2'], ['measurements','Misure']] as [$tbl, $lbl]) {
        $st = $pdo->prepare("SELECT DISTINCT $LD d FROM $tbl WHERE ts >= ?");
        $st->execute([$noteStartUtc]);
        foreach ($st as $r) { $daysWithData[$r['d']][] = $lbl; }
    }
    $st = $pdo->prepare("SELECT DISTINCT sleep_date d FROM sleep_segments WHERE sleep_date >= ?");
    $st->execute([$noteStartDate]);
    foreach ($st as $r) { $daysWithData[$r['d']][] = 'Sonno'; }
    krsort($daysWithData);   // giorni più recenti in cima
    ensure_day_notes($pdo);
    foreach ($pdo->query("SELECT note_date, note FROM day_notes") as $r) { $dayNotes[$r['note_date']] = $r['note']; }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

// ultimo report AI salvato (try a parte: la tabella potrebbe non esistere ancora)
$lastReport = null;
if (isset($pdo)) {
    try {
        $lastReport = $pdo->query("SELECT ts, model, report_short, report, report_diet, tokens_in, tokens_out
                                   FROM ai_report ORDER BY id DESC LIMIT 1")->fetch();
    } catch (Throwable $e) { /* nessun report ancora */ }
}

function card_val($latest, $key, $suffix = '') {
    if (!isset($latest[$key])) return '—';
    $r = $latest[$key];
    if ($key === 'blood_pressure') return intval($r['value']) . '/' . intval($r['value2']);
    return rtrim(rtrim(number_format($r['value'], 1, '.', ''), '0'), '.') . $suffix;
}

// etichetta italiana per il tipo di dato (le metriche on-demand sono in inglese nel DB)
function tipo_label($t) {
    return ['heart_rate'=>'Battito', 'blood_pressure'=>'Pressione', 'spo2'=>'SpO2',
            'stress'=>'Stress', 'battery'=>'Batteria'][$t] ?? $t;
}

// totali sonno per fase (minuti) da una lista di segmenti
function sleep_totals(array $segs): array {
    $t = ['light'=>0, 'deep'=>0, 'rem'=>0, 'awake'=>0, 'total'=>0];
    foreach ($segs as $s) { $t[$s['stage']] = ($t[$s['stage']] ?? 0) + (int)$s['minutes']; $t['total'] += (int)$s['minutes']; }
    return $t;
}
function hhmm(int $min): string { return intdiv($min, 60) . 'h ' . str_pad($min % 60, 2, '0', STR_PAD_LEFT) . 'm'; }

// Corpo del pannello sonno (totali + ipnogramma + asse orario + statistiche) per UNA notte.
function sleep_panel_body(array $segs, ?string $startTs, DateTimeZone $TZL): string {
    $tot = sleep_totals($segs);
    $axisStart = $axisEnd = null; $ticks = [];
    if ($startTs && $tot['total']) {
        $axisStart = new DateTime($startTs, new DateTimeZone('UTC')); $axisStart->setTimezone($TZL);
        $axisEnd = (clone $axisStart)->modify("+{$tot['total']} minutes");
        $s0 = (int)$axisStart->format('U'); $s1 = (int)$axisEnd->format('U'); $span = max(1, $s1 - $s0);
        for ($t = (int)(ceil($s0 / 3600) * 3600); $t < $s1; $t += 3600) {
            $td = (new DateTime("@$t"))->setTimezone($TZL);
            $ticks[] = [round(($t - $s0) / $span * 100, 3), $td->format('H:i')];
        }
    }
    ob_start(); ?>
    <div><span style="font-size:28px;font-weight:600"><?= hhmm($tot['total']) ?></span>
      <?php if ($axisStart): ?><span class="sleeprange"><?= $axisStart->format('H:i') ?> &rarr; <?= $axisEnd->format('H:i') ?></span><?php endif; ?>
    </div>
    <div class="hypno">
      <?php foreach ($segs as $s): $w = $tot['total'] ? round($s['minutes'] * 100 / $tot['total'], 3) : 0; ?>
        <div class="seg seg-<?= htmlspecialchars($s['stage']) ?>" style="width:<?= $w ?>%"
             title="<?= htmlspecialchars($s['stage']) ?> &middot; <?= (int)$s['minutes'] ?> min"></div>
      <?php endforeach; ?>
    </div>
    <?php if ($ticks): ?>
    <div class="hypnoaxis">
      <?php foreach ($ticks as [$pos, $lbl]): ?>
        <span class="tick" style="left:<?= $pos ?>%"><?= htmlspecialchars($lbl) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="sleepstats">
      <span><span class="dot" style="background:#5a9bff"></span>Leggero <b><?= hhmm($tot['light']) ?></b></span>
      <span><span class="dot" style="background:#2b3f86"></span>Profondo <b><?= hhmm($tot['deep']) ?></b></span>
      <span><span class="dot" style="background:#b07bff"></span>REM <b><?= hhmm($tot['rem']) ?></b></span>
      <span><span class="dot" style="background:#f5b73b"></span>Sveglio <b><?= hhmm($tot['awake']) ?></b></span>
    </div>
    <?php
    return ob_get_clean();
}

// valore formattato per la tabella unica
function row_value($r) {
    if ($r['tipo'] === 'Passi') {
        $s = intval($r['v1']) . ' passi';
        if ($r['v2'] !== null) $s .= ' · ' . intval($r['v2']) . ' kcal';
        if ($r['v3'] !== null) $s .= ' · ' . intval($r['v3']) . ' m';
        return $s;
    }
    if ($r['tipo'] === 'blood_pressure')
        return intval($r['v1']) . '/' . intval($r['v2']) . ' mmHg';
    $n = rtrim(rtrim(number_format($r['v1'], 1, '.', ''), '0'), '.');
    return $n . ($r['unit'] ? ' ' . $r['unit'] : '');
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
  #status, #aistatus { margin-top:12px; color:var(--mut); font-size:14px; white-space:pre-wrap; }
  code { background:#0f1419; border:1px solid #2a3540; border-radius:6px; padding:1px 6px; font-size:13px; }
  .report { line-height:1.55; font-size:14.5px; margin-top:12px; }
  .report:empty { display:none; }
  .report h3 { font-size:17px; margin:20px 0 8px; color:var(--acc); }
  .report h3:first-child { margin-top:4px; }
  .report h4 { font-size:15px; margin:14px 0 6px; }
  .report p { margin:8px 0; }
  .report ul, .report ol { margin:8px 0; padding-left:22px; }
  .report li { margin:4px 0; }
  .report table { width:100%; border-collapse:collapse; margin:12px 0; font-size:14px; }
  .report th, .report td { text-align:left; padding:7px 8px; border-bottom:1px solid #232c36; }
  .report th { color:var(--mut); font-weight:600; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  td,th { text-align:left; padding:7px 8px; border-bottom:1px solid #232c36; }
  th { color:var(--mut); font-weight:500; }
  .err { background:#3a1f24; color:#ffb4bd; padding:12px 16px; border-radius:10px; margin:10px 0; }
  .hint { color:var(--mut); font-size:13px; margin-top:6px; }
  .hypno { display:flex; height:34px; border-radius:8px; overflow:hidden; margin:10px 0; background:#0f1419; }
  .hypno .seg { height:100%; }
  .seg-light { background:#5a9bff; } .seg-deep { background:#2b3f86; }
  .seg-rem { background:#b07bff; } .seg-awake { background:#f5b73b; }
  .sleepstats { display:flex; gap:18px; flex-wrap:wrap; margin-top:10px; font-size:14px; }
  .sleepstats .dot { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:6px; vertical-align:middle; }
  .sleepstats b { font-weight:600; }
  .hypnoaxis { position:relative; height:26px; margin-top:1px; }
  .hypnoaxis .tick { position:absolute; top:0; transform:translateX(-50%); color:var(--mut); font-size:12px; white-space:nowrap; }
  .hypnoaxis .tick::before { content:''; display:block; width:1px; height:6px; background:#3a4654; margin:0 auto 2px; }
  .sleeprange { color:var(--mut); font-size:15px; font-weight:600; margin-left:10px; }
  .sleepday { padding:14px 0; border-top:1px solid #232c36; }
  .sleepday:first-of-type { border-top:0; }
  .sleepday h3 { margin:0 0 4px; font-size:16px; font-weight:600; }
  .sleepday.bad { background:#2a1a1d; border-radius:10px; padding:14px; margin:6px 0; border-top:0; }
  .flag { display:inline-block; margin-left:10px; font-size:12px; font-weight:600; color:#ffb4bd;
          background:#3a1f24; padding:2px 8px; border-radius:999px; }
  .notescroll { max-height:560px; overflow:auto; margin-top:6px; padding-right:6px; }
  .noteday { padding:13px 0; border-top:1px solid #232c36; }
  .noteday:first-child { border-top:0; }
  .noteday-head { display:flex; align-items:baseline; gap:10px; flex-wrap:wrap; margin-bottom:7px; }
  .noteday-date { font-weight:600; font-size:15px; }
  .noteday-meta { color:var(--mut); font-size:12.5px; }
  .note-dot { color:var(--acc); font-size:13px; }
  .noteday textarea { width:100%; background:#0f1419; color:var(--txt); border:1px solid #2a3540;
          border-radius:8px; padding:9px 10px; font:inherit; font-size:14px; line-height:1.45;
          resize:vertical; min-height:44px; }
  .notebar { display:flex; align-items:center; gap:12px; margin-top:8px; }
  button.mini { padding:8px 14px; font-size:13px; }
  .notesaved { color:var(--acc); font-size:13px; }
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
  <div class="card"><div class="lbl">SpO2</div><div class="big"><?= $latestSpo2 !== false ? intval($latestSpo2) : card_val($latest,'spo2') ?><span class="u"> %</span></div></div>
  <div class="card"><div class="lbl">Pressione</div><div class="big"><?= card_val($latest,'blood_pressure') ?><span class="u"> mmHg</span></div></div>
  <div class="card"><div class="lbl">Stress</div><div class="big"><?= $latestStress !== false ? intval($latestStress) : card_val($latest,'stress') ?></div></div>
  <div class="card"><div class="lbl">HRV</div><div class="big"><?= $latestHrv !== false ? intval($latestHrv) : '—' ?><span class="u"> ms</span></div></div>
  <div class="card"><div class="lbl">Sonno</div><div class="big"><?php $tc = sleep_totals($sleepSegs); echo $tc['total'] ? intdiv($tc['total'],60).'<span class="u">h </span>'.($tc['total']%60).'<span class="u">m</span>' : '—'; ?></div></div>
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

<div class="panel">
  <h2>Analisi AI</h2>
  <div class="btns">
    <button onclick="aiAnalyze()">🧠 Analizza con AI</button>
  </div>
  <div class="hint">Invia il riepilogo dei dati dell'ultimo anno (365 giorni) a <?= htmlspecialchars(OPENROUTER_MODEL) ?> via OpenRouter per un'analisi sanitaria. Il report viene salvato nel database (tabella <code>ai_report</code>). Può richiedere qualche secondo.</div>
  <div id="aistatus"></div>
  <?php $lrShort = $lastReport ? ($lastReport['report_short'] ?: $lastReport['report']) : '';
        $lrFull  = $lastReport ? $lastReport['report'] : '';
        $lrDiet  = $lastReport ? ($lastReport['report_diet'] ?? '') : ''; ?>
  <div id="aimeta" class="hint"<?= $lastReport ? '' : ' style="display:none"' ?>><?php if ($lastReport): ?>Ultimo report · <?= htmlspecialchars(tlocal($lastReport['ts'])) ?> · <?= htmlspecialchars($lastReport['model']) ?><?= $lastReport['tokens_out'] ? ' · ' . (int)$lastReport['tokens_out'] . ' token' : '' ?><?php endif; ?></div>
  <div id="aireport" class="report"><?= $lrShort ?></div>
  <div class="btns" id="aibtns" style="margin-top:12px;<?= $lastReport ? '' : 'display:none' ?>">
    <button type="button" class="alt" data-label="analisi completa" onclick="toggleBox('aifull', this)">Vedi analisi completa</button>
    <button type="button" class="alt" data-label="consigli alimentari e integratori" onclick="toggleBox('aidiet', this)"<?= $lrDiet ? '' : ' style="display:none"' ?>>Vedi consigli alimentari e integratori</button>
  </div>
  <div id="aifull" class="report" style="display:none"><?= $lrFull ?></div>
  <div id="aidiet" class="report" style="display:none"><?= $lrDiet ?></div>
</div>

<div class="panel">
  <h2>Diario · note giornaliere <span class="sleeprange"><?= count($daysWithData) ?> giorni con dati</span></h2>
  <p class="hint">Aggiungi una nota ai giorni con dati raccolti per dare contesto all'<strong>Analisi AI</strong> qui sopra: cosa hai fatto, attività, eventi, come ti sentivi (es. &ldquo;pomeriggio corsa 10&nbsp;km&rdquo;, &ldquo;16-18 palestra&rdquo;, &ldquo;ferie al mare&rdquo;). Le note degli ultimi 6 mesi vengono incluse nel prompt inviato al modello. Dal giorno più recente.</p>
  <?php if ($daysWithData): ?>
  <div class="notescroll">
    <?php foreach ($daysWithData as $d => $labels): $note = $dayNotes[$d] ?? ''; ?>
      <div class="noteday" data-date="<?= htmlspecialchars($d) ?>" data-saved="<?= htmlspecialchars($note) ?>">
        <div class="noteday-head">
          <span class="noteday-date"><?= htmlspecialchars(date('d/m/Y', strtotime($d))) ?></span>
          <span class="note-dot"<?= $note !== '' ? '' : ' style="display:none"' ?>>● nota</span>
          <span class="noteday-meta"><?= htmlspecialchars(implode(' · ', array_unique($labels))) ?></span>
        </div>
        <textarea rows="2" placeholder="Aggiungi una nota per questo giorno…" oninput="noteChanged(this)"><?= htmlspecialchars($note) ?></textarea>
        <div class="notebar">
          <button type="button" class="mini savebtn" onclick="saveNote('<?= htmlspecialchars($d) ?>')" disabled>Salva nota</button>
          <span class="notesaved"></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div class="hint">Nessun giorno con dati ancora. Sincronizza col braccialetto, poi torna qui per annotare le tue giornate.</div>
  <?php endif; ?>
</div>

<?php $pl = $RANGES[$range]; ?>
<div class="panel">
  <h2>Visualizzazione grafici</h2>
  <div class="btns" id="dataModeToggle">
    <a class="rbtn on" href="#" data-mode="real" onclick="setStatMode(false);return false;">Dati reali</a>
    <a class="rbtn" href="#" data-mode="stat" onclick="setStatMode(true);return false;">Dati statistici</a>
  </div>
  <div class="hint"><strong>Dati reali</strong>: campioni grezzi del sensore (mostra anche eventuali artefatti). <strong>Dati statistici</strong>: filtro di mediana mobile che rimuove i picchi/cali isolati del sensore. Vale per battito, SpO2, stress e HRV.</div>
</div>

<div class="panel">
  <h2>Battito · <?= htmlspecialchars($pl) ?></h2>
  <canvas id="hrChart" height="110"></canvas>
</div>

<?php $stepsDaily = count($stepsByDay) > 1; ?>
<div class="panel">
  <h2>Passi · <?= htmlspecialchars($pl) ?><?= $stepsDaily ? ' <span class="sleeprange">totale per giorno</span>' : '' ?></h2>
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
  <h2>Sonno<?= $sleepDate ? ' · ' . htmlspecialchars(date('d/m/Y', strtotime($sleepDate))) : '' ?></h2>
  <?php if ($sleepSegs): ?>
    <?= sleep_panel_body($sleepSegs, $sleepStart, $TZL) ?>
  <?php else: ?>
    <div class="hint">Nessun dato di sonno ancora. Indossa il braccialetto di notte, poi premi "Solo storico".</div>
  <?php endif; ?>
</div>

<?php if ($sleepDays): ?>
<div class="panel">
  <h2>Sonno · verifica giorno per giorno <span class="sleeprange"><?= count($sleepDays) ?> notti nel periodo</span></h2>
  <p class="hint">Controllo visivo dei dati passati all'analisi AI. Una notte oltre ~16h, o identica a quella precedente, è quasi certamente un errore di lettura del braccialetto (non un sonno reale).</p>
  <?php $prevSig = null;
    foreach ($sleepDays as $day):
        $tot = sleep_totals($day['segs']);
        $sig = md5(json_encode($day['segs']));
        $dupOfPrev = ($sig === $prevSig);
        $tooLong = $tot['total'] > 16 * 60;
        $prevSig = $sig;
  ?>
    <div class="sleepday<?= ($tooLong || $dupOfPrev) ? ' bad' : '' ?>">
      <h3><?= htmlspecialchars(date('d/m/Y', strtotime($day['date']))) ?>
        <span class="hint" style="font-weight:400"><?= count($day['segs']) ?> segmenti</span>
        <?php if ($tooLong): ?><span class="flag">⚠ <?= round($tot['total'] / 60, 1) ?>h · impossibile</span><?php endif; ?>
        <?php if ($dupOfPrev): ?><span class="flag">⧉ identico al giorno prima</span><?php endif; ?>
      </h3>
      <?= sleep_panel_body($day['segs'], $day['start'], $TZL) ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

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

<div class="panel">
  <h2>Tutti i dati salvati · <?= htmlspecialchars($pl) ?></h2>
  <table>
    <tr><th>Quando</th><th>Tipo</th><th>Valore</th></tr>
    <?php foreach ($allrows as $r): ?>
      <tr><td><?= htmlspecialchars(tlocal($r['ts'])) ?></td>
          <td><?= htmlspecialchars(tipo_label($r['tipo'])) ?></td>
          <td><?= htmlspecialchars(row_value($r)) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$allrows): ?><tr><td colspan="3" style="color:var(--mut)">Nessun dato nel periodo selezionato.</td></tr><?php endif; ?>
  </table>
  <div class="hint"><?= count($allrows) ?> righe nel periodo selezionato (max 1000), dalla più recente. Cambia il periodo qui sopra per vedere più giorni.</div>
</div>

</div>

<script>
<?php $LR = $longRange; ?>
const hrLabels    = <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $hr)) ?>;
const hrVals      = <?= json_encode(array_map(fn($r)=>(int)$r['bpm'], $hr)) ?>;
const stepLabels  = <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $steps)) ?>;
const stepVals    = <?= json_encode(array_map(fn($r)=>(int)$r['steps'], $steps)) ?>;
const stepDayLabels = <?= json_encode(array_map(fn($r)=>date('d/m', strtotime($r['d'])), $stepsByDay)) ?>;
const stepDayVals   = <?= json_encode(array_map(fn($r)=>(int)$r['s'], $stepsByDay)) ?>;
const bpLabels    = <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $series['blood_pressure'])) ?>;
const bpSys       = <?= json_encode(array_map(fn($r)=>(int)$r['value'], $series['blood_pressure'])) ?>;
const bpDia       = <?= json_encode(array_map(fn($r)=>(int)$r['value2'], $series['blood_pressure'])) ?>;
const spo2Labels  = <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $spo2hist)) ?>;
const spo2Vals    = <?= json_encode(array_map(fn($r)=>(int)$r['spo2'], $spo2hist)) ?>;
const stressLabels= <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $stressHist)) ?>;
const stressVals  = <?= json_encode(array_map(fn($r)=>(int)$r['score'], $stressHist)) ?>;
const hrvLabels   = <?= json_encode(array_map(fn($r)=>tlabel($r['ts'],$LR), $hrv)) ?>;
const hrvVals     = <?= json_encode(array_map(fn($r)=>(int)$r['ms'], $hrv)) ?>;

// ---- Vista "Dati statistici": filtro di mediana mobile (finestra ±2 campioni)
// che sostituisce i singoli picchi/cali isolati del sensore con la mediana locale,
// lasciando intatta la forma della serie. Usato dal toggle "Dati reali / statistici".
function _median(a){ if(!a.length) return NaN; const s=a.slice().sort((x,y)=>x-y), n=s.length;
  return n%2 ? s[(n-1)/2] : (s[n/2-1]+s[n/2])/2; }
function rollingMedian(vals, w=2){
  if(vals.length < 2*w+1) return vals.slice();
  return vals.map((_,i)=>{
    const lo=Math.max(0,i-w), hi=Math.min(vals.length-1,i+w);
    return Math.round(_median(vals.slice(lo,hi+1)));
  });
}
const hrValsStat     = rollingMedian(hrVals);
const spo2ValsStat   = rollingMedian(spo2Vals);
const stressValsStat = rollingMedian(stressVals);
const hrvValsStat    = rollingMedian(hrvVals);

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
  if(!labels.length){ noData(id); return null; }
  return new Chart(document.getElementById(id), { type:'line', data:{labels, datasets},
    options:{ ...baseOpts, plugins:{legend:{display:datasets.length>1, labels:{color:'#e6edf3'}}},
      scales:{ ...baseOpts.scales, y:{ ...baseOpts.scales.y, beginAtZero:zero } } } });
}

// Grafici a campioni continui registrati per il toggle "Dati reali / statistici".
const _statCharts = [];
function registerStat(chart, raw, stat){ if(chart) _statCharts.push({chart, raw, stat}); }
function setStatMode(on){
  document.querySelectorAll('#dataModeToggle .rbtn').forEach(b =>
    b.classList.toggle('on', (b.dataset.mode==='stat')===on));
  _statCharts.forEach(({chart, raw, stat})=>{ chart.data.datasets[0].data = on?stat:raw; chart.update(); });
  try{ localStorage.setItem('ludohealt_datamode', on?'stat':'real'); }catch(e){}
}

const hrChart = lineChart('hrChart', hrLabels, [{ label:'bpm', data:hrVals, borderColor:'#46d39a',
    backgroundColor:'rgba(70,211,154,.15)', fill:true, tension:.3, pointRadius:2, borderWidth:2 }]);
registerStat(hrChart, hrVals, hrValsStat);

// Periodo su più giorni: una barra per giorno col totale passi giornaliero.
// Altrimenti i singoli slot del sensore (vista intra-giornaliera) come prima.
if(stepDayVals.length > 1){
  new Chart(document.getElementById('stepChart'), { type:'bar',
    data:{ labels:stepDayLabels, datasets:[{ label:'passi/giorno', data:stepDayVals, backgroundColor:'#46d39a' }]},
    options:{ ...baseOpts, scales:{ ...baseOpts.scales, y:{ ...baseOpts.scales.y, beginAtZero:true } } } });
} else if(stepLabels.length){
  new Chart(document.getElementById('stepChart'), { type:'bar',
    data:{ labels:stepLabels, datasets:[{ label:'passi', data:stepVals, backgroundColor:'#46d39a' }]},
    options:{ ...baseOpts, scales:{ ...baseOpts.scales, y:{ ...baseOpts.scales.y, beginAtZero:true } } } });
} else noData('stepChart');

lineChart('bpChart', bpLabels, [
  { label:'sistolica', data:bpSys, borderColor:'#ff6b6b', backgroundColor:'rgba(255,107,107,.12)', fill:false, tension:.3, pointRadius:3, borderWidth:2 },
  { label:'diastolica', data:bpDia, borderColor:'#5a9bff', backgroundColor:'rgba(90,155,255,.12)', fill:false, tension:.3, pointRadius:3, borderWidth:2 }
]);
const spo2Chart = lineChart('spo2Chart', spo2Labels, [{ label:'SpO2 %', data:spo2Vals, borderColor:'#46d39a',
    backgroundColor:'rgba(70,211,154,.15)', fill:true, tension:.3, pointRadius:3, borderWidth:2 }]);
registerStat(spo2Chart, spo2Vals, spo2ValsStat);
const stressChart = lineChart('stressChart', stressLabels, [{ label:'stress', data:stressVals, borderColor:'#f5b73b',
    backgroundColor:'rgba(245,183,59,.15)', fill:true, tension:.3, pointRadius:2, borderWidth:2 }]);
registerStat(stressChart, stressVals, stressValsStat);
const hrvChart = lineChart('hrvChart', hrvLabels, [{ label:'HRV ms', data:hrvVals, borderColor:'#b07bff',
    backgroundColor:'rgba(176,123,255,.15)', fill:true, tension:.3, pointRadius:2, borderWidth:2 }]);
registerStat(hrvChart, hrvVals, hrvValsStat);

// Applica la modalità salvata (default: dati reali).
try{ if(localStorage.getItem('ludohealt_datamode')==='stat') setStatMode(true); }catch(e){}

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
      const span = (j.days_synced!=null) ? (j.days_synced===0 ? 'solo oggi' : ('ultimi '+(j.days_synced+1)+' giorni')) : '';
      msg += 'Storico'+(span?' ('+span+')':'')+': '+j.hr_points+' punti battito, '+j.step_points+' slot passi, '
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

async function aiAnalyze(){
  const btns = document.querySelectorAll('button'); btns.forEach(b=>b.disabled=true);
  const s = document.getElementById('aistatus');
  s.textContent = 'Analisi in corso con il modello AI... (può richiedere qualche secondo)';
  try{
    const r = await fetch('?action=ai_prompt', {method:'POST'});
    const j = await r.json();
    if(j.ok){
      s.textContent = '✓ Analisi completata · '+j.days+' giorni'
        + (j.tokens_out ? ' · '+j.tokens_out+' token generati' : '')+' · salvata nel database (#'+j.id+').';
      const meta = document.getElementById('aimeta');
      meta.textContent = 'Ultimo report · adesso · '+j.model;
      meta.style.display = '';
      document.getElementById('aireport').innerHTML = j.short;
      const full = document.getElementById('aifull'); full.innerHTML = j.full; full.style.display = 'none';
      const diet = document.getElementById('aidiet'); diet.innerHTML = j.diet || ''; diet.style.display = 'none';
      const bx = document.getElementById('aibtns'); bx.style.display = '';
      bx.querySelectorAll('button').forEach(b => b.textContent = 'Vedi ' + b.dataset.label);
      bx.querySelector('[data-label="consigli alimentari e integratori"]').style.display =
        (j.diet && j.diet.trim()) ? '' : 'none';
    } else {
      s.textContent = '✗ Errore: '+((j.errors||['sconosciuto']).join(' | '));
    }
  }catch(e){ s.textContent = '✗ Errore di rete: '+e; }
  finally{ btns.forEach(b=>b.disabled=false); }
}

function toggleBox(boxId, btn){
  const f = document.getElementById(boxId);
  const hidden = (f.style.display === 'none');
  f.style.display = hidden ? '' : 'none';
  btn.textContent = (hidden ? 'Nascondi ' : 'Vedi ') + btn.dataset.label;
}

// Diario: abilita "Salva" solo quando la nota è cambiata rispetto a quella salvata.
function noteChanged(ta){
  const box = ta.closest('.noteday');
  box.querySelector('.savebtn').disabled = (ta.value === box.dataset.saved);
  box.querySelector('.notesaved').textContent = '';
}
async function saveNote(date){
  const box = document.querySelector('.noteday[data-date="'+date+'"]');
  if(!box) return;
  const ta = box.querySelector('textarea');
  const btn = box.querySelector('.savebtn');
  const stat = box.querySelector('.notesaved');
  btn.disabled = true; stat.textContent = 'Salvataggio…';
  try{
    const r = await fetch('?action=save_note', {method:'POST',
      body: new URLSearchParams({date: date, note: ta.value})});
    const j = await r.json();
    if(j.ok){
      box.dataset.saved = j.note;
      ta.value = j.note;                       // riflette il cap server-side (2000 caratteri)
      box.querySelector('.note-dot').style.display = j.has ? '' : 'none';
      stat.textContent = '✓ Salvato';
      btn.disabled = true;
      setTimeout(()=>{ if(stat.textContent==='✓ Salvato') stat.textContent=''; }, 2500);
    } else {
      stat.textContent = '✗ '+((j.errors||['errore']).join(' '));
      btn.disabled = false;
    }
  }catch(e){ stat.textContent = '✗ Errore di rete'; btn.disabled = false; }
}
</script>
</body>
</html>
