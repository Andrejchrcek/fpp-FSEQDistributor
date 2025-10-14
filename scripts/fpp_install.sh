#!/bin/bash
set -e

# Vytvoríme log súbor
LOGFILE="/home/fpp/media/logs/fpp_install_FSEQDistributor.log"
echo "=== Inštalácia FSEQDistributor ===" > $LOGFILE
echo "Dátum: $(date)" >> $LOGFILE

echo "Inštalujem openpyxl..." | tee -a $LOGFILE
pip3 install openpyxl 2>&1 | tee -a $LOGFILE

echo "Inštalácia dokončená." | tee -a $LOGFILE
echo "=== KONIEC ===" >> $LOGFILE
