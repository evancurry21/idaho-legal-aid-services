<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\EventSubscriber\SentryOptionsSubscriber;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SentryOptionsSubscriber.
 *
 * @group ilas_site_assistant
 * @coversDefaultClass \Drupal\ilas_site_assistant\EventSubscriber\SentryOptionsSubscriber
 */
class SentryOptionsSubscriberTest extends TestCase {

  /**
   * Skips the test if Sentry SDK is not installed.
   */
  protected function requireSentry(): void {
    if (!class_exists('\Sentry\Event')) {
      $this->markTestSkipped('Sentry SDK not installed.');
    }
  }

  /**
   * Skips the test if Raven module is not installed.
   */
  protected function requireRaven(): void {
    if (!class_exists('Drupal\raven\Event\OptionsAlter')) {
      $this->markTestSkipped('drupal/raven not installed.');
    }
  }

  /**
   * Tests that getSubscribedEvents includes OptionsAlter when Raven is present.
   *
   * @covers ::getSubscribedEvents
   */
  public function testSubscribedEventsIncludesOptionsAlter(): void {
    $this->requireRaven();

    $events = SentryOptionsSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey('Drupal\raven\Event\OptionsAlter', $events);
    $this->assertSame('onOptionsAlter', $events['Drupal\raven\Event\OptionsAlter']);
  }

  /**
   * Tests that send_default_pii is disabled after onOptionsAlter.
   *
   * @covers ::onOptionsAlter
   */
  public function testSendDefaultPiiDisabled(): void {
    $this->requireRaven();

    $options = ['send_default_pii' => TRUE];
    $event = new \Drupal\raven\Event\OptionsAlter($options);

    $subscriber = new SentryOptionsSubscriber();
    $subscriber->onOptionsAlter($event);

    $this->assertFalse($event->options['send_default_pii']);
    $this->assertIsCallable($event->options['before_send']);
  }

  /**
   * Tests that server_name is set after onOptionsAlter.
   *
   * @covers ::onOptionsAlter
   */
  public function testServerNameIsSet(): void {
    $this->requireRaven();

    $options = [];
    $event = new \Drupal\raven\Event\OptionsAlter($options);

    $subscriber = new SentryOptionsSubscriber();
    $subscriber->onOptionsAlter($event);

    $this->assertArrayHasKey('server_name', $event->options);
    // Should be "{env}.{sapi}" format.
    $this->assertMatchesRegularExpression('/^.+\..+$/', $event->options['server_name']);
    $this->assertStringContainsString(PHP_SAPI, $event->options['server_name']);
  }

  /**
   * Tests that tags are set after onOptionsAlter.
   *
   * @covers ::onOptionsAlter
   */
  public function testTagsAreSet(): void {
    $this->requireRaven();

    $options = [];
    $event = new \Drupal\raven\Event\OptionsAlter($options);

    $subscriber = new SentryOptionsSubscriber();
    $subscriber->onOptionsAlter($event);

    $this->assertArrayHasKey('tags', $event->options);
    $tags = $event->options['tags'];
    $this->assertArrayHasKey('pantheon_env', $tags);
    $this->assertArrayHasKey('php_sapi', $tags);
    $this->assertArrayHasKey('runtime_context', $tags);
    $this->assertSame(PHP_SAPI, $tags['php_sapi']);
  }

  /**
   * Tests that existing tags are preserved (merged, not overwritten).
   *
   * @covers ::onOptionsAlter
   */
  public function testExistingTagsArePreserved(): void {
    $this->requireRaven();

    $options = [
      'tags' => ['custom_tag' => 'custom_value'],
    ];
    $event = new \Drupal\raven\Event\OptionsAlter($options);

    $subscriber = new SentryOptionsSubscriber();
    $subscriber->onOptionsAlter($event);

    $tags = $event->options['tags'];
    $this->assertSame('custom_value', $tags['custom_tag'], 'Pre-existing tags must be preserved');
    $this->assertArrayHasKey('pantheon_env', $tags);
    $this->assertArrayHasKey('php_sapi', $tags);
    $this->assertArrayHasKey('runtime_context', $tags);
  }

