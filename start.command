#!/bin/bash
# LudoHealt - avvio dashboard.
# Avvia il server PHP DAL TERMINALE cosi' eredita il permesso Bluetooth.
# Doppio click oppure: bash start.command
cd "$(dirname "$0")" || exit 1
echo "LudoHealt -> http://127.0.0.1:8080"
echo "(Ctrl+C per fermare)"
php -S 127.0.0.1:8080
