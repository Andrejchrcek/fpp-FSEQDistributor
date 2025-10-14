#!/bin/bash

# Získaj priečinok pluginu
BASEDIR=$(dirname $0)
cd $BASEDIR
cd ..
PLUGIN_DIR=$(pwd)
LOG_FILE="/home/fpp/media/plugins/fpp-FSEQDistributor/install.log"

echo "Starting install at $(date)" > $LOG_FILE

# Aktualizuj APT
echo "Running apt-get update..." >> $LOG_FILE
sudo apt-get update >> $LOG_FILE 2>&1 || echo "APT update failed" >> $LOG_FILE

# Nainštaluj python3-pip
echo "Installing python3-pip..." >> $LOG_FILE
sudo apt-get install -y python3-pip >> $LOG_FILE 2>&1 || echo "python3-pip install failed" >> $LOG_FILE

# Nainštaluj openpyxl a requests
echo "Installing openpyxl and requests..." >> $LOG_FILE
if ! sudo pip3 install openpyxl requests >> $LOG_FILE 2>&1; then
    echo "Failed to install openpyxl and requests via pip" >> $LOG_FILE
    exit 1
fi

# Vytvor temp priečinok
echo "Creating temp dir..." >> $LOG_FILE
mkdir -p /home/fpp/media/plugins/fpp-FSEQDistributor/temp >> $LOG_FILE 2>&1 || echo "mkdir failed" >> $LOG_FILE

# Skopíruj Python skript
echo "Copying parse_fseq.py..." >> $LOG_FILE
cp parse_fseq.py /home/fpp/media/plugins/fpp-FSEQDistributor/ >> $LOG_FILE 2>&1 || echo "cp failed" >> $LOG_FILE

# Nastav práva
echo "Setting executable permissions..." >> $LOG_FILE
chmod +x /home/fpp/media/plugins/fpp-FSEQDistributor/parse_fseq.py >> $LOG_FILE 2>&1 || echo "chmod failed" >> $LOG_FILE

# Nastav reštart FPPD
echo "Setting restart flag..." >> $LOG_FILE
. /opt/fpp/scripts/common
setSetting restartFlag 1 >> $LOG_FILE 2>&1 || echo "setSetting failed" >> $LOG_FILE

echo "FSEQDistributor installed. Python deps: openpyxl, requests." >> $LOG_FILE
