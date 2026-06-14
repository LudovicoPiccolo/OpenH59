"""
LudoHealt - Persistenza su MySQL. Connessione configurata via `.env` (vedi config.py).

Tabelle:
  measurements   -> misure on-demand (battito, SpO2, pressione, stress) col momento
  hr_samples     -> curva del battito storica (1 punto / 5 min), ts unico
  step_samples   -> passi/calorie/distanza storici (1 punto / 15 min), ts unico
  stress_samples -> curva dello stress storica (1 punto / 30 min), ts unico
  hrv_samples    -> curva dell'HRV storica (ms, 1 punto / 30 min), ts unico
"""
from __future__ import annotations

from datetime import datetime, timezone

import pymysql

from config import DB_CONFIG

SCHEMA = [
    """CREATE TABLE IF NOT EXISTS measurements (
        id      BIGINT AUTO_INCREMENT PRIMARY KEY,
        ts      DATETIME NOT NULL,
        metric  VARCHAR(32) NOT NULL,
        value   DOUBLE,
        value2  DOUBLE,
        unit    VARCHAR(16),
        source  VARCHAR(32) DEFAULT 'H59',
        INDEX (metric), INDEX (ts)
    ) CHARACTER SET utf8mb4""",
    """CREATE TABLE IF NOT EXISTS hr_samples (
        ts   DATETIME NOT NULL PRIMARY KEY,
        bpm  SMALLINT NOT NULL
    ) CHARACTER SET utf8mb4""",
    """CREATE TABLE IF NOT EXISTS step_samples (
        ts        DATETIME NOT NULL PRIMARY KEY,
        steps     INT NOT NULL,
        calories  INT,
        distance  INT
    ) CHARACTER SET utf8mb4""",
    """CREATE TABLE IF NOT EXISTS stress_samples (
        ts     DATETIME NOT NULL PRIMARY KEY,
        score  SMALLINT NOT NULL
    ) CHARACTER SET utf8mb4""",
    """CREATE TABLE IF NOT EXISTS hrv_samples (
        ts   DATETIME NOT NULL PRIMARY KEY,
        ms   SMALLINT NOT NULL
    ) CHARACTER SET utf8mb4""",
    """CREATE TABLE IF NOT EXISTS spo2_samples (
        ts    DATETIME NOT NULL PRIMARY KEY,
        spo2  SMALLINT NOT NULL
    ) CHARACTER SET utf8mb4""",
    """CREATE TABLE IF NOT EXISTS sleep_segments (
        sleep_date  DATE NOT NULL,
        idx         SMALLINT NOT NULL,
        stage       VARCHAR(8) NOT NULL,
        minutes     SMALLINT NOT NULL,
        PRIMARY KEY (sleep_date, idx)
    ) CHARACTER SET utf8mb4""",
    """CREATE TABLE IF NOT EXISTS sleep_sessions (
        sleep_date  DATE NOT NULL PRIMARY KEY,
        start_ts    DATETIME NOT NULL
    ) CHARACTER SET utf8mb4""",
]


def ensure_database() -> None:
    """Crea il database `ludohealt` se non esiste."""
    cfg = dict(DB_CONFIG)
    db = cfg.pop("database")
    conn = pymysql.connect(**cfg)
    with conn.cursor() as cur:
        cur.execute(f"CREATE DATABASE IF NOT EXISTS {db} CHARACTER SET utf8mb4")
    conn.commit()
    conn.close()


class Store:
    def __init__(self):
        ensure_database()
        self.conn = pymysql.connect(**DB_CONFIG, autocommit=True)
        with self.conn.cursor() as cur:
            for ddl in SCHEMA:
                cur.execute(ddl)

    def _fmt(self, ts: datetime) -> str:
        return ts.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

    def add_measurement(self, metric: str, value: float | None, value2: float | None = None,
                        unit: str | None = None, ts: datetime | None = None) -> None:
        ts = ts or datetime.now(timezone.utc)
        with self.conn.cursor() as cur:
            cur.execute(
                "INSERT INTO measurements (ts, metric, value, value2, unit) VALUES (%s,%s,%s,%s,%s)",
                (self._fmt(ts), metric, value, value2, unit),
            )

    def upsert_hr(self, samples: list[tuple[datetime, int]]) -> int:
        if not samples:
            return 0
        with self.conn.cursor() as cur:
            cur.executemany(
                "INSERT INTO hr_samples (ts, bpm) VALUES (%s,%s) "
                "ON DUPLICATE KEY UPDATE bpm=VALUES(bpm)",
                [(self._fmt(t), int(hr)) for t, hr in samples],
            )
        return len(samples)

    def upsert_steps(self, samples: list[tuple[datetime, int, int, int]]) -> int:
        if not samples:
            return 0
        with self.conn.cursor() as cur:
            cur.executemany(
                "INSERT INTO step_samples (ts, steps, calories, distance) VALUES (%s,%s,%s,%s) "
                "ON DUPLICATE KEY UPDATE steps=VALUES(steps), calories=VALUES(calories), distance=VALUES(distance)",
                [(self._fmt(t), int(s), int(c), int(d)) for t, s, c, d in samples],
            )
        return len(samples)

    def _upsert_slot(self, table: str, col: str, samples: list[tuple[datetime, int]]) -> int:
        if not samples:
            return 0
        with self.conn.cursor() as cur:
            cur.executemany(
                f"INSERT INTO {table} (ts, {col}) VALUES (%s,%s) "
                f"ON DUPLICATE KEY UPDATE {col}=VALUES({col})",
                [(self._fmt(t), int(v)) for t, v in samples],
            )
        return len(samples)

    def upsert_stress(self, samples: list[tuple[datetime, int]]) -> int:
        return self._upsert_slot("stress_samples", "score", samples)

    def upsert_hrv(self, samples: list[tuple[datetime, int]]) -> int:
        return self._upsert_slot("hrv_samples", "ms", samples)

    def upsert_spo2(self, samples: list[tuple[datetime, int]]) -> int:
        return self._upsert_slot("spo2_samples", "spo2", samples)

    def replace_sleep(self, sleep_date: str, segments: list[tuple[str, int]],
                      start_ts: datetime | None = None) -> int:
        """Sostituisce le fasi del sonno di un giorno (YYYY-MM-DD): lista di (stage, minutes).
        start_ts = istante d'inizio del sonno (per posizionare l'asse orario)."""
        with self.conn.cursor() as cur:
            cur.execute("DELETE FROM sleep_segments WHERE sleep_date=%s", (sleep_date,))
            cur.execute("DELETE FROM sleep_sessions WHERE sleep_date=%s", (sleep_date,))
            if segments:
                cur.executemany(
                    "INSERT INTO sleep_segments (sleep_date, idx, stage, minutes) VALUES (%s,%s,%s,%s)",
                    [(sleep_date, i, st, int(m)) for i, (st, m) in enumerate(segments)],
                )
            if start_ts is not None:
                cur.execute("INSERT INTO sleep_sessions (sleep_date, start_ts) VALUES (%s,%s)",
                            (sleep_date, self._fmt(start_ts)))
        return len(segments)

    def close(self) -> None:
        self.conn.close()
