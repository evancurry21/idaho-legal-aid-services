#!/usr/bin/env bash
# Direct entry point for Phase 1d — the Functional assistant API behavior suite
# (AssistantMessageRuntimeBehaviorFunctionalTest). Runs ONLY this suite, not the
# rest of the module quality gate, so the slow gate that most often fails the
# strict pre-push check can be iterated on by itself.
#
# Mirrors the host execution path used at run-quality-gate.sh:243-246
# (host PHP, sqlite, php -S router server).
#
# Usage:
#   bash scripts/ci/run-assistant-functional.sh                    # full suite
#   bash scripts/ci/run-assistant-functional.sh --filter <regex>   # one test or pattern
#
# Any args after the recognised flags are forwarded to phpunit verbatim,
# matching the convention used by scripts/ci/phase-1d-fast.sh.
#
# Related entry points:
#   npm run gate:assistant-functional                 — this script, no filter
#   npm run gate:assistant-functional:filter -- <re>  — this script, with filter
#   npm run test:assistant:phase1d                    — phase-1d-fast.sh (single
#                                                        pinned test, fastest loop)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
TEST_FILE="web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantMessageRuntimeBehaviorFunctionalTest.php"

cd "$REPO_ROOT"

FILTER=""
if [[ "${1:-}" == "--filter" && -n "${2:-}" ]]; then
  FILTER="$2"
  shift 2
fi

echo "=== gate:assistant-functional ==="
echo "Suite:  ${TEST_FILE}"
echo "Filter: ${FILTER:-<none>}"
echo ""

EXIT_CODE=0
if [[ -n "$FILTER" ]]; then
  bash scripts/ci/run-host-phpunit.sh "$TEST_FILE" --filter "$FILTER" "$@" || EXIT_CODE=$?
else
  bash scripts/ci/run-host-phpunit.sh "$TEST_FILE" "$@" || EXIT_CODE=$?
fi

if [[ "$EXIT_CODE" -ne 0 ]]; then
  REPRODUCE='npm run gate:assistant-functional'
  if [[ -n "$FILTER" ]]; then
    REPRODUCE="npm run gate:assistant-functional:filter -- ${FILTER}"
  fi
  {
    echo ""
    echo "=================================================================="
    echo "FAIL: Functional assistant API behavior suite"
    echo "=================================================================="
    echo "  Failed suite:    ${TEST_FILE}"
    echo "  Failed test:     (see PHPUnit output above)"
    echo "  Reproduce:       ${REPRODUCE}"
    echo "  Narrow further:  npm run gate:assistant-functional:filter -- <TestNameRegex>"
    echo "=================================================================="
  } >&2
  exit "$EXIT_CODE"
fi

echo ""
echo "=== gate:assistant-functional: PASS ==="
