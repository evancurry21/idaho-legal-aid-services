#!/usr/bin/env bash
# tests/ci/deploy-bound-fail-closed.sh — PIPE-02 enforcement assertion.
#
# Requirement: PIPE-02 (no silent skip, no silent pass)
# Defect closed: .planning/todos/pending/2026-05-07-promptfoo-deploy-bound-gate-silently-auto-skipped.md
#
# Asserts that gate_promptfoo_deploy_bound_required (the fail-closed variant added in
# plan 03.1-04) behaves correctly across three scenarios:
#
#   Scenario A — env unset (fail-closed expected):
#     With ILAS_LIVE_PROVIDER_GATE unset, the function MUST exit non-zero and emit
#     an operator-readable verdict. The "Strict pre-push gate PASSED." string must
#     NOT appear in the output.
#
#   Scenario B — env set (delegation path expected):
#     With ILAS_LIVE_PROVIDER_GATE=1, the function MUST delegate to
#     gate_promptfoo_deploy_bound (verified via a stub that increments a counter),
#     without exiting before the delegate is called.
#
#   Scenario C — call-site verification:
#     pre-push-strict.sh MUST call gate_promptfoo_deploy_bound_required (not the bare
#     gate_promptfoo_deploy_bound) on the deploy-bound branch.
#
# Exit contract:
#   0 — all assertions pass (at least 3 [ok] lines on stdout)
#   1 — any assertion fails (with [FAIL] lines on stderr)
#
# Usage:
#   bash tests/ci/deploy-bound-fail-closed.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
fi
# shellcheck source=./_assert.sh
source "$SCRIPT_DIR/_assert.sh"

cd "$REPO_ROOT"

# ---------------------------------------------------------------------------
# Scenario A: ILAS_LIVE_PROVIDER_GATE unset → fail-closed (exit non-zero)
# ---------------------------------------------------------------------------

scenario_a_output=""
scenario_a_exit=0

scenario_a_output="$(
  (
    unset ILAS_LIVE_PROVIDER_GATE
    # Source the library in a clean subshell — sourcing guard must not be set here.
    unset ILAS_PUBLISH_GATES_LIB_SOURCED
    unset ILAS_PUBLISH_GATES_RUN_INITIALIZED
    source "$REPO_ROOT/scripts/ci/publish-gates.lib.sh"
    # Override publish_gates_record_phase and inline skip write so they are no-ops
    # (summary file may not exist in this bare subshell context)
    # shellcheck disable=SC2034  # used by publish-gates.lib.sh sourced above
    ILAS_PUBLISH_GATES_SUMMARY_FILE="/dev/null"
    # Call the required variant; capture combined output (stdout+stderr)
    gate_promptfoo_deploy_bound_required "main" "http://ilas-pantheon.ddev.site"
  ) 2>&1
)" || scenario_a_exit=$?

# Assert A1: exit code must be non-zero
if [[ "$scenario_a_exit" -eq 0 ]]; then
  printf '[FAIL] Scenario A: expected non-zero exit when ILAS_LIVE_PROVIDER_GATE unset; got 0\n' >&2
  exit 1
fi
printf '[ok] Scenario A: exit non-zero when ILAS_LIVE_PROVIDER_GATE unset (exit=%d)\n' "$scenario_a_exit"

# Assert A2: stderr must mention the gate name
if ! echo "$scenario_a_output" | grep -qE 'Promptfoo deploy-bound'; then
  printf '[FAIL] Scenario A: output missing "Promptfoo deploy-bound"; got:\n%s\n' "$scenario_a_output" >&2
  exit 1
fi
printf '[ok] Scenario A: output contains "Promptfoo deploy-bound"\n'

# Assert A3: output must mention ILAS_LIVE_PROVIDER_GATE (recovery instruction)
if ! echo "$scenario_a_output" | grep -qE 'ILAS_LIVE_PROVIDER_GATE'; then
  printf '[FAIL] Scenario A: output missing ILAS_LIVE_PROVIDER_GATE recovery hint; got:\n%s\n' "$scenario_a_output" >&2
  exit 1
