# Artifact Consolidation Plan

**Status:** PROPOSED — review before executing
**Date:** 2026-01-27

---

## Current State

Artifacts live in 4 scattered locations:
1. **Repo root** — `chatbot-golden-dataset.csv`, `PERFORMANCE_AUDIT.md`
2. **`scripts/chatbot-eval/`** — eval harness code + analysis docs + reports/
3. **`web/modules/custom/ilas_site_assistant/`** — docs/, tests/fixtures/, tests/reports/, config/
4. **`button-audit/`** — UI audit outputs

## Proposed Structure

```
reports/
├── INDEX.md                          # ← already created
├── manifest.json                     # ← already created
├── _inventory/                       # ← already created
│   ├── inventory.json
│   ├── inventory.md
│   └── consolidation-plan.md         # this file
├── 2026-01-23/
│   ├── intent-eval/                  # all chatbot-report-* and chatbot-junit-* files
│   ├── retrieval-eval/               # retrieval-results-* and retrieval-report-*
│   ├── gate-eval/                    # gate-eval-* files
│   ├── safety-compliance/            # safety_compliance_* files
│   └── analysis/                     # coverage-gap, keyword audit, routing improvements
└── 2026-01-27/
    └── retrieval-eval/               # Jan 27 retrieval runs
```

## Mapping Table: old_path → new_path

### Low-risk moves (pure outputs, safe to git mv)

