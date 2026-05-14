#!/usr/bin/env bats
# tests/ci/gates-parity.bats — PIPE-05 lockfile-vs-consumer parity test.
#
# Purpose: when bats is installed, wraps the hand-rolled test harness in
# tests/ci/gates-parity.sh so both invocation styles produce the same results.
#
# Wired by: Wave 5 plan 03.1-06.
# Phase: 03.1-publish-pipeline-audit-hardening
#
# Hand-rolled fallback: bats not installed on this host per Wave 0 audit
# (see .planning/phases/03.1-publish-pipeline-audit-hardening/03.1-VALIDATION.md
# §"Tooling Audit"). The real implementation lives in tests/ci/gates-parity.sh.
# Run it directly: bash tests/ci/gates-parity.sh
#
# PIPE-05 enforcement test.

# --- Hand-rolled bash dispatcher (when invoked as `bash tests/ci/gates-parity.bats`) ---
if [ -z "${BATS_TEST_FILENAME:-}" ] && [ "${BASH_SOURCE[0]:-}" = "${0:-}" ]; then
  # Not running under bats — forward to the hand-rolled harness
  SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
  exec bash "$SCRIPT_DIR/gates-parity.sh" "$@"
fi

@test "clean state: verifier exits 0 on un-drifted repo" {
  REPO_ROOT="$(git -C "$(dirname "$BATS_TEST_FILENAME")" rev-parse --show-toplevel)"
  run bash "$REPO_ROOT/scripts/ci/verify-gates-parity.sh"
  [ "$status" -eq 0 ]
}

@test "extra gate call in publish-gate-local → verifier exits 1 and names the gate" {
  REPO_ROOT="$(git -C "$(dirname "$BATS_TEST_FILENAME")" rev-parse --show-toplevel)"
  TMPDIR_BATS="$(mktemp -d)"
  fake_local="$TMPDIR_BATS/publish-gate-local.sh"
  cp "$REPO_ROOT/scripts/ci/publish-gate-local.sh" "$fake_local"
  printf '\ngate_phantom_xyz\n' >> "$fake_local"
  run env PARITY_LOCAL_OVERRIDE="$fake_local" bash "$REPO_ROOT/scripts/ci/verify-gates-parity.sh"
  rm -rf "$TMPDIR_BATS"
  [ "$status" -eq 1 ]
  [[ "$output" =~ gate_phantom_xyz ]]
}

@test "missing required gate in pre-push copy → verifier exits 1 and names the gate" {
  REPO_ROOT="$(git -C "$(dirname "$BATS_TEST_FILENAME")" rev-parse --show-toplevel)"
  TMPDIR_BATS="$(mktemp -d)"
  fake_prepush="$TMPDIR_BATS/pre-push-strict.sh"
  grep -v 'gate_composer_dryrun' "$REPO_ROOT/scripts/ci/pre-push-strict.sh" > "$fake_prepush"
  run env PARITY_PREPUSH_OVERRIDE="$fake_prepush" bash "$REPO_ROOT/scripts/ci/verify-gates-parity.sh"
  rm -rf "$TMPDIR_BATS"
  [ "$status" -eq 1 ]
  [[ "$output" =~ composer_dryrun ]]
}
