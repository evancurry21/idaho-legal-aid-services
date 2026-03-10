#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
THEME_DIR="${ROOT_DIR}/web/themes/custom/b5subtheme"
SITE_NAME="${PANTHEON_SITE_NAME:-}"
TARGET_ENV="${PANTHEON_ENVIRONMENT:-}"
SENTRY_ORG_SLUG="${SENTRY_ORG_SLUG:-}"
SENTRY_PROJECT_SLUG_BROWSER="${SENTRY_PROJECT_SLUG_BROWSER:-}"
RELEASE_NAME="${SENTRY_RELEASE:-}"

usage() {
  cat <<'EOF'
Usage: sentry-release.sh [--site <site>] [--env <env>] [--release <release>] [--org <org>] [--project <browser-project>]

Resolves the Pantheon deployment identifier when possible, then creates/finalizes
the matching Sentry release and uploads any source maps found in the custom
theme build output.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --site)
      SITE_NAME="$2"
      shift 2
      ;;
    --env)
      TARGET_ENV="$2"
      shift 2
      ;;
    --release)
      RELEASE_NAME="$2"
      shift 2
      ;;
    --org)
      SENTRY_ORG_SLUG="$2"
      shift 2
      ;;
    --project)
      SENTRY_PROJECT_SLUG_BROWSER="$2"
      shift 2
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ -z "${SENTRY_AUTH_TOKEN:-}" ]]; then
  echo "SENTRY_AUTH_TOKEN is required." >&2
  exit 1
fi

if [[ -z "$SENTRY_ORG_SLUG" || -z "$SENTRY_PROJECT_SLUG_BROWSER" ]]; then
  echo "SENTRY_ORG_SLUG and SENTRY_PROJECT_SLUG_BROWSER are required." >&2
  exit 1
fi

if [[ -z "$RELEASE_NAME" && -n "$SITE_NAME" && -n "$TARGET_ENV" ]]; then
  RELEASE_NAME="$(terminus remote:drush "${SITE_NAME}.${TARGET_ENV}" -- php:eval 'echo getenv("PANTHEON_DEPLOYMENT_IDENTIFIER") ?: "";' 2>/dev/null | tr -d '\r')"
fi

if [[ -z "$RELEASE_NAME" ]]; then
  echo "Unable to determine a release name. Pass --release or provide Pantheon site/env." >&2
  exit 1
fi

if ! find "$THEME_DIR" -type f -name '*.map' -print -quit | grep -q .; then
  echo "No source maps found under ${THEME_DIR}; skipping upload for release ${RELEASE_NAME}."
  exit 0
fi

CLI=(npm exec --yes @sentry/cli -- --auth-token "$SENTRY_AUTH_TOKEN" --org "$SENTRY_ORG_SLUG" --project "$SENTRY_PROJECT_SLUG_BROWSER")

"${CLI[@]}" releases new "$RELEASE_NAME" || true

if git -C "$ROOT_DIR" rev-parse --git-dir >/dev/null 2>&1; then
  "${CLI[@]}" releases set-commits "$RELEASE_NAME" --auto || true
fi

"${CLI[@]}" releases files "$RELEASE_NAME" upload-sourcemaps "$THEME_DIR" \
  --ext map \
  --ext js \
  --ext css \
  --url-prefix "~/themes/custom/b5subtheme" \
  --strip-prefix "$THEME_DIR" \
  --rewrite

"${CLI[@]}" releases finalize "$RELEASE_NAME"

echo "Sentry release ${RELEASE_NAME} prepared for ${SENTRY_PROJECT_SLUG_BROWSER}."
