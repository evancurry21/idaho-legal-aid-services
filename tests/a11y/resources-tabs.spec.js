const { test, expect } = require('@playwright/test');
const { ROUTES, runAxe, formatViolations, gotoIfPresent } = require('./helpers/a11y-utils');

/**
 * Targeted validation of the resources-by-service filter UI.
 *
 * Template: web/themes/custom/b5subtheme/templates/views/views-view--resources-by-service.html.twig
 *
 * The view ships two interchangeable filter patterns:
 *   - Pills (`role="tablist"` with `role="tab"` <button>s) for ≤6 topics
 *   - Dropdown <select> for >6 topics
 * Whichever is visible must satisfy basic ARIA semantics and survive an
 * axe scan with no serious/critical violations.
 */

test.describe('resources-by-service filter a11y', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!ROUTES.resources, 'A11Y_ROUTE_RESOURCES not configured.');
    const ok = await gotoIfPresent(page, ROUTES.resources);
    test.skip(!ok, `Route ${ROUTES.resources} unavailable.`);
    // Skip cleanly if this route does not host the resources_by_service view.
    const hasFilter = await page.locator('.resource-filter-section').count();
    test.skip(hasFilter === 0, `Resource filter not present at ${ROUTES.resources}.`);
  });

  test('visible filter has correct ARIA semantics', async ({ page }) => {
    const pills = page.locator('.resource-filters--pills');
    const dropdown = page.locator('.resource-filters--dropdown');

    // The view JS reveals one or the other once topic counts are known.
    await expect.poll(async () => {
      const pillsVisible = await pills.isVisible().catch(() => false);
      const dropdownVisible = await dropdown.isVisible().catch(() => false);
      return pillsVisible || dropdownVisible;
    }, { timeout: 15000 }).toBe(true);

    if (await pills.isVisible()) {
      const tablist = pills.locator('[role="tablist"]');
      await expect(tablist).toHaveCount(1);

      const tabs = tablist.locator('[role="tab"]');
      const count = await tabs.count();
      expect(count).toBeGreaterThan(0);

      let selected = 0;
      for (let i = 0; i < count; i += 1) {
        const tab = tabs.nth(i);
        const name = (await tab.textContent())?.trim() ?? '';
        expect(name, `tab #${i} accessible name`).not.toBe('');
        if ((await tab.getAttribute('aria-selected')) === 'true') selected += 1;
      }
      expect(selected, 'exactly one tab is aria-selected').toBe(1);
    } else {
      const select = dropdown.locator('select#resource-topic-filter');
      await expect(select).toHaveCount(1);
      const labelFor = await page.locator('label[for="resource-topic-filter"]').count();
      expect(labelFor, '<select> has an associated <label>').toBeGreaterThan(0);
    }
  });

  test('axe scan of the filter region has no serious violations', async ({ page }, testInfo) => {
    // Wait for the filter UI to be revealed before scanning so axe sees the
    // visible variant (pills or dropdown) and not the hidden placeholder.
    await page.waitForSelector('.resource-filters--pills:visible, .resource-filters--dropdown:visible', { timeout: 15000 });
    const { results, blocking } = await runAxe(page, { include: '.resource-filter-section' });
    await testInfo.attach('axe-resources-tabs.json', {
      body: JSON.stringify(results.violations, null, 2),
      contentType: 'application/json',
    });
    if (blocking.length > 0) {
      throw new Error(
        `axe found ${blocking.length} serious/critical violation(s) on resource filter:\n${formatViolations(blocking)}`,
      );
    }
  });
});
