const { test, expect } = require('@playwright/test');

const {
  SELECTORS,
  installAssistantApiMocks,
  loadAssistantFixture,
  normalizeWhitespace,
  runWithAssistantDiagnostics,
} = require('./helpers/assistant-test-utils');

const BOISE_OFFICE_ANSWER = 'Our Boise office is listed on the offices page. Please call before visiting to confirm current hours.';

async function sendPageMessage(page, message) {
  const input = page.locator('#assistant-input');
  await expect(input).toBeVisible();
  await input.fill(message);
  await page.getByRole('button', { name: /send message/i }).click();
}

function mockedBoiseOfficeResponse(message = BOISE_OFFICE_ANSWER) {
  return {
    type: 'navigation',
    message,
    url: '/about/offices/boise',
    cta: 'Boise office information',
  };
}

test.describe('assistant PR smoke fixture', () => {
  test('assistant page fixture loads, mounts, and exposes basic accessible controls', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.pageChat,
      attachmentPrefix: 'assistant-pr-smoke-page-load',
    }, async (assistant) => {
      await installAssistantApiMocks(page);
      await loadAssistantFixture(page, { mode: 'page' });

      const chat = page.getByRole('log', { name: /chat messages/i });
      await expect(chat).toBeVisible();

      const input = page.locator('#assistant-input');
      await expect(input).toBeVisible();
      await input.focus();
      await expect(input).toBeFocused();

      await expect(page.getByRole('button', { name: /send message/i })).toBeVisible();

      const welcomeTurn = await assistant.latestAssistantTurn();
      await expect(welcomeTurn).toContainText('Aila');
      await assistant.assertAssistantTurnHealthy(welcomeTurn);
    });
  });

  test('assistant widget fixture mounts without assistant-owned frontend errors', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.widgetChat,
      attachmentPrefix: 'assistant-pr-smoke-widget-mount',
    }, async (assistant) => {
      await installAssistantApiMocks(page);
      await loadAssistantFixture(page, { mode: 'widget' });

      const toggle = page.getByRole('button', { name: /open aila chat/i });
      await expect(toggle).toBeVisible();
      await toggle.click();

      const panel = page.locator(SELECTORS.widgetPanel);
      await expect(panel).toBeVisible();
      await expect(panel.getByRole('log')).toBeVisible();

      const input = panel.locator('.assistant-input');
      await expect(input).toBeVisible();
      await input.focus();
      await expect(input).toBeFocused();

      await expect(panel.getByRole('button', { name: /^send$/i })).toBeVisible();

      const welcomeTurn = await assistant.latestAssistantTurn();
      await expect(welcomeTurn).toContainText('Aila');
      await assistant.assertAssistantTurnHealthy(welcomeTurn);
    });
  });

  test('bootstrap recovers after one invalid CSRF response and renders the retried answer', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.pageChat,
      attachmentPrefix: 'assistant-pr-smoke-csrf-recovery',
    }, async (assistant) => {
      const mocked = await installAssistantApiMocks(page, {
        bootstrapResponses: [
          { body: 'playwright-csrf-token-1', contentType: 'text/plain; charset=UTF-8' },
          { body: 'playwright-csrf-token-2', contentType: 'text/plain; charset=UTF-8' },
        ],
        messageResponses: [
          {
            status: 403,
            body: {
              message: 'The CSRF token is invalid.',
              error_code: 'csrf_invalid',
            },
          },
          mockedBoiseOfficeResponse('Recovered after refreshing the security token. Boise office information is available.'),
        ],
      });
      await loadAssistantFixture(page, { mode: 'page' });

      await sendPageMessage(page, 'Where is your Boise office?');

      await expect.poll(() => mocked.bootstrapRequests.length).toBe(2);
      await expect.poll(() => mocked.messageRequests.length).toBe(2);

      const latestTurn = await assistant.latestAssistantTurn();
      await expect(latestTurn).toContainText('Recovered after refreshing the security token');
      await expect(page.locator('.recovery-message')).toHaveCount(0);
    });
  });

  test('message send renders a mocked Boise office answer safely', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.pageChat,
      attachmentPrefix: 'assistant-pr-smoke-message-send',
    }, async (assistant) => {
      const mocked = await installAssistantApiMocks(page, {
        messageResponses: [
          mockedBoiseOfficeResponse(),
        ],
      });
      await loadAssistantFixture(page, { mode: 'page' });

      await sendPageMessage(page, 'Where is your Boise office?');

      await expect.poll(() => mocked.messageRequests.length).toBe(1);
      expect(mocked.messageRequests[0]?.message).toBe('Where is your Boise office?');

      const userTurn = await assistant.latestUserTurn();
      await expect(userTurn).toContainText('Where is your Boise office?');

      const latestTurn = await assistant.latestAssistantTurn();
      await expect(latestTurn).toContainText(BOISE_OFFICE_ANSWER);
      await expect(latestTurn.getByRole('link', { name: /boise office information/i })).toHaveAttribute(
        'href',
        /\/about\/offices\/boise$/,
      );
      await assistant.assertAssistantTurnHealthy(latestTurn);
    });
  });

  test('mocked server error shows safe user-facing copy without raw debug output', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.pageChat,
      attachmentPrefix: 'assistant-pr-smoke-server-error',
      allowedAssistantConsolePatterns: [
        /ILAS Assistant API error/i,
      ],
    }, async (assistant) => {
      await installAssistantApiMocks(page, {
        messageResponses: [
          {
            status: 500,
            body: {
              message: 'Traceback: RuntimeException at /var/www/html/web/modules/custom/ilas_site_assistant/src/Controller.php',
              debug: {
                stack: ['Controller.php:123', 'index.php:19'],
              },
            },
          },
        ],
      });
      await loadAssistantFixture(page, { mode: 'page' });

      await sendPageMessage(page, 'Where is your Boise office?');

      const latestTurn = await assistant.latestAssistantTurn();
      const visibleText = normalizeWhitespace(await latestTurn.innerText());

      expect(visibleText).toContain('Our server is having trouble right now');
      expect(visibleText).not.toMatch(/traceback|runtimeexception|controller\.php|\/var\/www|stack|debug/i);
    });
  });

  test('unsafe mocked response HTML is escaped and does not execute', async ({ page }, testInfo) => {
    await runWithAssistantDiagnostics(page, testInfo, {
      chatSelector: SELECTORS.pageChat,
      attachmentPrefix: 'assistant-pr-smoke-xss',
    }, async (assistant) => {
      await installAssistantApiMocks(page, {
        messageResponses: [
          {
            type: 'resources',
            message: 'Unsafe payload <script>window.__assistantXss = true</script><img src=x onerror="window.__assistantXss = true">',
            results: [
              {
                title: 'Boise <script>window.__assistantXss = true</script> office',
                url: 'javascript:window.__assistantXss = true',
                description: '<img src=x onerror="window.__assistantXss = true">Office details',
                type: 'guide',
                has_file: false,
              },
            ],
            fallback_url: 'javascript:window.__assistantXss = true',
            fallback_label: 'Unsafe fallback',
          },
        ],
      });
      await loadAssistantFixture(page, { mode: 'page' });

      await sendPageMessage(page, 'Where is your Boise office?');

      const latestTurn = await assistant.latestAssistantTurn();
      await expect(latestTurn).toContainText('<script>window.__assistantXss = true</script>');
      await expect(latestTurn.locator('script')).toHaveCount(0);
      await expect(latestTurn.locator('img')).toHaveCount(0);
      await expect(latestTurn.locator(SELECTORS.resultLinks).first()).toHaveAttribute('href', /#$/);
      await expect.poll(() => page.evaluate(() => window.__assistantXss)).toBe(false);
      await assistant.assertAssistantTurnHealthy(latestTurn);
    });
  });
});
