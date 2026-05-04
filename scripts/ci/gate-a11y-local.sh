#!/usr/bin/env bash
# Local mirror of GitHub's a11y-local-gate job.
#
# Builds Drupal from the checked-out commit inside DDEV (composer + theme +
# drush si --existing-config + seed) then runs the impact-card and axe spec
# files via Playwright. This is what the a11y-local-gate job in
# .github/workflows/quality-gate.yml runs on every PR.
#
# Slow (~10 min on a cold DDEV, ~2-3 min on a warm one). Not part of the
# default pre-push path — invoke explicitly before any push that touches
# theme/templates/JS/SCSS or any rendered markup.
#
# Requirements:
#   - DDEV must be installed and reachable on PATH
#   - Project DDEV environment available (this script will start it if needed)
#   - npm dependencies installed (npm ci)
#   - Playwright Chromium installed (npx playwright install chromium)
#
# Related entry points:
#   npm run gate:publish-local   — deploy-bound test gates (PHPUnit, etc.)
#   npm run gate:github-local    — fast static analysis (phpcs + phpstan + widget)
#   npm run gate:a11y-local      — this script

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

echo "=== gate:a11y-local: mirrors GitHub a11y-local-gate job ==="
echo "[note] Builds Drupal in DDEV and runs Playwright a11y suite."
echo "[note] Slow (~10 min cold). Run before pushing markup/CSS/JS/theme changes."
echo ""

if ! command -v ddev >/dev/null 2>&1; then
  echo "[fail] ddev not found on PATH. Install DDEV or add it to PATH." >&2
  echo "       https://ddev.readthedocs.io/en/stable/users/install/" >&2
  exit 127
fi

echo "--- Step 1/2: setup-a11y-local.sh (composer install + theme build + drush si if needed) ---"
bash "$REPO_ROOT/scripts/ci/setup-a11y-local.sh"
echo ""

echo "--- Step 2/2: Playwright a11y suite (impact-card + axe specs) ---"
A11Y_ROUTE_HOME="${A11Y_ROUTE_HOME:-/}" \
A11Y_ROUTE_IMPACT_CARDS="${A11Y_ROUTE_IMPACT_CARDS:-/}" \
A11Y_ROUTE_RESOURCES="${A11Y_ROUTE_RESOURCES:-}" \
A11Y_ROUTE_ASSISTANT="${A11Y_ROUTE_ASSISTANT:-}" \
A11Y_ROUTE_STANDARD="${A11Y_ROUTE_STANDARD:-}" \
PLAYWRIGHT_BASE_URL="${PLAYWRIGHT_BASE_URL:-}" \
npm run test:a11y -- tests/a11y/axe.spec.js tests/a11y/impact-card.spec.js
echo ""

echo "=== gate:a11y-local: PASS ==="
