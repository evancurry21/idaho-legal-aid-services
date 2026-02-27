# Aila Retrospective QA: Prior Audit vs New Production Transcript Failures

Date: 2026-02-27  
Scope: retrospective QA of prior audit deliverables against newly observed production failures.

## 1) Last "extensive audit" selection (locked)

### Selection decision

Primary source of truth is the 2026-02-27 `docs/aila/*` audit package.

| Artifact | Date signal | Recency/provenance evidence | Decision |
|---|---|---|---|
| `docs/aila/current-state.md` | `2026-02-27T20:15:50Z` metadata | `git log -- docs/aila/*` shows latest audit bundle change in commit `6f0a6f334` (2026-02-27) | **Authoritative** |
| `docs/aila/gap-analysis.md` + `roadmap.md` + `backlog.md` + `risk-register.md` + `evidence-index.md` | same package cadence | same commit family and shared refs (`IMP-*`, `R-*`, `B-*`) | **Authoritative** |
| `docs/assistant_audit_backlog.md` | created `2026-02-19` | older commit ancestry (`899cd1feb`) | Secondary (older) |
| `docs/assistant_audit/ASSISTANT_AUDIT_REPORT.md` | dated `2026-02-20` in file body | ignored by git (`.gitignore`), no commit history in repo | Secondary (stored, untracked) |
| `web/modules/custom/ilas_site_assistant/HOSTILE_AUDIT_REPORT.md` | dated `2026-02-16` in file body | older hostile audit pass | Secondary (older) |
| `chatbotreview.md` | legacy comparative review | older broad review; not the 2026-02-27 roadmap package | Secondary (context only) |

### Search evidence (what was searched)

- Broad repo keyword scan (`audit`, `findings`, `roadmap`, `phase`, `governance`, `kernel`, `taxonomy`, `fallback`, `session`, `CSRF`, `403`, `access denied`) across `docs/`, module code, tests, scripts.
- Exact strings requested:
  - `"What are you looking for?"` found in `Disambiguator`, `IntentRouter`, `AssistantApiController`, and widget welcome text.
  - `"I couldn't find matching forms"` found in form-finder no-match copy in controller.
  - `"access denied. please refresh the page."` matched case-insensitively in widget and widget hardening tests (`Access denied. Please refresh the page and try again.`).
- Git history scans for `audit`, `csrf`, `fallback`, `forms`, `guides`, `history`, `loop`, `403`, `access denied`.
- CI/eval surface scan:
  - `.github/workflows/` exists but no workflow files found.
  - Promptfoo gate scripts exist under `scripts/ci/*`; default config is `promptfooconfig.abuse.yaml`.

## 2) Extracted findings inventory

## 2.1 Primary audit package (2026-02-27)

### Improvement findings (`IMP-*`)

