#!/usr/bin/env bash
set -euo pipefail

SITE_NAME="${SITE_NAME:-idaho-legal-aid-services}"
ENV_NAME=""
ROUTE_PATH="/assistant/api/message"

usage() {
  cat <<USAGE
Usage: $0 --env <dev|test|live> [--site <pantheon-site>] [--path </assistant/api/message>]

Options:
  --env   Pantheon environment name (required)
  --site  Pantheon site machine name (default: idaho-legal-aid-services)
  --path  Endpoint path appended to base URL (default: /assistant/api/message)
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env)
      ENV_NAME="${2:-}"
      shift 2
      ;;
    --site)
      SITE_NAME="${2:-}"
      shift 2
      ;;
    --path)
      ROUTE_PATH="${2:-}"
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

if ! command -v terminus >/dev/null 2>&1; then
  echo "terminus is required but was not found in PATH" >&2
  exit 127
fi

BASE_URL="$(terminus env:view "${SITE_NAME}.${ENV_NAME}" --print)"
if [[ -z "$BASE_URL" ]]; then
  echo "Failed to resolve Pantheon base URL for ${SITE_NAME}.${ENV_NAME}" >&2
  exit 1
fi

# Normalize slashes before output.
BASE_URL="${BASE_URL%/}"
if [[ "$ROUTE_PATH" != /* ]]; then
  ROUTE_PATH="/${ROUTE_PATH}"
fi

echo "${BASE_URL}${ROUTE_PATH}"
