# Environment Variables

## Runtime Secrets
- `SENTRY_DSN`: backend Sentry DSN
- `SENTRY_BROWSER_DSN`: browser Sentry DSN
- `SENTRY_AUTH_TOKEN`: Sentry release/source-map tooling only
- `SENTRY_CRON_MONITOR_ID`: optional Drupal cron monitor ID
- `NEW_RELIC_BROWSER_SNIPPET`: full browser snippet or NerdGraph `jsConfigScript`
- `NEW_RELIC_API_KEY`: New Relic user/API key for change tracking
- `NEW_RELIC_ENTITY_GUID_APM`: target APM entity GUID
- `NEW_RELIC_ENTITY_GUID_BROWSER`: target Browser entity GUID

## Environment / Metadata
- `PUBLIC_SITE_URL`
- `PANTHEON_SITE_NAME`
- `PANTHEON_SITE_ID`
- `PANTHEON_ENVIRONMENT`
- `PANTHEON_DEPLOYMENT_IDENTIFIER`
- `GITHUB_SHA` or `SOURCE_VERSION` for auxiliary `git_sha` tagging

## Local DDEV
- Use `.ddev/.env` or `.ddev/.env.local` for local-only values.
- Start from `.ddev/.env.example`.
- Set `ILAS_LOCAL_BROWSER_OBSERVABILITY=1` if you want local browser Sentry/New Relic injection when the corresponding secrets exist.
- Keep `.ddev/config.newrelic.yaml` local-only; start from `.ddev/config.newrelic.yaml.example`.

## Hosted Pantheon
- Prefer Pantheon runtime secrets.
- Keep browser snippets, DSNs, and API keys out of committed files.
- Pantheon release tagging comes from `PANTHEON_DEPLOYMENT_IDENTIFIER`.