| ID | Title | Severity/Priority | Category | Where in audit | Roadmap phase | Status (planned/implemented) |
|---|---|---|---|---|---|---|
| IMP-SEC-01 | Resolve CSRF ambiguity and harden endpoint protections for message/track POST routes | Score 23, Rank 1, Must-Do | Security & Privacy | `docs/aila/gap-analysis.md` "Full scored improvement list" | 0 | Implemented (`6f0a6f334`; strict CSRF check + matrix tests) |
| IMP-GOV-01 | Enforce and audit "no legal advice" policy with reason-code provenance | Score 22, Must-Do | Governance & Compliance | `docs/aila/gap-analysis.md` scored list | 1 | Planned |
| IMP-OBS-01 | Enable observability baseline (Sentry + Langfuse + structured logs + redaction checks) | Score 22, Must-Do | Observability | `docs/aila/gap-analysis.md` scored list | 1 | Planned (Phase 0 dependency unblocked for start) |
| IMP-TST-01 | Wire existing quality gates into CI (PHPUnit + Promptfoo thresholds) | Score 22 | Maintainability & Testing | `docs/aila/gap-analysis.md` scored list | 1 | Partial (scripts in `scripts/ci/*`; no first-party workflow file in repo) |
| IMP-RAG-01 | Retrieval confidence gating with citation-first answers and weak-retrieval refusal | Score 21, Must-Do | Retrieval Quality | `docs/aila/gap-analysis.md` scored list | 2 | Planned |
| IMP-CONF-01 | Fix config schema parity (`vector_search`) and add env drift checks | Score 20 | Config / Retrieval | `docs/aila/gap-analysis.md` scored list | 0 | Implemented (`eb57c238b`; CLAIM-124 config parity evidence) |
| IMP-SLO-01 | Define SLOs and alerts for availability/latency/errors/cron/queue | Score 20 | Observability | `docs/aila/gap-analysis.md` scored list | 1 | Planned |
| IMP-ABUSE-01 | Add layered abuse controls beyond Flood API | Score 20, Must-Do | Security & Abuse | `docs/aila/gap-analysis.md` scored list | 1 | Planned |
| IMP-COST-01 | Cost guardrails (budgets/sampling/cache-hit/kill-switch) | Score 20 | Performance & Cost | `docs/aila/gap-analysis.md` scored list | 3 | Planned |
| IMP-UX-01 | A11y/mobile/error-state hardening with explicit keyboard/SR criteria | Score 19 | UX & Accessibility | `docs/aila/gap-analysis.md` scored list | 3 | Planned |
| IMP-REL-01 | Deterministic degradation contract tests for integration failures | Score 18 | Reliability | `docs/aila/gap-analysis.md` scored list | 1 | Planned |
| IMP-REL-02 | Idempotency/replay correctness suite with correlation/conversation IDs | Score 18 | Reliability | `docs/aila/gap-analysis.md` scored list | 1 | Planned |
| IMP-MNT-01 | Pipeline seam extraction for maintainability | Score 15 | Maintainability | `docs/aila/gap-analysis.md` scored list | 3 | Planned |
| IMP-GOV-02 | Retention/access quarterly attestation workflow | Score 18 | Governance | `docs/aila/gap-analysis.md` scored list | 1 | Planned |
| IMP-RAG-02 | Provenance/freshness registry for indexed sources | Score 18 | Retrieval Governance | `docs/aila/gap-analysis.md` scored list | 2 | Planned |

### Risk findings (`R-*`)

