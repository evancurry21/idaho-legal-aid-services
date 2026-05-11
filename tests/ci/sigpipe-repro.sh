#!/usr/bin/env bash
set -euo pipefail
#
# tests/ci/sigpipe-repro.sh — SIGPIPE root-cause spike harness for Wave 1 (Hypothesis #1, FD-inheritance).
#
# Purpose: falsify or confirm RESEARCH §"Hypothesis #1 (FD-inheritance) — falsification strategy"
# (lines ~579-599). The pre-push pipeline emits "Strict pre-push gate PASSED." then dies with
# SIGPIPE (exit 141) on `git push origin master`. The leading hypothesis is that a gate child
# (ddev exec / npm run / phpunit / composer) leaks an open stdin/stdout fd to the parent shell
# which git push then writes into → SIGPIPE.
#
# Method:
#   1. Source publish-gates.lib.sh.
#   2. Redirect this script's stderr to a spike trace log under /tmp.
#   3. Enable `set -x` to capture every subprocess invocation.
#   4. Run EACH gate in a subshell with explicit FD-isolation: `</dev/null` on stdin, redirect
#      stdout+stderr into a per-gate log. The subshell guarantees the parent does not inherit
#      whatever fd-state the gate child manipulates.
#   5. After all gates complete, close inherited fds 0/1/2 in the parent shell.
#   6. Attempt `git push --dry-run origin master` to see if the symptom reproduces post-isolation.
#   7. Emit one of three verdict tokens on the final stdout line:
#        - hypothesis1-confirmed   (push succeeded after isolation; FD-inheritance was the cause)
#        - hypothesis1-falsified   (push failed identically; FD-inheritance is NOT the cause)
#        - inconclusive            (push failed but with different signature; further bisect needed)
#
# Requirement(s) served: PIPE-01 (SIGPIPE root-cause) via D-SIGPIPE-02 hypothesis #1.
# Wired by: Wave 1 (Plan 03.1-02 / 03.1-03 spike chain). This script is invoked; it is NOT a stub.
# Phase: 03.1-publish-pipeline-audit-hardening — Plan 03.1-01 (Wave 0 lays the harness).
#
# Exit contract:
#   0 — harness ran end-to-end; verdict token captured on final stdout line.
#   1 — harness itself failed (cannot source library, etc.).

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
fi

# Source the assertion helpers (used for sanity checks before the spike).
# shellcheck source=./_assert.sh
source "$SCRIPT_DIR/_assert.sh"

# Sanity: publish-gates.lib.sh must exist.
GATES_LIB="$REPO_ROOT/scripts/ci/publish-gates.lib.sh"
if [[ ! -f "$GATES_LIB" ]]; then
  printf '[FAIL] sigpipe-repro: missing %s\n' "$GATES_LIB" >&2
  exit 1
fi

# Source the gates library so the gate_* functions are callable in this shell.
# shellcheck source=../../scripts/ci/publish-gates.lib.sh
source "$GATES_LIB"

# --- Trace log setup ---
TRACE_TS="$(date -u +%Y%m%dT%H%M%SZ)"
TRACE_LOG="/tmp/sigpipe-repro-${TRACE_TS}.log"
COMPOSER_LOG="/tmp/spike-composer-${TRACE_TS}.out"
VCPURE_LOG="/tmp/spike-vc-pure-${TRACE_TS}.out"
MQ_LOG="/tmp/spike-mq-${TRACE_TS}.out"
PROMPTFOO_LOG="/tmp/spike-promptfoo-${TRACE_TS}.out"
PUSH_LOG="/tmp/spike-push-${TRACE_TS}.out"

printf '[info] sigpipe-repro: trace=%s\n' "$TRACE_LOG"

# Redirect stderr into the trace log; keep stdout for the final verdict token.
exec 2>"$TRACE_LOG"
set -x

CURRENT_BRANCH="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"

# --- Run each gate in a fully FD-isolated subshell ---
#
# Each invocation:
#   - subshell `( ... )` so any fd-table mutation does NOT leak to parent
#   - `</dev/null` closes the gate's stdin from the parent's tty
#   - `>"$LOG" 2>&1` directs all gate output to a per-gate file
#
# Gate exit codes are NOT propagated as harness failure here; the spike is about whether
# the parent's fd-state is corrupted, not about whether gates pass on a clean checkout.

COMPOSER_EXIT=0
( gate_composer_dryrun </dev/null >"$COMPOSER_LOG" 2>&1 ) || COMPOSER_EXIT=$?

VCPURE_EXIT=0
( gate_vc_pure </dev/null >"$VCPURE_LOG" 2>&1 ) || VCPURE_EXIT=$?

MQ_EXIT=0
( gate_module_quality </dev/null >"$MQ_LOG" 2>&1 ) || MQ_EXIT=$?

PROMPTFOO_EXIT=0
( gate_promptfoo_branch_aware "$CURRENT_BRANCH" </dev/null >"$PROMPTFOO_LOG" 2>&1 ) || PROMPTFOO_EXIT=$?

# --- Parent-side fd reset (per RESEARCH H#1) ---
#
# After all gates complete, explicitly reattach inherited fds 0/1/2 to safe targets so any
# lingering pipe-end from a gate child cannot survive into the git push call. We do NOT
# `exec 0<&- 1<&- 2<&-` (close) because closing stdout/stderr would lose the verdict line
# and the trace; instead we reattach stdin to /dev/null (closes any leaked pipe) and leave
# stdout/stderr pointing at their current targets. This is a stricter version of the spike's
# pseudocode that preserves observability.
exec 0</dev/null

# --- Real push attempt under FD-isolated conditions ---
#
# `--dry-run` avoids touching remote state; we are looking for SIGPIPE (exit 141), not actual push success.
# The push itself is run with explicit `</dev/null` to remove any doubt that this invocation
# itself could be the source of the leaked fd.
set +e
git -C "$REPO_ROOT" push --dry-run origin master </dev/null >"$PUSH_LOG" 2>&1
PUSH_EXIT=$?
set -e

set +x

# Reset stderr to the terminal so the verdict line + summary land on the user's stderr too.
exec 2>&1

# --- Verdict classification ---
#
# 141 == 128 + SIGPIPE(13) — the symptom we are hunting.
# 0    == push succeeded with no SIGPIPE under FD-isolation → hypothesis #1 likely confirmed.
# other non-zero — push failed for a different reason (auth, sync-check, network) → inconclusive.

VERDICT="inconclusive"
if [[ $PUSH_EXIT -eq 0 ]]; then
  VERDICT="hypothesis1-confirmed"
elif [[ $PUSH_EXIT -eq 141 ]]; then
  VERDICT="hypothesis1-falsified"
fi

# --- Summary block (to current stdout) ---
printf '\n=== sigpipe-repro summary (%s) ===\n' "$TRACE_TS"
printf 'gate_composer_dryrun        exit=%d log=%s\n' "$COMPOSER_EXIT" "$COMPOSER_LOG"
printf 'gate_vc_pure                exit=%d log=%s\n' "$VCPURE_EXIT" "$VCPURE_LOG"
printf 'gate_module_quality         exit=%d log=%s\n' "$MQ_EXIT" "$MQ_LOG"
printf 'gate_promptfoo_branch_aware exit=%d log=%s\n' "$PROMPTFOO_EXIT" "$PROMPTFOO_LOG"
printf 'git push --dry-run          exit=%d log=%s\n' "$PUSH_EXIT" "$PUSH_LOG"
printf 'parent_trace                       log=%s\n' "$TRACE_LOG"
printf '\nverdict=%s\n' "$VERDICT"

exit 0
