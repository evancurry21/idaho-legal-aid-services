# Roadmap: Idaho Legal Aid Services — Codebase Maintenance & Quality Sweep

**Created:** 2026-05-06
**Granularity:** Large (6–8 weeks, 8+ phases)
**Coverage:** 17/17 v1 requirements mapped
**Mode:** Brownfield maintenance — no greenfield features

## Core Value

Visible quality wins (a11y, SEO, performance) reach production without regressing the existing site or introducing visual breakage. Every change ships through Pantheon dev → test → live with PR review.

## Phases

- [ ] **Phase 1: Quick Wins — A11y + Taxonomy noindex + Dead module + Hero SVG** — Five small, isolated fixes that ship visible improvements and clear repo dead weight before deeper refactors begin.
- [ ] **Phase 2: SEO Schema Correctness — ES canonicals + JSON-LD properties** — Fix ES canonical URL rendering and ensure `foundingDate` / `areaServed` / `articleSection` actually emit in the JSON-LD `@graph`.
- [ ] **Phase 3: SEO Test Coverage — Functional schema assertions** — Add Functional tests under `ilas_seo/tests/src/Functional/` that lock in Phase 2 schema rendering and prevent silent regression.
- [ ] **Phase 4: Employment Security Pipeline Tests** — Build the safety net required before splitting `EmploymentApplicationController`: one test method per pipeline outcome (happy path + every rejection branch).
- [ ] **Phase 5: Controller Refactors — AssistantApi + EmploymentApplication split** — Decompose the two oversized controllers into route-grouped sub-controllers and extract `FileUploadHandler`. Behavior unchanged.
- [ ] **Phase 6: SCSS Refactor — Top-3 `!important` files + reduced-motion** — Replace `!important` with specificity-based selectors in `_events.scss`, `_employment-wizard.scss`, `_donate.scss`, and add `prefers-reduced-motion` blocks.
- [ ] **Phase 7: Build Pipeline + JS/CSS Performance** — Add JS minification (terser via Mix), subset Font Awesome, and audit globally-attached libraries to per-template attachment.
- [ ] **Phase 8: Asset Optimization — Hero SVG bytes** — Reduce 1.4MB `Front Cover.svg` to under 100KB by inspecting embedded base64 raster paths and externalizing as WebP (or `svgo --multipass` if pure vector).
- [ ] **Phase 9: A11y Test Coverage Extension** — Extend `tests/a11y/axe.spec.js` to lock in Phase 1 a11y fixes and Phase 6 reduced-motion changes against the donation form, employment wizard, mobile menu, search overlay, and language switcher.

## Phase Details

### Phase 1: Quick Wins — A11y + Taxonomy noindex + Dead module + Hero SVG
**Goal**: Ship five small, low-risk, high-leverage fixes that improve visible quality and clear repo dead weight before any deeper refactor begins.
**Depends on**: Nothing (first phase)
**Requirements**: A11Y-01, A11Y-02, SEO-02, ARCH-03
**Success Criteria** (what must be TRUE):
  1. The sticky menu in `navigation--gin.html.twig` is rendered inside the `<nav>` landmark and reachable via screen-reader landmark navigation; the `@todo` comment is removed.
  2. The honeypot inputs `fax_number` (employment-application template) and `website_url` (donate template) carry `aria-hidden="true"` and are not announced when navigating with VoiceOver/NVDA.
  3. A new `config/metatag.metatag_defaults.taxonomy_term__tags.yml` exists with `robots: 'noindex, follow'`, applied via `drush cim`; tag term pages return `<meta name="robots" content="noindex, follow">` on production.
  4. The `ilas_test` module is either deleted or its `.info.yml` references only existing modules; running `drush en ilas_test` no longer fails on missing dependencies.
**Plans**: 4 plans
- [x] 01-01-PLAN.md — A11Y-01 sticky menu inside <nav> landmark (navigation--gin + navigation twig)
- [x] 01-02-PLAN.md — A11Y-02 aria-hidden on honeypot inputs (fax_number + website_url)
- [x] 01-03-PLAN.md — SEO-02 tags taxonomy noindex metatag default
- [x] 01-04-PLAN.md — ARCH-03 ilas_test module info.yml repair
**UI hint**: yes