| ID | Title | Severity/Priority | Category | Where in audit | Roadmap phase | Status |
|---|---|---|---|---|---|---|
| R-SEC-01 | CSRF behavior ambiguity on write endpoints | L=M, I=H | Security & Privacy | `docs/aila/risk-register.md` | 0/1 via IMP-SEC-01 | Proposed |
| R-SEC-02 | Flood-only abuse controls insufficient | L=M, I=H | Security & Privacy | `docs/aila/risk-register.md` | 1 via IMP-ABUSE-01 | Proposed |
| R-SEC-03 | Telemetry PII leakage risk without recurring validation | L=M, I=H | Security & Privacy | `docs/aila/risk-register.md` | 1 via IMP-OBS-01 | Proposed |
| R-REL-01 | Integration failure behavior inconsistency risk | L=M, I=H | Reliability | `docs/aila/risk-register.md` | 1 via IMP-REL-01 | Proposed |
| R-REL-02 | Cron/queue throughput unproven under load | L=M, I=M | Reliability | `docs/aila/risk-register.md` | 1 via IMP-SLO-01/IMP-OBS-01 | Proposed |
| R-REL-03 | Replay/idempotency duplicates or contradictions | L=M, I=M | Reliability | `docs/aila/risk-register.md` | 1 via IMP-REL-02 | Proposed |
| R-OBS-01 | Sentry inactive in sampled envs | L=M, I=H | Observability | `docs/aila/risk-register.md` | 1 via IMP-OBS-01 | Proposed |
| R-OBS-02 | Langfuse unavailable in sampled envs | L=M, I=M | Observability | `docs/aila/risk-register.md` | 1 via IMP-OBS-01 | Proposed |
| R-OBS-03 | Missing explicit SLOs/alerts | L=M, I=H | Observability | `docs/aila/risk-register.md` | 1 via IMP-SLO-01 | Proposed |
| R-RAG-01 | Retrieval confidence not formal contract | L=M, I=H | Retrieval | `docs/aila/risk-register.md` | 2 via IMP-RAG-01 | Proposed |
| R-RAG-02 | Vector schema/config drift | L=M, I=M | Retrieval | `docs/aila/risk-register.md` | 0/2 via IMP-CONF-01 | Proposed |
| R-LLM-01 | Prompt governance drift on future rollout | L=M, I=H | LLM Quality | `docs/aila/risk-register.md` | 1/2 | Proposed |
| R-UX-01 | A11y regression risk without gates | L=M, I=H | UX | `docs/aila/risk-register.md` | 3 via IMP-UX-01 | Proposed |
| R-UX-02 | Mobile timeout/offline UX regression risk | L=M, I=M | UX | `docs/aila/risk-register.md` | 3 via IMP-UX-01 | Proposed |
| R-PERF-01 | LLM spend unpredictability without guardrails | L=M, I=H | Cost | `docs/aila/risk-register.md` | 3 via IMP-COST-01 | Proposed |
| R-PERF-02 | No formal latency budgets | L=M, I=M | Performance | `docs/aila/risk-register.md` | 1 via IMP-SLO-01 | Proposed |
| R-MNT-01 | Controller/service complexity risk | L=M, I=M | Maintainability | `docs/aila/risk-register.md` | 3 via IMP-MNT-01 | Proposed |
| R-MNT-02 | CI orchestration gap | L=H, I=H | Maintainability & Testing | `docs/aila/risk-register.md` | 1 via IMP-TST-01 | Proposed |
| R-GOV-01 | Policy enforcement auditability gaps | L=M, I=H | Governance | `docs/aila/risk-register.md` | 1 via IMP-GOV-01 | Proposed |
| R-GOV-02 | Content provenance/freshness governance gaps | L=M, I=H | Governance | `docs/aila/risk-register.md` | 2 via IMP-RAG-02 | Proposed |

### Blockers (`B-*`) and verification findings (`V-*`)

| ID | Title | Severity/Priority | Category | Where in audit | Roadmap phase | Status |
|---|---|---|---|---|---|---|
| B-01 | Authenticated CSRF behavior unknown | Critical blocker | Security | `docs/aila/gap-analysis.md` "Critical unknowns" | 0 | Resolved in current code/test state (CLAIM-123) |
| B-02 | `vector_search` active behavior uncertain | High blocker | Config/Retrieval | `docs/aila/gap-analysis.md` | 0/2 | Partially resolved (schema parity implemented; env drift still operational concern) |
| B-03 | CI owner/source of truth unknown | High blocker | Testing governance | `docs/aila/gap-analysis.md` | 1 | Open |
| B-04 | Cron/queue sustained-load evidence missing | High blocker | Reliability/Observability | `docs/aila/gap-analysis.md` | 1 | Open |
| V-01 | CSRF synthetic endpoint verification | Verification finding | Security | `docs/aila/gap-analysis.md` "Verification Log" | 0 | Historical pre-fix evidence; superseded by CLAIM-123 |
| V-02 | `vector_search` parity spot check | Verification finding | Config | `docs/aila/gap-analysis.md` | 0 | Addressed by parity change set (`eb57c238b`) |
| V-03 | Test assets exist; CI orchestration gap | Verification finding | Testing | `docs/aila/gap-analysis.md` | 1 | Still true (scripts exist; first-party workflow missing) |

## 2.2 Secondary stored findings (older artifacts)

### `docs/assistant_audit_backlog.md` (2026-02-19, tracked)

