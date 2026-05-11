#!/usr/bin/env bash
set -euo pipefail
#
# tests/ci/four-path-matrix.sh — PIPE-07 four-path push matrix harness (STUB until Wave 4).
#
# Purpose: drive the four canonical push paths (origin/master, github/master via PR,
# feature-branch → github, dual-remote drift) end-to-end using the sentinel-commit pattern
# from RESEARCH §"Synthetic Commit Pattern" (lines ~670-700).
#
# Requirement(s) served: PIPE-07 (test-bed coverage of all four push paths).
# Wired by: Wave 4 plan 03.1-07. Until then this script exits 64 (EX_USAGE) with a loud
# stderr message so any pre-Wave-4 caller is fail-loud, not fail-silent.
# Phase: 03.1-publish-pipeline-audit-hardening — Plan 03.1-01 (Wave 0 lays the harness).
#
# Future CLI shape (Wave 4 will implement; argparse stub mirrors scripts/git/sync-check.sh:23-54):
#   --path origin-master          run only path 1 (Pantheon origin/master push)
#   --path finish-full-flow       run only path 2 (npm run git:publish → git:finish)
#   --path feature-branch         run only path 3 (feature → github/<branch>)
#   --path dual-remote-drift      run only path 4 (synthetic drift + sync-check)
#   --path all                    default; runs all four sequentially
#
# Exit contract (stub): 64 (EX_USAGE per BSD sysexits.h).
# Exit contract (wired): 0 if all selected paths pass; non-zero with per-path summary on first failure.

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
fi

# shellcheck source=./_assert.sh
source "$SCRIPT_DIR/_assert.sh"

# Stub-accept any args; ignore them until Wave 4 wires the real matrix.
# The future Wave 4 argparse will mirror scripts/git/sync-check.sh:23-54.
for _arg in "$@"; do
  : "$_arg"
done

# Touch REPO_ROOT to keep shellcheck quiet about unused vars in stub mode.
: "$REPO_ROOT"

printf '[FAIL] four-path-matrix not yet wired — Wave 4 plan 07 implements\n' >&2
exit 64
