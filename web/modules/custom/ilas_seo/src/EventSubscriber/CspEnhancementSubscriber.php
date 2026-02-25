<?php

namespace Drupal\ilas_seo\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Appends hardening directives to CSP headers set by SecKit.
 *
 * SecKit 2.0.3 has no UI for base-uri, form-action, or worker-src.
 * This subscriber runs after SecKit (priority 0) and before the
 * existing ResponseSubscriber (priority -10) to append them.
 */
class CspEnhancementSubscriber implements EventSubscriberInterface {

  /**
   * Directives to append if not already present.
   */
  private const DIRECTIVES = [
    'base-uri' => "'self'",
    'form-action' => "'self'",
    'worker-src' => "'self' blob:",
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => [
        ['onResponse', -5],
      ],
    ];
  }

  /**
   * Appends hardening directives to CSP headers on HTML responses.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $response = $event->getResponse();

    // Guardrail 1: Only modify HTML responses.
    $contentType = $response->headers->get('Content-Type', '');
    if (!str_starts_with($contentType, 'text/html')) {
      return;
    }

    // Guardrail 2: Apply to both enforced and report-only headers.
    foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only'] as $headerName) {
      // Guardrail 4: Handle multiple header values.
      $values = $response->headers->all(strtolower($headerName));
      if (empty($values)) {
        continue;
      }

      // Remove existing headers so we can re-set the modified values.
      $response->headers->remove($headerName);

      foreach ($values as $policy) {
        foreach (self::DIRECTIVES as $directive => $value) {
          // Guardrail 3: Idempotent — skip if directive already present.
          if (str_contains($policy, $directive)) {
            continue;
          }
          $policy .= '; ' . $directive . ' ' . $value;
        }
        // Re-add as individual header (false = don't replace previous).
        $response->headers->set($headerName, $policy, FALSE);
      }
    }
  }

}
