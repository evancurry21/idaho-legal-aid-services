#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

REMOTE_NAME="${1:-unknown-remote}"
REMOTE_URL="${2:-unknown-url}"
CURRENT_BRANCH="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"

echo "Strict pre-push gate: remote=${REMOTE_NAME} branch=${CURRENT_BRANCH}"
echo "Remote URL: ${REMOTE_URL}"

if [[ -z "${ILAS_ASSISTANT_URL:-}" ]] && ! command -v terminus >/dev/null 2>&1; then
  echo "ERROR: ILAS_ASSISTANT_URL is unset and Terminus is unavailable." >&2
  echo "Set ILAS_ASSISTANT_URL or install/authenticate Terminus before push." >&2
  exit 1
fi

cd "$REPO_ROOT"

echo "Running module quality gate..."
bash web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

echo "Running branch-aware promptfoo gate..."
CI_BRANCH="$CURRENT_BRANCH" bash scripts/ci/run-promptfoo-gate.sh --env dev --mode auto

echo "Strict pre-push gate PASSED."
