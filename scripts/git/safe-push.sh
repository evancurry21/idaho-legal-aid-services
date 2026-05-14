#!/usr/bin/env bash
# PIPE-04 bypass audit trail.
#
# Wrapper around `git push` that records a bypass= audit entry in the publish
# summary file before invoking git push with --no-verify. If --no-verify is not
# present in the argument list this script is a transparent passthrough — it
# simply exec-replaces itself with `git push "$@"` so no overhead is added to
# the normal push path.
#
# Usage (same argv as `git push`):
#   bash scripts/git/safe-push.sh [git-push-args...]
#   bash scripts/git/safe-push.sh --no-verify origin master
#
# Called by: npm run git:safe-push (and publish.sh/finish.sh --no-verify-* paths)
# Per RESEARCH §"--no-verify Audit Trail (open question #2)" — Option B chosen.
#
# Known coverage gap: operators typing `git push --no-verify` directly in a
# terminal bypass this wrapper and leave no audit record. This is accepted partial
# coverage (see PIPE-04 safe-push.sh centralization_justification in the plan).
# PR-time CI covers what pre-push hooks would have caught.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=./common.sh
source "$SCRIPT_DIR/common.sh"

# Source publish-gates.lib.sh to get ILAS_PUBLISH_GATES_SUMMARY_FILE + init helper.
# Preserve any externally-set ILAS_PUBLISH_GATES_SUMMARY_FILE (e.g., from test harnesses).
_EXTERNAL_SUMMARY_FILE="${ILAS_PUBLISH_GATES_SUMMARY_FILE:-}"
_GATES_LIB="$SCRIPT_DIR/../ci/publish-gates.lib.sh"
if [[ -f "$_GATES_LIB" ]]; then
  # shellcheck source=../ci/publish-gates.lib.sh
  source "$_GATES_LIB"
fi
# Restore externally-set value if lib overwrote it.
if [[ -n "$_EXTERNAL_SUMMARY_FILE" ]]; then
  ILAS_PUBLISH_GATES_SUMMARY_FILE="$_EXTERNAL_SUMMARY_FILE"
fi

# Detect --no-verify in argument list.
BYPASS=0
REMOTE=""
REF=""
for arg in "$@"; do
  case "$arg" in
    --no-verify) BYPASS=1 ;;
    -*) : ;;  # other flags
    *)
      # First non-flag arg is remote, second is ref.
      if [[ -z "$REMOTE" ]]; then
        REMOTE="$arg"
      elif [[ -z "$REF" ]]; then
        REF="$arg"
      fi
      ;;
  esac
done

if [[ "$BYPASS" == "1" ]]; then
  warn "WARNING: bypassing pre-push gates with --no-verify. Recording bypass intent."

  # Ensure summary file directory exists and file is initialized.
  if [[ -n "${ILAS_PUBLISH_GATES_SUMMARY_FILE:-}" ]]; then
    mkdir -p "$(dirname "$ILAS_PUBLISH_GATES_SUMMARY_FILE")"
    if [[ ! -f "$ILAS_PUBLISH_GATES_SUMMARY_FILE" ]]; then
      {
        echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        echo "entrypoint=safe-push"
      } > "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
    fi
    # Write bypass record synchronously before exec (per PIPE-04 schema).
    {
      echo "bypass=--no-verify invoker=${USER:-unknown} timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ) commit_sha=$(git rev-parse HEAD 2>/dev/null || echo unknown) remote=${REMOTE:-unknown} ref=${REF:-unknown}"
    } >> "$ILAS_PUBLISH_GATES_SUMMARY_FILE"
  fi

  # Test seam: _SAFE_PUSH_TEST=1 short-circuits exec; harness asserts bypass record was written.
  if [[ "${_SAFE_PUSH_TEST:-0}" == "1" ]]; then
    exit 0
  fi

  exec git push "$@"
fi

# No --no-verify: transparent passthrough. Pre-push hook handles its own observability.
exec git push "$@"
