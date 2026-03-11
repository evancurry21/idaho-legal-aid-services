<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Support;

/**
 * Shared canonical URL fixtures for pure-PHP unit tests.
 */
final class CanonicalUrlFixtures {

  /**
   * Returns a complete canonical URL map for offline tests.
   */
  public static function defaults(): array {
    return [
      'apply' => '/apply-for-help',
      'online_application' => 'https://example.com/intake?pid=60&h=test',
      'hotline' => '/Legal-Advice-Line',
      'offices' => '/contact/offices',
      'donate' => '/donate',
      'feedback' => '/get-involved/feedback',
      'resources' => '/what-we-do/resources',
      'forms' => '/forms',
      'guides' => '/guides',
      'senior_risk_detector' => '/resources/legal-risk-detector',
      'faq' => '/faq',
      'services' => '/services',
      'service_areas' => [
        'housing' => '/legal-help/housing',
        'family' => '/legal-help/family',
        'seniors' => '/legal-help/seniors',
        'health' => '/legal-help/health',
        'consumer' => '/legal-help/consumer',
        'civil_rights' => '/legal-help/civil-rights',
      ],
    ];
  }

}
