#!/bin/bash
# ------------------------------------------------------------------
## Author = Navjot Tomer
##
## Osclass release packager.
##
## Packages the release zips for the version in OSCLASS_VERSION. Assets are built
## in CI (`npm ci && npm run build`) BEFORE this runs; this script does not build.
## It layers the freshly built runtime output onto a clean `git archive` base
## (so .gitattributes export-ignore stays the single source of exclusions) and
## bundles the storefront theme.
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

if [ ! -d oc-includes/assets ]; then
  echo "expected built assets dir missing: oc-includes/assets (did 'npm run build' run?)" >&2
  exit 1
fi
rm -rf "$DIR/osclass/oc-includes/assets"
mkdir -p "$DIR/osclass/oc-includes/assets"
cp -R oc-includes/assets/. "$DIR/osclass/oc-includes/assets/"

# Bundle the newest storefront release whose "Requires Shopclass" this core meets,
# so a patch build of an older line never ships a theme it cannot run.
THEME_REPO="mindstellar/theme-storefront"
# Same suffix rule as Compatibility::releaseVersion(); keep the two in step.
CORE_RELEASE=$(echo "$VERSION" | sed -E 's/[.-](dev|beta|rc|alpha)[0-9]*$//I')
AUTH=()
if [ -n "${GH_TOKEN:-}" ]; then AUTH=(-H "Authorization: Bearer $GH_TOKEN"); fi
THEME_URL=""
while read -r tag url; do
  header=$(curl -fsSL "https://raw.githubusercontent.com/$THEME_REPO/$tag/index.php") || continue
  requires=$(printf '%s\n' "$header" | sed -nE 's/^Requires Shopclass:[[:space:]]*v?([0-9.]+).*/\1/p')
  requires=${requires%%$'\n'*}
  if [ -z "$requires" ] || [ "$(printf '%s\n%s\n' "$requires" "$CORE_RELEASE" | sort -V | head -1)" = "$requires" ]; then
    echo "Bundling storefront $tag (requires ${requires:-any}, core $CORE_RELEASE)"
    THEME_URL="$url"
    break
  fi
done < <(curl -fsSL ${AUTH[@]+"${AUTH[@]}"} "https://api.github.com/repos/$THEME_REPO/releases?per_page=100" \
  | jq -r '.[] | select((.draft or .prerelease) | not)
           | [.tag_name, (.assets[].browser_download_url | select(endswith(".zip")))] | select(length == 2) | @tsv' \
  | sort -t "$(printf '\t')" -k1,1 -V -r)
if [ -z "$THEME_URL" ]; then
  echo "no storefront release supports core $CORE_RELEASE" >&2
  exit 1
fi
curl -fsSL -o "$DIR/storefront.zip" "$THEME_URL"
unzip -qq "$DIR/storefront.zip" -d "$DIR/osclass/oc-content/themes/"
rm -f "$DIR/storefront.zip"

# Package twice from the same tree. shopclass_v*.zip is the download; osclass_v*.zip,
# wrapped in osclass/, is what updaters older than 6.4.0 look for and can unpack.
( cd "$DIR" && zip -qr "osclass_v${VERSION}.zip" osclass )
mv "$DIR/osclass" "$DIR/shopclass"
( cd "$DIR" && zip -qr "shopclass_v${VERSION}.zip" shopclass )
echo "Build created successfully in $DIR/shopclass_v${VERSION}.zip and $DIR/osclass_v${VERSION}.zip"
