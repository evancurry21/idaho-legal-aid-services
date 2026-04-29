# Assistant Evaluation And Smoke Testing

## Authoritative Test Systems

Use these systems for current Site Assistant confidence:

| System | Authoritative For | Not For |
|--------|-------------------|---------|
| Promptfoo (`promptfoo-evals/`) | answer quality, retrieval/grounding expectations, safety boundaries, multilingual and multi-turn quality | HTTP bootstrap mechanics by itself |
| Production-safe smoke (`scripts/smoke/assistant-smoke.mjs`) | `/assistant` reachability, `/assistant/api/session/bootstrap`, CSRF token use, anonymous session cookie continuity, public read endpoints, private endpoint denial | answer quality or vector/LLM quality |
| Playwright (`web/modules/custom/ilas_site_assistant/tests/playwright/`) | widget/page UI behavior and browser product flows | API quality gates by itself |
| PHPUnit/functional tests | deterministic service, controller, and runtime contracts | live provider quality |

`scripts/chatbot-eval/` is legacy chatbot-era tooling. It is preserved for
historical context and possible fixture mining only. Its HTTP mode does not
honor the current strict bootstrap/session/CSRF/conversation contract, so it
must not be treated as active Site Assistant quality coverage.

## Current Commands

Run the production-safe HTTP/session smoke check:

```bash
ASSISTANT_BASE_URL=https://ilas-pantheon.ddev.site npm run test:assistant:smoke
```

If Node does not trust the local DDEV certificate, point it at the mkcert root:

```bash
NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem" \
  ASSISTANT_BASE_URL=https://ilas-pantheon.ddev.site \
  npm run test:assistant:smoke
```

Run the Promptfoo quality mini-suite against an explicit assistant API target:

```bash
export ILAS_ASSISTANT_URL=https://your-assistant.example/assistant/api/message
export ILAS_EVAL_RUN_ID=quality-${GITHUB_SHA:-local}
PROMPTFOO_OUTPUT_FILE=promptfoo-evals/output/results-quality.json \
  bash promptfoo-evals/scripts/run-promptfoo.sh eval promptfooconfig.quality.yaml
```

Run the larger Promptfoo deep suite only against local/dev/test targets:

```bash
export ILAS_ASSISTANT_URL=https://your-dev-assistant.example/assistant/api/message
export ILAS_EVAL_RUN_ID=deep-${GITHUB_SHA:-local}
PROMPTFOO_OUTPUT_FILE=promptfoo-evals/output/results-deep.json \
  bash promptfoo-evals/scripts/run-promptfoo.sh eval promptfooconfig.deep.yaml
```

Run browser UI coverage separately:

```bash
PLAYWRIGHT_BASE_URL=https://ilas-pantheon.ddev.site npm run test:assistant:playwright:smoke
```

## Production-Safe Smoke Test

`scripts/smoke/assistant-smoke.mjs` verifies the public assistant HTTP/session/security contract. It is intentionally not an answer-quality eval and does not require paid LLM, vector, or reranking services.

Run it against an explicit base URL:

```bash
ASSISTANT_BASE_URL=https://ilas-pantheon.ddev.site npm run test:assistant:smoke
```

The default mode is `production-safe`. It checks:

- `GET /assistant`
- `GET /assistant/api/session/bootstrap`
- `POST /assistant/api/message` with the bootstrap CSRF token and session cookie
- `GET /assistant/api/faq?q=eviction`
- `GET /assistant/api/suggest?q=office`
- anonymous denial for `/assistant/api/health`, `/assistant/api/metrics`, and `/admin/reports/ilas-assistant`

The script exits nonzero on failed checks and prints a compact summary of passed, failed, and skipped checks. Diagnostics redact token-like and secret-like values.

## Environment Variables

