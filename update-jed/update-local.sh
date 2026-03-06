#!/bin/bash
#
# Deploy su dev www.nospace.lan local testing instance.
#
set -e

# --- Configuration ---
# Get the absolute path of the script's directory (the source)
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Define the destination paths for the Joomla instance
DST_DIR="/workspace/sites/nospace.net/data/www/nospace/update-jed"

# --- Deployment ---
echo "🚀 Deploying to nospace.lan..."
echo "Source:      $SRC_DIR"
echo "Destination: $DST_DIR"

# Sync folders — trailing slash on SRC_DIR copies its CONTENTS into DST_DIR
echo "Synchronizing files..."
sudo rsync -av --delete \
    "$SRC_DIR/" \
    "$DST_DIR/"

sudo chown -R www-data:www-data $DST_DIR 

echo "✅ Test on http://www.nospace.lan."
