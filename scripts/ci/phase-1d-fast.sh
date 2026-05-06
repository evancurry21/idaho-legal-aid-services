#!/usr/bin/env bash
# Fast-fail loop for Phase 1d (Functional assistant API behavior suite).
#
# Defaults to the test that has been failing the strict pre-push gate:
#   testMultiTurnBoiseOfficeHoursThroughApi
#
# Override by passing --filter <regex> as the first two args, or by setting
# the ASSISTANT_FUNCTIONAL_FILTER env var. Any further arguments are passed
# through to phpunit verbatim (e.g. --debug, --testdox).
#
# Identical execution path to run-quality-gate.sh:243-246, so reproducing the
# failure here means reproducing it under the same conditions the strict
# pre-push gate uses (host PHP, sqlite, php -S router server).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
TEST_FILE="web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantMessageRuntimeBehaviorFunctionalTest.php"

FILTER_DEFAULT="testMultiTurnBoiseOfficeHoursThroughApi"
FILTER="${ASSISTANT_FUNCTIONAL_FILTER:-$FILTER_DEFAULT}"

if [[ "${1:-}" == "--filter" && -n "${2:-}" ]]; then
  FILTER="$2"
  shift 2
fi

cd "$REPO_ROOT"

echo "=== phase-1d-fast: ${TEST_FILE} ==="
echo "Filter: ${FILTER:-<none>}"
echo ""

if [[ -n "$FILTER" ]]; then
  bash scripts/ci/run-host-phpunit.sh "$TEST_FILE" --filter "$FILTER" "$@"
else
  bash scripts/ci/run-host-phpunit.sh "$TEST_FILE" "$@"
fi
