#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
PHPUNIT_BIN="$REPO_ROOT/vendor/bin/phpunit"
DOCROOT="$REPO_ROOT/web"
ROUTER="$DOCROOT/.ht.router.php"

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

# BrowserTestBase makes real HTTP requests to SIMPLETEST_BASE_URL. On the host
# (outside DDEV), nothing usable answers at the default http://localhost — DDEV's
# router replies 404 for unrouted hostnames, which masquerades as a missing
# Drupal route and breaks Functional tests. Spin up a short-lived PHP built-in
# server bound to the same docroot + SIMPLETEST_DB so the webserver process and
# the test process see the exact same site.
if [[ -z "${SIMPLETEST_BASE_URL:-}" ]]; then
  if [[ ! -f "$ROUTER" ]]; then
    echo "ERROR: Drupal router script not found at $ROUTER" >&2
    exit 1
  fi

  # Pick a free port deterministically.
  PORT="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()' 2>/dev/null || true)"
  if [[ -z "$PORT" ]]; then
    # Fallback: try a fixed port range until one binds.
    for candidate in 8889 8890 8891 8892 8893; do
      if ! (echo > "/dev/tcp/127.0.0.1/${candidate}") >/dev/null 2>&1; then
        PORT="$candidate"
        break
      fi
    done
  fi
  if [[ -z "${PORT:-}" ]]; then
    echo "ERROR: Could not find a free port for the built-in PHP server" >&2
    exit 1
  fi

  SERVER_LOG="$(mktemp -t ilas-host-phpunit-server.XXXXXX.log)"
  # Export SIMPLETEST_DB so the webserver bootstrap (drupal_valid_test_ua /
  # TestDatabase) connects to the same SQLite the test process installs into.
  export SIMPLETEST_DB
  php -S "127.0.0.1:${PORT}" -t "$DOCROOT" "$ROUTER" >"$SERVER_LOG" 2>&1 &
  SERVER_PID=$!

  cleanup() {
    if kill -0 "$SERVER_PID" 2>/dev/null; then
      kill "$SERVER_PID" 2>/dev/null || true
      wait "$SERVER_PID" 2>/dev/null || true
    fi
    if [[ "${ILAS_HOST_PHPUNIT_KEEP_LOGS:-0}" != "1" ]]; then
      rm -f "$SERVER_LOG"
    else
      echo "Host-safe PHPUnit: server log retained at $SERVER_LOG" >&2
    fi
  }
  trap cleanup EXIT

  # Wait until the server accepts connections (max ~10s).
  for _ in $(seq 1 50); do
    if (echo > "/dev/tcp/127.0.0.1/${PORT}") >/dev/null 2>&1; then
      break
    fi
    sleep 0.2
  done
  if ! (echo > "/dev/tcp/127.0.0.1/${PORT}") >/dev/null 2>&1; then
    echo "ERROR: Built-in PHP server failed to bind on port ${PORT}" >&2
    cat "$SERVER_LOG" >&2 || true
    exit 1
  fi

  export SIMPLETEST_BASE_URL="http://127.0.0.1:${PORT}"
  echo "Host-safe PHPUnit: launched built-in server, SIMPLETEST_BASE_URL=${SIMPLETEST_BASE_URL}" >&2
else
  echo "Host-safe PHPUnit: honoring caller-provided SIMPLETEST_BASE_URL=${SIMPLETEST_BASE_URL}" >&2
fi

"$PHPUNIT_BIN" --configuration "$REPO_ROOT/phpunit.xml" "$@"