fi
printf '[ok] Scenario A: output contains ILAS_LIVE_PROVIDER_GATE recovery hint\n'

# Assert A4: "Strict pre-push gate PASSED." must NOT appear in output
if echo "$scenario_a_output" | grep -qF 'Strict pre-push gate PASSED.'; then
  printf '[FAIL] Scenario A: "Strict pre-push gate PASSED." found in output when gate should have failed\n' >&2
  exit 1
fi
printf '[ok] Scenario A: "Strict pre-push gate PASSED." absent from output\n'

# ---------------------------------------------------------------------------
# Scenario B: ILAS_LIVE_PROVIDER_GATE=1 → delegation path called
# ---------------------------------------------------------------------------

scenario_b_exit=0
scenario_b_counter=0

scenario_b_counter="$(
  (
    export ILAS_LIVE_PROVIDER_GATE=1
    unset ILAS_PUBLISH_GATES_LIB_SOURCED
    unset ILAS_PUBLISH_GATES_RUN_INITIALIZED
    source "$REPO_ROOT/scripts/ci/publish-gates.lib.sh"
    # shellcheck disable=SC2034  # used by publish-gates.lib.sh sourced above
    ILAS_PUBLISH_GATES_SUMMARY_FILE="/dev/null"

    # Stub the advisory variant so we can observe delegation without live providers.
    _stub_call_count=0
    gate_promptfoo_deploy_bound() {
      # shellcheck disable=SC2317  # reachable: called indirectly by gate_promptfoo_deploy_bound_required
      _stub_call_count=$((_stub_call_count + 1))
      # shellcheck disable=SC2317
      return 0
    }

    gate_promptfoo_deploy_bound_required "main" "http://ilas-pantheon.ddev.site" 2>/dev/null
    printf '%d' "$_stub_call_count"
  )
)" || scenario_b_exit=$?

if [[ "$scenario_b_exit" -ne 0 ]]; then
  printf '[FAIL] Scenario B: unexpected non-zero exit when ILAS_LIVE_PROVIDER_GATE=1 (exit=%d)\n' "$scenario_b_exit" >&2
  exit 1
fi

if [[ "$scenario_b_counter" -lt 1 ]]; then
  printf '[FAIL] Scenario B: gate_promptfoo_deploy_bound stub was not called (counter=%s); delegation path broken\n' "$scenario_b_counter" >&2
  exit 1
fi
printf '[ok] Scenario B: delegation to gate_promptfoo_deploy_bound confirmed (stub called %s time(s))\n' "$scenario_b_counter"

# ---------------------------------------------------------------------------
# Scenario C: call-site verification in pre-push-strict.sh
# ---------------------------------------------------------------------------

# After plan 03.1-05, gate calls are wrapped via _publish_gates_run_with_record,
# so the call site pattern is: _publish_gates_run_with_record gate_promptfoo_deploy_bound_required ...
required_count="$(grep -cE 'gate_promptfoo_deploy_bound_required' "$REPO_ROOT/scripts/ci/pre-push-strict.sh" || true)"
if [[ "$required_count" -ne 1 ]]; then
  printf '[FAIL] Scenario C: expected exactly 1 call to gate_promptfoo_deploy_bound_required in pre-push-strict.sh; found %s\n' "$required_count" >&2
  exit 1
fi
printf '[ok] Scenario C: exactly 1 call to gate_promptfoo_deploy_bound_required in pre-push-strict.sh\n'

bare_count="$(grep -cE '^\s*gate_promptfoo_deploy_bound "' "$REPO_ROOT/scripts/ci/pre-push-strict.sh" || true)"
if [[ "$bare_count" -ne 0 ]]; then
  printf '[FAIL] Scenario C: expected 0 bare gate_promptfoo_deploy_bound calls in pre-push-strict.sh; found %s\n' "$bare_count" >&2
  exit 1
fi
printf '[ok] Scenario C: 0 bare gate_promptfoo_deploy_bound calls in pre-push-strict.sh\n'

printf '\nAll PIPE-02 assertions passed.\n'
