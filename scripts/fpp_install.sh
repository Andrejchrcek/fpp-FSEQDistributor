#!/bin/bash
set -e  # Zastav pri chybe

# Log súbor
LOG_FILE="/home/fpp/media/logs/fseq_install.log"
echo "=== FSEQDistributor Installation ===" > $LOG_FILE
echo "Started: $(date)" >> $LOG_FILE

# Nainštaluj python3-pip ak chýba
echo "Installing python3-pip..." | tee -a $LOG_FILE
sudo apt-get update >> $LOG_FILE 2>&1
sudo apt-get install -y python3-pip >> $LOG_FILE 2>&1

# Nainštaluj Python balíky
echo "Installing Python packages..." | tee -a $LOG_FILE
pip3 install --break-system-packages openpyxl requests 2>&1 | tee -a $LOG_FILE

# Vytvor temp priečinok
mkdir -p temp

echo "Installation complete!" | tee -a $LOG_FILE
echo "Finished: $(date)" >> $LOG_FILE
