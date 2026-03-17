#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
PHPUNIT_BIN="$REPO_ROOT/vendor/bin/phpunit"

if [[ ! -x "$PHPUNIT_BIN" ]]; then
  echo "ERROR: PHPUnit not found at $PHPUNIT_BIN" >&2
  exit 1
fi

if [[ -z "${SIMPLETEST_DB:-}" ]]; then
  SQLITE_BASE="${TMPDIR:-/tmp}"
  SQLITE_PATH="${SQLITE_BASE%/}/ilas-host-phpunit.sqlite"

  rm -f "${SQLITE_PATH}" "${SQLITE_PATH}-journal" "${SQLITE_PATH}-wal" "${SQLITE_PATH}-shm"

  export SIMPLETEST_DB="sqlite://localhost//${SQLITE_PATH}?module=sqlite"
  echo "Host-safe PHPUnit: defaulting SIMPLETEST_DB to ${SIMPLETEST_DB}" >&2
else
  echo "Host-safe PHPUnit: honoring caller-provided SIMPLETEST_DB" >&2
fi

exec "$PHPUNIT_BIN" --configuration "$REPO_ROOT/phpunit.xml" "$@"