| Old Path | New Path | Risk |
|----------|----------|------|
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_232317.json` | `reports/2026-01-23/intent-eval/chatbot-report-232317.json` | LOW |
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_232317.md` | `reports/2026-01-23/intent-eval/chatbot-report-232317.md` | LOW |
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_232456.json` | `reports/2026-01-23/intent-eval/chatbot-report-232456.json` | LOW |
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_232456.md` | `reports/2026-01-23/intent-eval/chatbot-report-232456.md` | LOW |
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_232521.json` | `reports/2026-01-23/intent-eval/chatbot-report-232521.json` | LOW |
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_232521.md` | `reports/2026-01-23/intent-eval/chatbot-report-232521.md` | LOW |
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_233306.json` | `reports/2026-01-23/intent-eval/chatbot-report-233306.json` | LOW |
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_233306.md` | `reports/2026-01-23/intent-eval/chatbot-report-233306.md` | LOW |
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_233627.json` | `reports/2026-01-23/intent-eval/chatbot-report-233627.json` | LOW |
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_233627.md` | `reports/2026-01-23/intent-eval/chatbot-report-233627.md` | LOW |
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_234325.json` | `reports/2026-01-23/intent-eval/chatbot-report-234325.json` | LOW |
| `scripts/chatbot-eval/reports/chatbot-report-2026-01-23_234325.md` | `reports/2026-01-23/intent-eval/chatbot-report-234325.md` | LOW |
| `scripts/chatbot-eval/reports/chatbot-junit-2026-01-23_232317.xml` | `reports/2026-01-23/intent-eval/chatbot-junit-232317.xml` | LOW |
| `scripts/chatbot-eval/reports/chatbot-junit-2026-01-23_232456.xml` | `reports/2026-01-23/intent-eval/chatbot-junit-232456.xml` | LOW |
| `scripts/chatbot-eval/reports/chatbot-junit-2026-01-23_232521.xml` | `reports/2026-01-23/intent-eval/chatbot-junit-232521.xml` | LOW |
| `scripts/chatbot-eval/reports/chatbot-junit-2026-01-23_233306.xml` | `reports/2026-01-23/intent-eval/chatbot-junit-233306.xml` | LOW |
| `scripts/chatbot-eval/reports/chatbot-junit-2026-01-23_233627.xml` | `reports/2026-01-23/intent-eval/chatbot-junit-233627.xml` | LOW |
| `scripts/chatbot-eval/reports/chatbot-junit-2026-01-23_234325.xml` | `reports/2026-01-23/intent-eval/chatbot-junit-234325.xml` | LOW |
| `scripts/chatbot-eval/reports/gate-eval-golden-2026-01-23_233700.json` | `reports/2026-01-23/gate-eval/gate-eval-golden-233700.json` | LOW |
| `scripts/chatbot-eval/reports/gate-eval-confusable-2026-01-23_233731.json` | `reports/2026-01-23/gate-eval/gate-eval-confusable-233731.json` | LOW |
| `scripts/chatbot-eval/reports/gate-eval-safety-2026-01-23_233718.json` | `reports/2026-01-23/gate-eval/gate-eval-safety-233718.json` | LOW |
| `scripts/chatbot-eval/reports/retrieval-results-2026-01-23_234050.json` | `reports/2026-01-23/retrieval-eval/retrieval-results-234050.json` | LOW |
| `scripts/chatbot-eval/reports/retrieval-report-2026-01-23_234050.md` | `reports/2026-01-23/retrieval-eval/retrieval-report-234050.md` | LOW |
| `scripts/chatbot-eval/reports/retrieval-results-2026-01-23_234407.json` | `reports/2026-01-23/retrieval-eval/retrieval-results-234407.json` | LOW |
| `scripts/chatbot-eval/reports/retrieval-report-2026-01-23_234407.md` | `reports/2026-01-23/retrieval-eval/retrieval-report-234407.md` | LOW |
| `scripts/chatbot-eval/reports/baseline-before-improvements.json` | `reports/2026-01-23/retrieval-eval/baseline-before-improvements.json` | LOW |
| `scripts/chatbot-eval/reports/retrieval-results-2026-01-27_162615.json` | `reports/2026-01-27/retrieval-eval/retrieval-results-162615.json` | LOW |
| `scripts/chatbot-eval/reports/retrieval-report-2026-01-27_162615.md` | `reports/2026-01-27/retrieval-eval/retrieval-report-162615.md` | LOW |
| `scripts/chatbot-eval/reports/retrieval-results-2026-01-27_162826.json` | `reports/2026-01-27/retrieval-eval/retrieval-results-162826.json` | LOW |
| `scripts/chatbot-eval/reports/retrieval-report-2026-01-27_162826.md` | `reports/2026-01-27/retrieval-eval/retrieval-report-162826.md` | LOW |
| `scripts/chatbot-eval/reports/post-improvements-results.json` | `reports/2026-01-27/retrieval-eval/post-improvements-results.json` | LOW |

### Medium-risk moves (referenced by code or other docs)

| Old Path | New Path | Risk | Reason |
|----------|----------|------|--------|
| `scripts/chatbot-eval/COVERAGE-GAP-REPORT-FINAL.md` | `reports/2026-01-23/analysis/COVERAGE-GAP-REPORT-FINAL.md` | MEDIUM | May be referenced in README |
| `scripts/chatbot-eval/KEYWORD_AUDIT_REPORT.md` | `reports/2026-01-23/analysis/KEYWORD_AUDIT_REPORT.md` | MEDIUM | May be referenced |
| `scripts/chatbot-eval/coverage-gap-analysis.json` | `reports/2026-01-23/analysis/coverage-gap-analysis.json` | MEDIUM | May be loaded by code |
| `scripts/chatbot-eval/coverage-gap-analysis.md` | `reports/2026-01-23/analysis/coverage-gap-analysis.md` | MEDIUM | May be referenced |
| `scripts/chatbot-eval/content-backlog.json` | `reports/2026-01-23/analysis/content-backlog.json` | MEDIUM | May be loaded by code |
| `scripts/chatbot-eval/reports/RETRIEVAL-METRICS-REPORT.md` | `reports/2026-01-27/retrieval-eval/RETRIEVAL-METRICS-REPORT.md` | MEDIUM | May be referenced |

### DO NOT MOVE (functional code, fixtures, configs)

These stay where they are — they're consumed by running code:
- `chatbot-golden-dataset.csv` — loaded by FixtureLoader.php
- `scripts/chatbot-eval/*.php` — eval harness entry points
- `scripts/chatbot-eval/retrieval-fixture*.json` — loaded by RetrievalEvaluator.php
- `web/.../tests/fixtures/*` — loaded by test harnesses
- `web/.../config/routing/*` — loaded by Drupal services
- `web/.../config/kb-stubs/*` — loaded by Drupal
- `web/.../docs/*` — spec docs co-located with code (appropriate)
- `PERFORMANCE_AUDIT.md` — standalone, pre-chatbot
- `button-audit/*` — self-contained tool

## Implementation

To execute the low-risk moves:

```bash
# Create target dirs
mkdir -p reports/2026-01-23/{intent-eval,retrieval-eval,gate-eval,safety-compliance,analysis}
mkdir -p reports/2026-01-27/retrieval-eval

# Move timestamped reports (low-risk, pure outputs)
# Intent eval
for f in scripts/chatbot-eval/reports/chatbot-{report,junit}-2026-01-23_*.{json,md,xml}; do
  base=$(basename "$f" | sed 's/2026-01-23_//')
  git mv "$f" "reports/2026-01-23/intent-eval/$base"
done

# Gate eval
for f in scripts/chatbot-eval/reports/gate-eval-*-2026-01-23_*.json; do
  base=$(basename "$f" | sed 's/2026-01-23_//')
  git mv "$f" "reports/2026-01-23/gate-eval/$base"
done

# Retrieval (Jan 23)
for f in scripts/chatbot-eval/reports/retrieval-{results,report}-2026-01-23_*.{json,md}; do
  base=$(basename "$f" | sed 's/2026-01-23_//')
  git mv "$f" "reports/2026-01-23/retrieval-eval/$base"
done
git mv scripts/chatbot-eval/reports/baseline-before-improvements.json reports/2026-01-23/retrieval-eval/

# Retrieval (Jan 27)
for f in scripts/chatbot-eval/reports/retrieval-{results,report}-2026-01-27_*.{json,md}; do
  base=$(basename "$f" | sed 's/2026-01-27_//')
  git mv "$f" "reports/2026-01-27/retrieval-eval/$base"
done
git mv scripts/chatbot-eval/reports/post-improvements-results.json reports/2026-01-27/retrieval-eval/

# Update symlinks
cd scripts/chatbot-eval/reports
rm -f chatbot-report-latest.json chatbot-report-latest.md chatbot-junit-latest.xml
rm -f retrieval-report-latest.md retrieval-results-latest.json
# Recreate pointing to new locations (or remove since reports/INDEX.md now serves this purpose)

# Update ReportGenerator.php output_dir if needed
```

## Recommendation

**Do the low-risk moves first** (31 files). These are pure outputs that nothing references programmatically. Leave the medium-risk moves for a second pass after verifying no code references them.
