#!/usr/bin/env bash
set -euo pipefail
#
# tests/ci/dual-remote-invariant.sh — PIPE-06 typed-drift invariant harness (STUB until Wave 3).
#
# Purpose: assert that after `git:finish` completes, `github/master` and `origin/master`
# (Pantheon) agree on HEAD; if not, emit a typed drift status token (`github-ahead`,
# `origin-ahead`, `both-diverged`) with a recovery command on stderr. Logic mirrors
# scripts/git/sync-check.sh:describe_remote_status (lines ~89-116) but adds a final invariant.
#
# Requirement(s) served: PIPE-06 (dual-remote drift detection + typed recovery).
# Wired by: Wave 3 plan 03.1-06. Until then this script exits 64 (EX_USAGE) with a loud
# stderr message so any pre-Wave-3 caller is fail-loud, not fail-silent.
# Phase: 03.1-publish-pipeline-audit-hardening — Plan 03.1-01 (Wave 0 lays the harness).
#
# Exit contract (stub): 64 (EX_USAGE).
# Exit contract (wired): 0 if both remotes agree on master HEAD; non-zero with typed token on drift.

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
fi

# shellcheck source=./_assert.sh
source "$SCRIPT_DIR/_assert.sh"

: "$REPO_ROOT"

printf '[FAIL] dual-remote-invariant not yet wired — Wave 3 plan 06 implements\n' >&2
exit 64
