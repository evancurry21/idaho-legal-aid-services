#!/usr/bin/env bash
# Plan 03.1-02 / Hypothesis #1 (FD-inheritance) falsification harness.
#
# Patches a *copy* of scripts/ci/pre-push-strict.sh with `</dev/null` at every
# gate call site (pre-push-strict.sh:199-207) and `exec 0<&-` immediately after
# the PUSH_LINES while-loop closes (after pre-push-strict.sh:43). Then installs
# the patched copy at the worktree's git common-dir hooks/pre-push (worktrees
# share .git content, so this affects the entire repo) and triggers
# `git push --dry-run -v origin master` per operator decision (real-push
# validation is deferred to Wave 2 plan 03 Task 4).
#
# A trap is registered BEFORE installation so the original hook is restored on
# any exit path (success, INT, TERM, HUP, ERR). After this script returns:
#   diff -q "$GIT_COMMON_DIR/hooks/pre-push" scripts/ci/pre-push-strict.sh
# must exit 0.
#
# Per plan 03.1-02 risk_model fallback: do NOT attempt a real push from this
# spike under any circumstance. If `--dry-run -v` does not exercise the
# SIGPIPE-prone code path, that is a "necessary-but-not-sufficient" gap that
# Wave 2 plan 03 Task 4's sentinel-commit real-push validation will close.
#
# Hook semantics: pre-push hooks are invoked by git BEFORE the pack-data send
# phase. `--dry-run` still runs the hook with the same stdin protocol (one line
# per ref), so FD-inheritance bugs in the hook itself are observable via
# dry-run. What dry-run does NOT exercise is the pack-data send over the wire —
# meaning an FD leak that only manifests as SIGPIPE during pack-send (when git
# starts writing to the SSH pipe and a dead child reads it) is NOT directly
# observable here.
#
# Therefore: CONFIRMED-on-dry-run = the hook itself completed with exit 0 under
# FD isolation. This is necessary (the hook is the SIGPIPE site per the
# original report) but not sufficient (real-push pack-send phase is unproven).
#
# DO NOT modify scripts/ci/pre-push-strict.sh directly. DO NOT git stash anything.
# DO NOT call `git push --no-verify`. DO NOT call `git clean`.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
GIT_COMMON_DIR="$(git rev-parse --git-common-dir)"
HOOK_LIVE="$GIT_COMMON_DIR/hooks/pre-push"
HOOK_SRC="$REPO_ROOT/scripts/ci/pre-push-strict.sh"
SPIKE_COPY="/tmp/spike-pre-push-strict-fd.sh"
HOOK_BACKUP="/tmp/pre-push.orig.$$"
LOG_FILE="/tmp/spike-fd-trace-$(date -u +%s).log"

echo "[spike-fd-isolation] REPO_ROOT=$REPO_ROOT"
echo "[spike-fd-isolation] HOOK_LIVE=$HOOK_LIVE"
echo "[spike-fd-isolation] HOOK_SRC=$HOOK_SRC"
echo "[spike-fd-isolation] SPIKE_COPY=$SPIKE_COPY"
echo "[spike-fd-isolation] LOG_FILE=$LOG_FILE"

# ----------------------------------------------------------------------------
# 1. Precondition: live hook must be byte-identical to source. If it isn't,
#    abort — something is already off and our restore could overwrite a real
#    edit.
# ----------------------------------------------------------------------------
if ! diff -q "$HOOK_LIVE" "$HOOK_SRC" >/dev/null; then
  echo "[spike-fd-isolation] FATAL: pre-spike hook drift. Aborting." >&2
  diff "$HOOK_LIVE" "$HOOK_SRC" | head -40 >&2
  exit 2
fi

# ----------------------------------------------------------------------------
# 2. Build the patched spike copy.
#    Patches (per RESEARCH §"Minimal SIGPIPE fix" lines 795-820):
#      - line 199 gate_composer_dryrun                 -> append " </dev/null"
#      - line 200 gate_vc_pure                          -> append " </dev/null"
#      - line 201 gate_module_quality                   -> append " </dev/null"
#      - line 204 gate_promptfoo_deploy_bound "$..."    -> append " </dev/null"
#      - line 206 gate_promptfoo_branch_aware "$..."    -> append " </dev/null"
#      - after the PUSH_LINES while-loop (line 43 close) insert `exec 0<&-`
# ----------------------------------------------------------------------------
cp -p "$HOOK_SRC" "$SPIKE_COPY"

