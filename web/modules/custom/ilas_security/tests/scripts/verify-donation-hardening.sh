#!/bin/bash
# Verify donation inquiry hardening for findings M-10, L-10, M-4.
# Run from project root: bash web/modules/custom/ilas_security/tests/scripts/verify-donation-hardening.sh

set -euo pipefail
ERRORS=0

echo "=== Donation Inquiry Hardening Verification (M-10, L-10, M-4) ==="
echo ""

CONTROLLER="web/modules/custom/ilas_donation_inquiry/src/Controller/DonationInquiryController.php"

# M-10: Flood control
echo "[M-10] Checking flood control in $CONTROLLER ..."

if [ ! -f "$CONTROLLER" ]; then
  echo "  FAIL: $CONTROLLER does not exist"
  ERRORS=$((ERRORS + 1))
else
  if grep -q 'use Drupal\\Core\\Flood\\FloodInterface;' "$CONTROLLER"; then
    echo "  PASS: FloodInterface imported"
  else
    echo "  FAIL: FloodInterface not imported"
    ERRORS=$((ERRORS + 1))
  fi

  if grep -q "FloodInterface \$donationFlood" "$CONTROLLER"; then
    echo "  PASS: FloodInterface property declared"
  else
    echo "  FAIL: FloodInterface property not found"
    ERRORS=$((ERRORS + 1))
  fi

  if grep -q "isAllowed('donation_inquiry_submit'" "$CONTROLLER"; then
    echo "  PASS: Flood isAllowed() check present"
  else
    echo "  FAIL: Flood isAllowed() check not found"
    ERRORS=$((ERRORS + 1))
  fi

  if grep -q "register('donation_inquiry_submit'" "$CONTROLLER"; then
    echo "  PASS: Flood register() call present"
  else
    echo "  FAIL: Flood register() call not found"
    ERRORS=$((ERRORS + 1))
  fi

  if grep -q "429" "$CONTROLLER"; then
    echo "  PASS: 429 status code returned for flood limit"
  else
    echo "  FAIL: 429 status code not found"
    ERRORS=$((ERRORS + 1))
  fi
fi

# L-10: source_url validation
echo ""
echo "[L-10] Checking source_url validation ..."

if [ -f "$CONTROLLER" ]; then
  if grep -q 'parse_url(\$sourceUrl)' "$CONTROLLER"; then
    echo "  PASS: source_url parsed for validation"
  else
    echo "  FAIL: parse_url() not found for source_url"
    ERRORS=$((ERRORS + 1))
  fi

  if grep -q 'getHost()' "$CONTROLLER"; then
    echo "  PASS: Host comparison present"
  else
    echo "  FAIL: getHost() comparison not found"
    ERRORS=$((ERRORS + 1))
  fi
fi

# M-4: Filename sanitization
CONFIG_FILE="config/file.settings.yml"
echo ""
echo "[M-4] Checking $CONFIG_FILE ..."

if [ ! -f "$CONFIG_FILE" ]; then
  echo "  FAIL: $CONFIG_FILE does not exist"
  ERRORS=$((ERRORS + 1))
else
  for OPTION in transliterate replace_whitespace replace_non_alphanumeric deduplicate_separators lowercase; do
    if grep -q "${OPTION}: true" "$CONFIG_FILE"; then
      echo "  PASS: ${OPTION} is true"
    else
      echo "  FAIL: ${OPTION} is not true"
      ERRORS=$((ERRORS + 1))
    fi
  done
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
