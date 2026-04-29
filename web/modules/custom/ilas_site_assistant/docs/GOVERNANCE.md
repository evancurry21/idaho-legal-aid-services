# Aila Conversation Kernel — Governance SOP

## Weekly Review Cadence

**When:** Every Monday (or first business day of the week).

**Who:** Site assistant maintainer(s).

**What:**

1. **Review Langfuse dashboard** for the past 7 days:
   - Check `dead_end_rate` (target: 0%)
   - Check `fallback_L3_rate` (should trend down over time)
   - Check `clarify_rate` (healthy range: 5-15%)
   - Check `history_fallback_rate` (expected: 10-20%)
   - Review `turn_type` distribution (NEW vs FOLLOW_UP vs INVENTORY vs RESET)

2. **Top 10 intents by volume:** Validate coverage against Top Intents Pack.
   If a frequently occurring intent has no pack entry, add one.

3. **Unresolved fallback intents:** Identify queries that hit Level 3+ repeatedly.
   Triage into:
   - **Add to pack:** New intent entry with answer_text + chips
   - **Add synonym:** Existing intent needs better keyword coverage
   - **Out of scope:** Add to out-of-scope classifier patterns

4. **Conversation intent fixture review:** Run
   `vendor/bin/phpunit --filter=ConversationIntentFixtureUnitTest` to verify
   classifier, resolver, and chip fixture regressions haven't been introduced.
   This unit test does not prove public API response quality.

## Intent Pack Update Process

1. Edit `config/intents/top_intents.yml`
2. Run unit tests: `vendor/bin/phpunit --group=ilas_site_assistant --no-configuration --bootstrap=vendor/autoload.php web/modules/custom/ilas_site_assistant/tests/src/Unit/`
3. If adding a new intent:
   - Add to `TopIntentsPackTest` if it represents a significant category
   - Add a conversation intent fixture turn if it covers new classifier,
     resolver, or chip behavior
4. Clear cache on deployment: `drush cr` (TopIntentsPack caches for 1 hour)

## Conversation Intent Fixture Maintenance

- **When to add a fixture:** When a new classifier, resolver, or chip pattern is
  discovered in production that is not covered by existing fixtures.
- **When to update:** When intent routing logic changes in a way that affects
  expected turn types or intent assignments.
- **File:** `tests/goldens/conversation-intent-fixtures.yml`
- **Test:** `tests/src/Unit/ConversationIntentFixtureUnitTest.php`
- **Boundary:** This is unit-level fixture coverage only. API-level golden
  conversation quality belongs in
  `tests/src/Functional/AssistantMessageRuntimeBehaviorFunctionalTest.php` and
  Promptfoo multi-turn suites.

## Threshold Tuning

Thresholds are configured in `ilas_site_assistant.settings`:

| Threshold | Default | Purpose |
|-----------|---------|---------|
| `intent_high_conf` | 0.85 | Above this: answer confidently |
| `intent_medium_conf` | 0.65 | Above this: answer with medium confidence |
| `intent_low_conf` | 0.40 | Above this: still route direct intents |
| `retrieval_high_score` | 0.75 | Above this: high retrieval confidence |
| `combined_high_conf` | 0.80 | Above this: combined answer confidence |
| `combined_fallback_threshold` | 0.50 | Below this: fallback to LLM |

Adjust thresholds based on weekly review metrics. If `clarify_rate` exceeds
15%, consider lowering `intent_medium_conf`. If `dead_end_rate` exceeds 0%,
check fallback tree levels and intent pack coverage.

## Metrics to Track

| Metric | Definition | Target |
|--------|-----------|--------|
| `dead_end_rate` | Responses with no suggestions/links / total responses | 0% |
| `fallback_L3_rate` | Level 3 fallbacks / total fallbacks | Should trend down |
| `clarify_rate` | CLARIFY gate decisions / total decisions | 5-15% |
| `history_fallback_rate` | History fallback source / total intents | 10-20% |
| `turn_type` distribution | NEW vs FOLLOW_UP vs INVENTORY vs RESET | Monitor |
| Top 10 intents | By volume | Validate pack coverage |
| Unresolved fallback intents | Queries hitting Level 3+ repeatedly | Triage weekly |

## Fallback Level Definitions

| Level | Trigger | Response |
|-------|---------|----------|
| 1 | First failure, known intent | Clarifier chips + contact links |
| 2 | Repeated same-area failure | Parent service area page + sub-topic chips |
| 3 | 2+ consecutive failures | Prominent contact info + nearby intents |
| 4 | 3+ consecutive failures | Direct human connection message |

**Invariant:** Every level includes >= 2 actionable links.
