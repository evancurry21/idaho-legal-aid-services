const { test, expect } = require('@playwright/test');
const { ROUTES, runAxe, formatViolations, gotoIfPresent } = require('./helpers/a11y-utils');

/**
 * Impact-card flip pattern.
 *
 * Template: web/themes/custom/b5subtheme/templates/paragraph/paragraph--impact-card.html.twig
 * JS:       web/themes/custom/b5subtheme/js/scripts.js (flipCard, handleTriggerClick)
 *
 * The card root is a non-interactive container. The interactive trigger is a
 * native <button class="impact-card__trigger"> on the front face that carries
 * aria-expanded and aria-controls. The back face's close button is a sibling
 * <button class="impact-card__back-close">. Two sibling buttons — neither
 * nested inside an interactive ancestor — so axe nested-interactive cannot fire.
 * The back face is hidden via aria-hidden + inert + visibility:hidden while
 * closed, so axe color-contrast cannot scan through the rotated face.
 */

const CARD    = '.impact-card';
const TRIGGER = '.impact-card .impact-card__trigger';

test.describe('impact-card a11y', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!ROUTES.impactCards, 'A11Y_ROUTE_IMPACT_CARDS not configured.');
    const ok = await gotoIfPresent(page, ROUTES.impactCards);
    test.skip(!ok, `Route ${ROUTES.impactCards} unavailable.`);
    const present = await page.locator(TRIGGER).count();
    test.skip(present === 0, 'No .impact-card__trigger on this page.');
  });

  test('each trigger has an accessible name', async ({ page }) => {
    const triggers = page.locator(TRIGGER);
    const count = await triggers.count();
    for (let i = 0; i < count; i += 1) {
      const t = triggers.nth(i);
      const name = await t.evaluate((el) => {
        const aria = el.getAttribute('aria-label');
        if (aria && aria.trim()) return aria.trim();
        return (el.textContent || '').trim();
      });
      expect(name, `trigger #${i} accessible name`).not.toBe('');
    }
  });

  test('Enter and Space toggle aria-expanded on the trigger', async ({ page }) => {
    const trigger = page.locator(TRIGGER).first();
    await trigger.focus();
    await expect(trigger).toBeFocused();

    const initial = (await trigger.getAttribute('aria-expanded')) ?? 'false';

    await page.keyboard.press('Enter');
    await expect.poll(async () => trigger.getAttribute('aria-expanded')).not.toBe(initial);

    await page.keyboard.press('Escape'); // global Escape handler in scripts.js
    await expect.poll(async () => trigger.getAttribute('aria-expanded')).toBe(initial);

    await trigger.focus();
    await page.keyboard.press(' ');
    await expect.poll(async () => trigger.getAttribute('aria-expanded')).not.toBe(initial);
  });

  test('focus is visible on the trigger', async ({ page }) => {
    const trigger = page.locator(TRIGGER).first();
    await trigger.focus();
    const hasVisibleFocus = await trigger.evaluate((el) => {
      const cs = window.getComputedStyle(el);
      const outlineHidden = cs.outlineStyle === 'none' || cs.outlineWidth === '0px';
      const noShadow = !cs.boxShadow || cs.boxShadow === 'none';
      // Must have at least one of: outline OR a non-empty box-shadow.
      return !(outlineHidden && noShadow);
    });
    expect(hasVisibleFocus, 'focused trigger must have visible focus indicator (outline or box-shadow)').toBe(true);
  });

  test('card root is not interactive (regression guard)', async ({ page }) => {
    const card = page.locator(CARD).first();
    expect(await card.getAttribute('role'), 'card root must not have role').toBeNull();
    expect(await card.getAttribute('tabindex'), 'card root must not have tabindex').toBeNull();
    expect(await card.getAttribute('aria-expanded'), 'card root must not have aria-expanded').toBeNull();
  });

  test('axe scan of the card region (closed state) has no serious violations', async ({ page }, testInfo) => {
    // Scroll the cards into view and wait for any in-flight paint/animation
    // to settle before axe-core samples pixels. The home page's scroll-reveal
    // (.animate-1 fadeInLeft) plus the card's `transform-style: preserve-3d`
    // 3D context cause axe pixel sampling to read alpha-blended values from
    // the off-screen rasterizer otherwise. With reducedMotion=reduce in the
    // playwright config the animation is already a no-op, but the cards still
    // need to be in the viewport for Chromium to paint them at full fidelity.
    await page.locator(CARD).first().scrollIntoViewIfNeeded();
    await page.waitForFunction(() => {
      const card = document.querySelector('.impact-card .impact-card__trigger');
      if (!card) return false;
      const rect = card.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0;
    });

    const { results, blocking } = await runAxe(page, { include: CARD });
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