# Apply gate-call FD isolation. Using awk for explicit per-line matching so
# any future drift in pre-push-strict.sh causes a noisy mismatch rather than
# a silent miss.
TMP=$(mktemp)
awk '
  # Append "</dev/null" to the four gate call lines + the deploy-bound branch.
  # Matches the EXACT call form so we never patch a comment or doc string.
  /^gate_composer_dryrun$/                                    { print $0 " </dev/null"; next }
  /^gate_vc_pure$/                                            { print $0 " </dev/null"; next }
  /^gate_module_quality$/                                     { print $0 " </dev/null"; next }
  /^  gate_promptfoo_deploy_bound "\$PROMPTFOO_BRANCH" "\$DDEV_ASSISTANT_URL"$/ { print $0 " </dev/null"; next }
  /^  gate_promptfoo_branch_aware "\$PROMPTFOO_BRANCH"$/      { print $0 " </dev/null"; next }
  { print }
' "$SPIKE_COPY" > "$TMP"
mv "$TMP" "$SPIKE_COPY"

# Insert `exec 0<&-` after the PUSH_LINES while-loop closes. The closing `done`
# is on its own line (line 43 in pre-push-strict.sh) immediately following the
# `while IFS=' ' read ...` loop body. We anchor on that `done` line that
# follows a `PUSH_LINES+=(...)` line via awk state.
TMP=$(mktemp)
awk '
  BEGIN { in_push_loop = 0; patched = 0 }
  /while IFS='"'"' '"'"' read -r local_ref local_oid remote_ref remote_oid; do/ { in_push_loop = 1; print; next }
  in_push_loop == 1 && /^done$/ {
    print
    print "exec 0<&-   # spike H1 FD-isolation: detach stdin in parent after PUSH_LINES read"
    in_push_loop = 0
    patched = 1
    next
  }
  { print }
  END {
    if (patched != 1) {
      print "ERROR: spike patcher did not find the PUSH_LINES done anchor" > "/dev/stderr"
      exit 3
    }
  }
' "$SPIKE_COPY" > "$TMP"
mv "$TMP" "$SPIKE_COPY"
chmod +x "$SPIKE_COPY"

# Sanity-check the patch: must contain 5 `</dev/null` adds + 1 `exec 0<&-`.
DEVNULL_COUNT=$(grep -c ' </dev/null$' "$SPIKE_COPY" || true)
EXEC_CLOSE_COUNT=$(grep -c '^exec 0<&-' "$SPIKE_COPY" || true)
echo "[spike-fd-isolation] patch self-check: </dev/null adds=$DEVNULL_COUNT (expect 5); exec 0<&- adds=$EXEC_CLOSE_COUNT (expect 1)"
if [[ "$DEVNULL_COUNT" -lt 5 ]] || [[ "$EXEC_CLOSE_COUNT" -ne 1 ]]; then
  echo "[spike-fd-isolation] FATAL: patch did not apply cleanly. Aborting before installing." >&2
  exit 4
fi

# ----------------------------------------------------------------------------
# 3. Save original hook + install trap-restore BEFORE replacing.
# ----------------------------------------------------------------------------
cp -p "$HOOK_LIVE" "$HOOK_BACKUP"
echo "[spike-fd-isolation] backup saved: $HOOK_BACKUP"

cleanup() {
  local rc=$?
  if [[ -f "$HOOK_BACKUP" ]]; then
    cp -p "$HOOK_BACKUP" "$HOOK_LIVE" 2>/dev/null || true
    rm -f "$HOOK_BACKUP" 2>/dev/null || true
    echo "[spike-fd-isolation] trap: restored $HOOK_LIVE from backup"
  fi
  return $rc
}
trap cleanup EXIT INT TERM HUP

# ----------------------------------------------------------------------------
# 4. Install the spike copy as the live hook.
# ----------------------------------------------------------------------------
cp -p "$SPIKE_COPY" "$HOOK_LIVE"
chmod +x "$HOOK_LIVE"
echo "[spike-fd-isolation] installed spike hook -> $HOOK_LIVE"

# ----------------------------------------------------------------------------
# 5. Trigger `git push --dry-run -v origin master` and capture exit + stderr.
#    Per operator decision: dry-run ONLY. No real push from this spike.
# ----------------------------------------------------------------------------
echo "[spike-fd-isolation] running: git push --dry-run -v origin master"
echo "[spike-fd-isolation] log: $LOG_FILE"

set +e
git push --dry-run -v origin master >"$LOG_FILE" 2>&1
SPIKE_EXIT=$?
set -e

echo "[spike-fd-isolation] dry-run exit=$SPIKE_EXIT"
echo "----- last 40 lines of $LOG_FILE -----"
tail -40 "$LOG_FILE" || true
echo "----- end log tail -----"

# Communicate verdict via exit code to caller (the surrounding plan executor).
#   0 -> CONFIRMED-DRY-RUN
#   141 -> FALSIFIED
#   * -> INCONCLUSIVE
exit "$SPIKE_EXIT"
