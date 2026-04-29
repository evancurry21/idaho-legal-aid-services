# ILAS Chatbot Coverage Gap Report - Final Summary

> **Historical legacy report.** This report was produced by deprecated
> `scripts/chatbot-eval` tooling. Use Promptfoo, assistant smoke tests,
> PHPUnit/functional tests, and Playwright for current Site Assistant coverage.

**Generated:** 2026-01-23
**Baseline Evaluation:** 185 test cases from golden dataset

---

## Executive Summary

This report identifies coverage gaps in the ILAS Site Assistant chatbot's knowledge base and routing system, with recommendations for improvement and KB content additions.

### Baseline Performance Metrics

| Metric | Value | Target | Gap |
|--------|-------|--------|-----|
| **Overall Pass Rate** | 26.5% | 80%+ | -53.5% |
| **Intent Accuracy** | 34.1% | 90%+ | -55.9% |
| **Action Accuracy** | 17.3% | 85%+ | -67.7% |
| **Safety Compliance** | 90.9% | 95%+ | -4.1% |
| **Fallback Rate** | 0.5% | <10% | Within target |
| **Misroute Rate** | 58.3% | <15% | -43.3% |

### Performance by Category

| Category | Pass Rate | Issues |
|----------|-----------|--------|
| apply_for_help | 5.3% (1/19) | Action mismatch - routing to FAQ instead of /apply |
| legal_advice_line | 8.3% (1/12) | Action mismatch |
| offices_contact | 0% (0/15) | Complete failure - not routing to offices |
| donations | 0% (0/10) | Not recognized as donate intent |
| feedback_complaints | 50% (5/10) | Partial recognition |
| forms_finder | 0% (0/11) | Not routing to forms |
| guides_finder | 0% (0/10) | Not routing to guides |
| faq | 62.5% (5/8) | Reasonable performance |
| senior_risk_detector | 71.4% (5/7) | Good performance |
| services_overview | 0% (0/11) | Not recognizing services queries |
| out_of_scope | 25% (3/12) | Some criminal/immigration detected |
| high_risk_dv | 0% (0/9) | Critical - DV not triggering safety |
| high_risk_eviction | 83.3% (5/6) | Good urgent detection |
| high_risk_scam | 20% (1/5) | Needs improvement |
| high_risk_deadline | 0% (0/3) | Deadline urgency not detected |
| multi_intent | 79.2% (19/24) | Good handling |
| adversarial | 30.8% (4/13) | Expected lower rate |

---

## 1. Identified Coverage Gaps

### Gap Cluster 1: Employment & Workplace Issues (HIGH PRIORITY)
- **Current Coverage:** None
- **Impact:** 8-12% of expected queries
- **Missing Patterns:** wrongful termination, unpaid wages, fired, laid off
- **Recommendation:** Add `topic_employment` intent, create FAQs

### Gap Cluster 2: Veterans Benefits (MEDIUM-HIGH)
- **Current Coverage:** None
- **Impact:** 3-5% of expected queries
- **Missing Patterns:** VA benefits, veteran legal help, military
- **Recommendation:** Add topic or out-of-scope with referrals

### Gap Cluster 3: Utilities & Basic Needs (MEDIUM)
- **Current Coverage:** Minimal
- **Impact:** 4-6% of expected queries
- **Missing Patterns:** utility shutoff, LIHEAP, power disconnected
- **Recommendation:** Add utility patterns, urgent triggers

### Gap Cluster 4: Mobile/Manufactured Home (MEDIUM)
- **Current Coverage:** None
- **Impact:** 2-4% of expected queries
- **Missing Patterns:** mobile home, trailer park, lot rent
- **Recommendation:** Add patterns, create specific FAQ

### Gap Cluster 5: Name Change (MEDIUM)
- **Current Coverage:** None
- **Impact:** 2-3% of expected queries
- **Missing Patterns:** change my name, legal name change
- **Recommendation:** Add patterns, FAQ, link to forms

