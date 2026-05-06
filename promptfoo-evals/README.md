# ILAS Site Assistant — Promptfoo Evaluation Harness

Evaluation scaffolding for the Idaho Legal Aid Services site assistant.
Uses [Promptfoo](https://promptfoo.dev) for multi-turn conversation evaluation
with simulated-user support.

## Status

**Live-wired.** The default provider is the custom JS provider at
`providers/ilas-live.js`, which handles Drupal CSRF tokens automatically.
Run `npm run eval:promptfoo:live` to evaluate against the production assistant.

Promptfoo is the authoritative harness for current Site Assistant answer
quality, retrieval/grounding expectations, safety boundaries, multilingual
behavior, and multi-turn quality. The older `scripts/chatbot-eval/` PHP harness
is deprecated legacy fixture material only; do not use it as a replacement for
Promptfoo gates.

## Directory layout

```
promptfoo-evals/
├── promptfooconfig.yaml          # Main config — providers, tests, options
├── promptfooconfig.quality.yaml  # CI-sized answer-quality mini-suite
├── package.json                  # js-yaml dependency for generator script
├── providers/
│   └── ilas-live.js              # Custom provider (CSRF + live endpoint + multi-turn)
├── prompts/
│   └── ilas-thread.yaml          # Chat prompt template (Nunjucks + YAML)
├── tests/
│   ├── live-50.sanitized.yaml    # 50 single-turn source questions
│   ├── live-10convos.yaml        # Generated: 10 convos x 5 turns (from generator)
│   ├── simulated-user-smoke.yaml # Smoke test suite (5 tests)
│   ├── golden-conversations-live.yaml # Required API-level golden conversations
│   ├── conversation-quality.yaml # Core and multi-turn quality checks
│   ├── retrieval-grounding.yaml  # Source/topic grounding checks
│   ├── safety-boundaries.yaml    # Legal/safety boundary checks
│   ├── spanish-quality.yaml      # Spanish response quality checks
│   └── adversarial-grounding.yaml # Injection and fake-source checks
├── scripts/
│   ├── adjudicate-failures.mjs   # Classifies failed eval cases for audit
│   ├── lint-javascript-assertions.mjs # Fails on multiline JS asserts without return
│   ├── generate-multiturn.js     # Converts 50 single-turn → 10 multi-turn convos
│   ├── run-promptfoo.sh          # Bash wrapper (Linux/macOS)
│   └── run-promptfoo.ps1         # PowerShell wrapper (Windows)
├── output/                       # Generated results (git-ignored)
└── README.md                     # This file
```

## Quick start

### Prerequisites

- Node.js >= 18 (for `npx`)
- Install the repo-pinned dependency with `npm ci`; wrapper scripts use `npx --no-install promptfoo`

### Run evaluation against LIVE endpoint

From the **repo root**:

```bash
npm run eval:promptfoo:live
```

This sets `ILAS_ASSISTANT_URL` inline and runs the 5-test smoke suite against
the production assistant. The custom provider at `providers/ilas-live.js`
automatically fetches a Drupal CSRF token before the first request and retries
once on 403 (token expiry).

The wrapper sets a run salt automatically. To make reruns deterministic across
machines, set an explicit run salt:

```bash
ILAS_EVAL_RUN_ID=eval-remediate-1 npm run eval:promptfoo:live
```

When `ILAS_EVAL_RUN_ID` is present, conversation IDs are deterministic within
the run and isolated across runs, preventing cross-run cache bleed.

**Safety notes:**
- Keep the test set small (5 tests at concurrency 1 = ~5 requests)
- Do not run simulated-user/redteam against production — use a Pantheon multidev
- No PII in test questions
- Rate limits: 15 req/min, 120 req/hr per IP

To run manually or against a different environment:

```bash
export ILAS_ASSISTANT_URL=https://your-multidev.idaholegalaid.org/assistant/api/message
bash promptfoo-evals/scripts/run-promptfoo.sh eval
```

### Run CI quality mini-suite

The quality suite is the small, CI-blocking answer-quality suite. It hits the
real assistant API provider, uses deterministic JavaScript assertions, and does
not use Promptfoo LLM graders or paid remote generation.

```bash
export ILAS_ASSISTANT_URL=https://your-assistant.example/assistant/api/message
export ILAS_EVAL_RUN_ID=quality-${GITHUB_SHA:-local}
PROMPTFOO_OUTPUT_FILE=promptfoo-evals/output/results-quality.json \
  bash promptfoo-evals/scripts/run-promptfoo.sh eval promptfooconfig.quality.yaml
```

CI should treat this command's exit code as blocking for assistant-related PRs.
If `ILAS_ASSISTANT_URL` is unavailable, the suite should fail honestly unless
the job intentionally runs a separate documented mock-mode config. The larger
deep suite can remain nightly/manual.

Blocking coverage in this phase:

- `promptfooconfig.quality.yaml` is the blocking PR-facing quality gate.
- `promptfooconfig.hosted.yaml` is the blocking hosted/manual quality gate.
- `promptfooconfig.smoke.yaml` remains small, but it now rejects obvious
  generic fallback copy on concrete service and eviction prompts.

Some cases are intentionally expected to fail until product behavior is fixed:

- concrete legal-help prompts that still return generic fallback wording;
- retrieval scenarios that do not prove retrieval or vector usage in metadata;
- generation-required scenarios that do not prove `generation.provider=cohere`.

Quality rubric covered by the mini-suite:

| Dimension | What the assertions look for |
|-----------|------------------------------|
| Helpfulness | Correct route/topic/action when available, plus a useful next step |
| Legal safety | No individualized legal advice, outcome prediction, or representation guarantee |
| Grounding | Resource-specific answers need source/topic support, not just any link |
| Continuity | Follow-ups preserve or intentionally reset topic context |
| Clarity | Plain-language, actionable responses without stack traces/debug output |
| Tone | Supportive handling of confused or frustrated users |
| Refusal quality | Refusals redirect to safe ILAS channels where appropriate |
| Brevity | Responses must be useful without dumping irrelevant content |
| Calibration | Unclear or unsupported prompts clarify or escalate instead of overclaiming |

## Reviewed gap candidate exports

Reviewed Site Assistant gap items can be exported into untrusted Promptfoo
candidate cases with Drush:

```bash
ddev drush ilas:export-reviewed-gaps-to-promptfoo
ddev drush ilas:export-reviewed-gaps-to-promptfoo --write
```

The command is dry-run by default. `--write` creates
`promptfoo-evals/output/reviewed-gaps.candidate.yaml`, which is generated and
git-ignored. It refuses to write directly into `promptfoo-evals/tests` because
reviewed gaps are candidates, not automatically trusted regressions.

The exporter reads canonical governance storage only:

- `assistant_gap_item`
- `ilas_site_assistant_conversation_turn`
- `ilas_site_assistant_gap_hit`

It re-runs the site PII redactor before export and skips obvious PII residue.
It does not export raw logs, full conversation IDs, request IDs, reviewer IDs,
reviewer names, legal-hold reasons, Langfuse IDs, Sentry IDs, secrets, or raw
private user data.

Before promotion to CI, a human reviewer should confirm:

- the prompt contains no PII or case-specific facts;
- redaction placeholders do not make the case too vague;
- the failure represents a reusable assistant contract, not a one-off oddity;
- the expected behavior is clear enough for a deterministic assertion;
- the case is placed in the smallest appropriate suite.

Promotion targets:

- Retrieval/content gaps: `tests/retrieval-grounding.yaml` or hosted retrieval
  threshold suites.
- Safety/escalation gaps: `tests/safety-boundaries.yaml`,
  `tests/abuse-safety-hosted.yaml`, or
  `tests/grounding-escalation-safety-boundaries-hosted.yaml`.
- Spanish gaps: `tests/spanish-quality.yaml` or
  `tests/multilingual-routing-live.yaml`.
- Multi-turn continuity gaps: edit `tests/conversations-deep.src.yaml`, then
  regenerate the generated deep suite.

Do not turn one unusual conversation into a brittle assertion. Generalize the
question, keep only the reusable legal-help scenario, and assert the assistant
contract: useful routing, supported retrieval, safe refusal, escalation,
Spanish handling, or continuity.

### Run evaluation (dry-run / offline)

Offline or simulated runs are only for harness plumbing, YAML syntax, and
assertion development. They cannot prove assistant routing, retrieval,
grounding, safety behavior, CSRF/session behavior, vector usage, or live
provider integrations.

If a CI job intentionally runs a simulated pass-rate path, it must label that
mode as simulated and must not satisfy live quality/release gates. Live configs
assert `provider_mode: live_api` through provider metadata so simulated output
cannot silently pass as a real assistant eval.

To run offline without hitting the live endpoint, use a documented mock-mode
config or edit `promptfooconfig.yaml` locally to use an echo provider:

```bash
# Linux / macOS
npm run eval:promptfoo

# Windows PowerShell
npm run eval:promptfoo:win
```

### View results

```bash
npm run view:promptfoo
```

This starts the Promptfoo web UI as a **foreground process**. The viewer
auto-selects port 15500 (or the next free port up to 15510). The URL is only
accessible while the process is running — press `Ctrl+C` to stop it.

To use a custom starting port:

```bash
PROMPTFOO_PORT=16000 npm run view:promptfoo
```

## Privacy & offline defaults

All wrapper scripts automatically set these environment variables:

| Variable                             | Value  | Purpose                          |
|--------------------------------------|--------|----------------------------------|
| `PROMPTFOO_DISABLE_TELEMETRY`        | `1`    | No usage analytics               |
| `PROMPTFOO_DISABLE_UPDATE`           | `1`    | No update checks                 |
| `PROMPTFOO_DISABLE_REMOTE_GENERATION`| `true` | No remote test generation        |
| `PROMPTFOO_DISABLE_SHARING`          | `1`    | No result sharing                |
| `PROMPTFOO_SELF_HOSTED`             | `1`    | Self-hosted mode                 |
| `PROMPTFOO_DISABLE_ADAPTIVE_SCHEDULER` | `1` | Deterministic runtime (no long retry loops) |
| `ILAS_REQUEST_TIMEOUT_MS`            | `45000` | Fail hung live-assistant requests instead of waiting indefinitely |

You can also add these to a `.env` file in `promptfoo-evals/` or export them
in your shell profile.

## How the custom provider works

The custom provider (`providers/ilas-live.js`) handles the Drupal assistant
API contract:

1. **CSRF token/session** — on first request, fetches a token from
   `${baseUrl}/assistant/api/session/bootstrap` and preserves the issued session
   cookie. A legacy `/session/token` fallback remains in the provider only for
   compatibility with older environments; new tests should target the bootstrap
   contract.
2. **POST** — sends `{"message": "<question>", "conversation_id": "<uuid>",
   "context": {...}}` to `/assistant/api/message` with the token in
   `X-CSRF-Token` and the session cookie.
3. **Response rendering** — builds Promptfoo output from the public response
   fields and appends both `[contract_meta]{...}` and
   `[ilas_provider_meta]{...}` for assertions.
4. **Error handling** — retries once on 403 (CSRF expiry), reports 429 rate
   limits, aborts hung requests after `ILAS_REQUEST_TIMEOUT_MS`, and returns
   descriptive errors for network failures

No authentication is needed. The provider still preserves anonymous session
cookies because CSRF and multi-turn continuity depend on the browser-like
session contract.

`[ilas_provider_meta]` is the primary assertion contract. It exposes only
sanitized fields: public assistant text, normalized assistant text,
route/intent/topic fields when returned by the API, response type/mode/reason,
citations, links/actions, retrieval result IDs/titles/URLs/topics/source
classes, safety/OOS/fallback metadata where available, vector/rerank/LLM fields
where available, safe request/correlation IDs, hashed conversation ID, and
sanitized bootstrap/retry/error metadata.

The provider does not expose secrets, raw cookies, CSRF token values, private
user data, or raw unsafe logs. Missing live internals are represented as
`null`, `unknown`, or `unavailable`; assertions must not infer them from broad
keywords or infrastructure health.

`[contract_meta]` remains as a compatibility summary for older gate tooling.
Its citation fields now use strict supported-citation semantics:

- `citations_count` and `supported_citations_count` count only explicit
  supported source/citation metadata.
- `derived_citation_count` counts URLs found in links, actions, and retrieval
  results, but these are candidates only.
- `grounded: true` requires supported citation metadata. A link, a result URL,
  a citation-looking string, or a broad keyword is not grounding.
- `grounding_status` explains whether a response was supported,
  candidate-only, missing required citations, or did not require grounding
  because it was a clarification/refusal/escalation.
- `retrieval_attempted` records whether retrieval was actually attempted.
- `generation.provider` and `generation.used` are the normalized provider-proof
  contract for scenarios that require live Cohere generation.
- `safety.blocked` and `safety.stage` distinguish pre-generation safety blocks
  from ordinary clarifications/refusals.
- `generic_fallback` is a normalized helper for rejecting stock fallback copy
  on concrete legal-help questions.

Promptfoo helper assertions live in `lib/ilas-assertions.js`. Use these instead
of inline keyword checks when possible:

- `hasNoGenericFallback`
- `hasExpectedTopicTerms`
- `hasActionableNextStep`
- `hasGroundedSupportWhenExpected`
- `hasRetrievalAttemptProof`
- `hasVectorRetrievalProof`
- `hasGenerationProviderProof`
- `hasSafetyBlockProof`
- `hasStableConversationTrace`
- `respectsMustNotSafetyLayer`
- `isSpanishOrBilingualUseful`
- `hasSupportedCitation`
- `hasNoUnsupportedClaim`
- `usedExpectedSourceClass`
- `didNotUseDisallowedSource`
- `isSafeLegalBoundary`
- `isUsefulClarification`
- `preservedConversationContext`
- `refusedUnsafeRequestUsefully`

Live/real API mode is required for retrieval quality, grounding, safety
boundaries, route/intent behavior, CSRF/cookie behavior, vector/rerank evidence,
and any release or protected-push quality gate.

## Quality reports

The gate summary and structured diagnostic summary now separate quality by
dimension instead of only reporting aggregate pass rate. Review
`promptfoo-evals/output/structured-error-summary.txt` for:

- `mechanical_transport`
- `retrieval_quality`
- `grounding_quality`
- `safety_quality`
- `multi_turn_continuity`
- `provider_provenance_proof`
- `generic_fallback_failures`

Interpretation:

- A fully green overall pass rate is not enough if one of the quality groups
  is failing.
- `provider_provenance_proof` shows whether Cohere, Pinecone, and Voyage were
  evidenced by the response contract rather than merely configured.
- `generic_fallback_failures` is the fast check for regressions like
  `How can I help you today?` or `What would you like to know?` on concrete
  legal-help questions.

### Enable simulated-user multi-turn (optional)

Uncomment the `redteam` block in `promptfooconfig.yaml` and set
`OPENAI_API_KEY` (or another LLM provider key) so the simulated user can
drive conversations. Only run this against a Pantheon multidev, not production.

## Multi-turn test generation

The default eval suite uses 10 multi-turn conversations (5 turns each, 50 tests
total) to detect quality degradation as conversation context grows.

### One-time setup

```bash
cd promptfoo-evals && npm install && cd ..
```

### Generate multi-turn tests

```bash
npm run generate:multiturn --prefix promptfoo-evals
# or: node promptfoo-evals/scripts/generate-multiturn.js
```

This reads `tests/live-50.sanitized.yaml` (50 single-turn questions), splits
them into 10 sequential groups of 5, and writes `tests/live-10convos.yaml`.

Each turn carries the prior user messages in `vars.history`, which the custom
provider sends as `context.history` in the API request body. Turn 1 of each
conversation has an empty history; turn 5 has 4 prior messages.

Re-run the generator after editing the source questions in
`live-50.sanitized.yaml`.

## Harness integrity checks

Run the JS assertion linter before gate/eval runs:

```bash
node promptfoo-evals/scripts/lint-javascript-assertions.mjs
```

This catches promptfoo custom JS assertions that would otherwise return
`undefined` in multiline blocks and create false negatives.

Generate a failure adjudication artifact from current result files:

```bash
node promptfoo-evals/scripts/adjudicate-failures.mjs
```

This writes `output/failure-adjudication.json` with per-case classification:
`harness_false_negative`, `rubric_false_negative`, or `product_defect`.

## Adding new test suites

Create YAML files in `tests/` and reference them in `promptfooconfig.yaml`:

```yaml
tests:
  - tests/simulated-user-smoke.yaml
  - tests/your-new-suite.yaml
```

Each test case should include:
- `vars.question` — the user message
- `vars.goal` — conversation goal (for documentation / simulated user)
- `assert` — one or more assertions
- `metadata.conversationId` — groups turns in the same conversation

## Troubleshooting

- **"Cannot find module promptfoo"** — ensure Node.js >= 18 is installed.
  Run `npm ci` first; wrapper scripts use the repo-installed `promptfoo` package.
- **Network errors** — the default `echo` provider makes no network calls.
  If you see connection errors, you may have uncommented the HTTP provider
  without setting `ILAS_ASSISTANT_URL`.
- **Telemetry warnings** — the wrapper scripts disable telemetry by default.
  If running `npx promptfoo` directly, set the env vars listed above.

### Viewer troubleshooting (WSL2)

- **Viewer lifecycle** — the URL (`http://localhost:<PORT>`) only works while
  `npm run view:promptfoo` is running. Deep-link URLs (e.g. `/eval-...`)
  require the viewer to be running with access to that eval's data; always
  start from the root URL.
- **Check if a port is in use:**
  ```bash
  ss -ltnp | grep 15500
  ```
- **Kill a stuck viewer process:**
  ```bash
  fuser -k 15500/tcp
  # or
  kill $(ss -ltnp | grep ':15500 ' | grep -oP 'pid=\K\d+')
  ```
- **Custom port:**
  ```bash
  PROMPTFOO_PORT=16000 npm run view:promptfoo
  ```
- **No evals visible?** — run `npm run eval:promptfoo` first. The viewer reads
  from the project-local DB at `promptfoo-evals/.promptfoo/`.
