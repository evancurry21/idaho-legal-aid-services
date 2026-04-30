const { test, expect } = require('@playwright/test');
const { ROUTES, runAxe, formatViolations, gotoIfPresent } = require('./helpers/a11y-utils');

/**
 * Targeted validation of the impact-card flip pattern.
 *
 * Template: web/themes/custom/b5subtheme/templates/paragraph/paragraph--impact-card.html.twig
 * JS:       web/themes/custom/b5subtheme/js/scripts.js (handleCardKeydown, flipCard)
 *
 * The card is a `<div role="button" tabindex="0">` that toggles `is-flipped`
 * and `aria-expanded` via Enter / Space; Escape collapses. We validate the
 * WAI-ARIA "button" pattern obligations rather than refactoring to a native
 * <button>, because the existing keyboard contract already meets them and a
 * native button would regress nested-focus management for the back-close.
 */

const SELECTOR = '.impact-card[role="button"][tabindex="0"]';

test.describe('impact-card a11y', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!ROUTES.impactCards, 'A11Y_ROUTE_IMPACT_CARDS not configured.');
    const ok = await gotoIfPresent(page, ROUTES.impactCards);
    test.skip(!ok, `Route ${ROUTES.impactCards} unavailable.`);
    const present = await page.locator(SELECTOR).count();
    test.skip(present === 0, 'No .impact-card[role="button"] on this page.');
  });

  test('each card has an accessible name', async ({ page }) => {
    const cards = page.locator(SELECTOR);
    const count = await cards.count();
    for (let i = 0; i < count; i += 1) {
      const card = cards.nth(i);
      const name = await card.evaluate((el) => {
        const labelledBy = el.getAttribute('aria-labelledby');
        if (labelledBy) {
          const ref = document.getElementById(labelledBy);
          if (ref && ref.textContent.trim()) return ref.textContent.trim();
        }
        const aria = el.getAttribute('aria-label');
        if (aria && aria.trim()) return aria.trim();
        const heading = el.querySelector('h1, h2, h3, h4, h5, h6');
        return heading ? heading.textContent.trim() : '';
      });
      expect(name, `card #${i} accessible name`).not.toBe('');
    }
  });

  test('Enter and Space toggle aria-expanded', async ({ page }) => {
    const card = page.locator(SELECTOR).first();
    await card.focus();
    await expect(card).toBeFocused();

    const initial = (await card.getAttribute('aria-expanded')) ?? 'false';
    await page.keyboard.press('Enter');
    await expect.poll(async () => card.getAttribute('aria-expanded')).not.toBe(initial);

    await page.keyboard.press('Escape');
    await expect.poll(async () => card.getAttribute('aria-expanded')).toBe(initial);

    await card.focus();
    await page.keyboard.press(' ');
    await expect.poll(async () => card.getAttribute('aria-expanded')).not.toBe(initial);
  });

  test('focus is visible on the card', async ({ page }) => {
    const card = page.locator(SELECTOR).first();
    await card.focus();
    const hasVisibleFocus = await card.evaluate((el) => {
      const cs = window.getComputedStyle(el);
      const outlineHidden = cs.outlineStyle === 'none' || cs.outlineWidth === '0px';
      const noShadow = !cs.boxShadow || cs.boxShadow === 'none';
      // Must have at least one of: outline OR a non-empty box-shadow.
      return !(outlineHidden && noShadow);
    });
    expect(hasVisibleFocus, 'focused card must have visible focus indicator (outline or box-shadow)').toBe(true);
  });

  test('axe scan of the card region (closed state) has no serious violations', async ({ page }, testInfo) => {
    const { results, blocking } = await runAxe(page, { include: SELECTOR });
    await testInfo.attach('axe-impact-card.json', {
      body: JSON.stringify(results.violations, null, 2),
      contentType: 'application/json',
    });
    if (blocking.length > 0) {
      throw new Error(
        `axe found ${blocking.length} serious/critical violation(s) on impact-card:\n${formatViolations(blocking)}`,
      );
    }
  });
});
