#!/bin/bash
#
# Builds the nsprism plugin installation package (zip).
#
# Usage:
#   ./build.sh              # creates plg_system_nsprism_vX.Y.Z.zip
#   ./build.sh --copy       # also copies the zip to /mnt/c/Data/_Backups
#
set -e

# --- Configuration ---
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="/mnt/c/Data/_Backups"

# Read version from the manifest (e.g. <version>1.0.2</version>)
VERSION=$(grep -oP '(?<=<version>)[^<]+' "$SRC_DIR/nsprism.xml")
ZIP_NAME="plg_system_nsprism_v${VERSION}.zip"
ZIP_PATH="$SRC_DIR/$ZIP_NAME"

# --- Build ---
echo "Building NsPrism Plugin package..."
echo "Version: $VERSION"
echo "Output:  $ZIP_PATH"

# Remove any existing zip for this version
rm -f "$ZIP_PATH"

cd "$SRC_DIR"
zip -r "$ZIP_NAME" \
    nsprism.php \
    nsprism.xml \
    script.php \
    LICENSE.txt \
    fields/ \
    language/ \
    media/

echo "Done: $(du -sh "$ZIP_PATH" | cut -f1)  $ZIP_NAME"

# --- Optional copy to backup ---
if [[ "$1" == "--copy" ]]; then
    if [[ -d "$BACKUP_DIR" ]]; then
        cp "$ZIP_PATH" "$BACKUP_DIR/"
        echo "Copied to $BACKUP_DIR/$ZIP_NAME"
    else
        echo "Warning: backup dir not found ($BACKUP_DIR), skipping copy."
    fi
fi