| ID | Title (source summary) | Priority signal in source |
|---|---|---|
| OBS-1 | Langfuse duplicate trace event type (`trace-create` vs `trace-update`) | P0 |
| OBS-2 | Langfuse queue unbounded growth | P0 |
| OBS-3 | Langfuse queue item TTL missing | P1 |
| OBS-4 | Sentry integration missing | P2 |
| LLM-1 | No circuit breaker during Gemini outages | P0 |
| LLM-2 | No global LLM rate limit | P0 |
| LLM-3 | LLM cache collision risk negligible | No Action Required |
| SAF-1 | Indirect prompt injection via retrieved CMS content | P1 |
| SAF-2 | Direct prompt injection residual risk acceptable | No Action Required |
| SAF-3 | CSRF implementation verified | No Action Required |
| SAF-4 | XSS protection verified | No Action Required |
| SAF-5 | Debug mode server-side only | No Action Required |
| SAF-6 | UUID4 conversation ID entropy acceptable | No Action Required |
| PII-1 | Freestanding names not reliably redacted | P2 |
| PII-2 | Idaho DL pattern not covered | P2 |
| PII-3 | "Raw cache text" claim disproved | No Action Required |
| PII-4 | Conversation logs redacted | No Action Required |
| RET-1 | Search API legacy fallback already implemented | No Action Required |
| RET-2 | Vector search disabled but implementation present | P3 |
| RET-3 | Conversation cache eviction risk under pressure | P3 |
| I18N-1 | English-only LLM prompts in multilingual context | P3 |
| EVAL-1 | No offline routing eval harness | P3 |

Also present in this source:
- explicit `P0`..`P3` sequencing (`Prioritized Implementation Order`)
- explicit superseded/no-action bucket (`No Action Required`)
- audit errata correcting prior line references and disproved claims.

### `web/modules/custom/ilas_site_assistant/HOSTILE_AUDIT_REPORT.md` (2026-02-16, untracked/ignored)

This artifact includes:
- stop-ship findings `SS-1`, `SS-2`, `SS-3` (section `2) Stop-Ship Findings`)
- full priority table `F-01`..`F-28` with `P0`..`P3` severities (section `3) Priority Findings Table`)
- claim-validation table `C-01`..`C-15` (section `8) Contradictions and Verified Claims`).

### `docs/assistant_audit/ASSISTANT_AUDIT_REPORT.md` (2026-02-20, untracked/ignored)

This artifact is structured as deliverables `A`..`H` plus roadmap blocks (no standalone `ID` namespace like `IMP-*`).

### `chatbotreview.md` (legacy, untracked/ignored)

Comparative weakness/recommendation narrative with no stable ticket ID taxonomy.

## 3) Cross-check of newly observed failures (A/B/C)

Classification key:
- **A** = explicitly identified in prior audit findings
- **B** = indirectly implied
- **C** = not mentioned

