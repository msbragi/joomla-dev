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
#   ./release.sh                # full release: build, tag, push, GitHub release
#   ./release.sh --dry-run      # build + sha256 locally, skip all git/gh ops
#
set -e

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SRC_DIR/../.." && pwd)"
DRY_RUN=false

[[ "$1" == "--dry-run" ]] && DRY_RUN=true

# --- Checks (skipped in dry-run) ---
if ! $DRY_RUN; then
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
fi

# --- Plugin identity (derived from directory name) ---
PLUGIN_NAME="$(basename "$SRC_DIR")"
MANIFEST="$SRC_DIR/${PLUGIN_NAME}.xml"

# --- Version ---
VERSION=$(grep -oP '(?<=<version>)[^<]+' "$MANIFEST")
TAG="${PLUGIN_NAME}-v${VERSION}"
ZIP_NAME="plg_system_${PLUGIN_NAME}_v${VERSION}.zip"
ZIP_PATH="$SRC_DIR/$ZIP_NAME"
SHA256_NAME="${ZIP_NAME}.sha256"
SHA256_PATH="$SRC_DIR/$SHA256_NAME"

echo "Releasing plugin: $PLUGIN_NAME"
echo "Version  : $VERSION"
echo "Tag      : $TAG"
echo "Package  : $ZIP_NAME"
echo "Checksum : $SHA256_NAME"
echo ""

if $DRY_RUN; then
    echo "[dry-run] Skipping git/gh operations — building locally only."
    echo ""
fi

# Check if tag already exists (only in full release)
if ! $DRY_RUN; then
    if git rev-parse "$TAG" &>/dev/null; then
        echo "Error: tag $TAG already exists. Bump <version> in nsprism.xml first."
        exit 1
    fi
fi

# --- Build ---
echo "Building package..."
"$SRC_DIR/build.sh"

if [[ ! -f "$ZIP_PATH" ]]; then
    echo "Error: expected zip not found: $ZIP_PATH"
    exit 1
fi

# --- Generate SHA-256 companion file ---
echo "Computing SHA-256 checksum..."
if command -v sha256sum &>/dev/null; then
    sha256sum "$ZIP_PATH" | awk '{print $1}' > "$SHA256_PATH"
else
    # macOS fallback
    shasum -a 256 "$ZIP_PATH" | awk '{print $1}' > "$SHA256_PATH"
fi
echo "  $(cat "$SHA256_PATH")  $ZIP_NAME"

# --- Tag & push ---
if $DRY_RUN; then
    echo ""
    echo "[dry-run] Done. ZIP and checksum file ready for inspection:"
    echo "  $ZIP_PATH"
    echo "  $SHA256_PATH"
    echo "[dry-run] Skipped: git tag, git push, gh release create"
    exit 0
fi

echo "Creating tag $TAG..."
git tag "$TAG"
git push origin "$TAG"

# --- GitHub release ---
echo "Creating GitHub release $TAG..."
gh release create "$TAG" "$ZIP_PATH" "$SHA256_PATH" \
    --title "plg_system_${PLUGIN_NAME} v${VERSION}" \
    --generate-notes

echo ""
echo "Released: $(gh release view "$TAG" --json url -q .url)"

# --- Cleanup local artifacts ---
rm -f "$ZIP_PATH" "$SHA256_PATH"
echo "Removed local artifacts: $ZIP_NAME, $SHA256_NAME"
