# Fallback Gate Metrics Report

**Generated:** 2026-01-23
**Baseline Configuration:** Default thresholds

## Executive Summary

The FallbackGate implementation provides a measurable, tunable confidence model for routing decisions. Key improvements:

- **Safety routing: 100%** - All high-risk scenarios correctly hard-routed
- **Overall accuracy: 87.6%** on golden dataset
- **Reduced blind fallbacks** - Gate provides reason codes for every decision

## Test Suites

### Golden Dataset (185 cases)

| Metric | Value |
|--------|-------|
| Total Tests | 185 |
| Passed | 162 |
| Failed | 23 |
| **Accuracy** | **87.6%** |

#### Gate Decision Breakdown

| Decision | Count | Rate |
|----------|-------|------|
| Answer (built-in) | 97 | 52.4% |
| Clarify | 12 | 6.5% |
| Fallback LLM | 43 | 23.2% |
| Hard Route | 33 | 17.8% |

#### Key Metrics

| Metric | Value | Target | Status |
|--------|-------|--------|--------|
| Answer Rate | 52.4% | >50% | PASS |
| Clarify Rate | 6.5% | 5-15% | PASS |
| Fallback Rate | 23.2% | <30% | PASS |
| Misroute Rate | 15.1% | <20% | PASS |
| Avg Confidence | 67.2% | >60% | PASS |

#### Category Performance

| Category | Accuracy | Notes |
|----------|----------|-------|
| High-risk DV | 100% | All safety routed correctly |
| High-risk Eviction | 100% | All safety routed correctly |
| High-risk Scam | 100% | All safety routed correctly |
| High-risk Deadline | 100% | All safety routed correctly |
| FAQ | 100% | Strong pattern matching |
| Offices/Contact | 100% | Strong pattern matching |
| Apply for Help | 94.7% | Minor typo handling gaps |
| Multi-intent | 95.8% | Gate handles well |
| Forms Finder | 90.9% | Good |
| Donations | 90.0% | Good |
| Guides Finder | 90.0% | Good |
| Feedback | 90.0% | Good |
| Legal Advice Line | 83.3% | Confusion with apply |
| Senior Risk Detector | 85.7% | Good |
| Out of Scope | 75.0% | Some missed |
| Services Overview | 54.5% | Needs improvement |
| Adversarial | 53.8% | Needs policy filter enhancement |

### Safety Suite (33 cases)

| Metric | Value |
|--------|-------|
| Total Tests | 33 |
| Passed | 33 |
| Failed | 0 |
| **Accuracy** | **100%** |

All safety scenarios correctly hard-routed with `SAFETY_URGENT` reason code.

### Confusable Intents Suite (34 cases)

| Metric | Value |
|--------|-------|
| Total Tests | 34 |
| Passed | 26 |
| Failed | 8 |
| **Accuracy** | **76.5%** |

#### Analysis

The confusable suite intentionally tests edge cases where clarification is expected. Current behavior routes based on detected patterns rather than asking for clarification.

**Failure Pattern:**
- Single-word queries ("help", "forms", "phone") match patterns directly
- Expected: Ask clarification
- Actual: Route to detected intent

**Tuning Recommendation:**
Increase `short_message_words` threshold from 3 to 4-5 to trigger more clarification for very short, ambiguous queries.

## Reason Code Distribution

| Reason Code | Count | Description |
|-------------|-------|-------------|
| HIGH_CONF_INTENT | 86 | Confident pattern match |
| LOW_INTENT_CONF | 55 | Low confidence, fallback/clarify |
| SAFETY_URGENT | 33 | Safety override |
| BORDERLINE_CONF | 11 | Medium confidence |

## Before/After Comparison

### Before: Rule-based Router Only

| Metric | Value |
|--------|-------|
| Unknown Intent Rate | ~25% |
| Blind LLM Fallback | All unknowns |
| Safety Detection | Implicit in patterns |
| Misroute Tracking | None |
| Confidence Scoring | None |

### After: FallbackGate

| Metric | Value | Change |
|--------|-------|--------|
| Unknown Intent Rate | 0% | Gate handles all |
| LLM Fallback Rate | 23.2% | Targeted |
| Clarify Rate | 6.5% | New capability |
| Safety Hard Route | 17.8% | Explicit |
| Misroute Rate | 15.1% | Now measurable |
| Avg Confidence | 67.2% | Now measurable |

## Threshold Configuration

Current thresholds in `ilas_site_assistant.settings.yml`:

```yaml
fallback_gate:
  thresholds:
    intent_high_conf: 0.85
    intent_medium_conf: 0.65
    intent_low_conf: 0.40
    retrieval_high_score: 0.75
    retrieval_medium_score: 0.50
    retrieval_low_score: 0.30
    retrieval_score_gap_high: 0.25
    retrieval_score_gap_low: 0.10
    combined_high_conf: 0.80
    combined_fallback_threshold: 0.50
    short_message_words: 3
    ambiguous_message_words: 2
```

## Recommendations

### 1. Improve Services Overview Detection
- Add more patterns for service-related queries
- Consider topical keywords (housing, family, etc.)

### 2. Enhance Adversarial Handling
- Strengthen PolicyFilter patterns
- Add more prompt injection detection

### 3. Tune Short Message Handling
- Increase `ambiguous_message_words` to 3
- More aggressive clarification for 1-2 word queries

### 4. Add Retrieval Scoring
- Integrate actual Search API scores into gate evaluation
- Will improve retrieval confidence accuracy

## Monitoring

Track these metrics over time:

1. **Fallback Rate** - Should stay <30%
2. **Misroute Rate** - Should decrease as patterns improve
3. **Clarify Rate** - Should be 5-15% (not too high, not too low)
4. **Safety Routing** - Must stay 100%

## Current Test Commands

```bash
# API/session/security smoke check
ASSISTANT_BASE_URL=https://ilas-pantheon.ddev.site npm run test:assistant:smoke

# Promptfoo answer-quality gate
npm run test:promptfoo:runtime

# FallbackGate unit coverage
vendor/bin/phpunit web/modules/custom/ilas_site_assistant/tests/src/Unit/FallbackGateTest.php

# Promptfoo assertion lint
node promptfoo-evals/scripts/lint-javascript-assertions.mjs
```

Legacy `scripts/chatbot-eval/run-gate-eval.php` reports are historical fixture
material only. They are not active Site Assistant quality gates and do not
replace Promptfoo, smoke, PHPUnit/functional, or Playwright coverage.

---

*Report generated by FallbackGate evaluation harness*
