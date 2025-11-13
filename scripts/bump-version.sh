#!/usr/bin/env bash
#
# Bump plugin version across all locations
# Usage: ./bump-version.sh X.Y.Z
#

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN_FILE="$ROOT/at-woo-gf-integration.php"
INFO_FILE="$ROOT/plugin-info.json"
CHANGELOG="$ROOT/CHANGELOG.md"

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 X.Y.Z"
  echo "Example: $0 2.6.1"
  exit 1
fi

NEW_VER="$1"

# Validate semantic version format
if [[ ! "$NEW_VER" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "❌ Version must be semantic (e.g., 2.6.1)"
  exit 1
fi

# Check working tree is clean
if [[ -n "$(git status --porcelain)" ]]; then
  echo "❌ Working tree not clean. Commit or stash changes first."
  git status --short
  exit 1
fi

echo "🔄 Bumping version to $NEW_VER..."

# Update main plugin file (3 locations)
echo "  → Updating Version header in $MAIN_FILE"
sed -i '' -E "s/^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+/\1${NEW_VER}/" "$MAIN_FILE"

echo "  → Updating @version in $MAIN_FILE"
sed -i '' -E "s/^([[:space:]]*\*[[:space:]]*@version[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+/\1${NEW_VER}/" "$MAIN_FILE"

echo "  → Updating AT_WOO_GF_INTEGRATION_VERSION constant in $MAIN_FILE"
sed -i '' -E "s/define\([[:space:]]*'AT_WOO_GF_INTEGRATION_VERSION'[[:space:]]*,[[:space:]]*'[0-9]+\.[0-9]+\.[0-9]+'[[:space:]]*\)/define( 'AT_WOO_GF_INTEGRATION_VERSION', '${NEW_VER}' )/" "$MAIN_FILE"

# Update plugin-info.json
echo "  → Updating plugin-info.json"
TS="$(date -u '+%Y-%m-%d %H:%M:%S')"
sed -i '' -E "s/\"version\":[[:space:]]*\"[0-9]+\.[0-9]+\.[0-9]+\"/\"version\": \"${NEW_VER}\"/" "$INFO_FILE"
sed -i '' -E "s#\"download_url\":[[:space:]]*\"[^\"]+\"#\"download_url\": \"https://github.com/amit-trabelsi-digital/at-woo-gf-integration/releases/download/v${NEW_VER}/at-woo-gf-integration-${NEW_VER}.zip\"#" "$INFO_FILE"
sed -i '' -E "s/\"last_updated\":[[:space:]]*\"[^\"]+\"/\"last_updated\": \"${TS}\"/" "$INFO_FILE"

# Update CHANGELOG.md
echo "  → Adding entry to CHANGELOG.md"
tmp="${CHANGELOG}.tmp"
echo "## [${NEW_VER}] - $(date -u '+%Y-%m-%d')" > "$tmp"
echo "" >> "$tmp"
echo "### Added" >> "$tmp"
echo "- " >> "$tmp"
echo "" >> "$tmp"
echo "### Changed" >> "$tmp"
echo "- " >> "$tmp"
echo "" >> "$tmp"
echo "### Fixed" >> "$tmp"
echo "- " >> "$tmp"
echo "" >> "$tmp"
cat "$CHANGELOG" >> "$tmp" || true
mv "$tmp" "$CHANGELOG"

# Git operations
echo "  → Committing changes"
git add "$MAIN_FILE" "$INFO_FILE" "$CHANGELOG"
git commit -m "chore: bump at-woo-gf-integration to v${NEW_VER}"

echo "  → Creating tag v${NEW_VER}"
git tag "v${NEW_VER}"

echo "  → Pushing to remote"
git push origin dev
git push origin "v${NEW_VER}"

echo ""
echo "✅ Version bumped to $NEW_VER"
echo ""
echo "Next steps:"
echo "1. Go to GitHub: https://github.com/amit-trabelsi-digital/at-woo-gf-integration/releases"
echo "2. Create new release from tag v${NEW_VER}"
echo "3. Attach plugin ZIP file (without .git, node_modules, etc.)"
echo "4. Update main repository submodule"
