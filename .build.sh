#!/bin/bash
# ------------------------------------------------------------------
## Author = Navjot Tomer
##
## Shopclass release packager.
##
## Packages the release zips for the version in OSCLASS_VERSION. Assets and PHP
## dependencies are built in CI (`npm ci && npm run build`, `composer install --no-dev`)
## BEFORE this runs; this script does not build.
## It layers the freshly built runtime output onto a clean `git archive` base
## (so .gitattributes export-ignore applies to the sources) and
## bundles the themes listed in scripts/bundled-themes.
##
## Environment:
##   OSCLASS_VERSION  version label + zip names (e.g. 5.3.0 or 5.3.0.dev)  [required]
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

echo "Shopclass build started for v$VERSION (archiving ref: $REF)"

DIR="release"
rm -rf "$DIR"
mkdir -p "$DIR/shopclass"

# Base tree: committed files with .gitattributes export-ignore applied.
git archive "$REF" | tar -x -C "$DIR/shopclass"

# Overlay freshly built runtime output (may be gitignored, so the archive base
# won't necessarily carry it — this guarantees the zip ships fresh assets).
copy_built_file() {
  src="$1"
  if [ ! -f "$src" ]; then
    echo "expected built artifact missing: $src (did 'npm run build' run?)" >&2
    exit 1
  fi
  mkdir -p "$DIR/shopclass/$(dirname "$src")"
  cp "$src" "$DIR/shopclass/$src"
}
copy_built_file oc-admin/themes/modern/css/main.css
copy_built_file oc-admin/themes/modern/js/location.min.js

if [ ! -d oc-includes/assets ]; then
  echo "expected built assets dir missing: oc-includes/assets (did 'npm run build' run?)" >&2
  exit 1
fi
rm -rf "$DIR/shopclass/oc-includes/assets"
mkdir -p "$DIR/shopclass/oc-includes/assets"
cp -R oc-includes/assets/. "$DIR/shopclass/oc-includes/assets/"

if [ ! -f oc-includes/vendor/autoload.php ]; then
  echo "expected composer vendor tree missing: oc-includes/vendor (did 'composer install --no-dev' run?)" >&2
  exit 1
fi
if grep -q "'dev' => true" oc-includes/vendor/composer/installed.php; then
  echo "oc-includes/vendor is a dev install; run 'composer install --no-dev' first" >&2
  exit 1
fi
rm -rf "$DIR/shopclass/oc-includes/vendor"
mkdir -p "$DIR/shopclass/oc-includes/vendor"
cp -R oc-includes/vendor/. "$DIR/shopclass/oc-includes/vendor/"
# Drop dev-only and test files from the vendor tree.
rm -rf "$DIR/shopclass/oc-includes/vendor/bin" \
  "$DIR/shopclass/oc-includes/vendor/async-aws/core/src/Test" \
  "$DIR/shopclass/oc-includes/vendor/symfony/http-client-contracts/Test" \
  "$DIR/shopclass/oc-includes/vendor/symfony/service-contracts/Test"

# Bundle the newest release of each theme this core supports (the Docker builds use the same picker).
for THEME in $(cat scripts/bundled-themes); do
  THEME_URL=$(bash scripts/theme-release.sh "$THEME" "$VERSION" | cut -f2)
  curl -fsSL -o "$DIR/$THEME.zip" "$THEME_URL"
  rm -rf "$DIR/shopclass/oc-content/themes/$THEME"
  unzip -qq "$DIR/$THEME.zip" -d "$DIR/shopclass/oc-content/themes/"
  rm -f "$DIR/$THEME.zip"
done

# 7.1 and later ship only shopclass_v*.zip; sites update to 7.1 from 7.0.12, whose updater takes it.
( cd "$DIR" && zip -qr "shopclass_v${VERSION}.zip" shopclass )
echo "Build created successfully in $DIR/shopclass_v${VERSION}.zip"
