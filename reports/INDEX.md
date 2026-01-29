# ILAS Chatbot Evaluation — Artifact Registry

> **Canonical index** of all evaluation artifacts, test fixtures, reports, and specs.
> See `reports/manifest.json` for machine-readable version.

---

## How to Register New Outputs

Every evaluation prompt **must** register its outputs here. Follow this protocol:

### 1. Output Location
All timestamped outputs go to `scripts/chatbot-eval/reports/` using the naming convention:
```
<type>-<YYYY-MM-DD_HHMMSS>.{json,md,xml}
```
Examples: `chatbot-report-2026-01-23_234325.json`, `retrieval-results-2026-01-27_162826.json`

### 2. Update Symlinks
After each run, update the `*-latest.*` symlinks:
```bash
cd scripts/chatbot-eval/reports
ln -sf chatbot-report-2026-01-28_120000.json chatbot-report-latest.json
ln -sf chatbot-report-2026-01-28_120000.md   chatbot-report-latest.md
```

### 3. Register in This File
Add a row to the appropriate table below with: date, run ID, key metrics, and notes.

### 4. Regenerate Inventory
```bash
# From repo root — regenerate the full inventory
python3 -c "
import json, os, datetime

artifacts = []
for root, dirs, files in os.walk('.'):
    # Skip vendor/node_modules/core/contrib/.git
    dirs[:] = [d for d in dirs if d not in ('vendor', 'node_modules', '.git', 'core', 'contrib')]
    for f in files:
        path = os.path.join(root, f)[2:]  # strip ./
        if any(kw in f.lower() for kw in ('golden','eval','harness','confusion','confusab','metrics','report',
            'audit','ranking','safety','regression','fixture','coverage','fallback','retrieval','gate-eval',
            'compliance','intent','keyword','backlog','stub','baseline','improvement')):
            if any(path.startswith(p) for p in ('scripts/chatbot-eval','web/modules/custom/ilas_site_assistant',
                'button-audit','reports','chatbot-golden','PERFORMANCE')):
                stat = os.stat(path)
                artifacts.append({'path': path, 'size_bytes': stat.st_size,
                    'modified': datetime.datetime.fromtimestamp(stat.st_mtime).isoformat()})

with open('reports/_inventory/inventory.json', 'r') as f:
    inv = json.load(f)

inv['generated'] = datetime.datetime.now().isoformat()
inv['total_artifacts'] = len(artifacts)
print(f'Found {len(artifacts)} artifacts')

# To do a full refresh, replace inv['artifacts'] and re-annotate manually
# For now, just validate counts match
existing_paths = {a['path'] for a in inv['artifacts']}
new_paths = {a['path'] for a in artifacts}
added = new_paths - existing_paths
if added:
    print(f'NEW artifacts not in inventory: {added}')
else:
    print('Inventory is up to date')
"
```

Or use this one-liner to just list unregistered files:
```bash
find scripts/chatbot-eval web/modules/custom/ilas_site_assistant/tests \
  -type f \( -name '*.json' -o -name '*.md' -o -name '*.csv' -o -name '*.xml' -o -name '*.txt' -o -name '*.yml' \) \
  -newer reports/_inventory/inventory.json
```

---

## Quick Reference — Latest Results

| Area | Key Metric | Value | Date | Report |
|------|-----------|-------|------|--------|
| Intent (API eval) | Pass rate | 27.6% (51/185) | 2026-01-23 | `chatbot-report-latest.json` |
| Intent (harness) | Match rate | 96.7% (174/180) | 2026-01-23 | `harness_report.json` |
| Retrieval | Recall@1 | 50.7% | 2026-01-27 | `retrieval-report-latest.md` |
| Retrieval | MRR | 0.537 | 2026-01-27 | `retrieval-results-latest.json` |
| Safety (gate) | Pass rate | 100% (33/33) | 2026-01-23 | `gate-eval-safety-*.json` |
| Safety (stress) | Compliance | 94.12% (112/119) | 2026-01-23 | `safety_compliance_*_234325.txt` |
| Fallback gate | Golden accuracy | 87.6% (162/185) | 2026-01-23 | `gate-eval-golden-*.json` |
| Fallback gate | Confusable accuracy | 76.5% (26/34) | 2026-01-23 | `gate-eval-confusable-*.json` |
| Coverage | Intent coverage | 78% (25/32 intents) | 2026-01-23 | `coverage-gap-analysis.md` |

---

## Intent Routing Eval Runs

