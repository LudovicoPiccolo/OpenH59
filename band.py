"""
LudoHealt - Client BLE per il braccialetto H59 (protocollo Colmi/QC, Nordic UART).

Misure real-time (cmd 105, parsing dai byte reali del device):
  - Battito (1)   -> byte[3]
  - SpO2 (3)      -> byte[3]
  - Pressione (2) -> byte[4]=sistolica, byte[5]=diastolica, byte[3]=battito
  - Stress (8)    -> byte[3]
Storico scaricabile (si riempie indossando il braccialetto):
  - Battito (cmd 21): curva giornaliera a 5 minuti (288 punti)
  - Passi/calorie/distanza (cmd 67): a 15 minuti
Batteria: cmd 3.  Setup: set_time (cmd 1), log battito 24/7 (cmd 22).
"""
from __future__ import annotations

import asyncio
import statistics
import struct
import time
from dataclasses import dataclass
from datetime import datetime, timezone, timedelta
from zoneinfo import ZoneInfo

from bleak import BleakClient, BleakScanner

# Il braccialetto tiene l'orologio in ora LOCALE (non UTC): ancoriamo lo storico a
# questo fuso e lasciamo che store.py converta in UTC per il salvataggio nel DB.
LOCAL_TZ = ZoneInfo("Europe/Rome")

RX = "6E400002-B5A3-F393-E0A9-E50E24DCCA9E"
TX = "6E400003-B5A3-F393-E0A9-E50E24DCCA9E"

CMD_SET_TIME = 1
CMD_BATTERY = 3
CMD_HR_HISTORY = 21
CMD_HR_LOG = 22
CMD_STRESS_HISTORY = 55
CMD_HRV_HISTORY = 57
CMD_STEPS = 67
CMD_REALTIME = 105
CMD_REALTIME_STOP = 106


class RT:
    HEART_RATE = 1
    BLOOD_PRESSURE = 2
    SPO2 = 3
    STRESS = 8
    HRV = 10


def checksum(p: bytearray) -> int:
    return sum(p) & 255


def make_packet(cmd: int, sub: bytes = b"") -> bytearray:
    p = bytearray(16)
    p[0] = cmd
    for i, b in enumerate(sub):
        p[i + 1] = b
    p[-1] = checksum(p)
    return p


