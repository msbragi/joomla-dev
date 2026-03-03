#!/bin/bash
#
# Creates a GitHub release for the nsprism plugin.
#
# Prerequisites:
#   - GitHub CLI (gh) installed and authenticated
#   - Working directory must be clean (no uncommitted changes)
#   - Remote origin must be set
#
# Usage:
#   ./release.sh
#   ./release.sh --dry-run   # shows what would happen without doing it
#
set -e

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SRC_DIR/../.." && pwd)"
DRY_RUN=false

[[ "$1" == "--dry-run" ]] && DRY_RUN=true

# --- Checks ---
if ! command -v gh &>/dev/null; then
    echo "Error: GitHub CLI (gh) not found."
    echo "Install: https://cli.github.com/"
    exit 1
fi

if ! gh auth status &>/dev/null; then
    echo "Error: not authenticated with GitHub CLI. Run: gh auth login"
    exit 1
fi

cd "$REPO_ROOT"

if [[ -n "$(git status --porcelain)" ]]; then
    echo "Error: working tree has uncommitted changes."
    echo "Commit or stash them before releasing."
    git status --short
    exit 1
fi

# --- Plugin identity (derived from directory name) ---
PLUGIN_NAME="$(basename "$SRC_DIR")"
MANIFEST="$SRC_DIR/${PLUGIN_NAME}.xml"

# --- Version ---
VERSION=$(grep -oP '(?<=<version>)[^<]+' "$MANIFEST")
TAG="${PLUGIN_NAME}-v${VERSION}"
ZIP_NAME="plg_system_${PLUGIN_NAME}_v${VERSION}.zip"
ZIP_PATH="$SRC_DIR/$ZIP_NAME"

echo "Releasing plugin: $PLUGIN_NAME"
echo "Version : $VERSION"
echo "Tag     : $TAG"
echo "Package : $ZIP_NAME"
echo ""

if $DRY_RUN; then
    echo "[dry-run] Would build   : $ZIP_NAME"
    echo "[dry-run] Would tag     : $TAG"
    echo "[dry-run] Would release : $TAG with asset $ZIP_NAME"
    exit 0
fi

# Check if tag already exists
if git rev-parse "$TAG" &>/dev/null; then
    echo "Error: tag $TAG already exists. Bump <version> in nsprism.xml first."
    exit 1
fi

# --- Build ---
echo "Building package..."
"$SRC_DIR/build.sh"

if [[ ! -f "$ZIP_PATH" ]]; then
    echo "Error: expected zip not found: $ZIP_PATH"
    exit 1
fi

# --- Tag & push ---
echo "Creating tag $TAG..."
git tag "$TAG"
git push origin "$TAG"

# --- GitHub release ---
echo "Creating GitHub release $TAG..."
gh release create "$TAG" "$ZIP_PATH" \
    --title "plg_system_${PLUGIN_NAME} v${VERSION}" \
    --generate-notes

echo ""
echo "Released: $(gh release view "$TAG" --json url -q .url)"