| Run ID | Date | Cases | Pass | Fail | Err | Pass% | Notes |
|--------|------|-------|------|------|-----|-------|-------|
| `232317` | 2026-01-23 | 30 | 0 | 0 | 30 | — | API connectivity errors |
| `232456` | 2026-01-23 | 50 | 4 | 46 | 0 | 8.0% | Partial run |
| `232521` | 2026-01-23 | 185 | 59 | 126 | 0 | **31.9%** | Baseline (full) |
| `233306` | 2026-01-23 | 30 | 0 | 0 | 30 | — | API errors |
| `233627` | 2026-01-23 | 185 | 49 | 136 | 0 | 26.5% | Post-routing (regression) |
| `234325` | 2026-01-23 | 185 | 51 | 134 | 0 | **27.6%** | Final with all fixes |

**Reproduce:** `ddev drush scr scripts/chatbot-eval/run-eval.php -- --format=all`

---

## Retrieval Eval Runs

| Run ID | Date | Fixture | Cases | Recall@1 | MRR | Notes |
|--------|------|---------|-------|----------|-----|-------|
| `234050` | 2026-01-23 | v1 | small | — | — | Early/partial |
| `234407` | 2026-01-23 | v1 | 165 | — | — | Full v1 baseline |
| `162615` | 2026-01-27 | v2 | 73 | — | — | Pre-final tuning |
| `162826` | 2026-01-27 | v2 | 73 | **50.7%** | **0.537** | Post-improvements |

**Reproduce:** `ddev drush scr scripts/chatbot-eval/run-retrieval-eval.php`

---

## Safety Compliance Runs

| Run ID | Date | Cases | Pass | Rate | Notes |
|--------|------|-------|------|------|-------|
| `233854` | 2026-01-23 | 119 | — | — | First pass |
| `234257` | 2026-01-23 | 119 | — | — | After classifier tuning |
| `234325` | 2026-01-23 | 119 | 112 | **94.12%** | Final — 7 violations |

**Reproduce:** `ddev drush scr web/modules/custom/ilas_site_assistant/tests/run_safety_tests.php`

---

## Fallback Gate Eval Runs

| Suite | Date | Cases | Pass | Rate |
|-------|------|-------|------|------|
| Golden | 2026-01-23 | 185 | 162 | 87.6% |
| Confusable | 2026-01-23 | 34 | 26 | 76.5% |
| Safety | 2026-01-23 | 33 | 33 | 100% |

**Reproduce:** `ddev drush scr scripts/chatbot-eval/run-gate-eval.php`

---

## Fixture Registry

| Fixture | Path | Cases | Version | Notes |
|---------|------|-------|---------|-------|
| Golden dataset | `chatbot-golden-dataset.csv` | 185 | v1 | Master utterance set |
| Intent test cases | `web/.../tests/fixtures/intent_test_cases.json` | 180 | v1 | Harness unit tests |
| Confusable intents | `web/.../tests/fixtures/confusable-intents-suite.csv` | 34 | v1 | Ambiguous pairs |
| Safety suite | `web/.../tests/fixtures/safety-suite.csv` | 33 | v1 | Gate safety tests |
| Safety stress | `web/.../tests/fixtures/safety_stress_test_suite.yml` | 120 | v1 | 7-category stress |
| Retrieval v1 | `scripts/chatbot-eval/retrieval-fixture.json` | 165 | v1 | Original, some bad URLs |
| Retrieval v2 | `scripts/chatbot-eval/retrieval-fixture-v2.json` | 73 | v2 | Verified URLs |

---

## Specs & Analysis Docs

| Document | Path | Category |
|----------|------|----------|
| Decision Tree Spec | `web/.../docs/DECISION_TREE_SPEC.md` | Intent routing |
| Routing Improvements | `web/.../ROUTING_IMPROVEMENTS.md` | Intent routing |
| Fallback Gate Spec | `web/.../docs/FALLBACK_GATE_SPEC.md` | Fallback tuning |
| Gate Metrics Report | `web/.../docs/METRICS_REPORT.md` | Fallback tuning |
| Safety Hardening Report | `web/.../docs/SAFETY_HARDENING_REPORT.md` | Safety |
| Keyword Audit Report | `scripts/chatbot-eval/KEYWORD_AUDIT_REPORT.md` | Keyword audit |
| Coverage Gap Final | `scripts/chatbot-eval/COVERAGE-GAP-REPORT-FINAL.md` | Coverage gaps |
| Coverage Gap Analysis | `scripts/chatbot-eval/coverage-gap-analysis.md` | Coverage gaps |
| Content Backlog | `scripts/chatbot-eval/content-backlog.json` | Coverage gaps |
| Retrieval Metrics | `scripts/chatbot-eval/reports/RETRIEVAL-METRICS-REPORT.md` | Retrieval ranking |
| Performance Audit | `PERFORMANCE_AUDIT.md` | Drupal perf |

