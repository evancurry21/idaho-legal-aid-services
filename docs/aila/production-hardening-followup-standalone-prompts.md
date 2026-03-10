# Production Hardening Follow-On Standalone Prompts

Use these when you want to hand a single hardening task to another engineer or agent without making them read the full supplement pack first. The authoritative constraints, proof bar, and report format live in [production-hardening-followup-prompt-pack.md](/home/evancurry/idaho-legal-aid-services/docs/aila/production-hardening-followup-prompt-pack.md).

Global defaults for every standalone prompt:
- Preserve `llm.enabled=false` in `live`.
- Use sanitized, non-PII synthetic traffic only for live verification.
- Treat Drupal-managed ILAS content as the primary knowledge source.
- Keep retrieval lexical-first and taxonomy-aware; semantic supplements stay gated behind proof.
- Do not reintroduce raw user text into analytics, traces, logs, or transcripts.
- Return the same evidence-backed report structure used by the `RAUD` pack.

## `PHARD-01` — Sentry/Raven live operationalization
Implement `PHARD-01` from [production-hardening-followup-prompt-pack.md](/home/evancurry/idaho-legal-aid-services/docs/aila/production-hardening-followup-prompt-pack.md).

Direct prompt:
> Prove Sentry/Raven is an active live operational system for the assistant, not just a configured DSN. Reconstruct the current state, then verify or implement sanitized live capture, redaction, environment/release tagging, alert delivery, dashboard ownership, and a named review loop. Do not close from config/tests alone. A `Fixed` outcome requires live Sentry issue/event proof, alert-route proof, dashboard ownership proof, and named responder evidence. If SaaS proof is unavailable, return `Unverified`.

Recommended validation:
- `VC-UNIT`
- `VC-PANTHEON-READONLY`
- `VC-SENTRY-LIVE`

## `PHARD-02` — Langfuse live operationalization
Implement `PHARD-02` from [production-hardening-followup-prompt-pack.md](/home/evancurry/idaho-legal-aid-services/docs/aila/production-hardening-followup-prompt-pack.md).

Direct prompt:
> Prove Langfuse tracing is live, sampled intentionally, redacted, queue-monitored, alert-routed, and actually used as an improvement loop. Reconstruct the current trace lifecycle, then verify or implement live trace proof, queue-health proof, sample-rate policy, alert ownership, and weekly review-loop evidence. Do not close from queue code or credential presence. A `Fixed` outcome requires live trace URLs, queue-health proof, sampling proof, alert-route proof, and named owner evidence. If SaaS proof is unavailable, return `Unverified`.

Recommended validation:
- `VC-UNIT`
- `VC-PANTHEON-READONLY`
- `VC-LANGFUSE-LIVE`

## `PHARD-03` — Grounding, citations, freshness, and confidence-aware refusal
Implement `PHARD-03` from [production-hardening-followup-prompt-pack.md](/home/evancurry/idaho-legal-aid-services/docs/aila/production-hardening-followup-prompt-pack.md).

Direct prompt:
> Tighten grounding so answerable responses are source-grounded, cited, freshness-aware, and refusal-safe when grounding is weak. Inventory current gaps, then implement mandatory source links for answerable content, safe citation URL validation, explicit freshness handling, and confidence-aware refusal or clarification behavior. Prove improvement against generic uncited answers, including the named office/forms/service scenarios. Do not treat generic directory links as sufficient when a specific answer is available.

Recommended validation:
- `VC-UNIT`
- `VC-KERNEL`
- `VC-RETRIEVAL-LOCAL`
- `VC-RETRIEVAL-PANTHEON`

## `PHARD-04` — Simple-language recall and lexical query normalization
Implement `PHARD-04` from [production-hardening-followup-prompt-pack.md](/home/evancurry/idaho-legal-aid-services/docs/aila/production-hardening-followup-prompt-pack.md).

