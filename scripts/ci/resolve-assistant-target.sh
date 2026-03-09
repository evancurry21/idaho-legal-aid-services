#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
DERIVE_SCRIPT="$SCRIPT_DIR/derive-assistant-url.sh"

SITE_NAME="${SITE_NAME:-idaho-legal-aid-services}"
ENV_NAME=""
ROUTE_PATH="/assistant/api/message"

usage() {
  cat <<USAGE
Usage: $0 --env <dev|test|live> [--site <pantheon-site>] [--path </assistant/api/message>]
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

if ! command -v node >/dev/null 2>&1; then
  echo "node is required to resolve assistant targets" >&2
  exit 127
fi

assistant_url="${ILAS_ASSISTANT_URL:-}"
target_source=""
ddev_primary_url=""

if [[ -n "$assistant_url" ]]; then
  target_source="explicit_env"
elif command -v ddev >/dev/null 2>&1; then
  if ddev_json="$(ddev describe -j 2>/dev/null)"; then
    ddev_primary_url="$(
      printf '%s' "$ddev_json" | node -e "const fs=require('node:fs'); const data=JSON.parse(fs.readFileSync(0,'utf8')); process.stdout.write((data?.raw?.primary_url || '').trim());"
    )"
    if [[ -n "$ddev_primary_url" ]]; then
      assistant_url="$(
        node - "$ddev_primary_url" "$ROUTE_PATH" "$REPO_ROOT/promptfoo-evals/lib/gate-target.js" <<'NODE'
const [baseUrl, routePath, libPath] = process.argv.slice(2);
const { appendRoutePath } = require(libPath);
process.stdout.write(appendRoutePath(baseUrl, routePath));
NODE
      )"
      target_source="ddev_describe"
    fi
  fi
fi

if [[ -z "$assistant_url" ]]; then
  assistant_url="$("$DERIVE_SCRIPT" --site "$SITE_NAME" --env "$ENV_NAME" --path "$ROUTE_PATH")"
  target_source="terminus"
fi

target_json="$(
  node - "$assistant_url" "$ddev_primary_url" "$REPO_ROOT/promptfoo-evals/lib/gate-target.js" <<'NODE'
const [assistantUrl, ddevPrimaryUrl, libPath] = process.argv.slice(2);
const { classifyTargetUrl } = require(libPath);
process.stdout.write(JSON.stringify(classifyTargetUrl(assistantUrl, ddevPrimaryUrl)));
NODE
)"

target_kind="$(printf '%s' "$target_json" | node -e "const fs=require('node:fs'); const data=JSON.parse(fs.readFileSync(0,'utf8')); process.stdout.write(data.targetKind);")"
target_host="$(printf '%s' "$target_json" | node -e "const fs=require('node:fs'); const data=JSON.parse(fs.readFileSync(0,'utf8')); process.stdout.write(data.host);")"

printf 'assistant_url=%s\n' "$assistant_url"
printf 'target_kind=%s\n' "$target_kind"
printf 'target_source=%s\n' "$target_source"
printf 'target_host=%s\n' "$target_host"
printf 'ddev_primary_url=%s\n' "$ddev_primary_url"
