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
| Storico pressione | 20 | **non risponde** → la pressione NON ha storico (solo on-demand) |
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

## Capacità reali (dalla risposta del cmd 1 "set time")
Bitmap che dichiara cosa il device sa fare davvero:
- ❌ **Temperatura corporea: NON supportata** (il marketing la cita, il sensore non c'è).
- ✅ SpO2, Pressione, HRV, Stress, **Sonno** (nuovo protocollo). ❌ Glicemia, battito manuale.

## Cosa ha storico e cosa no
- **Storico sul device** (scaricabile in una sync, si riempie indossandolo): battito (5min), passi/calorie/distanza (15min), stress, HRV, **sonno**.
- **Solo on-demand** (misura del momento, nessuno storico): **pressione**, **SpO2**.

## TODO / prossimi step
1. **Identificare il comando del sonno** (cmd 68/42 danno errore; il dato comparirà solo dopo aver dormito col braccialetto — poi ri-catturare snoop o ri-probare). Materiale: `btsnoop_hci.log` + `parse_snoop.py` in questa cartella.
2. **Retry automatici** sulle misure real-time (battito/SpO2/pressione/stress) per aumentare il tasso di aggancio.
3. ✅ **Storici stress/HRV decodificati e implementati** (`band.py`, slot 30 min). Resta da rifinire l'**HRV real-time** (cmd 105 tipo 10: il valore non è in byte3).
4. Eventuale **schedulazione automatica** della sync (es. launchd) quando il braccialetto è in portata.
5. Migrazione DB su istanza remota (il PC resta il ponte BLE; il cloud non ha Bluetooth).

## File di supporto in questa cartella docs/
- `GUIDA_SNOOP_LOG.md` — come ri-catturare l'HCI snoop log da Android.
- `parse_snoop.py` — parser del btsnoop: estrae i frame da 16 byte del braccialetto.
- `btsnoop_hci.log` — cattura grezza del traffico QWatch Pro (utile per decodificare il sonno).
- `Hack the watch.md` / `.html` — articolo divulgativo del progetto.
