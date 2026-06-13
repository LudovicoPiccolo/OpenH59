#!/usr/bin/env python3
"""
LudoHealt - Setup una-tantum del braccialetto:
  - imposta l'orologio interno (timestamp corretti per lo storico)
  - attiva il log automatico del battito 24/7 (default ogni 5 min)
  - mostra le capacita' reali del dispositivo

Uso: python setup.py [--interval 5]
INDOSSA il braccialetto, Bluetooth del telefono SPENTO.
"""
import argparse
import asyncio

from band import Band
from config import BAND_ADDRESS


async def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--address", default=BAND_ADDRESS)
    ap.add_argument("--interval", type=int, default=5)
    args = ap.parse_args()

    async with Band(args.address) as band:
        print("Connesso.")
        caps = await band.set_time()
        print("Ora impostata. Capacita':", caps)
        await band.set_hr_logging(True, args.interval)
        print(f"Log battito 24/7 attivato (ogni {args.interval} min).")
    print("Fatto.")


if __name__ == "__main__":
    asyncio.run(main())
