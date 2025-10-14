#!/bin/bash
echo "Updating APT and installing Python dependencies..."
sudo apt-get update
sudo apt-get install -y python3-pip
if ! sudo pip3 install openpyxl requests; then
    echo "Failed to install openpyxl and requests via pip"
    exit 1
fi
mkdir -p /home/fpp/media/plugins/fpp-FSEQDistributor/temp
cp parse_fseq.py /home/fpp/media/plugins/fpp-FSEQDistributor/
chmod +x /home/fpp/media/plugins/fpp-FSEQDistributor/parse_fseq.py
echo "FSEQDistributor installed. Python deps: openpyxl, requests."
