#!/usr/bin/env bash
# scripts/ci/verify-gates-parity.sh — PIPE-05; reads scripts/ci/gates.lock.json.
#
# Purpose: verify that the three gate consumers (pre-push-strict.sh,
# publish-gate-local.sh, .github/workflows/quality-gate.yml) each contain
# all gate_* function calls / annotations required by the lockfile, and that
# no extra gate_* calls appear in any consumer that are absent from the lockfile.
#
# Exit codes:
#   0 — parity clean (no drift detected)
#   1 — drift detected (see stderr for named divergence)
#  64 — usage error (bad args, jq missing, lockfile missing)
#
# Override env vars (test seams — do NOT use in production):
#   PARITY_LOCAL_OVERRIDE    — path to substitute for publish-gate-local.sh
#   PARITY_PREPUSH_OVERRIDE  — path to substitute for pre-push-strict.sh
#   PARITY_WORKFLOW_OVERRIDE — path to substitute for quality-gate.yml
#
# PIPE-05: enforces comment-asserted parity claims as a runtime check.
# See scripts/ci/gates.lock.json for the source of truth.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# shellcheck source=../git/common.sh
source "$REPO_ROOT/scripts/git/common.sh"

LOCKFILE="$REPO_ROOT/scripts/ci/gates.lock.json"

# Consumer file paths (overridable for testing)
CONSUMER_LOCAL="${PARITY_LOCAL_OVERRIDE:-$REPO_ROOT/scripts/ci/publish-gate-local.sh}"
CONSUMER_PREPUSH="${PARITY_PREPUSH_OVERRIDE:-$REPO_ROOT/scripts/ci/pre-push-strict.sh}"
CONSUMER_WORKFLOW="${PARITY_WORKFLOW_OVERRIDE:-$REPO_ROOT/.github/workflows/quality-gate.yml}"

LIST_ONLY=false

usage() {
  cat <<'USAGE'
Usage:
  verify-gates-parity.sh [--list-only]

Verifies that all gate consumers (pre-push-strict.sh, publish-gate-local.sh,
.github/workflows/quality-gate.yml) are in parity with scripts/ci/gates.lock.json.

Options:
  --list-only   Print gate-id and required_in columns from lockfile; exit 0.
  -h, --help    Show this help and exit.

Exit codes:
  0   Parity clean.
  1   Drift detected — stderr names the diverging gate and consumer.
  64  Usage error (bad args, jq not found, lockfile missing).
USAGE
}

