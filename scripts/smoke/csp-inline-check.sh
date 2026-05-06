#!/usr/bin/env bash
# CSP smoke check: fail if any custom-theme template emits an executable inline
# <script> or <style> block. Allows non-executable JSON scripts and external
# <script src=...> references. Catches regressions introduced after the H-2
# externalisation work landed.
#
# Run from repo root:
#   bash scripts/smoke/csp-inline-check.sh

set -u
cd "$(dirname "$0")/../.."

ROOTS=(
  "web/themes/custom/b5subtheme/templates"
)

# Match <script ...>NON-EMPTY-BODY (not just whitespace) and any <style ...>BODY.
# Then exclude allowed forms:
#   - type="application/json"  /  type='application/json'
#   - type="application/ld+json" / type='application/ld+json'
#   - <script ... src=...>     (external, no inline body)
PATTERN='<(script|style)([^>]*)>[^<[:space:]]'

violations=$(grep -RInE "$PATTERN" "${ROOTS[@]}" 2>/dev/null \
  | grep -vE 'type=("|'\'')application/(json|ld\+json)' \
  | grep -vE '<script[^>]*\bsrc=')

if [ -n "$violations" ]; then
  echo "CSP smoke check FAILED — inline <script>/<style> body found in custom theme templates:"
  echo "$violations"
  exit 1
fi

echo "CSP smoke check OK — no inline <script>/<style> body in custom theme templates."
