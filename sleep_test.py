"""Dump del sonno grezzo dal braccialetto (cmd 13). Indossa il braccialetto e
spegni il Bluetooth del telefono, poi:  .venv/bin/python sleep_test.py
Salva i byte grezzi per giorno: serviranno a decodificare gli stadi del sonno
confrontandoli col grafico dell'app ufficiale."""
import asyncio

from band import Band
from config import BAND_ADDRESS


async def main() -> None:
    async with Band(BAND_ADDRESS) as band:
        days = await band.sleep_history()
        if not days:
            print("Nessun dato di sonno restituito.")
            return
        for d in days:
            seg = d.segments
            print(f"\n{d.date:%Y-%m-%d}  header={d.header.hex(' ')}")
            print(f"  {len(seg)} segmenti: {seg}")
            if seg:
                print(f"  min={min(seg)} max={max(seg)} hex={bytes(seg).hex(' ')}")


if __name__ == "__main__":
    asyncio.run(main())
