#!/bin/bash
# ------------------------------------------------------------------
## Author = Navjot Tomer
##
## Osclass release packager.
##
## Packages the release zip for the version in OSCLASS_VERSION. Assets are built
## in CI (`npm ci && npm run build`) BEFORE this runs; this script does not build.
## It layers the freshly built runtime output onto a clean `git archive` base
## (so .gitattributes export-ignore stays the single source of exclusions) and
## bundles the bender theme.
##
## Environment:
##   OSCLASS_VERSION  version label + zip name (e.g. 5.3.0 or 5.3.0.dev)  [required]
##   OSCLASS_REF      git ref to archive (defaults to OSCLASS_VERSION);
##                    set to HEAD for a local dry-run before the tag exists.
# ------------------------------------------------------------------
set -euo pipefail

VERSION="${OSCLASS_VERSION:-}"
if [ -z "$VERSION" ]; then
  echo "OSCLASS_VERSION is not set" >&2
  exit 1
fi
REF="${OSCLASS_REF:-$VERSION}"

echo "Osclass build started for v$VERSION (archiving ref: $REF)"

DIR="release"
rm -rf "$DIR"
mkdir -p "$DIR/osclass"

# Base tree: committed files with .gitattributes export-ignore applied.
git archive "$REF" | tar -x -C "$DIR/osclass"

# Overlay freshly built runtime output (may be gitignored, so the archive base
# won't necessarily carry it — this guarantees the zip ships fresh assets).
copy_built_file() {
  src="$1"
  if [ ! -f "$src" ]; then
    echo "expected built artifact missing: $src (did 'npm run build' run?)" >&2
    exit 1
  fi
  mkdir -p "$DIR/osclass/$(dirname "$src")"
  cp "$src" "$DIR/osclass/$src"
}
copy_built_file oc-admin/themes/modern/css/main.css
copy_built_file oc-admin/themes/modern/js/location.min.js
copy_built_file oc-admin/themes/modern/js/location.min.js.map

if [ ! -d oc-includes/assets ]; then
  echo "expected built assets dir missing: oc-includes/assets (did 'npm run build' run?)" >&2
  exit 1
fi
rm -rf "$DIR/osclass/oc-includes/assets"
mkdir -p "$DIR/osclass/oc-includes/assets"
cp -R oc-includes/assets/. "$DIR/osclass/oc-includes/assets/"

# Bundle the latest bender theme from its own repository.
echo "Downloading latest bender theme"
THEME_URL=$(curl -fsSL https://api.github.com/repos/mindstellar/theme-bender/releases/latest \
  | grep 'browser_download_url' \
  | grep -o 'https://[^"]*\.zip' \
  | head -1)
if [ -z "$THEME_URL" ]; then
  echo "could not resolve bender theme download url" >&2
  exit 1
fi
curl -fsSL -o "$DIR/bender.zip" "$THEME_URL"
unzip -qq "$DIR/bender.zip" -d "$DIR/osclass/oc-content/themes/"
rm -f "$DIR/bender.zip"

# Package.
( cd "$DIR" && zip -qr "osclass_v${VERSION}.zip" osclass )
echo "Build created successfully in $DIR/osclass_v${VERSION}.zip"