| Failure class | A/B/C | Where mentioned (file + section + short excerpt) | Assigned phase/milestone | Acceptance criteria (stated or missing) | Implementation evidence |
|---|---|---|---|---|---|
| Clarify-or-retrieve failure on vague intent (`i need some help` -> random retrieval) | **B** | `docs/aila/gap-analysis.md` reliability section emphasizes deterministic degrade/replay; code has explicit vague-query disambiguation for `i need help` in `Disambiguator`. Excerpt: "No explicit idempotency/replay test suite..." | Phase 1 (`IMP-REL-02`) | Missing: no transcript acceptance criterion asserting vague input must remain in clarify flow and must not jump to random resources | Routing/disambiguation pipeline shipped in `8f23d0e2d`; no dedicated `Disambiguator*Test.php` found |
| Mixed-intent routing (`eviction forms or guides?`) | **C** | No explicit prior finding in `docs/aila/*` naming "mixed forms/guides query" failure class | None explicitly assigned | Missing: no acceptance criterion for mixed content-type phrase parsing | Confusable pair exists in code (`forms_finder:guides_finder`), but no audit finding targeted this failure mode |
| Query normalization/sanitization producing empty effective query | **C** | No explicit prior finding in `docs/aila/*` | None explicitly assigned | Missing: no criterion that post-sanitize empty input must hard-fail before intent/retrieval | `AssistantApiController::message()` validates `empty($data['message'])` before sanitize, then sanitizes (`sanitizeInput`) without post-check |
| Session/state loss causing repeated "What are you looking for?" loop | **B** | `R-REL-03` / `IMP-REL-02` (replay/idempotency edge cases) in `risk-register.md` and `gap-analysis.md`; loop not directly named | Phase 1 | Partial: replay/idempotency criteria exist, but no explicit "no repeated clarify-loop" acceptance criterion | History/cache/fallback introduced in `8f23d0e2d`; golden tests are kernel-level and do not assert controller/widget loop behavior |
| Anonymous access/permissions/CSRF 403 path (`access denied...`) | **A** | `IMP-SEC-01`, `R-SEC-01`, `CLAIM-123` explicitly cover CSRF matrix and deterministic 403 for sessionless requests | Phase 0 (Sprint 1) | Present for security matrix (valid/missing/invalid token cells), but missing UX recovery contract for expired token/session bootstrap | Implemented in `6f0a6f334`: strict access check + route requirement + functional/unit CSRF matrix tests + widget 403 copy |

### Transcript-specific determinations

- **Transcript A (vague intent/forms/guides/loop):** partially implied but not directly caught as a production failure mode; key misses are mixed-intent parsing, option-schema contract, and loop assertions.
- **Transcript B (`i need help` -> access denied):** security behavior was explicitly targeted and implemented; however, user recovery UX contract was not explicit in prior acceptance criteria.

## 4) If caught, when planned to fix? (roadmap + status)

| Item | Prior finding(s) | Planned phase | Current status evidence |
|---|---|---|---|
| CSRF/403 handling | IMP-SEC-01, R-SEC-01, B-01 | Phase 0 / Sprint 1 | Implemented (`6f0a6f334`), `StrictCsrfRequestHeaderAccessCheck`, route dual requirements, functional matrix tests, CLAIM-123 |
| Replay/idempotency/state consistency | IMP-REL-02, R-REL-03 | Phase 1 / Sprints 2-3 | Planned; no complete controller/widget replay loop contract evidence in current tests |
| CI quality gate | IMP-TST-01, R-MNT-02, B-03 | Phase 1 / Sprints 2-3 | Partial: scripts exist (`scripts/ci/run-promptfoo-gate.sh`), but no first-party `.github/workflows/*` |
| Retrieval confidence contract | IMP-RAG-01, R-RAG-01 | Phase 2 / Sprints 4-5 | Planned; no complete production contract rollout evidence in this retrospective scope |

## 5) Why misses happened (where not caught)

1. **Transcript harness mismatch:** `GoldenTranscriptTest` validates classifier/kernel behavior, not full API/controller/widget loops; it does not enforce the observed clarify-loop failure path.
2. **No disambiguation schema contract test:** disambiguation option payload shape (`value` vs `intent`) is not locked by unit/functional contract tests.
3. **No mixed content-type regression assertions:** no explicit test for `"forms or guides"` mixed phrase behavior in controller response contract.
4. **No post-sanitize empty guard assertion:** message input is sanitized after initial request validation, but no explicit post-sanitize empty-path test is enforced.
5. **Default eval gate scope gap:** default promptfoo gate uses `promptfooconfig.abuse.yaml`; deep multi-turn suite (`promptfooconfig.deep.yaml` / `tests/conversations-deep.yaml`) is not the default blocking gate.
6. **CI enforceability gap:** repo has no first-party workflow file, so even existing scripts/suites are not guaranteed to block merges.

## 6) Formalized new findings and roadmap updates

### New formal findings