### Phase 2: SEO Schema Correctness — ES canonicals + JSON-LD properties
**Goal**: Make the structured-data layer actually emit what the metatag config claims it emits, and make ES canonicals correctly include the `/es/` language prefix.
**Depends on**: Nothing (independent of Phase 1)
**Requirements**: SEO-01, SEO-03
**Success Criteria** (what must be TRUE):
  1. Inspecting the `<link rel="canonical">` tag on a Spanish node page (e.g. `/es/sobre-nosotros`) on Pantheon dev shows a URL that includes the `/es/` prefix.
  2. View-source on `/about` shows `"foundingDate": "1967"` and `"areaServed"` inside the Organization `@graph` JSON-LD block.
  3. View-source on a `news`, `press_entry`, `resource`, and `legal_content` node each shows `"articleSection"` populated in the JSON-LD `@graph`.
  4. View-source on an `office_information` node shows `"areaServed"` populated from `field_county`.
  5. Either `schema_metatag` renders the properties natively, or `ilas_seo_page_attachments_alter()` injects them following the existing `publishingPrinciples` pattern (`ilas_seo.module:131–145`).
**Plans**: 5 plans
- [ ] 02-01-PLAN.md — SEO-01 ES canonical empirical investigation + conditional fix (Branch A/B/C)
- [ ] 02-02-PLAN.md — SEO-03 Organization foundingDate + areaServed via ilas_seo alter hook
- [ ] 02-03-PLAN.md — SEO-03 articleSection per bundle (news/press_entry/resource/legal_content) + YAML drift cleanup
- [ ] 02-04-PLAN.md — SEO-03 office_information areaServed via _ilas_seo_parse_county_string() + alter hook
- [ ] 02-05-PLAN.md — verify-schema.sh + dev/test/live deploy verification (D-08)

### Phase 3: SEO Test Coverage — Functional schema assertions
**Goal**: Lock Phase 2's schema fixes in with Functional tests so future config changes can't silently drop them.
**Depends on**: Phase 2 (tests assert against the fixes shipped in Phase 2)
**Requirements**: TEST-01
**Success Criteria** (what must be TRUE):
  1. `web/modules/custom/ilas_seo/tests/src/Functional/SchemaPropertiesTest.php` exists, extends `BrowserTestBase`, and runs in the `functional` testsuite.
  2. The test loads `/about`, a `news/{slug}`, and an `office_information` node, parses each `<script type="application/ld+json">` block, and asserts the presence of `foundingDate`, `areaServed`, and `articleSection` keys with expected values.
  3. `composer test:all` passes locally and in CI `quality-gate.yml`.
  4. The `FaqSchemaTest` regression test continues to pass (no aggregation regression).
**Plans**: 3 plans
- [ ] 03-01-PLAN.md — Author SchemaPropertiesTest functional test (5 methods, fixtures, JSON-LD parser helper)
- [ ] 03-02-PLAN.md — Add seo-functional-gate CI job to quality-gate.yml (DDEV-backed phpunit functional+kernel on every PR)
- [ ] 03-03-PLAN.md — Add D-SMOKE-01 authoritative pointer to scripts/seo/verify-schema.sh header

### Phase 03.1: Publish-Pipeline Audit & Hardening (INSERTED — URGENT)

**Goal:** End-to-end correctness, observability, and trust in the protected-master push process. `git push origin master` (no `--no-verify`) succeeds reliably end-to-end, and the dual-remote (`github` + `origin`/Pantheon) invariant cannot silently break.

**Why urgent:** Every protected-master deploy currently requires `git push --no-verify origin master`, silently bypassing the safety net the whole pipeline exists to provide. Tracked workaround: `.planning/todos/pending/2026-05-07-fix-git-push-origin-master-sigpipe-in-pre-push-hook.md`. This pre-empts Phase 4 (Employment Security Pipeline Tests) — adding more tests to a safety net that the deploy path cannot exercise is wasted work until this is fixed.

