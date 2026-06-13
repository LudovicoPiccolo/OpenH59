# Guida: catturare l'HCI snoop log di Android (QWatch Pro)

Obiettivo: registrare i comandi Bluetooth che QWatch Pro scambia col braccialetto
mentre sincronizza lo storico, per ricavare i comandi di sonno / pressione storica /
SpO2 storico / HRV / stress (e capire quanti giorni il braccialetto tiene in memoria).

## A. Sul telefono Android — attivare la registrazione

1. **Opzioni sviluppatore**: Impostazioni → Info sul telefono → tocca "Numero build" 7 volte.
2. Torna in Impostazioni → Sistema → **Opzioni sviluppatore**.
3. Attiva **"Debug USB"**.
4. Attiva **"Log di acquisizione HCI Bluetooth"** (Bluetooth HCI snoop log).
   - Se chiede un livello, scegli **"Abilitato"** o **"Full"** (non "Filtrato").
5. **Spegni e riaccendi il Bluetooth** (così parte un log pulito).

## B. Riprodurre una sincronizzazione completa

1. Apri **QWatch Pro** e assicurati che sia connesso al braccialetto.
2. Forza una **sincronizzazione** (di solito tirando giù la schermata principale).
3. **Apri ogni grafico storico**, uno per uno, per costringere l'app a scaricarli:
   - Frequenza cardiaca (vista giorno e settimana)
   - **Sonno**
   - **Ossigenazione (SpO2)** — storico
   - **Pressione** — storico
   - **HRV**
   - **Stress**
4. (Facoltativo) Avvia una **misura manuale** di pressione e SpO2 dall'app.
5. Annota l'ora: serve per ritrovare i pacchetti nel log.

## C. Scaricare il log sul Mac (via adb)

Sul Mac, una volta sola, installa gli strumenti Android:

```bash
brew install android-platform-tools
```

Collega il **telefono al Mac via USB**, sblocca lo schermo e autorizza il debug
USB quando il telefono lo chiede. Poi:

```bash
# verifica che il telefono sia visto
adb devices

# metodo affidabile: bug report (contiene il btsnoop)
adb bugreport ~/Downloads/braccialetto/bugreport.zip
```

In alternativa, su alcuni telefoni il file è accessibile diretto:

```bash
adb pull /sdcard/btsnoop_hci.log ~/Downloads/braccialetto/
# oppure
adb pull /data/misc/bluetooth/logs/btsnoop_hci.log ~/Downloads/braccialetto/
```

## D. Consegna

Metti il file (`bugreport.zip` o `btsnoop_hci.log`) nella cartella del progetto
`~/Downloads/braccialetto/`. Da lì lo analizzo io: estraggo i frame da 16 byte
scambiati col braccialetto e ricavo i comandi storici esatti.

## Note privacy
Il bug report contiene anche altri dati del telefono. Lo analizzo solo per i
pacchetti del braccialetto e non serve condividerlo con nessun altro.
