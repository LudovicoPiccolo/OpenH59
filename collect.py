#!/usr/bin/env python3
"""
LudoHealt - Collettore unificato.
Si connette al braccialetto, scarica lo storico (battito + passi) e fa le misure
on-demand (battito, SpO2, pressione, stress), salva tutto su MySQL `ludohealt`.
Stampa un riepilogo JSON sull'ultima riga (usato dalla pagina index.php).

Uso:
    python collect.py --mode quick     # batteria + HR + SpO2 + storico (~1 min)
    python collect.py --mode full      # tutto, anche pressione e stress (~3 min)
    python collect.py --mode history   # solo download storico (~10 s)
"""
import argparse
import asyncio
import json
import sys
from datetime import datetime

from band import Band, RT, BPResult
from config import BAND_ADDRESS
from store import Store


def log(msg: str) -> None:
    print(f"[{datetime.now():%H:%M:%S}] {msg}", file=sys.stderr)


async def run(address: str, mode: str, days: int) -> dict:
    result = {"ok": False, "battery": None, "measurements": [],
              "hr_points": 0, "step_points": 0, "stress_points": 0, "hrv_points": 0,
              "spo2_points": 0, "sleep_days": 0, "errors": []}
    store = Store()
    try:
        async with Band(address) as band:
            log("Connesso al braccialetto.")

            bat = await band.battery()
            if bat:
                store.add_measurement("battery", float(bat[0]), unit="%")
                result["battery"] = {"level": bat[0], "charging": bat[1]}
                log(f"Batteria {bat[0]}% (charging={bat[1]})")

            # misure on-demand
            if mode in ("quick", "full"):
                plan = [("heart_rate", RT.HEART_RATE, 22, "bpm"),
                        ("spo2", RT.SPO2, 22, "%")]
                if mode == "full":
                    plan += [("blood_pressure", RT.BLOOD_PRESSURE, 45, "mmHg"),
                             ("stress", RT.STRESS, 35, "score")]
                for name, kind, tmo, unit in plan:
                    log(f"Misuro {name} (max {tmo}s)...")
                    try:
                        res = await band.measure(kind, timeout=tmo)
                    except Exception as e:
                        result["errors"].append(f"{name}: {e}")
                        continue
                    if res is None:
                        log(f"  {name}: non agganciato")
                    elif isinstance(res, BPResult):
                        store.add_measurement("blood_pressure", float(res.systolic),
                                              value2=float(res.diastolic), unit=unit)
                        result["measurements"].append(
                            {"metric": "blood_pressure", "value": res.systolic, "value2": res.diastolic, "unit": unit})
                        log(f"  pressione {res.systolic}/{res.diastolic}")
                    else:
                        store.add_measurement(name, float(res), unit=unit)
                        result["measurements"].append({"metric": name, "value": res, "unit": unit})
                        log(f"  {name}: {res} {unit}")

            # storico
            measured_hr = any(m["metric"] == "heart_rate" for m in result["measurements"])
            last_hr_point = None
            if mode in ("quick", "full", "history"):
                for d in range(days + 1):
                    try:
                        hr = await band.heart_rate_history(d)
                        result["hr_points"] += store.upsert_hr(hr)
                        if d == 0 and hr:
                            last_hr_point = hr[-1]
                        st = await band.steps_history(d)
                        result["step_points"] += store.upsert_steps(st)
                        stress = await band.stress_history(d)
                        result["stress_points"] += store.upsert_stress(stress)
                        hrv = await band.hrv_history(d)
                        result["hrv_points"] += store.upsert_hrv(hrv)
                        # canale ricco bc: SpO2 storica + fasi del sonno
                        spo2 = await band.spo2_history(d)
                        result["spo2_points"] += store.upsert_spo2(spo2)
                        sleep = await band.sleep_detail(d)
                        n_sleep = 0
                        if sleep:
                            store.replace_sleep(sleep.date.strftime("%Y-%m-%d"),
                                                [(s.stage, s.minutes) for s in sleep.segments],
                                                start_ts=sleep.start)
                            result["sleep_days"] += 1
                            n_sleep = len(sleep.segments)
                        log(f"Giorno -{d}: {len(hr)} punti battito, {len(st)} slot passi, "
                            f"{len(stress)} stress, {len(hrv)} HRV, {len(spo2)} SpO2, {n_sleep} segmenti sonno")
                    except Exception as e:
                        result["errors"].append(f"storico giorno -{d}: {e}")

            # fallback: se il battito on-demand non ha agganciato, usa l'ultimo dal log 24/7
            if not measured_hr and last_hr_point is not None:
                ts, bpm = last_hr_point
                store.add_measurement("heart_rate", float(bpm), unit="bpm", ts=ts)
                result["measurements"].append({"metric": "heart_rate", "value": bpm, "unit": "bpm", "from": "log24h"})
                log(f"Battito (da log 24/7): {bpm} bpm")

            result["ok"] = True
    except Exception as e:
        result["errors"].append(str(e))
        log(f"ERRORE: {e}")
    finally:
        store.close()
    return result


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--address", default=BAND_ADDRESS)
    ap.add_argument("--mode", choices=["quick", "full", "history"], default="quick")
    ap.add_argument("--days", type=int, default=1, help="giorni di storico da scaricare (oltre a oggi)")
    args = ap.parse_args()

    result = asyncio.run(run(args.address, args.mode, args.days))
    print(json.dumps(result))  # ultima riga = JSON per la pagina


if __name__ == "__main__":
    main()
