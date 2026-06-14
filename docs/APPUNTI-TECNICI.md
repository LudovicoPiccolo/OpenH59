# LudoHealt — Appunti tecnici (riferimento per i prossimi step)

Riferimento completo sul braccialetto **H59** e il suo protocollo BLE, ricavato
con reverse engineering (lettura diretta + HCI snoop log dell'app QWatch Pro).

## Dispositivo
- Modello hardware **H59** (hw `H59_V2.0`, fw `H59_2.00.14_...`), nome BLE tipo `H59_xxxx`.
- Chip **Nordic nRF52832**. App ufficiale: **QWatch Pro** (`com.qcwireless.qcwatch`, vendor QC Wireless / "PubuWear"). SDK proprietario.
- **USB = solo ricarica**, nessun dato. Tutti i dati passano via **Bluetooth Low Energy (BLE)**.
- Il braccialetto si connette a **un solo master per volta**: per usarlo dal PC, spegnere il Bluetooth del telefono.
- macOS: connettersi per indirizzo "a freddo" fallisce → usare prima `BleakScanner.find_device_by_address`.

## Protocollo BLE
- Servizio dati = **Nordic UART**: `6E40FFF0-B5A3-F393-E0A9-E50E24DCCA9E`
  - RX (scrittura comandi): `6E400002-...`
  - TX (notifiche risposte): `6E400003-...`
- **Frame fisso da 16 byte**: `[cmd][payload …14 byte…][checksum]`
  - `checksum = (somma dei primi 15 byte) & 0xFF`
- Risposte storiche = **multi-pacchetto**: header `[cmd, 0x00, count, interval, …]`, poi chunk `[cmd, idx, …valori…]`. Sub-byte `0xFF` = nessun dato.
- Bit alto sul command byte nella risposta (`cmd | 0x80`) = errore/non supportato.

## Comandi (mappa, dallo snoop log)
| Funzione | cmd (dec) | Note |
|---|---:|---|
| Imposta ora | 1 | payload BCD; **la risposta è la mappa capacità** (vedi sotto) |
| Batteria | 3 | risposta: byte1 = livello %, byte2 = in carica |
| Storico battito | 21 | richiesta = timestamp mezzanotte (`<L`); 24 pacchetti, 288 punti/5min |
| Settings log battito | 22 | `[2, enabled(1/2), interval_min]` per impostare; `[1]` per leggere |
| Storico pressione | 20 | il cmd 20 **non risponde**; ma l'app mostra uno **storico orario** (es. 17:00 = 129/88) → probabilmente sul canale bc, **non ancora decodificato** |
| Storico stress | 55 | richiesta `[55, giorno]`; **decodificato** (vedi sotto) — slot 30 min |
| Storico HRV | 57 | richiesta `[57, giorno]`; **decodificato** (vedi sotto) — slot 30 min, valore in ms |
| Storico passi | 67 | richiesta `[giorno, 0x0f,0x00,0x5f,0x01]`; record per slot 15min |
| Riepilogo oggi | 72 | passi/calorie/distanza del giorno |
| Misura real-time | 105 | start `[tipo, 1]`, vedi sotto |
| Stop real-time | 106 | `[tipo, 0, 0]` |

### Real-time (cmd 105) — decodifica dai byte reali
Tipi: `1=battito, 2=pressione, 3=SpO2, 8=stress, 10=HRV`.
Risposta `69 <tipo> <err> <b3> <b4> <b5> …`:
- **Battito (1)** → `byte3`
- **SpO2 (3)** → `byte3`
- **Pressione (2)** → `byte4 = sistolica`, `byte5 = diastolica`, `byte3 = battito` (es. `117/73`, `127/79`)
- **Stress (8)** → `byte3`
- **HRV (10)** → da rifinire (il valore NON è in byte3; nel probe i byte6:7 crescevano come un accumulatore)

L'aggancio delle misure ottiche (battito/SpO2) è "ballerino": il sensore blocca un valore solo con contatto saldo e immobilità. Serve logica di **retry** per renderle affidabili (TODO).

### Storico stress (55) / HRV (57) — decodifica
Stessa struttura multi-pacchetto del battito, ma richiesta **per indice giorno** `[cmd, giorno]` (0=oggi):
- header `[cmd, 0x00, count, interval_min, …]` — es. `37 00 05 1e` → 5 pacchetti totali, slot da `0x1e`=30 min.
- chunk `[cmd, idx≥1, …13 valori…]` — un valore per slot, `bytes[2:15]`; `0` = nessuna misura.
- `slot = (idx-1)*13 + posizione`; timestamp = mezzanotte (UTC) + `slot*interval_min`.
- sub `0xFF` = nessun dato per quel giorno.
- Esempio reale: `37 04 …2d…` → stress **45** allo slot 43 (21:30); `39 04 …2c…` → HRV **44 ms** stesso slot.
Implementato in `band.py` (`stress_history`, `hrv_history`) → tabelle `stress_samples` / `hrv_samples`.

## Canale "ricco" bc — SpO2 storica e fasi del sonno (decodificato)
Oltre al Nordic UART, il device espone un **secondo canale ("bc")** su caratteristiche dedicate, con frame a lunghezza variabile. È qui che vivono **SpO2 storica** e **fasi del sonno** (l'app le scarica da qui; UUID e formato ricavati dallo snoop log + confronto byte-per-byte con l'app).

- Caratteristiche: write `de5bf72a-d711-4e47-af26-65e3012a5dc7`, notify `de5bf729-...`.
- **Frame**: `BC(0xBC) | type(1) | len(2 LE) | crc16-modbus(2 LE) | body`. Le notifiche possono arrivare **spezzate** → vanno riassemblate (vedi `_on_bc`).
- **Handshake** obbligatorio prima di leggere: login `0x4A` con l'account dell'app (stringa `utf-16`, col BOM `ff fe`) + init `0x30`. L'account è lo username QWatch (parte prima della `@`), configurabile via `.env` (`BAND_ACCOUNT`).
- **Debug**: `BC_DEBUG=1` fa il dump esadecimale di **tutti i frame TX/RX** su stderr (vedi `band.py` / `bc_test.py`). Indispensabile per il confronto byte-per-byte con l'app.

**SpO2 storica (type `0x2A`)** — richiesta `[giorno]` (0=oggi, 1=ieri…).
Body risposta: `[giorno] + 24 coppie (min,max)`, **una per ORA** (00..23); coppia `00 00` = ora senza misure. L'app mostra il range min-max orario; noi salviamo il massimo. ⚠️ **NON sono slot da 15 min** (errore della prima implementazione).
Esempio verificato: ore 07→16 = `99,97,97,97,96,99,99,98,99,98` (identico all'app).

**Fasi del sonno (type `0x27`)** — richiesta `[giorno, 0x01]`.
Body: **header di 7 byte** (`01 00` + start ecc.), poi coppie **(fase, durata_min)**. Fasi: `2=leggero, 3=profondo, 4=REM, 5=sveglio`. ⚠️ L'ordine è **(fase, durata)** — non (durata, fase) — e l'header è **7 byte**, non 6 (entrambi errori della prima implementazione).
Inizio sonno = minuti dalla mezzanotte, `u16 LE` su `header[3:5]` (es. `…29 00…` → 41 → **00:41**). Da confermare per addormentamenti **prima** di mezzanotte.
Esempio verificato (notte 2026-06-14): leggero 352, profondo 90, REM 63, sveglio 2 → totale **8h27**, inizio **00:41** — identico all'app.
Implementato in `band.py` (`spo2_history`, `sleep_detail`) → tabelle `spo2_samples` / `sleep_segments` + `sleep_sessions`.

## Capacità reali (dalla risposta del cmd 1 "set time")
Bitmap che dichiara cosa il device sa fare davvero:
- ❌ **Temperatura corporea: NON supportata** (il marketing la cita, il sensore non c'è).
- ✅ SpO2, Pressione, HRV, Stress, **Sonno** (nuovo protocollo). ❌ Glicemia, battito manuale.

## Cosa ha storico e cosa no
- **Storico sul device** (scaricabile in una sync, si riempie indossandolo): battito (5min), passi/calorie/distanza (15min), stress (30min), HRV (30min), **SpO2** (oraria, canale bc), **sonno** (fasi, canale bc).
- **Solo on-demand** per la nostra lettura: **pressione** (ma l'app ne mostra uno storico orario, vedi TODO).

## TODO / prossimi step
1. ✅ **Sonno e SpO2 storica decodificati** sul canale "ricco" bc (vedi sezione dedicata), verificati byte-per-byte contro l'app. Resta da confermare l'**inizio sonno** per addormentamenti prima di mezzanotte.
2. **Decodificare lo storico pressione orario**: l'app lo mostra (es. 17:00 = 129/88), il cmd 20 non risponde → con tutta probabilità è un altro `type` del canale bc. Catturare con `BC_DEBUG=1` mentre l'app sincronizza la pressione.
3. **Retry automatici** sulle misure real-time (battito/SpO2/pressione/stress) per aumentare il tasso di aggancio.
4. ✅ **Storici stress/HRV decodificati e implementati** (`band.py`, slot 30 min). Resta da rifinire l'**HRV real-time** (cmd 105 tipo 10: il valore non è in byte3).
5. **SpO2 min/max**: la tabella `spo2_samples` ha una sola colonna (salviamo il max orario). Valutare colonne `spo2_min`/`spo2_max` per non perdere il range.
6. Eventuale **schedulazione automatica** della sync (es. launchd) quando il braccialetto è in portata.
7. Migrazione DB su istanza remota (il PC resta il ponte BLE; il cloud non ha Bluetooth).

## File di supporto in questa cartella docs/
- `GUIDA_SNOOP_LOG.md` — come ri-catturare l'HCI snoop log da Android.
- `parse_snoop.py` — parser del btsnoop: estrae i frame da 16 byte del braccialetto.
- `btsnoop_hci.log` — cattura grezza del traffico QWatch Pro (utile per decodificare il sonno).
- `Hack the watch.md` / `.html` — articolo divulgativo del progetto.