def byte_to_bcd(b: int) -> int:
    return ((b // 10) << 4) | (b % 10)


def bcd_to_dec(b: int) -> int:
    return ((b >> 4) & 15) * 10 + (b & 15)


def midnight_ts(day: int = 0) -> int:
    now = datetime.now(LOCAL_TZ)
    mid = datetime(now.year, now.month, now.day, tzinfo=LOCAL_TZ)
    return int(mid.timestamp()) - day * 86400


@dataclass
class BPResult:
    systolic: int
    diastolic: int
    heart_rate: int | None = None


class Band:
    def __init__(self, address: str):
        self.address = address
        self.client: BleakClient | None = None
        self._rt_q: asyncio.Queue[bytes] = asyncio.Queue()
        self._hist: list[bytes] = []
        self._collecting = False
        self._other: dict[int, bytes] = {}

    async def __aenter__(self) -> "Band":
        device = await BleakScanner.find_device_by_address(self.address, timeout=15)
        if device is None:
            raise RuntimeError("Braccialetto non trovato (indossato? Bluetooth del telefono spento?)")
        self.client = BleakClient(device, timeout=20)
        await self.client.connect()
        await self.client.start_notify(TX, self._on_tx)
        return self

    async def __aexit__(self, *exc) -> None:
        if self.client is not None:
            try:
                await self.client.disconnect()
            except Exception:
                pass

    def _on_tx(self, _, data: bytearray) -> None:
        b = bytes(data)
        if b[0] == CMD_REALTIME:
            self._rt_q.put_nowait(b)
        else:
            self._other[b[0]] = b
            if self._collecting:
                self._hist.append(b)

    async def _send(self, pkt: bytearray) -> None:
        assert self.client is not None
        await self.client.write_gatt_char(RX, pkt, response=False)

    async def _request(self, cmd: int, sub: bytes = b"", wait: float = 2.8) -> list[bytes]:
        """Invia un comando e raccoglie tutti i pacchetti di risposta entro `wait`."""
        self._hist = []
        self._collecting = True
        await self._send(make_packet(cmd, sub))
        await asyncio.sleep(wait)
        self._collecting = False
        return [p for p in self._hist if p[0] == cmd]

    # ---------- setup ----------
    async def set_time(self, dt: datetime | None = None) -> dict:
        if dt is None:
            dt = datetime.now(LOCAL_TZ)
        dt = dt.astimezone(LOCAL_TZ)
        data = bytes([
            byte_to_bcd(dt.year % 2000), byte_to_bcd(dt.month), byte_to_bcd(dt.day),
            byte_to_bcd(dt.hour), byte_to_bcd(dt.minute), byte_to_bcd(dt.second), 1,
        ])
        self._other.pop(CMD_SET_TIME, None)
        await self._send(make_packet(CMD_SET_TIME, data))
        for _ in range(30):
            if CMD_SET_TIME in self._other:
                break
            await asyncio.sleep(0.1)
        resp = self._other.get(CMD_SET_TIME)
        if not resp:
            return {}
        b = resp[1:]
        return {
            "temperature": b[0] == 1, "spo2": bool(b[3] & 2), "blood_pressure": bool(b[3] & 4),
            "stress": bool(b[13] & 16), "hrv": bool(b[13] & 32), "sleep": b[8] == 1,
        }

    async def set_hr_logging(self, enabled: bool, interval_min: int = 5) -> None:
        await self._send(make_packet(CMD_HR_LOG, bytes([2, 1 if enabled else 2, interval_min])))
        await asyncio.sleep(0.4)

    # ---------- letture dirette ----------
    async def battery(self) -> tuple[int, bool] | None:
        self._other.pop(CMD_BATTERY, None)
        await self._send(make_packet(CMD_BATTERY))
        for _ in range(30):
            if CMD_BATTERY in self._other:
                p = self._other[CMD_BATTERY]
                return p[1], bool(p[2])
            await asyncio.sleep(0.1)
        return None

    # ---------- misure real-time (on-demand) ----------
    async def measure(self, rtype: int, timeout: float = 30.0, want: int = 6):
        while not self._rt_q.empty():
            self._rt_q.get_nowait()
        await self._send(make_packet(CMD_REALTIME, bytes([rtype, 1])))
        primary: list[int] = []
        bp: list[tuple[int, int, int]] = []
        deadline = time.monotonic() + timeout
        try:
            while time.monotonic() < deadline:
                try:
                    p = await asyncio.wait_for(self._rt_q.get(), timeout=2.0)
                except asyncio.TimeoutError:
                    continue
                if len(p) < 7 or p[1] != rtype or p[2] != 0:
                    if len(p) >= 3 and p[2] != 0:
                        break
                    continue
                if rtype == RT.BLOOD_PRESSURE:
                    if p[4] and p[5]:
                        bp.append((p[4], p[5], p[3]))
                        if len(bp) >= 3:
                            break
                elif p[3]:
                    primary.append(p[3])
                    if len(primary) >= want:
                        break
        finally:
            await self._send(make_packet(CMD_REALTIME_STOP, bytes([rtype, 0, 0])))
        if rtype == RT.BLOOD_PRESSURE:
            if not bp:
                return None
            return BPResult(int(statistics.median(s[0] for s in bp)),
                            int(statistics.median(s[1] for s in bp)),
                            int(statistics.median(s[2] for s in bp)) or None)
        return int(statistics.median(primary)) if primary else None

    # ---------- storico ----------
    async def heart_rate_history(self, day: int = 0) -> list[tuple[datetime, int]]:
        """Curva del battito del giorno (0=oggi) a passi di 5 minuti."""
        ts = midnight_ts(day)
        # Il braccialetto indicizza lo storico con la mezzanotte in ora LOCALE codificata
        # come fosse UTC (orologio "naive"): al timestamp UTC va aggiunto l'offset del fuso,
        # altrimenti la richiesta cade nel giorno precedente e il device risponde "nessun dato".
        base = datetime.fromtimestamp(ts, LOCAL_TZ)
        band_ts = ts + int(base.utcoffset().total_seconds())
        packets = await self._request(CMD_HR_HISTORY, struct.pack("<L", band_ts), wait=3.5)
        size = 0
        raw: list[int] = []
        index = 0
        for p in packets:
            sub = p[1]
            if sub == 0xff:
                return []
            if sub == 0:
                size = p[2]
                raw = [0] * (size * 13)
            elif sub == 1:
                raw[0:9] = list(p[6:15])
                index = 9
            else:
                raw[index:index + 13] = list(p[2:15])
                index += 13
        out = []
        for i, hr in enumerate(raw[:288]):
            if hr:
                out.append((base + timedelta(minutes=5 * i), hr))
        return out

    async def _slot_history(self, cmd: int, day: int) -> list[tuple[datetime, int]]:
        """Storico a slot (stress cmd 55, HRV cmd 57): 1 valore per slot, richiesta per giorno.
        Risposta multi-pacchetto: header [cmd, 0, count, interval_min, ...],
        chunk [cmd, idx>=1, ...13 valori...]. Valore 0 = nessuna misura nello slot.
        """
        packets = await self._request(cmd, bytes([day]), wait=3.0)
        interval = 30
        values: dict[int, int] = {}
        for p in packets:
            sub = p[1]
            if sub == 0xff:
                return []
            if sub == 0:
                interval = p[3] or 30
            else:
                for i, v in enumerate(p[2:15]):
                    if v:
                        values[(sub - 1) * 13 + i] = v
        base = datetime.fromtimestamp(midnight_ts(day), LOCAL_TZ)
        return [(base + timedelta(minutes=interval * slot), v) for slot, v in sorted(values.items())]

    async def stress_history(self, day: int = 0) -> list[tuple[datetime, int]]:
        """Curva dello stress del giorno (0=oggi), 1 valore per slot da 30 minuti."""
        return await self._slot_history(CMD_STRESS_HISTORY, day)

    async def hrv_history(self, day: int = 0) -> list[tuple[datetime, int]]:
        """Curva dell'HRV (ms) del giorno (0=oggi), 1 valore per slot da 30 minuti."""
        return await self._slot_history(CMD_HRV_HISTORY, day)

    async def steps_history(self, day: int = 0) -> list[tuple[datetime, int, int, int]]:
        """Ritorna (timestamp, passi, calorie, distanza_m) per slot da 15 minuti."""
        packets = await self._request(CMD_STEPS, bytes([day, 0x0f, 0x00, 0x5f, 0x01]), wait=3.0)
        out = []
        for p in packets:
            if p[1] in (0xff, 0xf0):
                continue
            year = bcd_to_dec(p[1]) + 2000
            month = bcd_to_dec(p[2])
            d = bcd_to_dec(p[3])
            time_index = p[4]
            cal = p[7] | (p[8] << 8)
            steps = p[9] | (p[10] << 8)
            dist = p[11] | (p[12] << 8)
            try:
                t = datetime(year, month, d, time_index // 4, (time_index % 4) * 15, tzinfo=LOCAL_TZ)
            except ValueError:
                continue
            out.append((t, steps, cal, dist))
        return out
