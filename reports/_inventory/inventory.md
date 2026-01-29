# Evaluation Artifact Inventory

**Generated:** 2026-01-27
**Total artifacts found:** 56 files across 7 locations
**Scan method:** `git ls-files --others`, `find`, filename keyword matching, content grep

---

## Summary by Location

| Location | Count | Types |
|----------|-------|-------|
| `scripts/chatbot-eval/` | 8 | fixtures, reports, analysis docs |
| `scripts/chatbot-eval/reports/` | 28 | timestamped run outputs (JSON, MD, XML) |
| `web/modules/custom/ilas_site_assistant/docs/` | 4 | specs, metric reports |
| `web/modules/custom/ilas_site_assistant/tests/fixtures/` | 4 | test suites (CSV, JSON, YML) |
| `web/modules/custom/ilas_site_assistant/tests/reports/` | 4 | harness + safety compliance |
| `web/modules/custom/ilas_site_assistant/config/` | 4 | routing configs, KB stubs |
| Root / other | 4 | golden dataset, PERFORMANCE_AUDIT, button-audit |

---

## Artifacts by Prompt Category

### 1. Intent Routing (18 artifacts)

**Fixtures:**
- `chatbot-golden-dataset.csv` — Master golden dataset, 185 utterances with intent labels + expected actions
- `web/.../tests/fixtures/intent_test_cases.json` — Unit test cases for IntentRouter
- `web/.../tests/fixtures/confusable-intents-suite.csv` — Ambiguous intent pair test suite

**Reports (timestamped eval runs):**
| Run ID | Cases | Pass | Fail | Err | Notes |
|--------|-------|------|------|-----|-------|
| `232317` | 30 | 0 | 0 | 30 | API errors — throwaway |
| `232456` | 50 | 4 | 46 | 0 | Partial run, early baseline |
| `232521` | 185 | 59 | 126 | 0 | **Baseline: 31.9%** |
| `233306` | 30 | 0 | 0 | 30 | API errors — throwaway |
| `233627` | 185 | 49 | 136 | 0 | Post-routing, 26.5% (regression due to format changes) |
| `234325` | 185 | 51 | 134 | 0 | **Final: 27.6%** with all improvements |

Each run produces: `.json` (structured), `.md` (narrative), `.xml` (JUnit CI)

**Harness (deterministic, no API):**
- `web/.../tests/reports/harness_report.json` — 180 cases, **96.7% match rate**, 6 misroutes

**Analysis docs:**
- `web/.../ROUTING_IMPROVEMENTS.md` — Before/after: 40.7% → 94.2% on harness
- `web/.../docs/DECISION_TREE_SPEC.md` — 12 intents, disambiguation rules, safety fast-paths

**Config:**
- `web/.../config/routing/phrases.yml` — Phrase detection rules
- `web/.../config/routing/negatives.yml` — Negative keyword filters

---

### 2. Keyword Audit (2 artifacts)

- `scripts/chatbot-eval/KEYWORD_AUDIT_REPORT.md` — Phrase detection, synonym mapping, typo handling, Spanish support. Baseline 31.9%
- `web/.../config/routing/synonyms.yml` — English + Spanish synonym mappings

---

### 3. Retrieval Ranking (12 artifacts)

**Fixtures:**
- `scripts/chatbot-eval/retrieval-fixture.json` — v1, 165 cases
- `scripts/chatbot-eval/retrieval-fixture-v2.json` — v2, 73 cases (verified URLs)

**Timestamped eval runs:**
| Run ID | Fixture | Cases | Recall@1 | MRR | Notes |
|--------|---------|-------|----------|-----|-------|
| `234050` (Jan 23) | v1 | small | — | — | Early/partial |
| `234407` (Jan 23) | v1 | 165 | — | — | Full v1 baseline |
| `162615` (Jan 27) | v2 | 73 | — | — | Pre-final tuning |
| `162826` (Jan 27) | v2 | 73 | **50.7%** | **0.537** | Post-improvements |

Each produces: `-results-*.json` + `-report-*.md`

**Baseline/comparison snapshots:**
- `scripts/chatbot-eval/reports/baseline-before-improvements.json` — 132 KB baseline snapshot
- `scripts/chatbot-eval/reports/post-improvements-results.json` — 54 KB post-fix snapshot

**Summary report:**
- `scripts/chatbot-eval/reports/RETRIEVAL-METRICS-REPORT.md` — Recall@1=50.7%, MRR=0.537, nDCG@5=0.547

---

### 4. Safety (8 artifacts)

**Fixtures:**
- `web/.../tests/fixtures/safety-suite.csv` — Safety classification test cases
- `web/.../tests/fixtures/safety_stress_test_suite.yml` — 120-prompt, 7-category stress suite

**Reports:**
- `scripts/chatbot-eval/reports/gate-eval-safety-2026-01-23_233718.json` — Gate eval: **100% pass** (33/33)
- `web/.../tests/reports/safety_compliance_2026-01-23_233854.txt` — First compliance run
- `web/.../tests/reports/safety_compliance_2026-01-23_234257.txt` — After classifier tuning
- `web/.../tests/reports/safety_compliance_2026-01-23_234325.txt` — **Final: 94.12%** (112/119)

**Specs/docs:**
- `web/.../docs/SAFETY_HARDENING_REPORT.md` — 7 categories, SafetyClassifier service
- `web/.../docs/FALLBACK_GATE_SPEC.md` (shared with fallback tuning)

---

### 5. Fallback Tuning (4 artifacts)

- `scripts/chatbot-eval/reports/gate-eval-golden-2026-01-23_233700.json` — 185 cases, **87.6% accuracy**
- `scripts/chatbot-eval/reports/gate-eval-confusable-2026-01-23_233731.json` — 34 cases, 76.5%
- `web/.../docs/FALLBACK_GATE_SPEC.md` — ANSWER/CLARIFY/FALLBACK_LLM/HARD_ROUTE decisions
- `web/.../docs/METRICS_REPORT.md` — 100% safety routing, 87.6% overall, reason codes

---

### 6. Coverage Gaps (4 artifacts)

- `scripts/chatbot-eval/coverage-gap-analysis.json` — Structured: 11 gaps
- `scripts/chatbot-eval/coverage-gap-analysis.md` — Narrative: 78% intent coverage
- `scripts/chatbot-eval/COVERAGE-GAP-REPORT-FINAL.md` — Final summary: 26.5% pass baseline
- `scripts/chatbot-eval/content-backlog.json` — Prioritized backlog of FAQ stubs needed
- `web/.../config/kb-stubs/top5-gap-faq-stubs.yml` — FAQ stubs for top 5 gaps

---

### 7. Other Audits (4 artifacts)

- `PERFORMANCE_AUDIT.md` — Drupal cache/aggregation audit (2025-11-17, pre-chatbot)
- `button-audit/button-inventory-raw.json` — Playwright button scan (854 KB)
- `button-audit/button-inventory-summary.json` — Button summary (95 KB)

---

## Symlinks (latest pointers)

| Symlink | Target |
|---------|--------|
| `chatbot-junit-latest.xml` | `chatbot-junit-2026-01-23_234325.xml` |
| `chatbot-report-latest.json` | `chatbot-report-2026-01-23_234325.json` |
| `chatbot-report-latest.md` | `chatbot-report-2026-01-23_234325.md` |
| `retrieval-report-latest.md` | `retrieval-report-2026-01-27_162826.md` |
| `retrieval-results-latest.json` | `retrieval-results-2026-01-27_162826.json` |
