#!/usr/bin/env bash
# 03.1-02-SPIKE.md §"File 0" mandate #4 (PIPE-09 enforcement).
#
# Asserts the LOAD-BEARING SIGPIPE fix is in place: repo-local core.sshCommand
# must contain `ServerAliveInterval=60` AND `ServerAliveCountMax=20`. Without
# both, the SSH socket to Pantheon (codeserver.dev.*.drush.in:2222) is dropped
# at ~603s of idle while the pre-push hook is running its ~673s gate suite,
# producing the SIGPIPE (exit 141) symptom that PIPE-01 fixes.
#
# Exit contract:
#   0 — keepalive config matches the H2 contract
#   1 — config missing or malformed (with named-divergence message on stderr)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
fi
# shellcheck source=./_assert.sh
source "$SCRIPT_DIR/_assert.sh"

cd "$REPO_ROOT"

actual="$(git config --local --get core.sshCommand 2>/dev/null || true)"

if [[ -z "$actual" ]]; then
  echo "[FAIL] core.sshCommand is not set in repo-local .git/config" >&2
  echo "       Run: bash scripts/git/install-keepalive.sh" >&2
  exit 1
fi

if ! [[ "$actual" =~ ServerAliveInterval=60 ]]; then
  echo "[FAIL] core.sshCommand missing ServerAliveInterval=60" >&2
  echo "       actual: $actual" >&2
  echo "       Expected substring per 03.1-02-SPIKE.md §\"File 0\" Option A: ServerAliveInterval=60" >&2
  exit 1
fi

if ! [[ "$actual" =~ ServerAliveCountMax=20 ]]; then
  echo "[FAIL] core.sshCommand missing ServerAliveCountMax=20" >&2
  echo "       actual: $actual" >&2
  echo "       Expected substring per 03.1-02-SPIKE.md §\"File 0\" Option A: ServerAliveCountMax=20" >&2
  exit 1
fi

echo "[ok] core.sshCommand = $actual"
