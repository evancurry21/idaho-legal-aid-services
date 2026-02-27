#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

ENV_NAME=""
MODE="auto"
SITE_NAME="${SITE_NAME:-idaho-legal-aid-services}"

usage() {
  cat <<USAGE
Usage: $0 --env <dev|test|live> [--mode auto|blocking|advisory] [--site <pantheon-site>]
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)
      ENV_NAME="${2:-}"
      shift 2
      ;;
    --mode)
      MODE="${2:-}"
      shift 2
      ;;
    --site)
      SITE_NAME="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "$ENV_NAME" ]]; then
  echo "--env is required" >&2
  usage >&2
  exit 2
fi

cd "$REPO_ROOT"

# Phase 1: PHPUnit-only quality gate from module harness.
bash web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh

# Phase 2: Promptfoo gate with branch-aware policy.
bash scripts/ci/run-promptfoo-gate.sh \
  --site "$SITE_NAME" \
  --env "$ENV_NAME" \
  --mode "$MODE"
