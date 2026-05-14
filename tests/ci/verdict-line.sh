#!/usr/bin/env bash
# tests/ci/verdict-line.sh — PIPE-03 verdict line assertion harness.
#
# Asserts that publish-failure-summary.sh emits a top-of-stderr verdict line
# matching the PUBLISH-VERDICT <kind> contract across 4 scenarios:
#   A — PASS: all gates passed, no drift, no bypass
#   B — FAIL: a gate exited nonzero
#   C — WARN: drift record present
#   D — BYPASS: bypass= record in summary file
#
# Phase: 03.1-publish-pipeline-audit-hardening — Plan 03.1-05
# PIPE-03: see 03.1-SPEC.md PIPE-03 acceptance criteria.
#
# Usage:
#   bash tests/ci/verdict-line.sh
#
# Exits 0 if all 4 verdict kinds produce correct top-of-stderr first line.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# shellcheck source=./_assert.sh
source "$SCRIPT_DIR/_assert.sh"

SUMMARY_SCRIPT="$REPO_ROOT/scripts/ci/publish-failure-summary.sh"
REAL_SUMMARY_FILE="$REPO_ROOT/promptfoo-evals/output/phpunit-summary.txt"
JUNIT_DIR="$REPO_ROOT/promptfoo-evals/output/junit"
STDERR_TMP="$(mktemp)"

# Save and restore real summary file around the test run.
_SAVED_SUMMARY=""
_SAVED_SUMMARY_EXISTS=false
if [[ -f "$REAL_SUMMARY_FILE" ]]; then
  _SAVED_SUMMARY="$(cat "$REAL_SUMMARY_FILE")"
  _SAVED_SUMMARY_EXISTS=true
fi

cleanup() {
  if [[ "$_SAVED_SUMMARY_EXISTS" == "true" ]]; then
    echo "$_SAVED_SUMMARY" > "$REAL_SUMMARY_FILE"
  else
    rm -f "$REAL_SUMMARY_FILE"
  fi
  rm -f "$STDERR_TMP"
}
trap cleanup EXIT

mkdir -p "$REPO_ROOT/promptfoo-evals/output" "$JUNIT_DIR"

echo "=== PIPE-03 Verdict Line Assertion Harness ==="

# -----------------------------------------------------------------------
# Scenario A — PASS: clean summary, upstream exit 0
# -----------------------------------------------------------------------
echo ""
echo "--- Scenario A: PASS ---"
{
  echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "entrypoint=verdict-test-a"
  echo "phase=composer_dry_run exit_code=0 timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "phase=vc_pure exit_code=0 timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} > "$REAL_SUMMARY_FILE"

bash "$SUMMARY_SCRIPT" 0 2>"$STDERR_TMP" || true
first_line="$(head -n 1 "$STDERR_TMP")"
echo "Verdict line: $first_line"

if [[ "$first_line" =~ ^PUBLISH-VERDICT\ PASS ]]; then
  printf '[ok] Scenario A: PASS verdict at top of stderr\n'
else
  printf '[FAIL] Scenario A: expected PUBLISH-VERDICT PASS, got: %s\n' "$first_line" >&2
  exit 1
fi

# Assert no internal spaces in any token after "PUBLISH-VERDICT PASS"
# (machine-grep-able: gate=, reason=, next= tokens must use dashes not spaces)
# PASS format is: PUBLISH-VERDICT PASS gate=all reason=ok next=none
tokens_part="${first_line#PUBLISH-VERDICT PASS }"
if [[ "$tokens_part" =~ [[:space:]][[:space:]] ]]; then
  printf '[FAIL] Scenario A: verdict line contains consecutive spaces in tokens: %s\n' "$tokens_part" >&2
  exit 1
fi
printf '[ok] Scenario A: verdict line starts with PUBLISH-VERDICT \n'

