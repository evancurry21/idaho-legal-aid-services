#!/usr/bin/env bash
# tests/ci/gates-parity.sh — PIPE-05 enforcement test (hand-rolled bash harness).
#
# Purpose: assert that scripts/ci/verify-gates-parity.sh correctly detects
# parity between gates.lock.json and its three consumers. Three test cases:
#   1. Clean state (real repo, no drift) → exit 0.
#   2. Extra gate_* call in publish-gate-local copy → exit 1, names the gate.
#   3. Missing required gate in pre-push copy → exit 1, names gate+consumer.
#
# Hand-rolled fallback because bats is not installed on this host (see
# .planning/phases/03.1-publish-pipeline-audit-hardening/03.1-VALIDATION.md
# §"Tooling Audit").
#
# PIPE-05 enforcement test.
# Phase: 03.1-publish-pipeline-audit-hardening — Plan 03.1-06 (Wave 5)

# shellcheck disable=SC2317  # test helper functions are called indirectly via run_test()
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# shellcheck source=./_assert.sh
source "$REPO_ROOT/tests/ci/_assert.sh"

VERIFIER="$REPO_ROOT/scripts/ci/verify-gates-parity.sh"
LOCAL_SH="$REPO_ROOT/scripts/ci/publish-gate-local.sh"
PREPUSH_SH="$REPO_ROOT/scripts/ci/pre-push-strict.sh"

PASS_COUNT=0
FAIL_COUNT=0
TMPDIR_CREATED=""

cleanup() {
  if [[ -n "$TMPDIR_CREATED" && -d "$TMPDIR_CREATED" ]]; then
    rm -rf "$TMPDIR_CREATED"
  fi
}
trap cleanup EXIT

run_test() {
  local label="$1"
  shift
  printf '\n=== Test: %s ===\n' "$label"
  if "$@"; then
    PASS_COUNT=$(( PASS_COUNT + 1 ))
  else
    FAIL_COUNT=$(( FAIL_COUNT + 1 ))
  fi
}

# -------------------------------------------------------------------
# Test 1: clean state — verifier exits 0 against the real (un-drifted) repo
# -------------------------------------------------------------------
test_clean_state() {
  local stderr_out
  stderr_out="$(bash "$VERIFIER" 2>&1 >/dev/null)" || {
    printf '[FAIL] clean-state: verifier exited non-zero; stderr:\n%s\n' "$stderr_out" >&2
    return 1
  }
  printf '[ok] clean-state: verifier exits 0 on un-drifted repo\n'
  return 0
}

# -------------------------------------------------------------------
# Test 2: extra gate call in publish-gate-local → exit 1, gate name in stderr
# -------------------------------------------------------------------
test_extra_gate_drift() {
  local tmpdir stderr_out exit_code

  tmpdir="$(mktemp -d)"
  TMPDIR_CREATED="$tmpdir"

  # Copy publish-gate-local.sh and append a fake gate_* call at the end
  local fake_local="$tmpdir/publish-gate-local.sh"
  cp "$LOCAL_SH" "$fake_local"
  printf '\ngate_phantom_xyz\n' >> "$fake_local"

  # Run verifier with the modified local consumer
  exit_code=0
  stderr_out="$(PARITY_LOCAL_OVERRIDE="$fake_local" bash "$VERIFIER" 2>&1 >/dev/null)" || exit_code=$?

  if [[ "$exit_code" -ne 1 ]]; then
    printf '[FAIL] extra-gate-drift: expected exit 1, got %s\n' "$exit_code" >&2
    return 1
  fi

  if ! echo "$stderr_out" | grep -q 'gate_phantom_xyz'; then
    printf '[FAIL] extra-gate-drift: stderr does not name gate_phantom_xyz:\n%s\n' "$stderr_out" >&2
    return 1
  fi

  printf '[ok] extra-gate-drift: verifier exits 1 and names gate_phantom_xyz\n'
  return 0
}

# -------------------------------------------------------------------
# Test 3: missing required gate in pre-push copy → exit 1, composer_dryrun + "missing" in stderr
# -------------------------------------------------------------------
test_missing_required_gate() {
  local tmpdir stderr_out exit_code

  tmpdir="$(mktemp -d)"
  # Overwrite TMPDIR_CREATED if already set by test 2 (both cleaned on EXIT)
  TMPDIR_CREATED="$tmpdir"

  # Copy pre-push-strict.sh and remove the gate_composer_dryrun line(s)
  local fake_prepush="$tmpdir/pre-push-strict.sh"
  cp "$PREPUSH_SH" "$fake_prepush"
  # Remove all lines containing gate_composer_dryrun
  grep -v 'gate_composer_dryrun' "$PREPUSH_SH" > "$fake_prepush"

  # Run verifier with the modified pre-push consumer
  exit_code=0
  stderr_out="$(PARITY_PREPUSH_OVERRIDE="$fake_prepush" bash "$VERIFIER" 2>&1 >/dev/null)" || exit_code=$?

  if [[ "$exit_code" -ne 1 ]]; then
    printf '[FAIL] missing-required-gate: expected exit 1, got %s\n' "$exit_code" >&2
    printf 'stderr was:\n%s\n' "$stderr_out" >&2
    return 1
  fi

  if ! echo "$stderr_out" | grep -q 'composer_dryrun'; then
    printf '[FAIL] missing-required-gate: stderr does not mention composer_dryrun:\n%s\n' "$stderr_out" >&2
    return 1
  fi

  if ! echo "$stderr_out" | grep -qiE 'missing|not found'; then
    printf '[FAIL] missing-required-gate: stderr does not indicate "missing"/"not found":\n%s\n' "$stderr_out" >&2
    return 1
  fi

  printf '[ok] missing-required-gate: verifier exits 1 and names composer_dryrun as missing\n'
  return 0
}

# -------------------------------------------------------------------
# Run all tests
# -------------------------------------------------------------------
printf '=== gates-parity test suite (PIPE-05) ===\n'
printf 'Verifier: %s\n' "$VERIFIER"
printf 'Lockfile: %s\n' "$REPO_ROOT/scripts/ci/gates.lock.json"
printf '\n'

run_test "clean-state" test_clean_state
run_test "extra-gate-drift" test_extra_gate_drift
run_test "missing-required-gate" test_missing_required_gate

printf '\n=== Results: %s passed, %s failed ===\n' "$PASS_COUNT" "$FAIL_COUNT"

if [[ "$FAIL_COUNT" -gt 0 ]]; then
  exit 1
fi
exit 0
