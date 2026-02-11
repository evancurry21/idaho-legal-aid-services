#!/bin/bash
# Verify information disclosure hardening for findings L-1, L-2, L-5, M-3.
# Run from project root: bash web/modules/custom/ilas_security/tests/scripts/verify-info-disclosure.sh

set -euo pipefail
ERRORS=0

echo "=== Information Disclosure Hardening Verification (L-1, L-2, L-5, M-3) ==="
echo ""

# L-1a: Generator meta tag removal in ilas_seo.module
MODULE="web/modules/custom/ilas_seo/ilas_seo.module"
echo "[L-1a] Checking generator meta tag removal in $MODULE ..."

if [ ! -f "$MODULE" ]; then
  echo "  FAIL: $MODULE does not exist"
  ERRORS=$((ERRORS + 1))
else
  if grep -q 'system_meta_generator' "$MODULE"; then
    echo "  PASS: system_meta_generator removal present"
  else
    echo "  FAIL: system_meta_generator not referenced in module"
    ERRORS=$((ERRORS + 1))
  fi
fi

# L-1b: X-Generator header removal via event subscriber
SUBSCRIBER="web/modules/custom/ilas_seo/src/EventSubscriber/ResponseSubscriber.php"
echo ""
echo "[L-1b] Checking X-Generator header removal in $SUBSCRIBER ..."

if [ ! -f "$SUBSCRIBER" ]; then
  echo "  FAIL: $SUBSCRIBER does not exist"
  ERRORS=$((ERRORS + 1))
else
  if grep -q "remove('X-Generator')" "$SUBSCRIBER"; then
    echo "  PASS: X-Generator header removal present"
  else
    echo "  FAIL: X-Generator header removal not found"
    ERRORS=$((ERRORS + 1))
  fi
fi

# L-1c: Services file registers subscriber
SERVICES="web/modules/custom/ilas_seo/ilas_seo.services.yml"
echo ""
echo "[L-1c] Checking $SERVICES ..."

if [ ! -f "$SERVICES" ]; then
  echo "  FAIL: $SERVICES does not exist"
  ERRORS=$((ERRORS + 1))
else
  if grep -q 'event_subscriber' "$SERVICES"; then
    echo "  PASS: Event subscriber registered"
  else
    echo "  FAIL: Event subscriber not registered"
    ERRORS=$((ERRORS + 1))
  fi
fi

# L-2: Core text files blocked in settings.php
SETTINGS="web/sites/default/settings.php"
echo ""
echo "[L-2] Checking $SETTINGS for core text file blocking ..."

if [ ! -f "$SETTINGS" ]; then
  echo "  FAIL: $SETTINGS does not exist"
  ERRORS=$((ERRORS + 1))
else
  if grep -q 'CHANGELOG' "$SETTINGS"; then
    echo "  PASS: CHANGELOG.txt blocking present"
  else
    echo "  FAIL: CHANGELOG.txt blocking not found"
    ERRORS=$((ERRORS + 1))
  fi
fi

# L-5: security.txt exists
SECURITY_TXT="web/.well-known/security.txt"
echo ""
echo "[L-5] Checking $SECURITY_TXT ..."

if [ ! -f "$SECURITY_TXT" ]; then
  echo "  FAIL: $SECURITY_TXT does not exist"
  ERRORS=$((ERRORS + 1))
else
  if grep -q 'Contact:' "$SECURITY_TXT"; then
    echo "  PASS: Contact field present"
  else
    echo "  FAIL: Contact field not found"
    ERRORS=$((ERRORS + 1))
  fi

  if grep -q 'Expires:' "$SECURITY_TXT"; then
    echo "  PASS: Expires field present"
  else
    echo "  FAIL: Expires field not found"
    ERRORS=$((ERRORS + 1))
  fi
fi

# M-3: User enumeration prevention
echo ""
echo "[M-3] Checking user enumeration prevention in $SUBSCRIBER ..."

if [ -f "$SUBSCRIBER" ]; then
  if grep -q 'entity.user.canonical' "$SUBSCRIBER"; then
    echo "  PASS: User canonical route check present"
  else
    echo "  FAIL: User canonical route check not found"
    ERRORS=$((ERRORS + 1))
  fi

  if grep -q 'isAnonymous()' "$SUBSCRIBER"; then
    echo "  PASS: Anonymous user check present"
  else
    echo "  FAIL: Anonymous user check not found"
    ERRORS=$((ERRORS + 1))
  fi

  if grep -q 'setStatusCode(403)' "$SUBSCRIBER"; then
    echo "  PASS: 404-to-403 normalization present"
  else
    echo "  FAIL: 404-to-403 normalization not found"
    ERRORS=$((ERRORS + 1))
  fi
fi

echo ""
echo "=== Summary ==="
if [ "$ERRORS" -eq 0 ]; then
  echo "All checks passed."
  exit 0
else
  echo "$ERRORS check(s) failed."
  exit 1
fi
