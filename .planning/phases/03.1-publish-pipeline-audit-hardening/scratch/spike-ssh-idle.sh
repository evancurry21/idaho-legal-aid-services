#!/usr/bin/env bash
# Plan 03.1-02 / Hypothesis #2 (SSH idle timeout) falsification harness.
#
# DEFERRED — requires an OPERATOR-driven long-lived SSH session that this
# executor agent cannot run reliably from inside a sandboxed bash call.
# This script exists so the operator can run it from a normal interactive
# shell and append the result to 03.1-02-SPIKE.md.
#
# Usage:
#   bash .planning/phases/03.1-publish-pipeline-audit-hardening/scratch/spike-ssh-idle.sh \
#     2>&1 | tee /tmp/spike-ssh-idle-$(date +%s).log
#
# Expected wall-clock time: ~20 minutes (the SSH sleeps 1200s inside).
#
# Pantheon SSH endpoint (audited 2026-05-11, see RESEARCH.md §"Hypothesis #2"):
#   ssh://codeserver.dev.0bbb0799-c3de-441d-8d26-5caed25eba3f@codeserver.dev.0bbb0799-c3de-441d-8d26-5caed25eba3f.drush.in:2222/
#
# Disables client-side keepalives so we measure the SERVER-side idle behavior:
#   -o ServerAliveInterval=0
#   -o ServerAliveCountMax=0
#
# Verdict logic (RESEARCH.md §"Hypothesis #2" lines 601-619):
#   - SSH drops at or before 11min AND short-gate push succeeds -> CONFIRMED
#   - SSH stays open >11min AND "still-alive" is printed         -> FALSIFIED
#   - Auth/network failure unrelated to idle                     -> INCONCLUSIVE
#
# Cross-reference: the documented hook runtime is 11m13s (per CONTEXT.md
# §Background and the original incident report at
# .planning/todos/pending/2026-05-07-fix-git-push-origin-master-sigpipe-in-pre-push-hook.md).

set -euo pipefail

PANTHEON_USER="codeserver.dev.0bbb0799-c3de-441d-8d26-5caed25eba3f"
PANTHEON_HOST="codeserver.dev.0bbb0799-c3de-441d-8d26-5caed25eba3f.drush.in"
PANTHEON_PORT=2222

echo "[spike-ssh-idle] START $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "[spike-ssh-idle] Opening SSH to ${PANTHEON_USER}@${PANTHEON_HOST}:${PANTHEON_PORT}"
echo "[spike-ssh-idle] Client keepalives DISABLED; will sleep 1200s (~20min); then echo still-alive"

START_EPOCH=$(date +%s)

# Wrap the SSH in a timeout so a runaway session does not block the operator.
# 1300s = 1200s sleep + 100s margin for SSH negotiation overhead.
set +e
timeout 1300 ssh \
  -o ServerAliveInterval=0 \
  -o ServerAliveCountMax=0 \
  -o StrictHostKeyChecking=accept-new \
  -p "$PANTHEON_PORT" \
  "${PANTHEON_USER}@${PANTHEON_HOST}" \
  'echo connected at $(date -u +%Y-%m-%dT%H:%M:%SZ); sleep 1200; echo still-alive at $(date -u +%Y-%m-%dT%H:%M:%SZ)'
SSH_RC=$?
set -e

END_EPOCH=$(date +%s)
ELAPSED=$((END_EPOCH - START_EPOCH))

echo "[spike-ssh-idle] END   $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "[spike-ssh-idle] SSH exit=$SSH_RC; elapsed=${ELAPSED}s"
echo ""
echo "[spike-ssh-idle] Interpret per plan 03.1-02 §Hypothesis #2:"
echo "  - elapsed >=1200 AND ssh_rc==0       -> FALSIFIED (idle 1200s did NOT break the session)"
echo "  - elapsed <1200  AND \"still-alive\"  NOT seen -> CONFIRMED (server dropped idle)"
echo "  - timeout fired (rc=124) at exactly 1300s    -> CONFIRMED (server held indefinitely; client gave up; need re-run)"
echo "  - immediate auth failure                     -> INCONCLUSIVE (re-check SSH key)"
echo ""
echo "[spike-ssh-idle] Append verdict + elapsed time to 03.1-02-SPIKE.md §Hypothesis #2."
