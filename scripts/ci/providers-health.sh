#!/usr/bin/env bash
# Live readiness check for Cohere generation, Voyage rerank, and Pinecone.
#
# Real network calls. API keys are never printed (only key_present + an
# 8-char sha256 fingerprint). Exits 0 when all three providers are healthy,
# non-zero otherwise.
#
# Use this explicitly before deploying when you want to prove live infra.
# It is intentionally NOT invoked by git:publish or the pre-push hook —
# those gates stay deterministic.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

if command -v ddev >/dev/null 2>&1 && ddev describe >/dev/null 2>&1; then
  exec ddev drush ilas:providers-health
fi

if [[ -x vendor/bin/drush ]]; then
  exec vendor/bin/drush ilas:providers-health
fi

echo "ERROR: neither DDEV nor vendor/bin/drush is available — cannot run providers-health." >&2
exit 1
