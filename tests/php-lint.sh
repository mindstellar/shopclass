#!/usr/bin/env bash
#
# This file is part of Shopclass (Mindstellar).
# Copyright (c) 2021-2026 Mindstellar Community
#
# Distributed under the GNU General Public License v3.0 or later. See LICENSE.
#
# SPDX-License-Identifier: GPL-3.0-or-later
#
## Parse-checks every PHP file that ships, on whatever PHP is on PATH.
##
## Two kinds of finding come out of `php -l`, and they deserve opposite
## treatment:
##
##   a parse error   the file cannot run on this PHP version. Fails the run.
##   a deprecation   the file runs, but the construct is on its way out. Worth
##                   knowing about early, worth nobody's build breaking over.
##
## `php -l` already exits 0 for a deprecation and non-zero only for a parse
## error, so the split is the tool's, not ours. What it does not do is separate
## them in its output: run it across a few thousand files and the handful of
## deprecations are lost in the noise, which is the same as not reporting them.
## This collects them, groups them by message, and separates ours from the
## dependencies' -- a deprecation in oc-includes/vendor is upstream's to fix and
## would only train people to ignore the report.
##
## Usage:  tests/php-lint.sh
##
## Env:
##   LINT_JOBS  parallel php processes  [number of cpus]
##
## Exits non-zero only if something failed to parse.

set -uo pipefail

# One file, invoked by xargs below. Kept in this script rather than inlined into
# the xargs command so the quoting stays legible.
if [ "${1:-}" = "--one" ]; then
  file="$2"
  out="$(php -l "$file" 2>&1)"
  rc=$?
  if [ "$rc" -ne 0 ]; then
    printf 'SYNTAX\t%s\t%s\n' "$file" "$(printf '%s' "$out" | tr '\n' ' ')"
  else
    printf '%s\n' "$out" | grep -F 'Deprecated:' | while IFS= read -r line; do
      printf 'DEPRECATED\t%s\t%s\n' "$file" "$line"
    done
  fi
  exit 0
fi

# Reduces a deprecation to the kind of thing it is, so that one construct
# deprecated across forty call sites reports as one line with a count of forty
# rather than forty lines nobody reads to the end of. Drops the "Deprecated:"
# prefix, the file and line, the Class::method() that raised it, and the
# specific parameter name -- what is left is the change that has to be made.
deprecation_kind() {
  sed -e 's/^Deprecated: //' \
      -e 's/ in .* on line [0-9]*$//' \
      -e 's/^[A-Za-z0-9_\\]*::[A-Za-z0-9_]*(): //' \
      -e 's/^[A-Za-z0-9_\\]*(): //' \
      -e 's/\$[A-Za-z0-9_]*/$ARG/g'
}

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 2

JOBS="${LINT_JOBS:-$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 4)}"
VERSION="$(php -r 'echo PHP_VERSION;')"

echo "PHP ${VERSION}, ${JOBS} parallel"

RESULTS="$(mktemp)"
trap 'rm -f "$RESULTS"' EXIT

# node_modules and release/ are build scratch that never ships; .git and
# .claude are not source. The two HTMLPurifier legacy autoloaders are excluded
# because they are deliberately unparseable stubs for old PHP.
find -L . -name '*.php' \
  ! -path './.git/*' \
  ! -path './node_modules/*' \
  ! -path './release/*' \
  ! -path './.claude/*' \
  ! -name 'HTMLPurifierExtras.autoload-legacy.php' \
  ! -name 'HTMLPurifier.autoload-legacy.php' \
  -print0 \
  | xargs -0 -r -P"$JOBS" -I{} "$0" --one {} > "$RESULTS"

TOTAL="$(find -L . -name '*.php' ! -path './.git/*' ! -path './node_modules/*' ! -path './release/*' ! -path './.claude/*' | wc -l | tr -d ' ')"

SYNTAX_COUNT="$(grep -c '^SYNTAX' "$RESULTS" || true)"
OURS="$(grep '^DEPRECATED' "$RESULTS" | grep -v '/vendor/' || true)"
THEIRS_COUNT="$(grep '^DEPRECATED' "$RESULTS" | grep -c '/vendor/' || true)"
OURS_COUNT="$(printf '%s' "$OURS" | grep -c . || true)"

echo "checked ${TOTAL} files"

# ---------------------------------------------------------------------------
if [ "$SYNTAX_COUNT" -gt 0 ]; then
  echo
  echo "Parse errors (${SYNTAX_COUNT}):"
  grep '^SYNTAX' "$RESULTS" | cut -f2,3 | sed 's/^/  /'
fi

if [ "$OURS_COUNT" -gt 0 ]; then
  echo
  echo "Deprecations in our code (${OURS_COUNT}) — not failing the run:"
  # Group by message: one construct deprecated across forty files is one thing
  # to fix, and listing it forty times buries everything else.
  printf '%s\n' "$OURS" | cut -f3 | deprecation_kind | sort | uniq -c | sort -rn | sed 's/^/  /'
  echo
  echo "  first few, with locations:"
  printf '%s\n' "$OURS" | cut -f2,3 | head -10 | sed 's/^/    /'
fi

if [ "$THEIRS_COUNT" -gt 0 ]; then
  echo
  echo "Deprecations in dependencies (${THEIRS_COUNT}) — upstream's to fix, listed as a count only."
fi

# ---------------------------------------------------------------------------
# A step summary so the result is readable without opening the log.
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo "### PHP ${VERSION}"
    echo
    echo "| | |"
    echo "|---|---|"
    echo "| Files checked | ${TOTAL} |"
    echo "| Parse errors | ${SYNTAX_COUNT} |"
    echo "| Deprecations (ours) | ${OURS_COUNT} |"
    echo "| Deprecations (dependencies) | ${THEIRS_COUNT} |"
    if [ "$OURS_COUNT" -gt 0 ]; then
      echo
      echo "<details><summary>Deprecations in our code</summary>"
      echo
      echo '```'
      printf '%s\n' "$OURS" | cut -f3 | sed 's/^Deprecated: //; s/ in \/.* on line [0-9]*$//' \
        | sort | uniq -c | sort -rn
      echo '```'
      echo
      echo "</details>"
    fi
  } >> "$GITHUB_STEP_SUMMARY"
fi

if [ "$SYNTAX_COUNT" -gt 0 ]; then
  echo
  echo "FAIL: ${SYNTAX_COUNT} file(s) do not parse on PHP ${VERSION}."
  exit 1
fi

echo
echo "OK: everything parses on PHP ${VERSION}."
