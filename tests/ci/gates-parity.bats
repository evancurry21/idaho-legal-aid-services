#!/usr/bin/env bats
# tests/ci/gates-parity.bats — PIPE-05 lockfile-vs-consumer parity test (STUB until Wave 3).
#
# Purpose: when wired in Wave 3, this test will diff each consumer
# (scripts/ci/pre-push-strict.sh, scripts/ci/publish-gate-local.sh, .github/workflows/quality-gate.yml)
# against scripts/ci/gates.lock.json and fail on any gate listed by a consumer but missing from the
# lockfile OR vice versa. The verifier helper (scripts/ci/verify-gates-parity.sh) is created by
# Wave 3 plan 03.1-05.
#
# Requirement(s) served: PIPE-05 (lockfile-based parity check).
# Wired by: Wave 3 plan 03.1-05.
# Phase: 03.1-publish-pipeline-audit-hardening — Plan 03.1-01 (Wave 0 lays the harness).
#
# Hand-rolled fallback (bats not installed on host per Wave 0 audit, see VALIDATION.md
# §"Tooling Audit"): this file is ALSO executable as plain bash via the dispatcher below.
# When `bats` is later installed, native bats execution remains valid.

# --- Hand-rolled bash dispatcher (when invoked as `bash tests/ci/gates-parity.bats`) ---
# bats sources files with its own runner; when executed via bash directly, BASH_SOURCE[0] == $0
# and BATS_TEST_FILENAME is unset, so we emit the fail-loud stub message and exit 64.
if [ -z "${BATS_TEST_FILENAME:-}" ] && [ "${BASH_SOURCE[0]:-}" = "${0:-}" ]; then
  printf '[FAIL] gates-parity stub — Wave 3 plan 05 wires the real verifier; bats not installed (see VALIDATION.md Tooling Audit)\n' >&2
  exit 64
fi

@test "stub fails until Wave 3 verifier lands" {
  # Intentionally fail: this test is a placeholder for the real parity check that Wave 3 wires.
  # The real test will run `scripts/ci/verify-gates-parity.sh` (created by plan 03.1-05) and
  # assert exit 0 with no drift messages on stderr.
  false
}
