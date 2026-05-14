#!/usr/bin/env bash
# tests/ci/summary-schema.sh — PIPE-04 schema assertion harness.
#
# Asserts that the six required PIPE-04 fields appear in the publish summary
# file across three scenarios: success run, skipped gate, and bypass run.
#
# Phase: 03.1-publish-pipeline-audit-hardening — Plan 03.1-05
# PIPE-04: see 03.1-SPEC.md PIPE-04 acceptance criteria.
#
# Usage:
#   bash tests/ci/summary-schema.sh
#
# Exits 0 if all required fields present; 1 with [FAIL] details otherwise.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# shellcheck source=./_assert.sh
source "$SCRIPT_DIR/_assert.sh"

echo "=== PIPE-04 Summary Schema Assertion Harness ==="

# -----------------------------------------------------------------------
# Scenario 1: Success run — call record helpers for two synthetic phases
# -----------------------------------------------------------------------
echo ""
echo "--- Scenario 1: success run ---"
SUMMARY_FILE_1="$(mktemp)"
export ILAS_PUBLISH_GATES_SUMMARY_FILE="$SUMMARY_FILE_1"

# Source the lib to get record helpers (using externally-set SUMMARY_FILE_1).
# The lib re-exports ILAS_PUBLISH_GATES_SUMMARY_FILE; we restore it after sourcing.
_ILAS_PUBLISH_GATES_SUMMARY_FILE_SAVED="$ILAS_PUBLISH_GATES_SUMMARY_FILE"
# shellcheck source=../../scripts/ci/publish-gates.lib.sh
source "$REPO_ROOT/scripts/ci/publish-gates.lib.sh"
ILAS_PUBLISH_GATES_SUMMARY_FILE="$_ILAS_PUBLISH_GATES_SUMMARY_FILE_SAVED"

# Initialize summary file
{
  echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "entrypoint=summary-schema-test"
} > "$ILAS_PUBLISH_GATES_SUMMARY_FILE"

# Record two synthetic phases: phase=, exit_code=, timestamp_utc=
publish_gates_record_phase "composer_dry_run" "0"
publish_gates_record_phase "vc_pure" "0"

# PIPE-04 field: phase_duration_ms=
publish_gates_record_duration_ms "composer_dry_run" "812"
publish_gates_record_duration_ms "vc_pure" "5432"

# PIPE-04 field: phase_env=
publish_gates_record_env "module_quality" "ASSISTANT_FUNCTIONAL_MODE=host" "ASSISTANT_FUNCTIONAL_FILTER="

# PIPE-04 field: junit_
publish_gates_record_junit "vc_pure" "$REPO_ROOT/promptfoo-evals/output/junit/vc_pure.xml"

echo "Summary file:"
cat "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
echo ""

# Assert all 6 required PIPE-04 fields
assert_grep '^phase=' "$ILAS_PUBLISH_GATES_SUMMARY_FILE" "PIPE-04: phase= record present"
assert_grep 'exit_code=' "$ILAS_PUBLISH_GATES_SUMMARY_FILE" "PIPE-04: exit_code= field present"
assert_grep '^phase_duration_ms=' "$ILAS_PUBLISH_GATES_SUMMARY_FILE" "PIPE-04: phase_duration_ms= record present"
assert_grep '^phase_env=' "$ILAS_PUBLISH_GATES_SUMMARY_FILE" "PIPE-04: phase_env= record present"
assert_grep '^junit_' "$ILAS_PUBLISH_GATES_SUMMARY_FILE" "PIPE-04: junit_ record present"
assert_grep 'timestamp_utc=' "$ILAS_PUBLISH_GATES_SUMMARY_FILE" "PIPE-04: timestamp_utc= field present"

# Bonus: assert secret redaction
publish_gates_record_env "build" "FOO_API_KEY=secret123" "NORMAL_VAR=visible"
assert_grep 'FOO_API_KEY=<redacted>' "$ILAS_PUBLISH_GATES_SUMMARY_FILE" "PIPE-04: secret redaction (*_KEY) works"
assert_not_grep 'FOO_API_KEY=secret123' "$ILAS_PUBLISH_GATES_SUMMARY_FILE" "PIPE-04: raw secret not in summary"

rm -f "$SUMMARY_FILE_1"

# -----------------------------------------------------------------------
# Scenario 2: Skipped gate — publish_gates_record_skip
# -----------------------------------------------------------------------
echo ""
echo "--- Scenario 2: skipped gate ---"
SUMMARY_FILE_2="$(mktemp)"
ILAS_PUBLISH_GATES_SUMMARY_FILE="$SUMMARY_FILE_2"

{
  echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "entrypoint=summary-schema-test-skip"
} > "$ILAS_PUBLISH_GATES_SUMMARY_FILE"

publish_gates_record_skip "promptfoo_branch_aware" "ILAS_LIVE_PROVIDER_GATE=0"

echo "Summary file:"
cat "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
echo ""

assert_grep '^phase_skipped=' "$ILAS_PUBLISH_GATES_SUMMARY_FILE" "PIPE-04: phase_skipped= record present on skip"

rm -f "$SUMMARY_FILE_2"

# -----------------------------------------------------------------------
# Scenario 3: Bypass run — safe-push.sh _SAFE_PUSH_TEST=1
# -----------------------------------------------------------------------
echo ""
echo "--- Scenario 3: bypass run (safe-push.sh _SAFE_PUSH_TEST=1) ---"
SUMMARY_FILE_3="$(mktemp)"
# safe-push.sh sources the lib which overwrites the env var; use external override.
before_count=0
if [[ -f "$SUMMARY_FILE_3" ]] && grep -q '^bypass=' "$SUMMARY_FILE_3" 2>/dev/null; then
  before_count=$(grep -c '^bypass=' "$SUMMARY_FILE_3")
fi

ILAS_PUBLISH_GATES_SUMMARY_FILE="$SUMMARY_FILE_3" \
  _SAFE_PUSH_TEST=1 \
  bash "$REPO_ROOT/scripts/git/safe-push.sh" --no-verify origin master >/dev/null 2>&1

after_count=0
if [[ -f "$SUMMARY_FILE_3" ]] && grep -q '^bypass=' "$SUMMARY_FILE_3" 2>/dev/null; then
  after_count=$(grep -c '^bypass=' "$SUMMARY_FILE_3")
fi

echo "Summary file:"
cat "$SUMMARY_FILE_3"
echo ""

assert_grep '^bypass=' "$SUMMARY_FILE_3" "PIPE-04: bypass= record written by safe-push.sh"
assert_grep 'invoker=' "$SUMMARY_FILE_3" "PIPE-04: bypass record contains invoker="
assert_grep 'timestamp_utc=' "$SUMMARY_FILE_3" "PIPE-04: bypass record contains timestamp_utc="
assert_grep 'commit_sha=' "$SUMMARY_FILE_3" "PIPE-04: bypass record contains commit_sha="

increment=$(( after_count - before_count ))
if [[ "$increment" -ne 1 ]]; then
  printf '[FAIL] bypass count increment: expected=1 actual=%d\n' "$increment" >&2
  exit 1
fi
printf '[ok] bypass count incremented by exactly 1\n'

rm -f "$SUMMARY_FILE_3"

echo ""
echo "All PIPE-04 summary schema assertions passed."