**Scope (in):**
  - Orchestration scripts: `scripts/git/finish.sh`, `scripts/git/publish.sh`, `scripts/git/sync-check.sh`, `scripts/git/common.sh`, related `npm run git:*` entries
  - Gate layer: `.git/hooks/pre-push` (versioned source `scripts/ci/pre-push-strict.sh`), `scripts/ci/publish-gates.lib.sh`, `scripts/ci/run-quality-gate.sh`, `scripts/ci/publish-gate-local.sh`, `scripts/ci/install-pre-push-strict-hook.sh`, `scripts/ci/publish-failure-summary.sh` and the EXIT-trap flow
  - Promptfoo gate variants: `gate_promptfoo_branch_aware` vs `gate_promptfoo_deploy_bound`, live-provider opt-in (`ILAS_LIVE_PROVIDER_GATE=1`), deploy-bound DDEV dependency
  - Topology: dual-remote sync invariant (`github` protected ↔ `origin`/Pantheon), Pantheon SSH connection lifecycle vs gate runtime, branch protection enforcement (master/main/release/*)
  - Known defects: (1) SIGPIPE / exit 141 on `git push origin master` after gates pass; (2) deploy-bound promptfoo gate silently auto-skipped on master (`.planning/todos/pending/2026-05-07-promptfoo-deploy-bound-gate-silently-auto-skipped.md`)
  - Local↔CI parity verification (claim: `publish-gate-local.sh` mirrors pre-push gates — verify this is true)

**Scope (out):** Changing what individual gates *test* (composer.json/lock content, PHPUnit suite contents, promptfooconfig.* policy contents). In-scope only: how/when/where they run and the orchestration around them.

**Depends on:** Phase 3 (complete). Must precede Phase 4 — Phase 4 adds more pre-push test coverage that is wasted until the push path itself is durable.

**Requirements:** PIPE-01, PIPE-02, PIPE-03, PIPE-04, PIPE-05, PIPE-06, PIPE-07, PIPE-08, PIPE-09, PIPE-10 (defined in 03.1-SPEC.md)

**Plans:** 7/8 plans executed

Plans:
- [x] 03.1-01-PLAN.md — Wave 0 test infrastructure: bats/shellcheck audit, gates.lock.json, harness stubs (PIPE-05/07/09)
- [x] 03.1-02-PLAN.md — Wave 1 SIGPIPE spike: falsify 4 hypotheses, produce SPIKE.md fix-shape (PIPE-01) [checkpoint]
- [x] 03.1-03-PLAN.md — Wave 2 SIGPIPE fix: apply spike-recommended FD-isolation; real push round-trip on Pantheon (PIPE-01)
- [x] 03.1-04-PLAN.md — Wave 3 deploy-bound fail-closed: new gate_promptfoo_deploy_bound_required (PIPE-02)
- [x] 03.1-05-PLAN.md — Wave 4 observability: PIPE-04 record schema + PIPE-03 verdict line + safe-push bypass audit
- [x] 03.1-06-PLAN.md — Wave 5 parity verifier: gates.lock.json drift check across local + pre-push + CI (PIPE-05)
- [x] 03.1-07-PLAN.md — Wave 5 dual-remote invariant: describe_remote_status 4th col recovery (PIPE-06)
- [ ] 03.1-08-PLAN.md — Wave 6 four-path acceptance + todo closure against real Pantheon (PIPE-07/08/09/10) [checkpoint]

### Phase 4: Employment Security Pipeline Tests
**Goal**: Build the safety net required before splitting `EmploymentApplicationController` — every fail-closed branch in the security pipeline is covered by an automated test.
**Depends on**: Nothing (must precede Phase 5 to protect ARCH-02)
**Requirements**: TEST-02
**Success Criteria** (what must be TRUE):
  1. `web/modules/custom/employment_application/tests/src/Functional/EmploymentSecurityPipelineTest.php` exists with one test method per outcome: happy-path, honeypot rejection, flood-burst rejection, flood-hour rejection, flood-global rejection, missing-nonce rejection, invalid-CSRF rejection, sub-3s submit rejection.
  2. Happy-path test verifies a successful submission writes a row to `employment_applications` and produces a PDF in `private://employment-applications`.
  3. Each rejection-branch test asserts the correct HTTP status, response body shape, and that no row is written to `employment_applications`.
  4. Test class verifies `Cache-Control: no-store` is present on the `/employment-application/token` response.
  5. `composer test:all` passes locally and in CI.
**Plans**: TBD

### Phase 5: Controller Refactors — AssistantApi + EmploymentApplication split
**Goal**: Decompose the two oversized controllers (8268 + 2526 lines) into route-grouped sub-controllers and a dedicated `FileUploadHandler` service, with no behavioral change.
**Depends on**: Phase 4 (employment security tests must exist before EmploymentApplicationController is split)
**Requirements**: ARCH-01, ARCH-02
**Success Criteria** (what must be TRUE):
  1. `AssistantApiController.php` is replaced by separate `Controller\Chat`, `Controller\Session`, `Controller\Faq`, `Controller\Governance` classes (or equivalent split); no individual controller file exceeds ~1500 lines; `ilas_site_assistant.routing.yml` references the new classes.
  2. `EmploymentApplicationController.php` is reduced by extracting (a) `Service\FileUploadHandler` covering the file-validation + secure-save logic at lines 1337–1380, and (b) `Controller\AdminController` covering admin endpoints at lines 2147–2526; `services.yml` and `routing.yml` updated.
  3. The Phase 4 employment security pipeline tests continue to pass against the refactored controller.
  4. CI `quality-gate.yml` passes including PHPCS Drupal + DrupalPractice and PHPStan level 2; baseline does not grow.
  5. Manual smoke on Pantheon dev: token endpoint mints, a happy-path submit succeeds, admin list/detail/status/delete endpoints all respond identically to pre-refactor.
**Plans**: TBD

### Phase 6: SCSS Refactor — Top-3 `!important` files + reduced-motion
**Goal**: Bring `_events.scss`, `_employment-wizard.scss`, `_donate.scss` into compliance with the CLAUDE.md `!important` policy, and respect `prefers-reduced-motion` for the wizard and donate components.
**Depends on**: Nothing (independent SCSS work)
**Requirements**: STYLE-01, STYLE-02
**Success Criteria** (what must be TRUE):
  1. `_events.scss`, `_employment-wizard.scss`, `_donate.scss` together drop from 38 `!important` instances to zero (or to instances that each carry an inline comment naming the upstream Bootstrap/Bootswatch rule countered).
  2. Each of those three files achieves the same rendered styling using parent-component-scoped specificity, following the pattern documented at `_layout-components.scss:407` and proven in `_smart-faq.scss:361`.
  3. `_employment-wizard.scss` and `_donate.scss` each contain a `@media (prefers-reduced-motion: reduce)` block that disables transitions and transforms on the wizard and donation-form components.
  4. Visual comparison on Pantheon dev: `/events`, `/donate`, an `employment` node, and the employment-application form all render identically (or with explicitly-approved diffs) before vs. after the refactor.
  5. With `prefers-reduced-motion: reduce` set in browser dev-tools, the wizard step transitions and donation-form animations are visibly disabled.
**Plans**: TBD
**UI hint**: yes

### Phase 7: Build Pipeline + JS/CSS Performance
**Goal**: Establish a JS minification build step, subset Font Awesome to only used glyphs, and stop globally attaching libraries that only a handful of templates actually need.
**Depends on**: Nothing (independent of code refactors)
**Requirements**: PERF-02, PERF-03, PERF-04
**Success Criteria** (what must be TRUE):
  1. `webpack.mix.js` produces minified `.min.js` outputs for `premium-application.js` (61KB → measurably smaller) and `donation-inquiry.js` (19KB → measurably smaller); `b5subtheme.libraries.yml` references the minified files in production.
  2. The Font Awesome bundle in compiled `style.css` drops from ~70KB to under 10KB; all icons currently rendered on production templates still render.
  3. `b5subtheme.info.yml` global library list is reduced — at minimum `search-overlay`, `language-switcher`, and `lazy-loading` are removed from the always-attached set; each is attached per-template via `{{ attach_library() }}` or via preprocess only on pages that need it.
  4. Pantheon dev pages that use those widgets (header search, language switcher, image-rich pages) all still function correctly; pages that don't use them ship without that JS/CSS.
  5. CI `quality-gate.yml` continues to pass; `a11y-gate` axe scan shows no new icon-related violations.
**Plans**: TBD
**UI hint**: yes

### Phase 8: Asset Optimization — Hero SVG bytes
**Goal**: Reduce the 1.4MB `Front Cover.svg` payload to under 100KB without visual regression.
**Depends on**: Nothing (standalone asset work)
**Requirements**: PERF-01
**Success Criteria** (what must be TRUE):
  1. `web/themes/custom/b5subtheme/images/Front Cover.svg` is either (a) replaced with a `<picture>` / `<img>` reference to an externalized WebP at rendered dimensions if embedded base64 raster was found, or (b) reduced via `svgo --multipass` if pure vector.
  2. Final on-the-wire byte size of the hero asset (or its WebP replacement) on the home page is under 100KB.
  3. The home-page hero renders visually identical (or with explicitly-approved diffs) on Pantheon dev across desktop and mobile breakpoints.
  4. Lighthouse "Properly size images" / "Efficiently encode images" audits no longer flag this file.
**Plans**: TBD
**UI hint**: yes

### Phase 9: A11y Test Coverage Extension
**Goal**: Extend `tests/a11y/axe.spec.js` to lock in the Phase 1 a11y fixes and the Phase 6 reduced-motion changes against the high-traffic widgets that currently have no axe coverage.
**Depends on**: Phase 1 (sticky-nav + honeypot fixes), Phase 6 (reduced-motion blocks)
**Requirements**: TEST-03
**Success Criteria** (what must be TRUE):
  1. `tests/a11y/axe.spec.js` contains explicit axe scans for: donation form route, employment wizard route, mobile menu open state, search overlay open state, language switcher dropdown.
  2. Corresponding `A11Y_ROUTE_*` env-var entries are documented in `tests/a11y/helpers/a11y-utils.js` (or its routes file) so each scan can be enabled in CI without code changes.
  3. The Playwright `a11y-local-gate` and hosted `a11y-gate` jobs both run the new scans and pass against Pantheon dev.
  4. Sticky-menu landmark and honeypot `aria-hidden` (Phase 1) are explicitly asserted by axe rules `landmark-no-duplicate-banner` / `aria-hidden-focus` on the relevant routes.
  5. No serious or critical axe violations in any of the new scans.
**Plans**: TBD

## Progress

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Quick Wins — A11y + Taxonomy noindex + Dead module | 0/4 | Not started | - |
| 2. SEO Schema Correctness | 0/5 | Not started | - |
| 3. SEO Test Coverage | 0/3 | Not started | - |
| 4. Employment Security Pipeline Tests | 0/0 | Not started | - |
| 5. Controller Refactors | 0/0 | Not started | - |
| 6. SCSS Refactor — Top-3 files | 0/0 | Not started | - |
| 7. Build Pipeline + JS/CSS Performance | 0/0 | Not started | - |
| 8. Asset Optimization — Hero SVG | 0/0 | Not started | - |
| 9. A11y Test Coverage Extension | 0/0 | Not started | - |

## Dependency Graph

```
Phase 1 (quick wins) ──────────────────────┐
                                            │
Phase 2 (SEO schema) ──→ Phase 3 (SEO tests)│
                                            │
Phase 4 (security tests) ──→ Phase 5 (controller refactors)
                                            │
Phase 6 (SCSS top-3 + reduced-motion) ─────┐│
                                           ▼▼
                                       Phase 9 (a11y test ext.)

Phase 7 (build pipeline) — independent
Phase 8 (hero SVG)        — independent
```

## Coverage Map

| REQ-ID    | Category | Phase |
|-----------|----------|-------|
| STYLE-01  | SCSS     | 6     |
| STYLE-02  | SCSS     | 6     |
| SEO-01    | SEO      | 2     |
| SEO-02    | SEO      | 1     |
| SEO-03    | SEO      | 2     |
| ARCH-01   | Arch     | 5     |
| ARCH-02   | Arch     | 5     |
| ARCH-03   | Arch     | 1     |
| A11Y-01   | A11y     | 1     |
| A11Y-02   | A11y     | 1     |
| PERF-01   | Perf     | 8     |
| PERF-02   | Perf     | 7     |
| PERF-03   | Perf     | 7     |
| PERF-04   | Perf     | 7     |
| TEST-01   | Test     | 3     |
| TEST-02   | Test     | 4     |
| TEST-03   | Test     | 9     |

**Coverage:** 17/17 v1 requirements mapped — no orphans, no duplicates.

---
*Roadmap created: 2026-05-06*
