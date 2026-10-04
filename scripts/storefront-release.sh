#!/bin/bash
# ------------------------------------------------------------------
## Prints "<tag><TAB><zip url>" for the newest Storefront release whose
## "Requires Shopclass" the given core version meets. Used by .build.sh and by
## the Docker builds, so the zip and the images bundle the same theme.
##
## Usage: scripts/storefront-release.sh <core version>
## GH_TOKEN, when set, authenticates the GitHub API call (avoids its rate limit).
# ------------------------------------------------------------------
set -euo pipefail

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  echo "usage: $0 <core version>" >&2
  exit 2
fi

THEME_REPO="mindstellar/theme-storefront"
# Same rules as Compatibility::releaseVersion(): drop an edge build stamp, then a prerelease suffix.
CORE_RELEASE=$(echo "$VERSION" | sed -E 's/\.[0-9]{12}$//; s/[.-](dev|beta|rc|alpha)[0-9]*$//I')
AUTH=()
CURL=(curl -fsSL --connect-timeout 10 --max-time 30 --retry 2)
if [ -n "${GH_TOKEN:-}" ]; then AUTH=(-H "Authorization: Bearer $GH_TOKEN"); fi

if ! releases=$("${CURL[@]}" ${AUTH[@]+"${AUTH[@]}"} "https://api.github.com/repos/$THEME_REPO/releases?per_page=100"); then
  echo "could not list storefront releases from the GitHub API" >&2
  exit 1
fi

while read -r tag url; do
  if ! header=$("${CURL[@]}" "https://raw.githubusercontent.com/$THEME_REPO/$tag/index.php"); then
    echo "could not read storefront $tag/index.php" >&2
    exit 1
  fi
  requires=$(printf '%s\n' "$header" | sed -nE 's/^Requires Shopclass:[[:space:]]*v?([0-9.]+).*/\1/p')
  requires=${requires%%$'\n'*}
  if [ -z "$requires" ] || [ "$(printf '%s\n%s\n' "$requires" "$CORE_RELEASE" | sort -V | head -1)" = "$requires" ]; then
    echo "storefront $tag (requires ${requires:-any}, core $CORE_RELEASE)" >&2
    printf '%s\t%s\n' "$tag" "$url"
    exit 0
  fi
done < <(printf '%s' "$releases" \
  | jq -r '.[] | select((.draft or .prerelease) | not)
           | [.tag_name, (.assets[].browser_download_url | select(endswith(".zip")))] | select(length == 2) | @tsv' \
  | sort -t "$(printf '\t')" -k1,1 -V -r)

echo "no storefront release supports core $CORE_RELEASE" >&2
exit 1