### Additional Gaps Identified:
- **Expungement/Record Sealing** - May be blocked by criminal negatives
- **Adult Guardianship (non-senior)** - Only covers seniors
- **Tribal/Native American Issues** - No coverage
- **Education/Special Needs** - No coverage
- **Medical Debt (specific)** - Merged into generic debt

---

## 2. Configuration Changes Made

### A. Phrases Added (config/routing/phrases.yml)

**Employment phrases:**
- wrongful termination, unpaid wages, final paycheck, laid off
- hostile work environment, wage theft, work discrimination
- perdí mi trabajo, no me pagan, despedido injustamente

**Utility phrases:**
- utility shutoff, power disconnected, gas turned off
- energy assistance, can't pay bills
- me cortaron la luz, ayuda con facturas

**Mobile home phrases:**
- mobile home, manufactured home, trailer park, lot rent

**Name change phrases:**
- change my name, legal name change, name change form
- cambiar mi nombre, cambio de nombre

**Expungement phrases:**
- expunge my record, seal my record, clean my record
- borrar mi record, limpiar mi record

**Veterans phrases:**
- veteran benefits, VA benefits, military benefits

**Medical debt phrases:**
- medical bills, hospital debt, medical collection

### B. Synonyms Added (config/routing/synonyms.yml)

**Employment synonyms:**
- fired → terminated, let go, despedido, corrido
- wages → pay, salary, salario, pago
- employer → boss, company, jefe, patron
- wrongful → unfair, illegal, injusto
- discrimination → discriminacion, bias

**Utility synonyms:**
- utility → power, electric, gas, water, luz, electricidad
- shutoff → disconnected, turned off, cortaron
- bill → factura, payment

**Mobile home synonyms:**
- mobile → manufactured, trailer, movil
- park → community, parque
- owner → management, dueno

**Typo corrections added:**
- eviction → evicton, eviciton, evition
- custody → cutsody, custidy, custoy
- divorce → divorec, divorse, divore
- bankruptcy → bankrupcy, bankrupty
- landlord → landord, lanldord

### C. New Triggers Added (config/routing/negatives.yml)

**high_risk_utility:**
- utility shutoff today/tomorrow
- power disconnected today
- no heat, no water

**topic_employment:**
- fired, wrongful termination, unpaid wages
- despedido, no me pagan

**expungement_allow:**
- Allow through criminal negatives for expungement queries

---

## 3. KB Stubs Created

Location: `web/modules/custom/ilas_site_assistant/config/kb-stubs/top5-gap-faq-stubs.yml`

### FAQ Entries Ready for Drupal Import:

1. **What if I was fired unfairly?** (Employment)
2. **How do I collect unpaid wages?** (Employment)
3. **What if my utilities are being shut off?** (Consumer/Utilities)
4. **How do I legally change my name?** (Family)
5. **What are my rights in a mobile home park?** (Housing)
6. **Does Idaho Legal Aid help veterans?** (General)
7. **Can I seal or expunge my criminal record?** (Consumer)

---

## 4. Evaluation Findings & Issues

### Harness Compatibility Issue
The evaluation harness compares expected URL paths (e.g., `/apply-for-help`) against actual response types (e.g., `faq`, `navigation`). This causes systematic failures even when intents are correctly identified.

**Recommendation:** Update the evaluator to:
1. Map response types to canonical URLs, OR
2. Compare intents/types separately from URL routing

### Safety System Performance
- **90.9% safety compliance** - Generally good
- **Critical gap:** high_risk_dv at 0% - domestic violence queries not triggering urgent safety
- **Issue:** DV triggers may not be matching correctly

### Gate Decision Analysis
- **71.5% answer rate** - Most queries get responses
- **19.2% clarify rate** - Disambiguation working
- **58.3% misroute rate** - High false positives in confident answers
- **100% bad answer rate** - All confident answers marked incorrect (may be harness issue)

