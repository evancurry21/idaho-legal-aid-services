---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: Executing Phase 03
last_updated: "2026-05-14T18:12:30.163Z"
last_activity: 2026-05-14
progress:
  total_phases: 10
  completed_phases: 3
  total_plans: 20
  completed_plans: 17
  percent: 85
---

# State: Idaho Legal Aid Services — Codebase Maintenance & Quality Sweep

**Last updated:** 2026-05-08 (Phase 3 closed — SchemaPropertiesTest + seo-functional-gate CI job + verify-schema.sh pointer shipped via PR #95 merge `b96e7e76f`. 4 follow-up commits resolved pre-existing test failures the new gate exposed (FingerprintingTest, HotspotBlockTest install-pass; AssistantMultiTurn household_context overlay — Phase 5 ARCH-01 carry-over). Deployed Pantheon dev → test → live, verifier 7/7/8 passed/0 failed.)

## Project Reference

**Core value:** Visible quality wins (a11y, SEO, performance) reach production without regressing the existing site or introducing visual breakage.

**Milestone:** Brownfield maintenance + quality sweep — 17 v1 requirements across 6 categories (STYLE, SEO, ARCH, A11Y, PERF, TEST), drawn from the May 2026 audit in `.planning/codebase/CONCERNS.md`.

**Stack:** Drupal 11.3.8 + PHP 8.3 + Bootstrap 5 + custom subtheme `b5subtheme`. Pantheon-hosted, MariaDB 10.6, DDEV local. CI: PHPCS + PHPStan L2 + PHPUnit + Playwright/axe + Promptfoo.

**Current focus:** Phase 03.1 — publish-pipeline-audit-hardening

## Current Position

Phase: 03.1 (publish-pipeline-audit-hardening) — EXECUTING
Plan: 7 of 8 complete (08 next)
Last activity: 2026-05-14
| Field          | Value                                                                |
|----------------|----------------------------------------------------------------------|
| Phase          | 03.1 — Publish-Pipeline Audit & Hardening — EXECUTING               |
| Plans          | 03.1-07 ✓ PIPE-06 dual-remote drift detection consolidation (describe_remote_status 4-col, dual-remote-invariant.sh harness) |
| Commits        | 8663cdfd5 (common.sh), c05d3a210 (sync-check), 22b164331 (pre-commit), b45fb3bf4 (harness) |
| Next action    | 03.1-08 — four-path acceptance + todo closure (PIPE-07/08/09/10) [checkpoint] |

## SEO-01 follow-up (deferred work)

Plan 02-01 was deferred because empirical observation showed the ES canonical "drop" is a symptom of a deeper bug: ES URLs render English-translated entities into the ES UI shell (`<html lang="en">`, English `<title>`, English body, English canonical, while `drupalSettings.path.currentLanguage="es"`). Branch A/B/C as scoped would only patch the canonical *string* on top of an English-rendered page. The real fix is upstream of metatag token resolution — in entity-translation routing. See `.planning/phases/02-.../02-01-OBSERVATION.md` and `02-01-SUMMARY.md` for full detail and probe recommendations.

## Phase Roster

| #   | Phase                                          | Status      | REQ-IDs                                |
|-----|------------------------------------------------|-------------|----------------------------------------|
| 1   | Quick Wins — A11y + Taxonomy noindex + Dead    | ✓ Complete (4/4 plans) | A11Y-01, A11Y-02, SEO-02, ARCH-03      |
| 2   | SEO Schema Correctness                         | ✓ Complete (4/5 plans, SEO-01 deferred) | ✓ SEO-03; ⊘ SEO-01 |
| 3   | SEO Test Coverage                              | ✓ Complete (3/3 plans + 4 follow-ups, deployed) | ✓ TEST-01 |
| 4   | Employment Security Pipeline Tests             | Not started | TEST-02                                |
| 5   | Controller Refactors                           | Not started | ARCH-01, ARCH-02                       |
| 6   | SCSS Refactor — Top-3 + reduced-motion         | Not started | STYLE-01, STYLE-02                     |
| 7   | Build Pipeline + JS/CSS Performance            | Not started | PERF-02, PERF-03, PERF-04              |
| 8   | Asset Optimization — Hero SVG                  | Not started | PERF-01                                |
| 9   | A11y Test Coverage Extension                   | Not started | TEST-03                                |

## Performance Metrics

| Metric                     | Value          |
|----------------------------|----------------|
| v1 requirements            | 17             |
| Phases                     | 9              |
| Coverage                   | 17/17 (100%)   |
| Plans complete             | 4 (in Phase 1) |
| Phases complete            | 1/9            |
| Open blockers              | 0              |

## Accumulated Context

### Roadmap Evolution

- Phase 3.1 inserted after Phase 3: Publish-Pipeline Audit & Hardening — full-pipeline audit of protected-master push flow (gates, orchestration, dual-remote, SSH lifecycle, two known defects: SIGPIPE on push + deploy-bound promptfoo silent skip) (URGENT)

### Decisions Locked (from PROJECT.md)

- Scope SCSS `!important` to top-3 files only (38/108 instances) — broader sweep deferred to v2.
- Test coverage gaps are inside this milestone, not a separate effort: schema verification (TEST-01) locks SEO-03, employment-pipeline tests (TEST-02) gate the controller split, a11y tests (TEST-03) lock Phase 1 fixes.
- JS minification + library audit + FA subsetting all bundled into one perf phase (Phase 7) for shared build-pipeline work.
- `ilas_site_assistant` behavioral changes deferred — ARCH-01 is architectural-only (route group split, no behavior change).
- Large milestone (6–8 weeks, 9 phases). No deploy freezes.

### Decisions Locked (during roadmap creation)

- Phase 4 (employment security tests) must precede Phase 5 (controller refactors) so the EmploymentApplicationController split has a safety net.
- Phase 9 (a11y test extension) sits at the end so it can lock both Phase 1 a11y fixes and Phase 6 reduced-motion changes.
- PERF-01 (hero SVG) kept as its own phase rather than bundled with Phase 7 build-pipeline work — it's an asset-inspection task, not build-pipeline work.

### Open Todos

- Begin Phase 2 (SEO Schema Correctness) — `/gsd-discuss-phase 2` recommended; Phase 2 is independent of Phase 1.
- (Tooling) Fix `git push origin master` SIGPIPE / `git:finish` failure — see `.planning/todos/pending/2026-05-07-fix-git-push-origin-master-sigpipe-in-pre-push-hook.md`.
- (Tooling) Fail-closed when `ILAS_LIVE_PROVIDER_GATE` is unset on deploy-bound master push — see `.planning/todos/pending/2026-05-07-promptfoo-deploy-bound-gate-silently-auto-skipped.md`.

### Phase 1 — Closed (2026-05-07)

- Plan 01-01: nav a11y landmark relocated (`6aa5a1f87`).
- Plan 01-02: honeypot inputs hardened with `aria-hidden` (`7ada48555`).
- Plan 01-03: `metatag.metatag_defaults.taxonomy_term__tags.yml` created (`6e0dd4459`); deployed dev→test→live and verified `<meta name="robots" content="noindex, follow">` on production.
- Plan 01-04: `ilas_test` module dependencies repaired (`ec9439133`).

### Plan-Checker Advisory Notes (Phase 1)

Verification passed; 5 non-blocking warnings noted. Highest-value to address before execution if you want stronger verify-step guarantees:

- W-2: 01-01 Task 2 verify omits `@todo` removal check on `navigation.html.twig` (could miss real failure)
- W-5: 01-04 Task 2 verify always exits 0 due to `|| echo` fallback in DDEV branch

Other warnings (W-1 prose clarity, W-3 regex order, W-4 line count) are cosmetic.

### Blockers

- None.

### Constraints / Quality Bar

- All changes must pass `quality-gate.yml`: PHPCS (Drupal + DrupalPractice), PHPStan level 2 (baseline must not grow), PHPUnit pure + drupal-unit, Playwright a11y on PR.
- `FaqSchemaTest` (`web/modules/custom/ilas_seo/tests/src/Kernel/FaqSchemaTest.php`) MUST stay green throughout.
- No JSON-LD in Twig — all structured data goes through `ilas_seo.graph_builder`.
- No path-string layout detection in Twig — layout flags come from preprocess hooks.
- New `!important` declarations require an inline comment naming the upstream rule countered.
- Pantheon scaffold exclusions for `web/robots.txt` and `web/.htaccess` must be respected.
- User preference: commit messages do NOT include `Co-Authored-By` lines. Push command is `git push origin master`.

## Codebase Map Reference

Full codebase analysis lives at `.planning/codebase/`:

- `STACK.md`, `STRUCTURE.md`, `ARCHITECTURE.md`, `CONVENTIONS.md`, `TESTING.md`, `INTEGRATIONS.md`, `CONCERNS.md`.

`CONCERNS.md` is the source of truth for the 17 v1 requirements.

## Session Continuity

**To resume:** Phase 1 is complete. Run `/gsd-discuss-phase 2` (or `/gsd-plan-phase 2`) to begin Phase 2 (SEO Schema Correctness — ES canonicals + JSON-LD properties). Phase 2 has no dependency on Phase 1.

**`.planning/` is gitignored** (`commit_docs: false` in `config.json`); planning docs are local-only and do not ship to Pantheon.

---
*State initialized: 2026-05-06 after roadmap creation*
*Phase 1 closed: 2026-05-07 after Pantheon dev→test→live deploy and live noindex verification*