---

## Consolidation Status

Artifacts are currently scattered across 4 top-level locations. See `reports/_inventory/consolidation-plan.md` for the proposed move plan.

---

## What We've Learned So Far

### Top Recurring Failure Modes

1. **Action routing mismatches (biggest gap):** The deterministic harness routes intents at 96.7% accuracy, but the full API eval only passes 27.6%. The gap is in *action URL generation* — the system detects the right intent but returns FAQ text instead of the expected URL (e.g., `apply_for_help` returns FAQ content instead of `/apply-for-help`).

2. **Single-word topic queries fall through:** Queries like "divorce", "custody", "child support" route to `greeting` or `fallback` instead of topic pages (`/legal-help/family`). The keyword system needs exact matches and single tokens don't trigger phrase detection.

3. **Navigation queries have low retrieval (37% R@1):** Queries asking "where do I find X" or "show me the page about Y" don't match well because FAQ content is answer-oriented, not navigation-oriented. Navigation intents need a separate retrieval path.

4. **Consumer/debt queries underperform (25% R@1):** The consumer law coverage is thin — no dedicated FAQ content for debt collection, garnishment, or consumer protection.

5. **Typos and abbreviations break retrieval (0% R@1):** Despite synonym expansion, typo queries ("custdy", "divorse") and abbreviations ("DV", "POA") don't expand enough to find correct content.

6. **Confusable intent pairs (76.5% gate accuracy):** Ambiguous queries between `apply_for_help` vs `offices_contact`, or `forms_finder` vs `guides_finder`, still confuse the gate. 8/34 confusable test cases fail.

7. **Safety edge cases (5.88% failure rate):** 7 of 119 safety stress tests fail. Key violations: "Predict whether the landlord will win" classified as `legal_advice` instead of `dv_emergency`; some scam/identity theft scenarios miss escalation.

### Best-Performing Fixes

1. **Deterministic phrase routing (+53.5% accuracy):** Adding phrase detection rules (`config/routing/phrases.yml`) took harness accuracy from 40.7% to 94.2%. This was the single highest-impact change.

2. **Negative keyword filters (-92 misroutes):** Adding `negatives.yml` to block confusable cross-intent matches (e.g., "donate" blocking `apply_for_help`) eliminated 92 misroutes.

3. **Synonym expansion for retrieval:** Adding canonical synonyms improved Spanish queries (75% R@1) and multi-word legal terms.

4. **Safety classifier (100% gate pass, 94.12% stress):** The deterministic SafetyClassifier with 15 priority-ordered classification levels and 100+ regex patterns achieves perfect pass rate on the 33-case gate suite and near-target on the 120-prompt stress test.

5. **Eligibility queries (100% R@1):** Well-defined eligibility patterns with clear canonical content.

6. **Fallback gate reason codes:** Every decision now includes a `reason_code`, making debugging straightforward.

### What Is Still Untested

1. **End-to-end API eval after routing + retrieval improvements:** The last full API eval (run `234325`) was before retrieval ranking improvements. Need a fresh `run-eval.php` run to see if the 27.6% pass rate has improved.

2. **Employment/workplace intent:** Phrases were added to `phrases.yml` but no eval run has tested them against the golden dataset yet.

3. **Veterans, utilities, mobile home, name change, tribal topics:** KB stubs exist (`top5-gap-faq-stubs.yml`) but haven't been imported into Drupal or tested.

4. **Abbreviation/acronym handling:** 0% R@1 on abbreviations — no fix has been attempted yet.

5. **Out-of-scope rejection:** 0% R@1 on out-of-scope queries — the system attempts to answer instead of gracefully declining.

6. **Hard difficulty queries:** Only 14.3% R@1 on hard queries (multi-hop, vague, complex). No specific fix attempted.

7. **Multi-language full eval:** Spanish queries work at 75% R@1 in retrieval, but no dedicated intent routing eval for Spanish-only or Spanglish flows.

8. **Regression testing after config changes:** No CI pipeline runs the eval harness automatically. Changes to `phrases.yml`, `synonyms.yml`, or `negatives.yml` can silently regress performance.

9. **Load/latency testing:** No performance benchmarks for the chatbot API endpoint under concurrent requests.
