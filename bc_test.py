"""
Test del canale "ricco" bc: scarica SpO2 storica + fasi del sonno dal braccialetto
e le stampa (NON salva nel DB). Serve a verificare l'handshake e il parsing dal vivo.

Indossa il braccialetto vicino al Mac, Bluetooth del telefono SPENTO, poi:
    .venv/bin/python bc_test.py            # oggi
    .venv/bin/python bc_test.py --day 1    # ieri

Per il confronto byte-per-byte con l'app ufficiale, attiva il dump dei frame grezzi
(TX/RX del canale bc) su stderr e salvalo su file:
    BC_DEBUG=1 .venv/bin/python bc_test.py --day 1 2> bc_raw.log
"""
import argparse
import asyncio

from band import Band
from config import BAND_ADDRESS, BAND_ACCOUNT


async def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--day", type=int, default=0, help="0=oggi, 1=ieri, ...")
    args = ap.parse_args()

    async with Band(BAND_ADDRESS) as band:
        if not band._bc_ready:
            print("Canale bc non disponibile su questo braccialetto/firmware.")
            return
        print(f"Account handshake: {BAND_ACCOUNT}")

        spo2 = await band.spo2_history(args.day)
        print(f"\n### SpO2 storica (giorno -{args.day}): {len(spo2)} punti")
        for ts, v in spo2[:8]:
            print(f"  {ts:%H:%M}  {v}%")
        if len(spo2) > 8:
            print(f"  ... (+{len(spo2)-8})")

        sleep = await band.sleep_detail(args.day)
        if not sleep:
            print(f"\n### Sonno (giorno -{args.day}): nessun dato")
            return
        t = sleep.totals()
        print(f"\n### Sonno (giorno -{args.day}): {len(sleep.segments)} segmenti, "
              f"totale {t['total']//60}h{t['total']%60:02d}")
        print(f"  leggero {t['light']}m · profondo {t['deep']}m · "
              f"REM {t['rem']}m · sveglio {t['awake']}m")
        print(f"  header grezzo: {sleep.header.hex(' ')}")


if __name__ == "__main__":
    asyncio.run(main())
