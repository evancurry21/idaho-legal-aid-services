# Manual Steps: New Relic

## Pantheon BYO APM
1. Open the Pantheon support/BYO request with:
   - site name: `<PANTHEON_SITE_NAME>`
   - site ID: `<PANTHEON_SITE_ID>`
   - New Relic account ID: `<NEW_RELIC_ACCOUNT_ID>`
   - New Relic license key: `<NEW_RELIC_LICENSE_KEY>`
2. Do not install a PHP agent on Pantheon from this repository. Pantheon provides the hosted APM agent path.

## Browser Monitoring
1. Create or confirm a Browser app for this site.
2. Generate the browser snippet or NerdGraph `jsConfigScript`.
3. Apply the privacy defaults before saving it as `NEW_RELIC_BROWSER_SNIPPET`:
   - mask/block replay text and media
   - obfuscate email-like strings
   - obfuscate bearer tokens
   - obfuscate cookies/session IDs
   - obfuscate assistant/user-submitted form content
4. Store the final snippet as a Pantheon runtime secret.
5. Verify the snippet renders in the `<head>` of public HTML responses.

## Change Tracking
1. Generate a separate New Relic user/API key and store it as `NEW_RELIC_API_KEY`.
2. Capture the target entity GUIDs and store them as:
   - `NEW_RELIC_ENTITY_GUID_APM`
   - `NEW_RELIC_ENTITY_GUID_BROWSER`
3. Verify the Pantheon deploy hook in `pantheon.yml` can call `scripts/quicksilver/new-relic-change-tracking.php`.

## Alerts, SLOs, and Synthetics
1. Create workflows/destinations for critical production alerts.
2. Add conservative synthetics:
   - live homepage uptime
   - one anonymous critical path
   - one assistant/API path if appropriate
3. Recommended NRQL:
   - p95 latency by env:
     `FROM Transaction SELECT percentile(duration, 95, 99) FACET environment SINCE 1 hour ago`
   - error rate by env:
     `FROM Transaction SELECT percentage(count(*), WHERE error IS true) FACET environment SINCE 1 hour ago`
   - browser JS errors:
     `FROM JavaScriptError SELECT count(*) FACET pageUrl SINCE 1 hour ago`
   - Core Web Vitals:
     `FROM PageViewTiming SELECT percentile(largestContentfulPaint, 75), percentile(interactionToNextPaint, 75), percentile(cumulativeLayoutShift, 75) FACET pageUrl SINCE 1 day ago`
   - assistant endpoint latency:
     `FROM AjaxRequest SELECT percentile(duration, 95) WHERE requestUrl LIKE '%/assistant/api/%' FACET requestUrl SINCE 1 hour ago`

## Security / Governance
1. Review vulnerability/security features for the linked account.
2. Enable SAML SSO / SCIM if required by the organization.
3. Review governance and data-retention settings for the nonprofit account.
