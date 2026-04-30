const { execSync } = require('node:child_process');

const { defineConfig } = require('@playwright/test');

function resolveBaseUrl() {
  if (process.env.PLAYWRIGHT_BASE_URL) {
    return process.env.PLAYWRIGHT_BASE_URL.trim();
  }

  try {
    const raw = execSync('ddev describe -j', {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    });
    const data = JSON.parse(raw);
    const primaryUrl = String(data?.raw?.primary_url || '').trim();
    if (primaryUrl) {
      return primaryUrl;
    }
  } catch (error) {
    // Leave undefined so the test runner surfaces a clear error.
  }

  return undefined;
}

const baseURL = resolveBaseUrl();

module.exports = defineConfig({
  testDir: './tests/a11y',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: 'output/playwright-a11y/report' }],
  ],
  outputDir: 'output/playwright-a11y/test-results',
  use: {
    baseURL,
    browserName: 'chromium',
    headless: true,
    ignoreHTTPSErrors: true,
    // Reduced motion: scroll-reveal entry animations on the home page (e.g.
    // .animate-1 fadeInLeft on .impact-card-col) start at opacity:0 and run
    // for 0.6s. axe-core scans run immediately after domcontentloaded and
    // catch the page mid-animation, producing false-positive color-contrast
    // violations through alpha-blended ancestors. The theme's SCSS already
    // honors prefers-reduced-motion by disabling these animations and
    // setting opacity:1, which is also what motion-sensitive users see.
    reducedMotion: 'reduce',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 10000,
    navigationTimeout: 30000,
  },
});
