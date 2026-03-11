#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# shellcheck source=../git/common.sh
source "$REPO_ROOT/scripts/git/common.sh"

REMOTE_NAME="${1:-unknown-remote}"
REMOTE_URL="${2:-unknown-url}"
CURRENT_BRANCH="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"

echo "Strict pre-push gate: remote=${REMOTE_NAME} branch=${CURRENT_BRANCH}"
echo "Remote URL: ${REMOTE_URL}"

if [[ "$CURRENT_BRANCH" == "HEAD" ]]; then
  echo "ERROR: Detached HEAD pushes are not supported by the strict hook." >&2
  echo "Push from a named branch or use git push --no-verify intentionally." >&2
  exit 1
fi

if [[ "$REMOTE_NAME" == "origin" || "$REMOTE_NAME" == "github" ]]; then
  OTHER_REMOTE="github"
  if [[ "$REMOTE_NAME" == "github" ]]; then
    OTHER_REMOTE="origin"
  fi

  echo "Checking dual-remote drift before push..."
  bash "$REPO_ROOT/scripts/git/sync-check.sh" --branch "$CURRENT_BRANCH"

  if git -C "$REPO_ROOT" show-ref --verify --quiet "refs/remotes/$OTHER_REMOTE/$CURRENT_BRANCH"; then
    read -r OTHER_REMOTE_ONLY OTHER_LOCAL_ONLY < <(
      git -C "$REPO_ROOT" rev-list --left-right --count \
        "$OTHER_REMOTE/$CURRENT_BRANCH...$CURRENT_BRANCH"
    )

    if [[ "$OTHER_REMOTE_ONLY" == "0" && "$OTHER_LOCAL_ONLY" != "0" ]]; then
      echo "WARN: $OTHER_REMOTE/$CURRENT_BRANCH is behind local by $OTHER_LOCAL_ONLY commit(s)." >&2
      echo "After this $REMOTE_NAME push, also run: git push $OTHER_REMOTE $CURRENT_BRANCH" >&2
    fi
  fi
fi

if [[ -z "${ILAS_ASSISTANT_URL:-}" ]] &&
  ! command -v ddev >/dev/null 2>&1 &&
  ! command -v terminus >/dev/null 2>&1; then
  echo "ERROR: ILAS_ASSISTANT_URL is unset and neither DDEV nor Terminus is available." >&2
  echo "Set ILAS_ASSISTANT_URL, start DDEV, or install/authenticate Terminus before push." >&2
  exit 1
fi

cd "$REPO_ROOT"

echo "Running module quality gate..."
bash web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

echo "Running branch-aware promptfoo gate..."
CI_BRANCH="$CURRENT_BRANCH" bash scripts/ci/run-promptfoo-gate.sh --env dev --mode auto

echo "Strict pre-push gate PASSED."
