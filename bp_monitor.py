#!/usr/bin/env python3
"""
LudoHealt - Monitor pressione (e battito/SpO2) a intervalli.

Il braccialetto NON tiene uno storico di pressione scaricabile via il protocollo a
16 byte: l'app ufficiale costruisce la curva facendo una misura ogni ora e salvandola.
Questo script fa lo stesso dalla nostra parte: ogni N minuti si connette, misura la
pressione (e gratis battito + SpO2, visto che è già connesso), salva su MySQL e si
disconnette — così il telefono può usare il braccialetto nel resto del tempo.

Uso (indossa il braccialetto vicino al Mac, Bluetooth del telefono SPENTO):
    .venv/bin/python bp_monitor.py --once        # una sola misura (test)
    .venv/bin/python bp_monitor.py               # ogni 60 min, finché non lo fermi (Ctrl-C)
    .venv/bin/python bp_monitor.py --interval 30 # ogni 30 min
"""
import argparse
import asyncio
import sys
from datetime import datetime

from band import Band, RT, BPResult
from config import BAND_ADDRESS
from store import Store


def log(msg: str) -> None:
    print(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] {msg}", flush=True)


async def one_reading(store: Store) -> bool:
    """Una misura: pressione + battito + SpO2. True se la pressione è agganciata."""
    try:
        async with Band(BAND_ADDRESS) as band:
            bat = await band.battery()
            if bat:
                store.add_measurement("battery", float(bat[0]), unit="%")

            res = await band.measure(RT.BLOOD_PRESSURE, timeout=60)
            if not isinstance(res, BPResult):
                log("pressione non agganciata (braccialetto indossato bene e fermo?)")
                return False
            store.add_measurement("blood_pressure", float(res.systolic),
                                  value2=float(res.diastolic), unit="mmHg")

            # già connessi: prendiamo anche battito e SpO2 per il confronto di stasera
            hr = await band.measure(RT.HEART_RATE, timeout=25)
            if hr:
                store.add_measurement("heart_rate", float(hr), unit="bpm")
            spo2 = await band.measure(RT.SPO2, timeout=25)
            if spo2:
                store.add_measurement("spo2", float(spo2), unit="%")

            log(f"OK  pressione {res.systolic}/{res.diastolic} mmHg"
                + (f", battito {hr} bpm" if hr else "")
                + (f", SpO2 {spo2}%" if spo2 else ""))
            return True
    except Exception as e:
        log(f"errore: {e}")
        return False


async def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--interval", type=int, default=60, help="minuti tra una misura e l'altra")
    ap.add_argument("--once", action="store_true", help="una sola misura, poi termina")
    args = ap.parse_args()

    if not BAND_ADDRESS:
        log("BAND_ADDRESS non configurato nel .env"); sys.exit(1)

    store = Store()
    n = 0
    try:
        while True:
            n += 1
            log(f"--- misura #{n} ---")
            await one_reading(store)
            if args.once:
                break
            log(f"prossima misura tra {args.interval} min (Ctrl-C per fermare)")
            await asyncio.sleep(args.interval * 60)
    finally:
        store.close()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        log("fermato.")