  /**
   * Tests that before_send chains a previous callback.
   *
   * @covers ::onOptionsAlter
   * @covers ::beforeSendCallback
   */
  public function testBeforeSendChainingCallsPrevious(): void {
    $this->requireRaven();
    $this->requireSentry();

    $previousCalled = FALSE;
    $previousCallback = static function (\Sentry\Event $event, ?\Sentry\EventHint $hint = NULL) use (&$previousCalled): ?\Sentry\Event {
      $previousCalled = TRUE;
      $event->setMessage('modified-by-previous: ' . $event->getMessage());
      return $event;
    };

    $options = ['before_send' => $previousCallback];
    $event = new \Drupal\raven\Event\OptionsAlter($options);

    $subscriber = new SentryOptionsSubscriber();
    $subscriber->onOptionsAlter($event);

    // Call the chained before_send.
    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage('test message');
    $result = ($event->options['before_send'])($sentryEvent, NULL);

    $this->assertTrue($previousCalled, 'Previous before_send callback must be called');
    $this->assertNotNull($result);
    $this->assertStringContainsString('modified-by-previous', $result->getMessage());
  }

  /**
   * Tests that chaining handles a previous callback that drops the event.
   *
   * @covers ::beforeSendCallback
   */
  public function testBeforeSendChainingRespectsNull(): void {
    $this->requireSentry();

    $droppingCallback = static function (\Sentry\Event $event, ?\Sentry\EventHint $hint = NULL): ?\Sentry\Event {
      return NULL;
    };

    $callback = SentryOptionsSubscriber::beforeSendCallback($droppingCallback);
    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage('should be dropped');
    $result = $callback($sentryEvent, NULL);

    $this->assertNull($result, 'If previous callback returns NULL, event should be dropped');
  }

  /**
   * Tests that before_send scrubs PII from the event message.
   *
   * @covers ::beforeSendCallback
   */
  public function testBeforeSendScrubsEventMessage(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage('Error for user john@example.com with SSN 123-45-6789');

    $result = $callback($sentryEvent, NULL);

    $this->assertNotNull($result);
    $message = $result->getMessage();
    $this->assertStringContainsString(PiiRedactor::TOKEN_EMAIL, $message);
    $this->assertStringContainsString(PiiRedactor::TOKEN_SSN, $message);
    $this->assertStringNotContainsString('john@example.com', $message);
    $this->assertStringNotContainsString('123-45-6789', $message);
  }

  /**
   * Tests that before_send scrubs PII from exception values.
   *
   * @covers ::beforeSendCallback
   */
  public function testBeforeSendScrubsExceptionValues(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $sentryEvent = \Sentry\Event::createEvent();
    $exception = new \RuntimeException('User called 208-555-1234 for help');
    $exceptionBag = new \Sentry\ExceptionDataBag($exception);
    $sentryEvent->setExceptions([$exceptionBag]);

    $result = $callback($sentryEvent, NULL);

    $this->assertNotNull($result);
    $exceptions = $result->getExceptions();
    $this->assertCount(1, $exceptions);
    $value = $exceptions[0]->getValue();
    $this->assertStringContainsString(PiiRedactor::TOKEN_PHONE, $value);
    $this->assertStringNotContainsString('208-555-1234', $value);
  }

