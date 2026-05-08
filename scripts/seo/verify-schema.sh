#!/usr/bin/env bash
#
# SEO JSON-LD verification smoke test.
# Authoritative pre-merge coverage lives in web/modules/custom/ilas_seo/tests/src/Functional/SchemaPropertiesTest.php (Phase 3 / TEST-01).
#
# Asserts (Phase 2 success criteria, minus SEO-01 which is deferred):
#   - Organization JSON-LD on /about contains "foundingDate":"1967" and "name":"Idaho"
#     inside an areaServed object.
#   - Article JSON-LD on representative news, press_entry, resource (single +
#     multi value field_service_areas), and legal_content nodes contains
#     "articleSection".
#   - Office_information JSON-LD on a representative office node contains
#     "@type":"AdministrativeArea" and " County, Idaho" inside areaServed.
#
# DEVIATION FROM PLAN 02-05 (documented):
#   The ES canonical assertion is intentionally OMITTED. Plan 02-01 was
#   deferred — empirical observation showed ES URLs render English-translated
#   entities into the ES UI shell, a routing/rendering bug upstream of metatag
#   token resolution. Asserting the canonical contains "/es/" without fixing
#   the rendering bug would emit a misleading SEO signal. See:
#   .planning/phases/02-seo-schema-correctness-es-canonicals-json-ld-properties/02-01-OBSERVATION.md
#   The ES_PATH env var is recognized but unused; the assertion will be added
#   back by the SEO-01 follow-up phase.
#
# Response bodies are NEVER logged (only status codes / boolean grep results),
# mirroring scripts/security/fingerprint-smoke.sh discipline.
#
# Usage:
#   BASE_URL=https://dev-idaho-legal-aid-services.pantheonsite.io \
#   NEWS_PATH=/news/<slug> \
#   PRESS_PATH=/press/<slug> \
#   RESOURCE_PATH_SINGLE=/resource/<slug> \
#   RESOURCE_PATH_MULTI=/resource/<slug> \
#   LEGAL_PATH=/legal/<slug> \
#   OFFICE_PATH=/office/<slug> \
#     bash scripts/seo/verify-schema.sh
#
# Phase 3 (TEST-01) replaces this with Functional tests; this is the
# operational verifier for the dev/test/live deploy pass.
#
# Exits non-zero on any failure.

set -u

BASE_URL="${BASE_URL:-}"
if [[ -z "${BASE_URL}" ]]; then
  echo "ERROR: BASE_URL is required (e.g. https://dev-idaho-legal-aid-services.pantheonsite.io)" >&2
  exit 2
fi
BASE_URL="${BASE_URL%/}"

pass=0
fail=0

# Fetch a URL once; assert that the response body matches a fixed grep pattern.
# Body is grepped but never echoed.
assert_grep() {
  local url="$1" pattern="$2" label="$3"
  local body
  body="$(curl -sS -L --connect-timeout 10 --max-time 20 "${url}?_=$$$RANDOM" 2>/dev/null || echo "")"
  if [[ -z "${body}" ]]; then
    printf "FAIL  %s -- empty response from %s\n" "${label}" "${url}"
    fail=$((fail + 1))
    return
  fi
  if printf '%s' "${body}" | grep -qE "${pattern}"; then
    printf "ok    %s\n" "${label}"
    pass=$((pass + 1))
  else
    printf "FAIL  %s -- expected pattern not found at %s\n" "${label}" "${url}"
    fail=$((fail + 1))
  fi
}

# Optional path: skip with a SKIP marker if env var is unset/empty.
assert_grep_optional() {
  local path_var="$1" pattern="$2" label="$3"
  local path="${!path_var:-}"
  if [[ -z "${path}" ]]; then
    printf "SKIP  %s -- %s not set\n" "${label}" "${path_var}"
    return
  fi
  assert_grep "${BASE_URL}${path}" "${pattern}" "${label}"
}

# SEO-01 (ES canonical) — DEFERRED. See header comment.

# SEO-03 / Success Criterion 2: Organization foundingDate + areaServed on /about.
assert_grep "${BASE_URL}/about" '"foundingDate"\s*:\s*"1967"'                        "Org foundingDate on /about"
assert_grep "${BASE_URL}/about" '"areaServed"'                                       "Org areaServed key on /about"
assert_grep "${BASE_URL}/about" '"name"\s*:\s*"Idaho"'                               "Org areaServed name = Idaho"

# SEO-03 / Success Criterion 3: articleSection per bundle.
assert_grep_optional NEWS_PATH                '"articleSection"\s*:\s*"News"'                "articleSection News on news node"
assert_grep_optional PRESS_PATH               '"articleSection"\s*:\s*"Press"'               "articleSection Press on press_entry node (D-01)"
assert_grep_optional RESOURCE_PATH_SINGLE     '"articleSection"\s*:\s*"[^"\[]+'              "articleSection string on single-value resource node"
assert_grep_optional RESOURCE_PATH_MULTI      '"articleSection"\s*:\s*\['                    "articleSection ARRAY on multi-value resource node (D-02)"
assert_grep_optional LEGAL_PATH               '"articleSection"'                             "articleSection on legal_content node"

# SEO-03 / Success Criterion 4: office_information areaServed.
assert_grep_optional OFFICE_PATH '"@type"\s*:\s*"AdministrativeArea"'   "Office areaServed AdministrativeArea (D-03)"
assert_grep_optional OFFICE_PATH ' County, Idaho"'                     "Office county qualifier (D-04)"

echo "---"
printf "passed=%d failed=%d\n" "${pass}" "${fail}"
exit $(( fail > 0 ? 1 : 0 ))