| New ID | Severity | Finding | Evidence | Roadmap assignment | Acceptance criteria |
|---|---|---|---|---|---|
| RETRO-F-01 | High | Disambiguation option schema mismatch can produce non-actionable chips and clarify loops (`value` emitted; controller expects `intent`) | `Disambiguator` options use `value`; controller disambiguation path reads `option['intent']` | `IMP-REL-03` (Phase 1) | 1) API accepts canonical `intent`; legacy `value` supported with deprecation telemetry. 2) Disambiguation options always render clickable actions. 3) Contract tests cover both payload variants. |
| RETRO-F-02 | High | Mixed forms/guides phrase parsing is not covered by explicit regression tests | observed failure + no explicit audit/test case for mixed content-type query | `IMP-REL-03` + `IMP-TST-02` (Phase 1) | 1) `"eviction forms or guides?"` yields single clarify response with both options. 2) No null-action chips. 3) Golden transcript + functional test pass. |
| RETRO-F-03 | Medium | Post-sanitize empty query can slip through and trigger ambiguous/noisy downstream behavior | request checks `empty($data['message'])` before sanitize; no post-sanitize guard | `IMP-REL-04` (Phase 1) | 1) Empty-after-sanitize returns deterministic `400 invalid_message`. 2) No retrieval/router invocation on empty effective query. 3) Unit+functional coverage added. |
| RETRO-F-04 | High | Repeated clarify-loop guard missing for same-question cycles | observed repeated "What are you looking for?" loop; no explicit loop-break contract | `IMP-REL-04` + `IMP-TST-02` (Phase 1) | 1) Max repeated clarify threshold enforced per conversation. 2) On threshold, response must escalate to topic chips or human-help CTA. 3) Loop regression test prevents recurrence. |
| RETRO-F-05 | Medium | CSRF/session expiry UX has only generic 403 copy; no machine-readable recovery code | widget maps 403 to generic text; no explicit recovery code contract | `IMP-SEC-02` + `IMP-UX-02` (Phase 0/3) | 1) API emits explicit error code for CSRF/session issues. 2) Widget uses code to present recover action (`refresh/retry`). 3) Functional test covers expired/missing token recovery path. |

### Roadmap addendum entries created in this implementation

- `IMP-SEC-02`: CSRF/session recovery error-code contract and UX-safe recover flow (Phase 0).
- `IMP-REL-03`: Disambiguation schema normalization + mixed-intent clarify contract (Phase 1).
- `IMP-REL-04`: Post-sanitize empty guard + clarify-loop prevention (Phase 1).
- `IMP-TST-02`: Golden transcript replay expansion and deep promptfoo gating for these regression classes (Phase 1).
- `IMP-UX-02`: UX recovery flow for CSRF/session denial states (Phase 3 hardening).
- Canonical tracking artifacts updated:
  - `docs/aila/roadmap.md` (`Retrospective addendum (2026-02-27 production failures)`).
  - `docs/aila/backlog.md` (`Retrospective Regression Hardening (2026-02-27 addendum)`).
  - `docs/aila/risk-register.md` (`R-REL-04..07`, `R-UX-03`).
  - `docs/aila/runbook.md` (`Retrospective regression checklist (mandatory)`).

## 7) Explicit "not found" evidence and uncertainties

- No PR metadata mirror found in repo for these items (no local PR/issue index artifacts in scanned docs).
- No first-party CI workflow YAML found under `.github/workflows/` in this snapshot.
- No dedicated `Disambiguator` contract test file found in `tests/src/`.
- Uncertainty: production runtime specifics beyond audited artifacts (e.g., browser/session expiry timing in live traffic) require live replay traces.

## 8) Required future audit checklist (short form)

1. Replay golden transcripts for vague intent and mixed forms/guides phrasing through full API + widget flow.
2. Assert disambiguation option schema contract (`intent` canonical, `value` compatibility path).
3. Run anonymous/session-bound CSRF matrix including expired token recovery UX.
4. Assert post-sanitize empty-query short-circuit behavior.
5. Assert clarify-loop breaker behavior on repeated unresolved turns.
6. Require deep multi-turn conversation eval in blocking gate, not abuse-only suite.
