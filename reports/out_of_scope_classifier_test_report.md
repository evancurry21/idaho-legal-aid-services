# Out-of-Scope Classifier Test Report

**Date:** 2026-01-28
**Module:** ilas_site_assistant
**Test File:** tests/src/Unit/OutOfScopeClassifierTest.php

## Summary

| Metric | Value |
|--------|-------|
| Total Test Cases | 71 |
| Passed | 71 |
| Failed | 0 |
| Pass Rate | **100%** |

## Classifier Statistics

| Category | Pattern Count |
|----------|--------------|
| emergency_services | 9 |
| criminal_defense | 30 |
| immigration | 29 |
| non_idaho | 9 |
| business_commercial | 20 |
| federal_matters | 10 |
| high_value_civil | 11 |
| **Total** | **118** |

## Test Categories Breakdown

### Criminal Defense (16 test cases) - 100% Pass
Tests for criminal matters including:
- Arrest detection ("I was arrested", "I got arrested")
- Criminal charges ("facing charges", "charged with")
- DUI/DWI detection
- Incarceration detection ("in jail", "in prison")
- Probation/parole matters
- Criminal defense representation requests
- Expungement requests
- Spanish language support ("Me arrestaron")

### Immigration (14 test cases) - 100% Pass
Tests for immigration matters including:
- Visa matters (denied, expired, application)
- Green card requests
- Citizenship/naturalization
- Deportation concerns
- ICE detention
- Asylum/refugee status
- Undocumented status
- Spanish language support ("Soy indocumentado")

### Non-Idaho Jurisdiction (8 test cases) - 100% Pass
Tests for location-based out-of-scope:
- Western US states (Oregon, Washington, Montana, Nevada, California)
- "I live in [state]"
- "I am from [state]"
- "I am in [state]"
- Generic "another state" / "not in Idaho"

### Emergency Services (6 test cases) - 100% Pass
Tests for emergency situations:
- 911 requests
- Police requests
- Ambulance requests
- Fire emergencies
- Home intrusion
- Spanish language support ("Llame a la policia")

### Business/Commercial (6 test cases) - 100% Pass
Tests for business matters:
- LLC formation
- Incorporation
- Patent requests
- Trademark requests
- Commercial leases

### Federal Matters (6 test cases) - 100% Pass
Tests for federal jurisdiction:
- Bankruptcy (Chapter 7, Chapter 13)
- IRS debt/audit
- VA benefits
- Social Security disability

### High-Value Civil (5 test cases) - 100% Pass
Tests for cases outside typical legal aid scope:
- Personal injury
- Car accident lawsuits
- Medical malpractice
- Wrongful death
- Workers compensation

### In-Scope Queries (10 test cases) - 100% Pass
Verifies that legitimate ILAS queries are NOT flagged:
- Eviction help
- Security deposit disputes
- Divorce
- Child custody
- Food stamps/benefits
- Wage theft
- Consumer complaints
- Protection orders
- Lease issues
- General service inquiries

## Files Delivered

### New Services
1. **OutOfScopeClassifier.php** (`src/Service/OutOfScopeClassifier.php`)
   - Deterministic pattern-based classifier
   - 7 out-of-scope categories
   - 118 regex patterns
   - Reason codes for logging/analytics
   - Suggestions for alternative resources

2. **OutOfScopeResponseTemplates.php** (`src/Service/OutOfScopeResponseTemplates.php`)
   - Category-specific response messages
   - Links to external resources
   - Brief explanation methods
   - Spanish language support
   - `can_still_help` flag for cross-category routing

### Test Files
3. **OutOfScopeClassifierTest.php** (`tests/src/Unit/OutOfScopeClassifierTest.php`)
   - 71 test cases (50+ as required)
   - PHPUnit data providers
   - Tests for all 7 categories
   - Utility method tests
   - Priority ordering tests

### Configuration
4. **ilas_site_assistant.services.yml** (updated)
   - Added `ilas_site_assistant.out_of_scope_classifier` service
   - Added `ilas_site_assistant.out_of_scope_response_templates` service

## Response Types

| Response Type | Usage |
|--------------|-------|
| `decline_politely` | Criminal defense, immigration, business/commercial |
| `redirect` | Non-Idaho, federal matters, high-value civil |
| `suggest_emergency` | Emergency services |
| `in_scope` | Legitimate ILAS queries (pass-through) |

## Key Design Decisions

1. **Priority Ordering**: Emergency services are checked first to ensure immediate threats are routed correctly before any other classification.

2. **Informational Query Dampening**: Queries that appear to be seeking information rather than reporting a situation may have certain triggers dampened (e.g., business/commercial).

3. **Suggestions with URLs**: Each out-of-scope category includes helpful suggestions with external URLs to appropriate resources.

4. **`can_still_help` Flag**: Many OOS responses include this flag set to TRUE, indicating that while the specific issue is out of scope, ILAS may help with related civil matters.

5. **Spanish Language Support**: Key categories include Spanish language patterns and Spanish translations of brief explanations.

## Integration Status: COMPLETE

The OutOfScopeClassifier is now integrated into `AssistantApiController` as Option 3 (second-pass check).

### Pipeline Order

```
1. SafetyClassifier (crisis, DV, wrongdoing, etc.)
   ↓ if safe
2. OutOfScopeClassifier (NEW - criminal, immigration, non-Idaho, emergency, business, federal, high-value)
   ↓ if in-scope
3. PolicyFilter (fallback policy checks)
   ↓ if no violation
4. IntentRouter (normal intent routing)
   ↓
5. FallbackGate → LLM enhancement → Response
```

### Changes Made to AssistantApiController

1. Added `use` statements for `OutOfScopeClassifier` and `OutOfScopeResponseTemplates`
2. Added protected properties `$outOfScopeClassifier` and `$outOfScopeResponseTemplates`
3. Updated constructor to accept both services (nullable for backwards compat)
4. Updated `create()` to inject services from container
5. Added OOS classification block in `message()` method after SafetyClassifier, before PolicyFilter

### Debug Output

When debug mode is enabled, the response includes:
```json
{
  "_debug": {
    "oos_classification": {
      "is_out_of_scope": true,
      "category": "criminal_defense",
      "reason_code": "oos_criminal_arrested",
      "response_type": "decline_politely"
    },
    "processing_stages": ["input_sanitized", "safety_classified", "oos_classified"]
  }
}
```

## Test Command

```bash
# Using PHPUnit (if available)
./vendor/bin/phpunit --filter OutOfScopeClassifierTest

# Using standalone test script
php /path/to/run_oos_tests.php
```
