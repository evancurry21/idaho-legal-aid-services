# Chatbot CI Check (Deprecated Legacy Local Tooling)

> **Deprecated legacy tooling.** `ci-check.php` and the bundled pre-push hook are
> preserved for historical local use only. They are not current Site Assistant
> pre-push coverage, not a production confidence gate, and not used by the
> repository's GitHub Actions workflows.
>
> The evaluation smoke path underneath this script uses the legacy
> `scripts/chatbot-eval` harness. Its HTTP mode does not perform the current
> `/assistant/api/session/bootstrap` flow, does not preserve the anonymous
> session cookie, and does not exercise the strict CSRF/session/conversation
> contract.
>
> Use Promptfoo for answer-quality gates, `scripts/smoke/assistant-smoke.mjs`
> for HTTP/session/security smoke checks, and Playwright for UI behavior.

This script was originally created to catch chatbot-era config regressions.

## Quick Start

```bash
# Run before deploying to Pantheon
php scripts/chatbot-eval/ci-check.php
```

If all legacy checks pass, the script prints:
```
Legacy checks passed
```

That message does not mean the current Site Assistant is safe to deploy. Current
deployment confidence should come from the active Promptfoo, smoke, PHPUnit, and
Playwright checks.

If something fails, you'll see which legacy threshold was violated.

---

## What It Checks

| Step | Check | What It Does |
|------|-------|--------------|
| 1 | YAML Validation | Ensures routing config files are valid YAML |
| 2 | Dataset Validation | Ensures golden dataset CSV is well-formed |
| 3 | Unit Tests | Runs PHPUnit unit tests for chatbot services |
| 4 | Evaluation Smoke Test | Runs 50 test cases and checks accuracy thresholds |

---

## Thresholds

| Metric | Threshold | Current Baseline | Rationale |
|--------|-----------|------------------|-----------|
| **Intent Accuracy** | ≥ 65% | 70.2% | Set 5% below baseline to catch significant regressions without false positives |
| **Action Accuracy** | ≥ 65% | 69.7% | Same rationale as intent |
| **Safety Compliance** | ≥ 85% | 88.2% | Higher bar because safety failures (missing hotline for DV) are critical |
| **Overall Pass Rate** | ≥ 70% | 78.1% | Composite metric, allows some variance |

### Why These Numbers?

1. **5% buffer below baseline**: Config changes often cause minor fluctuations. We want to catch *regressions*, not block normal variance.

2. **Safety is stricter (85%)**: Missing safety language when someone reports domestic violence is unacceptable. The higher threshold ensures we catch safety regressions quickly.

3. **Smoke test (50 cases)**: Running all 201 cases takes longer. The smoke test samples 50 cases for a quick check. Use `--full` for comprehensive testing.

---

## Usage Examples

### Quick check before pushing (legacy, not recommended as current coverage)
```bash
php scripts/chatbot-eval/ci-check.php
```

### Full evaluation (all 201 test cases)
```bash
php scripts/chatbot-eval/ci-check.php --full
```

### Verbose output (debugging)
```bash
php scripts/chatbot-eval/ci-check.php --verbose
```

### Just validate configs (skip tests/eval)
```bash
php scripts/chatbot-eval/ci-check.php --skip-unit --skip-eval
```

### Custom smoke test size
```bash
php scripts/chatbot-eval/ci-check.php --smoke-limit=100
```

---

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | All checks passed |
| 1 | Validation errors (malformed YAML or dataset) |
| 2 | Unit test failures |
| 3 | Evaluation below thresholds (regression) |

---

## Automatic Pre-Push Hook (Legacy, Not Recommended)

The hook is retained only so older local setups can understand what was
installed. Do not install it as the current assistant quality gate.

```bash
# Install the hook
./scripts/chatbot-eval/hooks/install-hook.sh

# Or manually
cp scripts/chatbot-eval/hooks/pre-push .git/hooks/pre-push
chmod +x .git/hooks/pre-push
```

The hook only runs when you're pushing changes to:
- `config/routing/*.yml` (topic_map, acronyms, synonyms, etc.)
- `ilas_site_assistant.settings.yml`
- `chatbot-golden-dataset.csv`

To bypass this legacy hook:
```bash
git push --no-verify
```

To uninstall:
```bash
rm .git/hooks/pre-push
```

---

## Adjusting Thresholds

If baseline performance improves significantly, you may want to raise thresholds to catch smaller regressions.

Edit the constants at the top of `ci-check.php`:

```php
define('THRESHOLD_INTENT_ACCURACY', 0.65);      // Raise to 0.70 if baseline > 75%
define('THRESHOLD_ACTION_ACCURACY', 0.65);      // Raise to 0.70 if baseline > 75%
define('THRESHOLD_SAFETY_COMPLIANCE', 0.85);    // Keep high, safety is critical
define('THRESHOLD_OVERALL_PASS_RATE', 0.70);    // Raise to 0.75 if baseline > 80%
```

---

## Troubleshooting

### "PHPUnit not found, skipping unit tests"
Run `composer install` to install dependencies.

### "Drupal bootstrap failed"
The legacy evaluation needs a running Drupal site for internal mode. Either:
1. Start your DDEV environment: `ddev start`
2. Or use legacy HTTP mode against an existing non-live site, understanding it
   does not exercise the current strict assistant session contract

### "Evaluation below thresholds"
The legacy harness detected a historical-threshold regression. Check the report
in `scripts/chatbot-eval/reports/` only if you are intentionally investigating
old fixtures.

---

## Files

| File | Purpose |
|------|---------|
| `ci-check.php` | Deprecated local validation script |
| `hooks/pre-push` | Deprecated optional git hook |
| `hooks/install-hook.sh` | Installs the deprecated git hook |
| `reports/` | Historical evaluation report output |
