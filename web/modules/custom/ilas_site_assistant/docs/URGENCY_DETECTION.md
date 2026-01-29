# Urgency Detection for Legal Deadlines

This document describes the urgency detection system that routes time-sensitive legal matters to `/legal-advice-line`.

## Overview

When users indicate they have an imminent legal deadline (lawsuit response, court date, etc.), the chatbot must:
1. Detect the urgency
2. Route to `/legal-advice-line` (not forms, FAQ, or topic pages)
3. Provide safety language about the importance of the deadline

## Where the Rules Live

### 1. IntentRouter.php - Primary Detection
**File:** `src/Service/IntentRouter.php`
**Method:** `initializeUrgentSafetyTriggers()`
**Lines:** ~603-621

The `urgentSafetyTriggers['urgent_deadline']` array contains all trigger phrases. When any trigger is found (substring match), the router returns:
```php
[
  'type' => 'urgent_safety',
  'category' => 'urgent_deadline',
  'confidence' => 1.0,
  'escalation_level' => 'immediate',
]
```

### 2. negatives.yml - Backup Detection
**File:** `config/routing/negatives.yml`
**Section:** `high_risk_deadline`

The KeywordExtractor also checks these triggers during the extraction phase. This provides redundancy if the urgentSafetyTriggers check is bypassed.

### 3. ResponseBuilder.php - Response Generation
**File:** `src/Service/ResponseBuilder.php`
**Case:** `urgent_safety`

Maps the `urgent_deadline` category to the correct response:
- Primary action: `/legal-advice-line`
- Secondary action: `/apply-for-help`
- Safety message about not ignoring the deadline

## Precedence Rules (Decision Flow)

```
User Message
     │
     ▼
┌────────────────────────────────────────────────┐
│ Step 1: Extract keywords (KeywordExtractor)    │
│   - Check high_risk triggers                   │
│   - Check out_of_scope triggers                │
└────────────────────────────────────────────────┘
     │
     ▼
┌────────────────────────────────────────────────┐
│ Step 2: Check URGENT SAFETY (HIGHEST PRIORITY) │
│   Triggers: deadline, court date, served,      │
│   lawsuit response, etc.                       │
│                                                │
│   If matched AND not dampened:                 │
│   → Return urgent_safety intent                │
│   → Route to /legal-advice-line               │
│                                                │
│   Dampeners prevent false positives:           │
│   "how long do i have", "what is the deadline" │
└────────────────────────────────────────────────┘
     │ (no urgent match)
     ▼
┌────────────────────────────────────────────────┐
│ Step 3: Check HIGH-RISK from extraction        │
│   If extraction['high_risk'] is set:           │
│   → Return high_risk intent                    │
└────────────────────────────────────────────────┘
     │ (no high-risk)
     ▼
┌────────────────────────────────────────────────┐
│ Step 4: Check OUT-OF-SCOPE                     │
│   Criminal, immigration, out-of-state, etc.   │
└────────────────────────────────────────────────┘
     │ (in scope)
     ▼
┌────────────────────────────────────────────────┐
│ Step 5+: Standard intent routing               │
│   - Vague queries → disambiguation             │
│   - Topic routing                              │
│   - Navigation intent                          │
│   - Pattern matching for primary intents       │
└────────────────────────────────────────────────┘
```

**Key Point:** Urgency detection (Step 2) runs BEFORE topic/service area routing. This ensures a message like "court date for eviction is friday" routes to urgency, not the eviction topic page.

## Trigger Categories

### English - Immediate (today/tomorrow)
- `deadline tomorrow`, `deadline today`
- `due tomorrow`, `due today`
- `court date tomorrow`, `court date today`
- `24 hours`, `within 24 hours`

### English - Day-of-Week
- `deadline friday`, `deadline monday`
- `deadline is friday`, `deadline is monday`
- `respond by friday`, `respond by monday`
- `file by friday`, `file by monday`
- `by friday`, `by monday`, `by end of week`

### English - Lawsuit/Summons
- `respond to lawsuit`, `respond to summons`
- `lawsuit deadline`, `summons deadline`
- `served papers`, `got served`, `been served`
- `answer the lawsuit`, `answer to complaint`

### Spanish
- `fecha limite hoy`, `fecha limite manana`
- `tengo que responder`
- `me llego una demanda`, `recibí una demanda`
- `corte manana`, `corte hoy`, `audiencia manana`
- `tengo corte`, `tengo una corte`

### Spanglish
- `corte date`
- `court date manana`
- `court manana`

## Dampeners (Prevent Over-Triggering)

These phrases indicate informational queries, NOT actual urgent deadlines:

- `how long do i have`
- `what is the deadline`
- `when is the deadline`
- `typical deadline`
- `general deadline`
- `deadline information`
- `how many days`
- `cuanto tiempo tengo`
- `cual es la fecha limite`

## Test Cases

The golden dataset includes these high_risk_deadline cases:

| Utterance | Expected Intent | Expected Action |
|-----------|-----------------|-----------------|
| "deadline to respond to lawsuit is friday" | high_risk_deadline | /legal-advice-line |
| "have to file paperwork by monday" | high_risk_deadline | /legal-advice-line |
| "tengo una corte date manana" | high_risk_deadline | /legal-advice-line |

## Eval Results

**Before Fix:** 0/3 (0%) - Utterances were routing to /faq or /forms

**After Fix:** 3/3 (100%) - All route correctly to /legal-advice-line

**Full Eval Improvement:**
- Pass rate: 78.1% → 89.9%
- high_risk_deadline: 0% → 100%

## Files Changed

1. `src/Service/IntentRouter.php` - Expanded urgentSafetyTriggers, added dampener support
2. `config/routing/negatives.yml` - Expanded high_risk_deadline triggers, added dampeners
3. `src/Service/KeywordExtractor.php` - Added dampener support to detectHighRisk()
4. `src/Service/ResponseBuilder.php` - Added urgent_safety case for proper URL routing
5. `scripts/chatbot-eval/ChatbotEvaluator.php` - Accept urgent_safety as valid intent
6. `tests/src/Unit/UrgencyDetectionTest.php` - Unit tests for trigger patterns

## Report Locations

- Latest eval report: `scripts/chatbot-eval/reports/chatbot-report-2026-01-28_203729.md`
- Full JSON: `scripts/chatbot-eval/reports/chatbot-report-2026-01-28_203729.json`