Direct prompt:
> Fix short plain-language recall failures before any semantic rollout claims. Reproduce current misses, then improve lexical normalization, taxonomy aliasing, spelling tolerance, pluralization, and query rewriting so `do you have custody forms` succeeds without requiring the user to rephrase to `custody forms`. Keep the proof lexical/taxonomy-first; do not use semantic retrieval as the shortcut.

Recommended validation:
- `VC-UNIT`
- `VC-DRUPAL-UNIT`
- `VC-RETRIEVAL-LOCAL`
- `VC-ROUTING-REGRESSION`

## `PHARD-05` — Search quality analytics dashboard and user feedback loop
Implement `PHARD-05` from [production-hardening-followup-prompt-pack.md](/home/evancurry/idaho-legal-aid-services/docs/aila/production-hardening-followup-prompt-pack.md).

Direct prompt:
> Add a measurable search quality analytics loop. Define the event contract, add a small helpful/not-helpful user control, and build reporting or dashboard outputs that show no-answer, generic-answer, bad-ranking, low-confidence refusal, and dissatisfaction patterns without storing raw user text. The result must answer: what failed, how often, and what was done about it.

Recommended validation:
- `VC-KERNEL`
- `VC-FUNCTIONAL`
- `VC-WIDGET-HARDENING`
- `VC-SEARCH-ANALYTICS`
- `VC-FEEDBACK-UI`

## `PHARD-06` — Drupal-primary retrieval contract with gated semantic supplements
Implement `PHARD-06` from [production-hardening-followup-prompt-pack.md](/home/evancurry/idaho-legal-aid-services/docs/aila/production-hardening-followup-prompt-pack.md).

Direct prompt:
> Formalize the retrieval contract so approved Drupal content remains the primary source of truth and semantic retrieval remains a subordinate, governed supplement. Make the lexical-first and provenance/freshness rules explicit in code, config, and tests. Prove semantic supplements cannot silently become the uncontrolled primary path.

Recommended validation:
- `VC-UNIT`
- `VC-PANTHEON-READONLY`
- `VC-RETRIEVAL-LOCAL`
- `VC-RETRIEVAL-PANTHEON`

## `PHARD-07` — Specific office/form/guide/service routing and low-literacy clarification
Implement `PHARD-07` from [production-hardening-followup-prompt-pack.md](/home/evancurry/idaho-legal-aid-services/docs/aila/production-hardening-followup-prompt-pack.md).

Direct prompt:
> Eliminate generic routing where specific answers or clear clarifications are possible. Improve office-specific direct answers, form-vs-guide-vs-service disambiguation, service-area specificity, and low-literacy clarification prompts. Required regression cases include `where is the Boise office`, `what office helps me in Twin Falls`, `eviction forms or guides`, and vague low-literacy help phrasing that should clarify instead of no-answering.

Recommended validation:
- `VC-UNIT`
- `VC-DRUPAL-UNIT`
- `VC-FUNCTIONAL`
- `VC-ROUTING-REGRESSION`
- `VC-RETRIEVAL-PANTHEON`

## `PHARD-08` — Transcript minimization and logging controls
Implement `PHARD-08` from [production-hardening-followup-prompt-pack.md](/home/evancurry/idaho-legal-aid-services/docs/aila/production-hardening-followup-prompt-pack.md).

Direct prompt:
> Build on `RAUD-11` and tighten runtime transcript/logging policy. Reconstruct the current live/default posture, then reduce privacy exposure through stronger live defaults, approved retained fields, access expectations, user notice behavior, and retention proof. Do not duplicate raw-text minimization work; extend it into runtime policy and operational controls. A `Fixed` outcome requires minimized payload enforcement plus proven runtime defaults, access boundaries, and cleanup behavior.

Recommended validation:
- `VC-UNIT`
- `VC-KERNEL`
- `VC-QUALITY-GATE`
- `VC-PANTHEON-READONLY`
