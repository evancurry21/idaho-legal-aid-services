<?php

namespace Drupal\ilas_site_assistant\EventSubscriber;

use Drupal\ilas_site_assistant\Service\PiiRedactor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Configures Sentry client options via Raven's OptionsAlter event.
 *
 * Responsibilities:
 * - Forces send_default_pii = false.
 * - Registers a before_send callback that scrubs PII from event messages,
 *   exception values, and extra context using PiiRedactor::redact().
 *
 * Soft dependency: returns an empty event map if drupal/raven is not installed.
 */
class SentryOptionsSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Soft dependency guard: if Raven is not installed, subscribe to nothing.
    if (!class_exists('Drupal\raven\Event\OptionsAlter')) {
      return [];
    }

    return [
      'Drupal\raven\Event\OptionsAlter' => 'onOptionsAlter',
    ];
  }

  /**
   * Alters Sentry client options to disable default PII and add scrubbing.
   *
   * @param object $event
   *   The OptionsAlter event. Typed as object to avoid a hard class dependency.
   */
  public function onOptionsAlter(object $event): void {
    // Disable default PII collection (IP address, cookies, etc.).
    $event->options['send_default_pii'] = FALSE;

    // Register before_send callback for PII scrubbing.
    $event->options['before_send'] = static::beforeSendCallback();
  }

  /**
   * Returns the before_send callback that scrubs PII from Sentry events.
   *
   * @return callable
   *   A callback compatible with Sentry's before_send option.
   */
  public static function beforeSendCallback(): callable {
    return static function (\Sentry\Event $sentryEvent, ?\Sentry\EventHint $hint = NULL): ?\Sentry\Event {
      // Scrub event message.
      $message = $sentryEvent->getMessage();
      if ($message !== NULL && $message !== '') {
        $sentryEvent->setMessage(PiiRedactor::redact($message));
      }

      // Scrub exception values.
      $exceptions = $sentryEvent->getExceptions();
      foreach ($exceptions as $exceptionBag) {
        $value = $exceptionBag->getValue();
        if ($value !== '') {
          $exceptionBag->setValue(PiiRedactor::redact($value));
        }
      }

      // Scrub extra context strings.
      $extra = $sentryEvent->getExtra();
      if (!empty($extra)) {
        $scrubbed = FALSE;
        foreach ($extra as $key => $val) {
          if (is_string($val) && $val !== '') {
            $redacted = PiiRedactor::redact($val);
            if ($redacted !== $val) {
              $extra[$key] = $redacted;
              $scrubbed = TRUE;
            }
          }
        }
        if ($scrubbed) {
          $sentryEvent->setExtra($extra);
        }
      }

      return $sentryEvent;
    };
  }

}
