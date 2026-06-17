#!/usr/bin/env python3
"""
LudoHealt - Collettore unificato.
Si connette al braccialetto, scarica lo storico (battito + passi) e fa le misure
on-demand (battito, SpO2, pressione, stress), salva tutto su MySQL `ludohealt`.
Stampa un riepilogo JSON sull'ultima riga (usato dalla pagina index.php).

Storico INCREMENTALE: di default riparte dall'ultimo dato salvato nel DB (MAX(ts)) e
scarica solo i giorni mancanti. Sync quotidiano -> velocissimo (oggi); rientro da
un'assenza -> recupera in automatico i giorni di buco. Niente marcatore da mantenere:
la sorgente di verita' e' il dato stesso nel DB; gli upsert sono idempotenti (ri-scaricare
un giorno gia' salvato non crea doppioni). Cap a MAX_DAYS oltre il buffer del device (~7 gg).

Uso:
    python collect.py --mode quick                   # batteria + HR + SpO2 + storico incrementale
    python collect.py --mode full                    # tutto, anche pressione e stress
    python collect.py --mode history                 # solo storico incrementale (dall'ultimo dato)
    python collect.py --mode history --days 7        # override: forza 7 giorni a ritroso
    python collect.py --mode history --from 2026-06-10T08:00   # parti da un istante preciso (ISO)
"""
import argparse
import asyncio
import json
import sys
from datetime import datetime, timezone

from band import Band, RT, BPResult, LOCAL_TZ
from config import BAND_ADDRESS
from store import Store

DEFAULT_DAYS = 7   # ripiego quando il DB e' vuoto (primo sync)
MAX_DAYS = 14      # tetto: oltre il buffer del device (~7 gg) insistere e' solo tempo perso


def log(msg: str) -> None:
    print(f"[{datetime.now():%H:%M:%S}] {msg}", file=sys.stderr)


def resolve_days(store: Store, days_arg: int | None, from_arg: str | None) -> tuple[int, str]:
    """Quanti giorni di storico scaricare (oltre a oggi) e perche'.
    Precedenza: --days esplicito > --from esplicito > ultimo dato nel DB (incrementale)."""
    if days_arg is not None:
        return max(0, days_arg), f"override --days={days_arg}"
    if from_arg:
        try:
            last = datetime.fromisoformat(from_arg)
        except ValueError:
            return DEFAULT_DAYS, f"--from non valido ({from_arg!r}), uso default {DEFAULT_DAYS}"
        src = "--from"
    else:
        last = store.last_sample_ts()
        src = "ultimo dato nel DB"
    if last is None:
        return DEFAULT_DAYS, f"DB vuoto, uso default {DEFAULT_DAYS}"
    if last.tzinfo is None:
        last = last.replace(tzinfo=timezone.utc)
    span = (datetime.now(LOCAL_TZ).date() - last.astimezone(LOCAL_TZ).date()).days
    days = max(0, min(span, MAX_DAYS))
    note = f"{src}: ultimo dato {last.astimezone(LOCAL_TZ):%Y-%m-%d %H:%M} -> {days} giorni"
    if span > MAX_DAYS:
        note += f" (limitato a {MAX_DAYS}, oltre il buffer del device)"
    return days, note


async def run(address: str, mode: str, days_arg: int | None, from_arg: str | None) -> dict:
    result = {"ok": False, "battery": None, "measurements": [], "days_synced": None,
              "hr_points": 0, "step_points": 0, "stress_points": 0, "hrv_points": 0,
              "spo2_points": 0, "sleep_days": 0, "errors": []}
    store = Store()
    days, why = resolve_days(store, days_arg, from_arg)
    result["days_synced"] = days
    log(f"Storico incrementale: {why}")
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
                        # canale ricco bc: SpO2 storica (le fasi del sonno arrivano in un blob unico)
                        spo2 = await band.spo2_history(d)
                        result["spo2_points"] += store.upsert_spo2(spo2)
                        log(f"Giorno -{d}: {len(hr)} punti battito, {len(st)} slot passi, "
                            f"{len(stress)} stress, {len(hrv)} HRV, {len(spo2)} SpO2")
                    except Exception as e:
                        result["errors"].append(f"storico giorno -{d}: {e}")

                # Sonno: il device impacchetta TUTTE le notti memorizzate in un unico blob,
                # quindi una sola richiesta (vedi Band.sleep_nights). Niente loop per-giorno:
                # iterare gli offset ridava sempre lo stesso blob, sommato come una notte sola.
                try:
                    nights = await band.sleep_nights()
                    for night in nights:
                        store.replace_sleep(night.date.strftime("%Y-%m-%d"),
                                            [(s.stage, s.minutes) for s in night.segments],
                                            start_ts=night.start)
                    result["sleep_days"] = len(nights)
                    log("Sonno: " + (", ".join(f"{n.date:%m-%d}={n.totals()['total']}m" for n in nights)
                                      or "nessuna notte"))
                except Exception as e:
                    result["errors"].append(f"sonno: {e}")

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
    ap.add_argument("--days", type=int, default=None,
                    help="override: forza N giorni di storico a ritroso (oltre a oggi). "
                         "Se assente, sincronizzazione incrementale dall'ultimo dato nel DB.")
    ap.add_argument("--from", dest="from_ts", default=None,
                    help="istante di partenza ISO (es. 2026-06-10T08:00): scarica da quel giorno "
                         "a oggi. Ignorato se c'e' --days.")
    args = ap.parse_args()

    result = asyncio.run(run(args.address, args.mode, args.days, args.from_ts))
    print(json.dumps(result))  # ultima riga = JSON per la pagina


if __name__ == "__main__":
    main()