---

## 5. Prioritized Backlog

### Tier 1: Immediate (Critical Impact)

| Item | Type | Priority |
|------|------|----------|
| Fix high_risk_dv trigger matching | Bug fix | P0 |
| Add employment intent patterns to IntentRouter | Code | P1 |
| Import employment FAQs to Drupal | Content | P1 |
| Fix evaluation harness action comparison | Test | P1 |

### Tier 2: Short-term (1-2 weeks)

| Item | Type | Priority |
|------|------|----------|
| Import utility shutoff FAQ | Content | P2 |
| Import name change FAQ | Content | P2 |
| Import mobile home rights FAQ | Content | P2 |
| Add utility urgent triggers | Code | P2 |
| Add veterans information | Content | P2 |

### Tier 3: Medium-term (2-4 weeks)

| Item | Type | Priority |
|------|------|----------|
| Expungement scope decision & content | Decision | P3 |
| Adult guardianship expansion | Content | P3 |
| Tribal/Native American scope decision | Decision | P3 |
| Medical debt specific FAQ | Content | P3 |

---

## 6. Files Created/Modified

### New Files Created:
```
scripts/chatbot-eval/coverage-gap-analysis.md     # Detailed gap analysis
scripts/chatbot-eval/coverage-gap-analysis.json   # Programmatic gap data
web/modules/custom/ilas_site_assistant/config/kb-stubs/top5-gap-faq-stubs.yml
scripts/chatbot-eval/COVERAGE-GAP-REPORT-FINAL.md # This report
```

### Files Modified:
```
web/modules/custom/ilas_site_assistant/config/routing/phrases.yml
web/modules/custom/ilas_site_assistant/config/routing/synonyms.yml
web/modules/custom/ilas_site_assistant/config/routing/negatives.yml
```

---

## 7. Success Metrics & Targets

After implementing recommendations:

| Metric | Current | Target | Method |
|--------|---------|--------|--------|
| Intent Accuracy | 34.1% | 85%+ | Fix routing + add patterns |
| Action Accuracy | 17.3% | 75%+ | Fix harness + improve routing |
| Safety Compliance | 90.9% | 98%+ | Fix DV triggers |
| Employment Routing | 0% | 90%+ | Add intent + patterns |
| Utility Routing | ~30% | 85%+ | Add patterns + triggers |
| DV Detection | 0% | 95%+ | Fix trigger matching |
| Spanish Support | ~65% | 85%+ | Add synonyms |
| Typo Tolerance | ~70% | 90%+ | Add typo corrections |

---

## 8. Next Steps

1. **Immediate:**
   - Import KB stubs to Drupal as FAQ paragraphs
   - Add employment intent patterns to IntentRouter.php
   - Investigate and fix high_risk_dv trigger failures
   - Clear Drupal caches and reindex Search API

2. **Short-term:**
   - Re-run evaluation after content import
   - Fix evaluation harness action comparison logic
   - Add any missing urgent safety triggers
   - Monitor analytics for new gap patterns

3. **Ongoing:**
   - Review analytics for "no_answer" queries
   - Add new FAQ content based on common queries
   - Expand Spanish language coverage
   - Test with real user queries

---

## Appendix A: Sample Organic Queries (100)

See `coverage-gap-analysis.md` Appendix A for complete list of sample queries across all gap clusters.

## Appendix B: Retrieval Fixture Coverage

The retrieval fixture (`retrieval-fixture.json`) contains 165 test cases covering:
- FAQ retrieval (45 cases)
- Form retrieval (15 cases)
- Guide retrieval (15 cases)
- Navigation (25 cases)
- Collisions/ambiguity (15 cases)
- Spanish queries (10 cases)
- Typos (10 cases)
- Topic queries (10 cases)
- Specific location queries (5 cases)
- Negative/out-of-scope (15 cases)

---

*Report generated by Claude Code Coverage Gap Analysis*
*For questions, contact the development team*
