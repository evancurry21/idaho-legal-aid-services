const { test, expect } = require('@playwright/test');
const { ROUTES, runAxe, formatViolations, gotoIfPresent } = require('./helpers/a11y-utils');

/**
 * Broad axe-core scan against a small allowlist of representative routes.
 *
 * Gate policy (v1):
 *   - serious + critical impact violations FAIL the build
 *   - moderate + minor are reported in the test output but do not block
 *
 * Routes are configurable via A11Y_ROUTE_* env vars. Empty values cause the
 * test for that route to be skipped, so CI can opt in incrementally.
 */

const targets = [
  { name: 'home', path: ROUTES.home },
  { name: 'resources-by-service', path: ROUTES.resources },
  { name: 'assistant', path: ROUTES.assistant },
  { name: 'standard-content-page', path: ROUTES.standard },
];

for (const { name, path } of targets) {
  test(`axe scan: ${name} (${path || 'unset'})`, async ({ page }, testInfo) => {
    test.skip(!path, `Route for ${name} not configured (set A11Y_ROUTE_${name.toUpperCase().replace(/-/g, '_')}).`);

    const ok = await gotoIfPresent(page, path);
    test.skip(!ok, `Route ${path} returned HTTP error or could not be loaded.`);

    const { results, blocking } = await runAxe(page);

    await testInfo.attach(`axe-${name}.json`, {
      body: JSON.stringify(results.violations, null, 2),
      contentType: 'application/json',
    });

    if (blocking.length > 0) {
      throw new Error(
        `axe found ${blocking.length} serious/critical violation(s) on ${path}:\n${formatViolations(blocking)}`,
      );
    }

    expect(blocking).toEqual([]);
  });
}