parse_args() {
  while (($# > 0)); do
    case "$1" in
      --list-only)
        LIST_ONLY=true
        shift
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        err "Unknown option: $1"
        usage
        exit 64
        ;;
    esac
  done
}

require_jq() {
  if ! command -v jq >/dev/null 2>&1; then
    err "jq is required but not found on PATH."
    err "Install jq: https://stedolan.github.io/jq/download/"
    exit 64
  fi
}

require_lockfile() {
  if [[ ! -f "$LOCKFILE" ]]; then
    err "Lockfile not found: $LOCKFILE"
    exit 64
  fi
}

list_gates() {
  # Print: gate-id  required_in (comma-separated)
  jq -r '.gates[] | .id + "  " + (.required_in | join(","))' "$LOCKFILE"
}

# Extract gate_* tokens from a file via grep.
# Matches any token of the form gate_[a-z_]+ — includes function definitions,
# call sites, and comments (per T-03.1-W5A-03 accept-with-mitigation).
extract_gate_tokens() {
  local file="$1"
  grep -oE 'gate_[a-z_]+' "$file" 2>/dev/null | sort -u || true
}

main() {
  parse_args "$@"
  require_jq
  require_lockfile

  if "$LIST_ONLY"; then
    list_gates
    exit 0
  fi

  info "Verifying gate parity against $LOCKFILE"

  local drift=false

  # --- Consumer map: symbolic-name -> file path ---
  # For each gate entry in the lockfile, required_in values use these names:
  #   "pre-push"               -> pre-push-strict.sh
  #   "publish-gate-local"     -> publish-gate-local.sh
  #   "ci:quality-gate"        -> quality-gate.yml
  #   "pre-push:master-origin-only" -> pre-push-strict.sh (deploy-bound block)

  declare -A CONSUMER_FILES
  CONSUMER_FILES["pre-push"]="$CONSUMER_PREPUSH"
  CONSUMER_FILES["publish-gate-local"]="$CONSUMER_LOCAL"
  CONSUMER_FILES["ci:quality-gate"]="$CONSUMER_WORKFLOW"
  CONSUMER_FILES["pre-push:master-origin-only"]="$CONSUMER_PREPUSH"

  # --- Check 1: required gates present in each consumer ---
  #
  # For each gate in the lockfile, for each consumer listed in required_in,
  # assert that the library_fn token appears in that consumer's file.

  local num_gates
  num_gates="$(jq '.gates | length' "$LOCKFILE")"

  for (( i=0; i<num_gates; i++ )); do
    local gate_id library_fn
    gate_id="$(jq -r ".gates[$i].id" "$LOCKFILE")"
    library_fn="$(jq -r ".gates[$i].library_fn" "$LOCKFILE")"

    local num_consumers
    num_consumers="$(jq ".gates[$i].required_in | length" "$LOCKFILE")"

    for (( j=0; j<num_consumers; j++ )); do
      local consumer
      consumer="$(jq -r ".gates[$i].required_in[$j]" "$LOCKFILE")"
      local consumer_file="${CONSUMER_FILES[$consumer]:-}"

      if [[ -z "$consumer_file" ]]; then
        err "Unknown consumer '$consumer' in lockfile gate '$gate_id' — update verify-gates-parity.sh CONSUMER_FILES map."
        drift=true
        continue
      fi

      if [[ ! -f "$consumer_file" ]]; then
        err "Consumer file not found: $consumer_file (for consumer '$consumer', gate '$gate_id')"
        drift=true
        continue
      fi

      # For pre-push:master-origin-only, scope the check to the deploy-bound
      # conditional block only (lines after the DEPLOY_BOUND_PROMPTFOO check).
      if [[ "$consumer" == "pre-push:master-origin-only" ]]; then
        local deploy_block
        deploy_block="$(awk '/DEPLOY_BOUND_PROMPTFOO.*true/,0' "$consumer_file" 2>/dev/null || true)"
        if ! echo "$deploy_block" | grep -qE "$library_fn"; then
          err "Gate parity drift detected: '$gate_id' ($library_fn) is required in '$consumer' but not found in the deploy-bound block of $consumer_file"
          err "Run: bash $REPO_ROOT/scripts/ci/verify-gates-parity.sh --list-only"
          drift=true
        fi
        continue
      fi

      # General case: library_fn must appear anywhere in the consumer file.
      local tokens
      tokens="$(extract_gate_tokens "$consumer_file")"
      if ! echo "$tokens" | grep -qx "$library_fn"; then
        err "Gate parity drift detected: '$gate_id' ($library_fn) is required in '$consumer' but missing from $consumer_file"
        err "Run: bash $REPO_ROOT/scripts/ci/verify-gates-parity.sh --list-only"
        drift=true
      fi
    done
  done

  # --- Check 2: no extra gate_* calls in consumers not tracked in lockfile ---
  #
  # For each consumer, grep all gate_* tokens; any token NOT in the lockfile's
  # library_fn list is untracked drift.

  # Build a lookup set of all lockfile library_fn values (one per line).
  local lockfile_fn_list
  lockfile_fn_list="$(jq -r '.gates[].library_fn' "$LOCKFILE")"

  # Unique consumer files (pre-push appears twice in the map; deduplicate).
  declare -A CHECKED_FILES
  local consumer_name consumer_path
  for consumer_name in "${!CONSUMER_FILES[@]}"; do
    consumer_path="${CONSUMER_FILES[$consumer_name]}"
    [[ -f "$consumer_path" ]] || continue
    [[ -n "${CHECKED_FILES[$consumer_path]:-}" ]] && continue
    CHECKED_FILES[$consumer_path]=1

    local file_tokens
    file_tokens="$(extract_gate_tokens "$consumer_path")"

    local token
    while IFS= read -r token; do
      [[ -z "$token" ]] && continue
      # Skip the verifier's own internal references
      [[ "$consumer_path" == *"verify-gates-parity"* ]] && continue
      if ! echo "$lockfile_fn_list" | grep -qx "$token"; then
        err "Gate parity drift detected: '$token' found in $consumer_path but not tracked in $LOCKFILE"
        err "Run: bash $REPO_ROOT/scripts/ci/verify-gates-parity.sh --list-only"
        drift=true
      fi
    done <<< "$file_tokens"
  done

  if "$drift"; then
    exit 1
  fi

  ok "Gate parity clean — all consumers match $LOCKFILE"
}

main "$@"