- `ASSISTANT_BASE_URL`: required site base URL. This is not hardcoded so the same script can target local, dev, test, or live.
- `ASSISTANT_SMOKE_MODE`: `production-safe` or `deep`; defaults to `production-safe`.
- `ASSISTANT_SMOKE_ENV`: optional environment label. If omitted, the script infers `local`, `dev`, `test`, `live`, `prod`, or `unknown` from the hostname.
- `ASSISTANT_SMOKE_TIMEOUT_MS`: optional per-request timeout; defaults to `15000`.

Equivalent CLI options are available:

```bash
node scripts/smoke/assistant-smoke.mjs --base-url https://ilas-pantheon.ddev.site
node scripts/smoke/assistant-smoke.mjs --base-url https://example.test --deep --env test
```

## Deep Mode

Deep mode is for local/dev/test only. It refuses to run unless the target is inferred or explicitly marked as `local`, `dev`, or `test`.

```bash
ASSISTANT_BASE_URL=https://example.test ASSISTANT_SMOKE_MODE=deep ASSISTANT_SMOKE_ENV=test npm run test:assistant:smoke
```

Deep mode adds validation and boundary checks:

- missing CSRF on `/assistant/api/message`
- invalid content type
- malformed JSON
- empty and oversized message validation
- safe legal-advice boundary prompt
- urgent eviction prompt

Rate-limit checks are skipped by default because there is no safe HTTP-only way to lower thresholds for a target environment. Do not use repeated live requests as a substitute for a controlled local/dev rate-limit test.

## Relationship To Promptfoo And Playwright

Use this smoke test before deployment to prove the assistant path, CSRF/session continuity, anonymous access controls, and public read endpoint shapes are intact.

Use Promptfoo as the authoritative answer-quality and retrieval-quality eval
system. Use Playwright separately when evaluating UI behavior or browser
product flows.

Required API-level golden conversation checks live in
`promptfoo-evals/tests/golden-conversations-live.yaml` and the Drupal
functional test
`web/modules/custom/ilas_site_assistant/tests/src/Functional/AssistantMessageRuntimeBehaviorFunctionalTest.php`.

## Reviewed Gap Candidate Tests

Reviewed Assistant Gap Review items can seed future regression tests through:

```bash
ddev drush ilas:export-reviewed-gaps-to-promptfoo
ddev drush ilas:export-reviewed-gaps-to-promptfoo --write
```

The export is dry-run by default. `--write` creates
`promptfoo-evals/output/reviewed-gaps.candidate.yaml`, not a blocking test
fixture. A reviewer must inspect each candidate for PII, over-redaction, and
overfitting before moving it into `promptfoo-evals/tests`.

Use the smallest appropriate suite:

- retrieval/content failures go into retrieval or grounding quality suites;
- safety and OOS failures go into safety/escalation suites;
- Spanish failures go into Spanish or multilingual routing suites;
- continuity failures go into `promptfoo-evals/tests/conversations-deep.src.yaml`
  and then the generated deep suite is rebuilt.

Do not promote one unusual conversation verbatim. Generalize to the reusable
assistant contract and keep deterministic assertions.

## Promptfoo Provider Metadata

Promptfoo uses `promptfoo-evals/providers/ilas-live.js` for real assistant API
evals. The provider appends sanitized metadata lines to each output:

- `[ilas_provider_meta]`: primary assertion contract with public assistant text,
  normalized text, response type/mode/reason, route/topic fields when available,
  citations, links/actions, retrieval result metadata, safe trace IDs, and
  sanitized bootstrap/retry/error metadata.
- `[contract_meta]`: compatibility summary for existing gate tooling.

Grounded means the response has explicit supported citation/source metadata for
the answer. Links, retrieval results, citation-looking strings, and broad topic
keywords are not enough. Those items are exposed as candidates so failures are
diagnosable, but they do not count toward `citations_count`.

Simulated or offline Promptfoo runs can validate harness wiring and assertion
syntax only. They cannot prove assistant behavior, retrieval quality, safety
boundaries, route/intent behavior, CSRF/cookie handling, vector usage, or live
provider integrations. CI may use simulated mode only for non-release plumbing
checks; live/real API mode is required for release, protected-push, and answer
quality gates.
