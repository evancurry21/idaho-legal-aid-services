#!/usr/bin/env bash
# Plan 03.1-02 / Hypothesis #3 (EXIT-trap on success) falsification harness.
#
# Produces three pre-push variants and exercises each via
# `git push --dry-run -v origin master` (per operator-locked decision: no
# real pushes from this spike).
#
#   Variant A: omit publish_gates_install_summary_trap entirely
#   Variant B: keep trap, but pipe summary script body's stdin from /dev/null
#   Variant C: explicitly `trap - EXIT` before the final "PASSED" echo
#
# Each variant is built as a separate /tmp/spike-pre-push-exit-trap-{A,B,C}.sh,
# installed at $(git rev-parse --git-common-dir)/hooks/pre-push, push attempted
# in dry-run, and the original hook is restored via trap-on-EXIT after each.
#
# Verdict per variant:
#   exit 0       -> CONFIRMED-DRY-RUN (variant prevents the hook-level failure)
#   exit 141     -> FALSIFIED (variant did not prevent SIGPIPE)
#   other        -> INCONCLUSIVE (typically a gate-internal failure unrelated
#                    to the EXIT-trap hypothesis)
#
# DO NOT call `git push` without `--dry-run`. DO NOT use `--no-verify`.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
GIT_COMMON_DIR="$(git rev-parse --git-common-dir)"
HOOK_LIVE="$GIT_COMMON_DIR/hooks/pre-push"
HOOK_SRC="$REPO_ROOT/scripts/ci/pre-push-strict.sh"
HOOK_BACKUP="/tmp/pre-push.orig.$$"

# Precondition: live hook byte-identical to source.
if ! diff -q "$HOOK_LIVE" "$HOOK_SRC" >/dev/null; then
  echo "[spike-exit-trap] FATAL: pre-spike hook drift. Aborting." >&2
  exit 2
fi

cp -p "$HOOK_LIVE" "$HOOK_BACKUP"
cleanup() {
  if [[ -f "$HOOK_BACKUP" ]]; then
    cp -p "$HOOK_BACKUP" "$HOOK_LIVE" 2>/dev/null || true
    rm -f "$HOOK_BACKUP" 2>/dev/null || true
    echo "[spike-exit-trap] trap: restored $HOOK_LIVE from backup"
  fi
}
trap cleanup EXIT INT TERM HUP

build_variant_a() {
  # Variant A: drop the EXIT-trap installation entirely.
  local out="$1"
  sed -e 's|^publish_gates_install_summary_trap$|# spike-exit-trap variant A: trap install omitted|' \
    "$HOOK_SRC" > "$out"
  chmod +x "$out"
}

build_variant_b() {
  # Variant B: rewrite the trap body in publish-gates.lib.sh's
  # publish_gates_install_summary_trap to attach `</dev/null` to the summary
  # call. We CANNOT modify the library file; instead, we monkey-patch by
  # overriding the function in the hook BEFORE init_run is called.
  local out="$1"
  # Insert an override of publish_gates_install_summary_trap after the
  # `source ... publish-gates.lib.sh` line.
  awk '
    /source "\$REPO_ROOT\/scripts\/ci\/publish-gates.lib.sh"/ {
      print
      print "# spike-exit-trap variant B: override trap body to detach summary stdin"
      print "publish_gates_install_summary_trap() {"
      print "  trap '\''gate_exit=$?; bash \"'\''\"$_PUBLISH_GATES_LIB_DIR\"'\''/publish-failure-summary.sh\" \"$gate_exit\" </dev/null; exit \"$gate_exit\"'\'' EXIT"
      print "}"
      next
    }
    { print }
  ' "$HOOK_SRC" > "$out"
  chmod +x "$out"
}

build_variant_c() {
  # Variant C: insert `trap - EXIT` immediately before the final
  # `echo "Strict pre-push gate PASSED."` line so the trap does not fire on
  # the success path.
  local out="$1"
  awk '
    /^echo "Strict pre-push gate PASSED\."$/ {
      print "trap - EXIT   # spike-exit-trap variant C: remove trap before PASSED"
      print
      next
    }
    { print }
  ' "$HOOK_SRC" > "$out"
  chmod +x "$out"
}

run_variant() {
  local label="$1"
  local hook_path="$2"
  local log_file="/tmp/spike-exit-trap-${label}-$(date -u +%s).log"

  echo ""
  echo "[spike-exit-trap] === Variant ${label} ==="
  echo "[spike-exit-trap] installing $hook_path -> $HOOK_LIVE"
  cp -p "$hook_path" "$HOOK_LIVE"
  chmod +x "$HOOK_LIVE"

  echo "[spike-exit-trap] running: git push --dry-run -v origin master"
  echo "[spike-exit-trap] log: $log_file"
  set +e
  git push --dry-run -v origin master >"$log_file" 2>&1
  local rc=$?
  set -e
  echo "[spike-exit-trap] variant ${label}: exit=$rc"
  echo "[spike-exit-trap] last 15 lines of log:"
  tail -15 "$log_file" | sed 's/^/  | /'

  # Print machine-readable verdict for the orchestrator to grep.
  case "$rc" in
    0)   echo "[spike-exit-trap] VERDICT variant=${label} CONFIRMED-DRY-RUN exit=$rc log=$log_file" ;;
    141) echo "[spike-exit-trap] VERDICT variant=${label} FALSIFIED exit=$rc log=$log_file" ;;
    *)   echo "[spike-exit-trap] VERDICT variant=${label} INCONCLUSIVE exit=$rc log=$log_file" ;;
  esac
}

# Build the three variants.
build_variant_a /tmp/spike-pre-push-exit-trap-A.sh
build_variant_b /tmp/spike-pre-push-exit-trap-B.sh
build_variant_c /tmp/spike-pre-push-exit-trap-C.sh

# Pre-run sanity: each variant compiles (bash -n).
for v in A B C; do
  if ! bash -n "/tmp/spike-pre-push-exit-trap-${v}.sh"; then
    echo "[spike-exit-trap] FATAL: variant ${v} failed bash -n syntax check." >&2
    exit 5
  fi
done

run_variant A /tmp/spike-pre-push-exit-trap-A.sh
run_variant B /tmp/spike-pre-push-exit-trap-B.sh
run_variant C /tmp/spike-pre-push-exit-trap-C.sh

echo ""
echo "[spike-exit-trap] all variants run. cleanup trap will restore live hook on exit."