# -----------------------------------------------------------------------
# Scenario B — FAIL: gate exited nonzero (upstream exit 1)
# -----------------------------------------------------------------------
echo ""
echo "--- Scenario B: FAIL ---"
{
  echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "entrypoint=verdict-test-b"
  echo "phase=composer_dry_run exit_code=0 timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "phase=vc_pure exit_code=1 timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} > "$REAL_SUMMARY_FILE"

bash "$SUMMARY_SCRIPT" 1 2>"$STDERR_TMP" || true
first_line="$(head -n 1 "$STDERR_TMP")"
echo "Verdict line: $first_line"

if [[ "$first_line" =~ ^PUBLISH-VERDICT\ FAIL\ gate= ]]; then
  printf '[ok] Scenario B: FAIL verdict with gate= at top of stderr\n'
else
  printf '[FAIL] Scenario B: expected PUBLISH-VERDICT FAIL gate=..., got: %s\n' "$first_line" >&2
  exit 1
fi
if [[ "$first_line" =~ reason= ]]; then
  printf '[ok] Scenario B: FAIL verdict contains reason=\n'
else
  printf '[FAIL] Scenario B: FAIL verdict missing reason=: %s\n' "$first_line" >&2
  exit 1
fi
if [[ "$first_line" =~ next= ]]; then
  printf '[ok] Scenario B: FAIL verdict contains next=\n'
else
  printf '[FAIL] Scenario B: FAIL verdict missing next=: %s\n' "$first_line" >&2
  exit 1
fi

# Verify no internal spaces in reason= or next= values (dashes replace spaces).
reason_val="${first_line#*reason=}"
reason_val="${reason_val%% *}"  # up to first space after reason=
if [[ "$reason_val" =~ [[:space:]] ]]; then
  printf '[FAIL] Scenario B: reason= value contains spaces (expected dashes): %s\n' "$reason_val" >&2
  exit 1
fi
printf '[ok] Scenario B: reason= value uses dashes not spaces\n'

# -----------------------------------------------------------------------
# Scenario C — WARN: drift record present, upstream exit 0
# -----------------------------------------------------------------------
echo ""
echo "--- Scenario C: WARN (drift) ---"
{
  echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "entrypoint=verdict-test-c"
  echo "phase=composer_dry_run exit_code=0 timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "drift=github/master behind local by 2 commit(s); follow up with: git push github master"
} > "$REAL_SUMMARY_FILE"

bash "$SUMMARY_SCRIPT" 0 2>"$STDERR_TMP" || true
first_line="$(head -n 1 "$STDERR_TMP")"
echo "Verdict line: $first_line"

if [[ "$first_line" =~ ^PUBLISH-VERDICT\ WARN ]]; then
  printf '[ok] Scenario C: WARN verdict at top of stderr\n'
else
  printf '[FAIL] Scenario C: expected PUBLISH-VERDICT WARN, got: %s\n' "$first_line" >&2
  exit 1
fi
printf '[ok] Scenario C: verdict line starts with PUBLISH-VERDICT \n'

# -----------------------------------------------------------------------
# Scenario D — BYPASS: bypass= record in summary, upstream exit 0
# -----------------------------------------------------------------------
echo ""
echo "--- Scenario D: BYPASS ---"
{
  echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "entrypoint=verdict-test-d"
  echo "bypass=--no-verify invoker=evancurry timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ) commit_sha=abc123 remote=origin ref=master"
} > "$REAL_SUMMARY_FILE"

bash "$SUMMARY_SCRIPT" 0 2>"$STDERR_TMP" || true
first_line="$(head -n 1 "$STDERR_TMP")"
echo "Verdict line: $first_line"

if [[ "$first_line" =~ ^PUBLISH-VERDICT\ BYPASS ]]; then
  printf '[ok] Scenario D: BYPASS verdict at top of stderr\n'
else
  printf '[FAIL] Scenario D: expected PUBLISH-VERDICT BYPASS, got: %s\n' "$first_line" >&2
  exit 1
fi
printf '[ok] Scenario D: verdict line starts with PUBLISH-VERDICT \n'

echo ""
echo "All PIPE-03 verdict-line assertions passed."
