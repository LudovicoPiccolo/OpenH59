# OpenH59 — il tuo braccialetto H59 senza l'app

![OpenH59 — reverse-engineer your H59 fitness band over Bluetooth LE, self-hosted health data, no cloud](docs/og-image.png)

> **Reverse engineering del fitness tracker H59 (protocollo Colmi/QWatch Pro) via Bluetooth LE.** Niente cloud, niente account: i tuoi dati sanitari restano sul tuo computer.

![Platform](https://img.shields.io/badge/platform-macOS-black)
![Python](https://img.shields.io/badge/python-3.11%2B-blue)
![PHP](https://img.shields.io/badge/PHP-8%2B-777bb4)
![Database](https://img.shields.io/badge/database-MySQL%20%2F%20MariaDB-00758f)
![BLE](https://img.shields.io/badge/protocol-Bluetooth%20LE-0082fc)
![AI](https://img.shields.io/badge/AI-OpenRouter-ff4b4b)

Raccoglie e visualizza i dati del braccialetto **H59** (battito, SpO2, pressione, passi, stress, HRV) **bypassando l'app QWatch Pro**: parla direttamente con il device via **Bluetooth LE** (`bleak` / CoreBluetooth), salva tutto in un database **MySQL/MariaDB** locale e mostra i grafici in una **dashboard PHP** self-hosted — con **analisi AI opzionale** dei tuoi trend sanitari via OpenRouter.

> 🇬🇧 **English version below** — [jump to English](#openh59--english).

**Cosa fa:** smartwatch / fitness band economico (cloni Colmi, app QWatch Pro / QCWatch) → dati grezzi sotto il tuo controllo, senza inviare nulla a server di terze parti.

## Componenti
- `config.py` — legge la configurazione da `.env` (indirizzo braccialetto + DB).
- `band.py` — client BLE del braccialetto (misure real-time + download storico).
- `store.py` — database MySQL (tabelle: `measurements`, `hr_samples`, `step_samples`, `stress_samples`, `hrv_samples`, `ai_report`).
- `collect.py` — collettore: scarica storico + misure on-demand → MySQL. Stampa un JSON di riepilogo.
- `setup.py` — setup una-tantum (ora + log battito 24/7).
- `index.php` — dashboard con grafici, bottoni di sincronizzazione e **analisi AI** dei trend (OpenRouter).
- `start.command` — avvia la dashboard.

## Cosa si ottiene
- **Storico** (si riempie indossando il braccialetto): battito (5 min), passi/calorie/distanza (15 min), stress (30 min), HRV (30 min), **SpO2 (oraria, min-max)** e **sonno a fasi** (leggero/profondo/REM/sveglio).
- **On-demand** (misura del momento): battito, SpO2, pressione (sis/dia), stress.
- **Analisi AI** (opzionale): invii un riepilogo degli ultimi 6 mesi — dettaglio completo sugli ultimi 7 giorni più recap giornaliero sui mesi precedenti — a un modello via **OpenRouter** e ottieni un report sanitario sui tuoi trend (riepilogo veloce, analisi completa e consigli alimentari), salvato nella tabella `ai_report`.

## Requisiti
- **macOS** (il client BLE usa CoreBluetooth tramite `bleak`).
- **Python 3.11+**.
- **PHP 8+** (es. via [Laravel Herd](https://herd.laravel.com/) o `brew install php`).
- **MySQL/MariaDB** in ascolto su `127.0.0.1:3306` (Herd lo fornisce; altrimenti `brew install mariadb`).
- Un braccialetto **H59** (protocollo Colmi/QC, app QWatch Pro).

## Installazione (provalo tu)

> Il database e le tabelle vengono **creati automaticamente** al primo avvio: non serve SQL manuale.

1. **Clona il progetto** ed entra nella cartella:
   ```bash
   git clone <URL-del-repo> LudoHealt && cd LudoHealt
   ```
2. **Crea l'ambiente Python** e installa le dipendenze:
   ```bash
   python3 -m venv .venv
   .venv/bin/pip install -r requirements.txt
   ```
3. **Configura** copiando il template:
   ```bash
   cp .env.example .env
   ```
   Apri `.env` e compila `BAND_ADDRESS` e le credenziali del DB (i default vanno bene per MySQL locale senza password). Per l'**analisi AI** (opzionale) aggiungi `OPENROUTER_API_KEY` (chiave da [openrouter.ai/keys](https://openrouter.ai/keys)) ed eventualmente `OPENROUTER_MODEL`.
4. **Trova l'indirizzo del braccialetto** (`BAND_ADDRESS`). Indossa il braccialetto, spegni il Bluetooth del telefono, poi:
   ```bash
   .venv/bin/python -c "import asyncio; from bleak import BleakScanner; print('\n'.join(f'{d.address}  {d.name}' for d in asyncio.run(BleakScanner.discover())))"
   ```
   Copia l'indirizzo del dispositivo H59 dentro `BAND_ADDRESS` nel `.env`.
   *(Su macOS è uno UUID CoreBluetooth, non un MAC: è specifico del tuo Mac.)*
5. **Setup iniziale** del braccialetto (una volta sola — imposta l'ora e attiva il log battito 24/7):
   ```bash
   .venv/bin/python setup.py
   ```
   Alla prima esecuzione macOS chiederà il permesso **Bluetooth**: consenti.
6. **Avvia la dashboard**:
   ```bash
   bash start.command          # oppure:  php -S 127.0.0.1:8080
   ```
   Apri **http://127.0.0.1:8080**.

## Uso quotidiano
1. **Indossa il braccialetto** e **spegni il Bluetooth del telefono** (il braccialetto parla con un solo dispositivo per volta).
2. Avvia la dashboard (`bash start.command`) e apri http://127.0.0.1:8080.
3. Premi **Misura veloce** (~2-3 min) / **Misura completa** (~4-5 min, con pressione e stress) / **Solo storico** (~2 min).
4. (Opzionale) Premi **Analisi AI** per inviare il riepilogo degli ultimi 6 mesi a OpenRouter e ricevere il report sui tuoi trend.

> **Sincronizzazione incrementale:** ogni sync riparte dall'ultimo dato salvato nel database e scarica solo i giorni mancanti. Sync quotidiano → istantaneo; se stai via qualche giorno col braccialetto al polso, al rientro un solo sync recupera in automatico tutto il buffer (il device tiene i dati ~7 giorni; ri-scaricarli non crea doppioni). Override manuali: `collect.py --days 7` o `collect.py --from 2026-06-10T08:00`.

### Dal cellulare
Mentre il Mac tiene aperta la dashboard, dal telefono (stessa rete Wi-Fi) apri
`http://<IP-del-Mac>:8080` per vedere i grafici. La misura dal braccialetto avviene sempre sul Mac.

## Struttura
- `docs/` — appunti tecnici, protocollo, guida snoop log, articolo del progetto.

## Note
- L'indirizzo BLE del braccialetto e le credenziali DB stanno nel `.env` (vedi `.env.example`).
- Timestamp salvati in UTC.
- SpO2 e sonno hanno storico sul dispositivo (canale BLE "ricco" `bc`, vedi `band.py`): li scarichiamo come gli altri.
- La **pressione** non ha uno storico reale sul dispositivo: la curva "oraria" mostrata dall'app ufficiale è generata lato app (valori quasi costanti). Da noi resta solo misura on-demand.
- L'**analisi AI** è opzionale e *opt-in*: è l'unica funzione che invia dati fuori dalla tua macchina (un riepilogo aggregato, non i campioni grezzi) e solo quando premi il bottone. Senza `OPENROUTER_API_KEY` tutto resta 100% locale.

---

# OpenH59 — English

> **Reverse engineering the H59 fitness tracker (Colmi / QWatch Pro protocol) over Bluetooth LE.** No cloud, no account: your health data stays on your own machine.

Collect and visualize data from the **H59** fitness band — heart rate, SpO2, blood pressure, steps, stress, HRV — **bypassing the QWatch Pro app**. It talks to the device directly over **Bluetooth LE** (`bleak` / CoreBluetooth), stores everything in a local **MySQL/MariaDB** database, and shows the charts in a self-hosted **PHP dashboard** — with **optional AI analysis** of your health trends via OpenRouter.

**What it does:** a cheap smartwatch / fitness band (Colmi clones, QWatch Pro / QCWatch app) → raw data under your control, with nothing sent to third-party servers.

## Components
- `config.py` — reads configuration from `.env` (band address + DB).
- `band.py` — band BLE client (real-time measurements + history download).
- `store.py` — MySQL database (tables: `measurements`, `hr_samples`, `step_samples`, `stress_samples`, `hrv_samples`, `ai_report`).
- `collect.py` — collector: downloads history + on-demand measurements → MySQL. Prints a JSON summary.
- `setup.py` — one-time setup (clock + 24/7 heart-rate logging).
- `index.php` — dashboard with charts, sync buttons and **AI trend analysis** (OpenRouter).
- `start.command` — starts the dashboard.

## What you get
- **History** (fills up while wearing the band): heart rate (5 min), steps/calories/distance (15 min), stress (30 min), HRV (30 min), **SpO2 (hourly, min-max)** and **staged sleep** (light/deep/REM/awake).
- **On-demand** (instant measurement): heart rate, SpO2, blood pressure (sys/dia), stress.
- **AI analysis** (optional): send a 6-month summary — the last 7 days in full detail plus a daily recap of the prior months — to a model via **OpenRouter** and get a health report on your trends (quick summary, full analysis and dietary advice), saved in the `ai_report` table.

## Requirements
- **macOS** (the BLE client uses CoreBluetooth via `bleak`).
- **Python 3.11+**.
- **PHP 8+** (e.g. via [Laravel Herd](https://herd.laravel.com/) or `brew install php`).
- **MySQL/MariaDB** listening on `127.0.0.1:3306` (provided by Herd; otherwise `brew install mariadb`).
- An **H59** band (Colmi/QC protocol, QWatch Pro app).

## Installation (try it yourself)

> The database and tables are **created automatically** on first run — no manual SQL needed.

1. **Clone the project** and enter the folder:
   ```bash
   git clone <repo-URL> LudoHealt && cd LudoHealt
   ```
2. **Create the Python environment** and install dependencies:
   ```bash
   python3 -m venv .venv
   .venv/bin/pip install -r requirements.txt
   ```
3. **Configure** by copying the template:
   ```bash
   cp .env.example .env
   ```
   Open `.env` and fill in `BAND_ADDRESS` and the DB credentials (defaults are fine for a local passwordless MySQL). For the (optional) **AI analysis** add `OPENROUTER_API_KEY` (key from [openrouter.ai/keys](https://openrouter.ai/keys)) and optionally `OPENROUTER_MODEL`.
4. **Find the band address** (`BAND_ADDRESS`). Wear the band, turn off your phone's Bluetooth, then:
   ```bash
   .venv/bin/python -c "import asyncio; from bleak import BleakScanner; print('\n'.join(f'{d.address}  {d.name}' for d in asyncio.run(BleakScanner.discover())))"
   ```
   Copy the H59 device's address into `BAND_ADDRESS` in `.env`.
   *(On macOS this is a CoreBluetooth UUID, not a MAC: it is specific to your Mac.)*
5. **Initial band setup** (once — sets the clock and enables 24/7 HR logging):
   ```bash
   .venv/bin/python setup.py
   ```
   On first run macOS will ask for **Bluetooth** permission: allow it.
6. **Start the dashboard**:
   ```bash
   bash start.command          # or:  php -S 127.0.0.1:8080
   ```
   Open **http://127.0.0.1:8080**.

## Daily use
1. **Wear the band** and **turn off your phone's Bluetooth** (the band talks to one device at a time).
2. Start the dashboard (`bash start.command`) and open http://127.0.0.1:8080.
3. Press **Quick measure** (~2-3 min) / **Full measure** (~4-5 min, with blood pressure and stress) / **History only** (~2 min).
4. (Optional) Press **AI analysis** to send the last 6 months' summary to OpenRouter and get a report on your trends.

> **Incremental sync:** each sync resumes from the last data point in the database and downloads only the missing days. Daily sync → instant; if you're away for a few days while still wearing the band, a single sync on your return automatically recovers the whole buffer (the device holds ~7 days; re-downloading creates no duplicates). Manual overrides: `collect.py --days 7` or `collect.py --from 2026-06-10T08:00`.

### From your phone
While the Mac keeps the dashboard running, open `http://<Mac-IP>:8080` from your phone
(same Wi-Fi network) to view the charts. The actual band measurement always happens on the Mac.

## Layout
- `docs/` — technical notes, protocol, snoop-log guide, project write-up.

## Notes
- The band's BLE address and DB credentials live in `.env` (see `.env.example`).
- Timestamps are stored in UTC.
- SpO2 and sleep have on-device history (the "rich" BLE `bc` channel, see `band.py`): we download them like the rest.
- **Blood pressure** has no real on-device history: the "hourly" curve shown by the official app is generated app-side (near-constant values). On our side it stays on-demand only.
- **AI analysis** is optional and *opt-in*: it's the only feature that sends data off your machine (an aggregated summary, not the raw samples) and only when you press the button. Without `OPENROUTER_API_KEY` everything stays 100% local.

---

## Keywords

H59 smart band · H59 fitness tracker · H59 smartwatch · QWatch Pro alternative · QCWatch · Colmi protocol · reverse engineering · Bluetooth Low Energy (BLE) · `bleak` · CoreBluetooth · heart rate · SpO2 · blood pressure · stress · HRV · steps · staged sleep · AI health analysis · OpenRouter · LLM · self-hosted health data · no cloud · privacy · MySQL · MariaDB · PHP dashboard · Python · macOS · open source fitness band.

</content>
</invoke>
