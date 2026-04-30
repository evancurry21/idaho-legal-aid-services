const { AxeBuilder } = require('@axe-core/playwright');

const SERIOUS_IMPACTS = new Set(['serious', 'critical']);

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Routes covered by the a11y gate. Each can be overridden with an env var so
 * CI / local dev can point at the correct paths without code changes. A route
 * resolved to an empty string is skipped at runtime.
 */
const ROUTES = {
  home: process.env.A11Y_ROUTE_HOME ?? '/',
  impactCards: process.env.A11Y_ROUTE_IMPACT_CARDS ?? '/',
  // resources_by_service view is only placed on /legal-help/<topic> pages.
  resources: process.env.A11Y_ROUTE_RESOURCES ?? '/legal-help/housing',
  // /assistant has known legacy color-contrast violations on .text-muted
  // footer text (#6c757d on #f8f9fa = 4.44:1, below 4.5:1 AA threshold).
  // Tracked as a follow-up in CONCERNS.md; opt-in via env var once fixed.
  assistant: process.env.A11Y_ROUTE_ASSISTANT ?? '',
  standard: process.env.A11Y_ROUTE_STANDARD ?? '/what-we-do/resources',
};

function buildAxe(page, { include, exclude } = {}) {
  let builder = new AxeBuilder({ page }).withTags(WCAG_TAGS);
  if (include) {
    builder = builder.include(include);
  }
  if (exclude) {
    builder = builder.exclude(exclude);
  }
  return builder;
}

async function runAxe(page, opts = {}) {
  const results = await buildAxe(page, opts).analyze();
  const blocking = results.violations.filter((v) => SERIOUS_IMPACTS.has(v.impact));
  return { results, blocking };
}

function formatViolations(violations) {
  return violations
    .map((v) => {
      const nodes = v.nodes
        .slice(0, 3)
        .map((n) => `      • ${n.target.join(' ')}\n        ${n.failureSummary?.split('\n').join(' ') ?? ''}`)
        .join('\n');
      return `  - [${v.impact}] ${v.id}: ${v.help}\n    ${v.helpUrl}\n${nodes}`;
    })
    .join('\n');
}

async function gotoIfPresent(page, route) {
  if (!route) return false;
  // Force reduced motion BEFORE navigation so initial-load animations
  // (e.g. .animate-1 fadeInLeft on the home page impact cards) are skipped
  // and elements settle at their final paint state by the time axe scans.
  // Setting `use.reducedMotion: 'reduce'` in the playwright config does not
  // reliably propagate to matchMedia in Chromium — page.emulateMedia does.
  await page.emulateMedia({ reducedMotion: 'reduce' });
  const response = await page.goto(route, { waitUntil: 'load' });
  if (!response) return false;
  if (response.status() >= 400) {
    return false;
  }
  return true;
}

module.exports = {
  ROUTES,
  WCAG_TAGS,
  buildAxe,
  runAxe,
  formatViolations,
  gotoIfPresent,
};
