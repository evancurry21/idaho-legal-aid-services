#!/usr/bin/env bash
# Idempotent installer for repo-local SSH keepalive on git push.
#
# 03.1-02-SPIKE.md §"Recommended Fix Shape" §"File 0 (PRIMARY, load-bearing)" Option A.
# Pantheon drops idle SSH at ~603s; pre-push hook runs ~673s; ServerAliveInterval=60s
# x ServerAliveCountMax=20 = 1200s tolerance.
#
# Behavior:
#   - If core.sshCommand is unset: install the keepalive value.
#   - If core.sshCommand already matches the keepalive regex: emit ok, no write.
#   - If core.sshCommand is set to an operator-customized value: warn but DO NOT
#     overwrite (preserves operator override; SIGPIPE protection then depends on
#     that value providing keepalives within Pantheon's ~603s idle window).
#
# Invoked from scripts/ci/install-pre-push-strict-hook.sh per SPIKE §"File 0"
# mandate #2 so a fresh clone receives both load-bearing pieces in one command.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
fi
# shellcheck source=./common.sh
source "$REPO_ROOT/scripts/git/common.sh"

# SPIKE-locked target values. Do NOT relax these without updating
# 03.1-02-SPIKE.md §"File 0" math (1200s tolerance > 673s hook runtime).
readonly KEEPALIVE_VALUE="ssh -o ServerAliveInterval=60 -o ServerAliveCountMax=20"
readonly KEEPALIVE_REGEX='ServerAliveInterval=60.*ServerAliveCountMax=20'

existing="$(git -C "$REPO_ROOT" config --local --get core.sshCommand 2>/dev/null || true)"

if [[ -z "$existing" ]]; then
  git -C "$REPO_ROOT" config --local core.sshCommand "$KEEPALIVE_VALUE"
  ok "Installed repo-local core.sshCommand = $KEEPALIVE_VALUE (per 03.1-02-SPIKE.md §\"File 0\")"
elif [[ "$existing" =~ $KEEPALIVE_REGEX ]]; then
  ok "Repo-local core.sshCommand already configured with keepalive: $existing"
else
  warn "Repo-local core.sshCommand is already set to a custom value: $existing"
  warn "Recommended (per 03.1-02-SPIKE.md §\"File 0\" Option A): $KEEPALIVE_VALUE"
  warn "Leaving operator value in place; SIGPIPE protection relies on your value providing keepalives at <= 600s intervals."
fi

info "Verify: git config --local --get core.sshCommand"
