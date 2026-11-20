#!/bin/bash
# ------------------------------------------------------------------
## Prints "<tag><TAB><zip url>" for the newest release of a bundled theme
## (mindstellar/theme-<slug>) whose "Requires Shopclass" the given core version
## meets. A prerelease core (7.1.0.rc2) counts as its release (7.1.0).
## Used by .build.sh and by the Docker builds, so the zip and the images bundle the same themes.
##
## Usage: scripts/theme-release.sh <theme slug> <core version>
## GH_TOKEN, when set, authenticates the GitHub API call (avoids its rate limit).
# ------------------------------------------------------------------
set -euo pipefail

SLUG="${1:-}"
VERSION="${2:-}"
if ! [[ "$SLUG" =~ ^[a-z0-9-]+$ ]] || [ -z "$VERSION" ]; then
  echo "usage: $0 <theme slug> <core version>" >&2
  exit 2
fi

THEME_REPO="mindstellar/theme-$SLUG"
# Same rules as Compatibility::releaseVersion(): drop an edge build stamp, then a prerelease suffix.
CORE_RELEASE=$(echo "$VERSION" | sed -E 's/\.[0-9]{12}$//; s/[.-](dev|beta|rc|alpha)[0-9]*$//I')
AUTH=()
CURL=(curl -fsSL --connect-timeout 10 --max-time 30 --retry 2)
if [ -n "${GH_TOKEN:-}" ]; then AUTH=(-H "Authorization: Bearer $GH_TOKEN"); fi

if ! releases=$("${CURL[@]}" ${AUTH[@]+"${AUTH[@]}"} "https://api.github.com/repos/$THEME_REPO/releases?per_page=100"); then
  echo "could not list $SLUG releases from the GitHub API" >&2
  exit 1
fi

while read -r tag url; do
  if ! header=$("${CURL[@]}" "https://raw.githubusercontent.com/$THEME_REPO/$tag/index.php"); then
    echo "could not read $SLUG $tag/index.php" >&2
    exit 1
  fi
  requires=$(printf '%s\n' "$header" | sed -nE 's/^Requires Shopclass:[[:space:]]*v?([0-9.]+).*/\1/p')
  requires=${requires%%$'\n'*}
  if [ -z "$requires" ] || [ "$(printf '%s\n%s\n' "$requires" "$CORE_RELEASE" | sort -V | head -1)" = "$requires" ]; then
    echo "$SLUG $tag (requires ${requires:-any}, core $CORE_RELEASE)" >&2
    printf '%s\t%s\n' "$tag" "$url"
    exit 0
  fi
done < <(printf '%s' "$releases" \
  | jq -r '.[] | select((.draft or .prerelease) | not)
           | [.tag_name, (.assets[].browser_download_url | select(endswith(".zip")))] | select(length == 2) | @tsv' \
  | sort -t "$(printf '\t')" -k1,1 -V -r)

echo "no $SLUG release supports core $CORE_RELEASE" >&2
exit 1
