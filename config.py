"""
LudoHealt - Configurazione condivisa, letta dal file `.env` (se presente).

Nessuna dipendenza esterna: piccolo parser KEY=VALUE.
Le variabili gia' presenti nell'ambiente hanno la precedenza sul file `.env`.
"""
from __future__ import annotations

import os
from pathlib import Path


def _load_env(path: Path) -> None:
    if not path.exists():
        return
    for raw in path.read_text().splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, val = line.partition("=")
        os.environ.setdefault(key.strip(), val.strip())


_load_env(Path(__file__).with_name(".env"))

BAND_ADDRESS = os.environ.get("BAND_ADDRESS", "")
# Nome account usato dall'handshake del canale "ricco" bc (login type 0x4a).
# E' lo username dell'app QWatch (parte prima della @ dell'email). Modificabile da .env.
BAND_ACCOUNT = os.environ.get("BAND_ACCOUNT", "ludoplus")

DB_CONFIG = dict(
    host=os.environ.get("DB_HOST", "127.0.0.1"),
    port=int(os.environ.get("DB_PORT", "3306")),
    user=os.environ.get("DB_USER", "root"),
    password=os.environ.get("DB_PASS", ""),
    database=os.environ.get("DB_NAME", "ludohealt"),
)
