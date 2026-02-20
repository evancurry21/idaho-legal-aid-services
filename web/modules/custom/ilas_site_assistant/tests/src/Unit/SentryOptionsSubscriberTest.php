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

}