  /**
   * Tests that before_send scrubs PII from extra context strings.
   *
   * @covers ::beforeSendCallback
   */
  public function testBeforeSendScrubsExtraData(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setExtra([
      'user_input' => 'my name is John Smith',
      'count' => 42,
      'clean' => 'no PII here',
    ]);

    $result = $callback($sentryEvent, NULL);

    $this->assertNotNull($result);
    $extra = $result->getExtra();
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $extra['user_input']);
    $this->assertSame(42, $extra['count'], 'Non-string values should be untouched');
    $this->assertSame('no PII here', $extra['clean'], 'Clean strings should be unchanged');
  }

  /**
   * Tests that before_send returns the event (does not drop it).
   *
   * @covers ::beforeSendCallback
   */
  public function testBeforeSendReturnsEvent(): void {
    $this->requireSentry();

    $callback = SentryOptionsSubscriber::beforeSendCallback();

    $sentryEvent = \Sentry\Event::createEvent();
    $sentryEvent->setMessage('Clean message with no PII');

    $result = $callback($sentryEvent, NULL);

    $this->assertSame($sentryEvent, $result, 'Callback should return the same event instance');
  }

  /**
   * Tests isDrushEvalNoise returns FALSE for non-CLI SAPI.
   *
   * @covers ::isDrushEvalNoise
   */
  public function testIsDrushEvalNoiseReturnsFalseForWeb(): void {
    // PHP_SAPI in PHPUnit is 'cli', so we can only test the method's
    // behavior indirectly. When running tests, SAPI is 'cli' and argv
    // typically points to phpunit, not drush — so it should return FALSE.
    $result = SentryOptionsSubscriber::isDrushEvalNoise();
    $this->assertFalse($result, 'isDrushEvalNoise should be FALSE when not running a drush eval command');
  }

  /**
   * Tests isDrushEvalNoise respects SENTRY_CAPTURE_DRUSH_EVAL env var.
   *
   * @covers ::isDrushEvalNoise
   */
  public function testIsDrushEvalNoiseRespectsEnvOverride(): void {
    $original = getenv('SENTRY_CAPTURE_DRUSH_EVAL');
    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');

    try {
      putenv('SENTRY_CAPTURE_DRUSH_EVAL=1');
      putenv('PANTHEON_ENVIRONMENT');

      $result = SentryOptionsSubscriber::isDrushEvalNoise();
      $this->assertFalse($result, 'isDrushEvalNoise should be FALSE when SENTRY_CAPTURE_DRUSH_EVAL=1');
    }
    finally {
      // Restore.
      if ($original !== FALSE) {
        putenv("SENTRY_CAPTURE_DRUSH_EVAL=$original");
      }
      else {
        putenv('SENTRY_CAPTURE_DRUSH_EVAL');
      }
      if ($originalPantheon !== FALSE) {
        putenv("PANTHEON_ENVIRONMENT=$originalPantheon");
      }
      else {
        putenv('PANTHEON_ENVIRONMENT');
      }
    }
  }

  /**
   * Tests isDrushEvalNoise returns FALSE when PANTHEON_ENVIRONMENT is set.
   *
   * @covers ::isDrushEvalNoise
   */
  public function testIsDrushEvalNoiseReturnsFalseOnPantheon(): void {
    $originalPantheon = getenv('PANTHEON_ENVIRONMENT');
    $originalCapture = getenv('SENTRY_CAPTURE_DRUSH_EVAL');

    try {
      putenv('PANTHEON_ENVIRONMENT=live');
      putenv('SENTRY_CAPTURE_DRUSH_EVAL');

      $result = SentryOptionsSubscriber::isDrushEvalNoise();
      $this->assertFalse($result, 'isDrushEvalNoise should be FALSE on Pantheon environments');
    }
    finally {
      if ($originalPantheon !== FALSE) {
        putenv("PANTHEON_ENVIRONMENT=$originalPantheon");
      }
      else {
        putenv('PANTHEON_ENVIRONMENT');
      }
      if ($originalCapture !== FALSE) {
        putenv("SENTRY_CAPTURE_DRUSH_EVAL=$originalCapture");
      }
      else {
        putenv('SENTRY_CAPTURE_DRUSH_EVAL');
      }
    }
  }

}
