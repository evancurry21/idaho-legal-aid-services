/**
 * @jest-environment jsdom
 */

describe('observability.js assistant error capture', function () {
  var recorded;

  function bootstrap(settingsOverrides) {
    recorded = {
      globalTags: null,
      scopeContext: null,
      scopeTags: null,
      replayOptions: null,
      integration: null,
      reportDialogArgs: null,
      captureMessageArgs: null,
    };

    window.Drupal = { t: function (s) { return s; } };
    window.drupalSettings = {
      ilasObservability: Object.assign({
        environment: 'pantheon-test',
        pantheonEnv: 'test',
        multidevName: '',
        release: 'test_155',
        gitSha: '',
        siteName: 'idaho-legal-aid-services',
        siteId: '',
        publicSiteUrl: '',
        routeName: 'ilas_site_assistant.page',
        path: '/assistant',
        assistant: {
          name: 'aila',
          apiBase: '/assistant/api',
        },
        sentry: {
          browserEnabled: true,
          showReportDialog: true,
          replayEnabled: true,
          replaySessionSampleRate: 0.05,
          replayOnErrorSampleRate: 1,
        },
      }, settingsOverrides || {}),
    };

    window.Sentry = {
      setTags: jest.fn(function (tags) {
        recorded.globalTags = tags;
      }),
      addEventProcessor: jest.fn(),
      lazyLoadIntegration: jest.fn(function () {
        return Promise.resolve(function (options) {
          recorded.replayOptions = options;
          return { name: 'replayIntegration', options: options };
        });
      }),
      addIntegration: jest.fn(function (integration) {
        recorded.integration = integration;
      }),
      withScope: jest.fn(function (callback) {
        callback({
          setTags: jest.fn(function (tags) {
            recorded.scopeTags = tags;
          }),
          setContext: jest.fn(function (name, value) {
            if (name === 'assistant_error') {
              recorded.scopeContext = value;
            }
          }),
        });
      }),
      captureMessage: jest.fn(function () {
        recorded.captureMessageArgs = Array.prototype.slice.call(arguments);
        return 'browser-event-123';
      }),
      showReportDialog: jest.fn(function (args) {
        recorded.reportDialogArgs = args;
      }),
      getClient: jest.fn(function () {
        return {
          addIntegration: jest.fn(function (integration) {
            recorded.integration = integration;
          }),
        };
      }),
    };

    jest.resetModules();
    require('../../js/observability.js');
  }

  beforeEach(function () {
    bootstrap();
  });

  afterEach(function () {
    delete window.Drupal;
    delete window.drupalSettings;
    delete window.Sentry;
  });

  test('scrubs assistant error payload and emits bounded tags', async function () {
    window.dispatchEvent(new CustomEvent('ilas:assistant:error', {
      detail: {
        surface: 'page',
        pageMode: true,
        feature: 'browser_probe',
        errorCode: 'synthetic_browser_probe',
        status: 503,
        promptForFeedback: false,
        prompt: 'Need help from jane@example.com',
        body: 'SSN 123-45-6789',
        content: 'Bearer secret-token',
        message: 'Call me at test@example.com',
        custom: 'uuid 123e4567-e89b-12d3-a456-426614174000',
      },
    }));

    await Promise.resolve();
    await Promise.resolve();

    expect(window.Sentry.captureMessage).toHaveBeenCalledWith('AILA browser error', 'error');
    expect(recorded.captureMessageArgs).toEqual(['AILA browser error', 'error']);
    expect(recorded.scopeContext).toEqual({
      surface: 'page',
      pageMode: true,
      feature: 'browser_probe',
      errorCode: 'synthetic_browser_probe',
      status: 503,
      promptForFeedback: false,
      prompt: '[REDACTED]',
      body: '[REDACTED]',
      content: '[REDACTED]',
      message: '[REDACTED]',
      custom: 'uuid [REDACTED-UUID]',
    });
    expect(JSON.stringify(recorded.scopeContext)).not.toContain('jane@example.com');
    expect(JSON.stringify(recorded.scopeContext)).not.toContain('123-45-6789');
    expect(JSON.stringify(recorded.scopeContext)).not.toContain('secret-token');
    expect(recorded.scopeTags).toMatchObject({
      environment: 'pantheon-test',
      pantheon_env: 'test',
      site_name: 'idaho-legal-aid-services',
      assistant_name: 'aila',
      release: 'test_155',
      route_name: 'ilas_site_assistant.page',
      assistant_surface: 'page',
      assistant_mode: 'page',
      assistant_feature: 'browser_probe',
      assistant_route: '/assistant/api',
      error_code: 'synthetic_browser_probe',
    });
    expect(window.Sentry.showReportDialog).not.toHaveBeenCalled();
  });

  test('opens the report dialog only when feedback is requested', async function () {
    window.dispatchEvent(new CustomEvent('ilas:assistant:error', {
      detail: {
        surface: 'widget',
        pageMode: false,
        feature: 'browser_probe',
        errorCode: 'feedback_probe',
        promptForFeedback: true,
      },
    }));

    await Promise.resolve();
    await Promise.resolve();

    expect(window.Sentry.showReportDialog).toHaveBeenCalledWith({ eventId: 'browser-event-123' });
    expect(recorded.reportDialogArgs).toEqual({ eventId: 'browser-event-123' });
  });

  test('loads replay with privacy-safe options when replay is enabled', async function () {
    await Promise.resolve();
    await Promise.resolve();

    expect(window.Sentry.lazyLoadIntegration).toHaveBeenCalledWith('replayIntegration');
    expect(recorded.replayOptions).toEqual({
      maskAllText: true,
      blockAllMedia: true,
      maskAllInputs: true,
      sessionSampleRate: 0.05,
      errorSampleRate: 1,
    });
    expect(recorded.integration).toEqual({
      name: 'replayIntegration',
      options: recorded.replayOptions,
    });
  });

  test('does not request replay when replay is disabled', async function () {
    bootstrap({
      sentry: {
        browserEnabled: true,
        showReportDialog: true,
        replayEnabled: false,
        replaySessionSampleRate: 0,
        replayOnErrorSampleRate: 0,
      },
    });

    await Promise.resolve();
    await Promise.resolve();

    expect(window.Sentry.lazyLoadIntegration).not.toHaveBeenCalled();
    expect(recorded.replayOptions).toBeNull();
    expect(recorded.integration).toBeNull();
  });
});
