#!/bin/bash
sudo apt-get update
sudo apt-get install -y python3-pandas python3-requests
mkdir -p /home/fpp/media/plugins/fpp-FSEQDistributor/temp  # Temp folder pre súbory
cp parse_fseq.py /home/fpp/media/plugins/fpp-FSEQDistributor/
chmod +x /home/fpp/media/plugins/fpp-FSEQDistributor/parse_fseq.py
echo "FSEQDistributor installed. Python deps: pandas, requests."
