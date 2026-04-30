<?php

namespace Drupal\Tests\ilas_seo\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Regression coverage for L-1 CMS fingerprinting reduction.
 *
 * Asserts that ilas_seo strips the Drupal generator meta tag from rendered
 * pages. Edge-level path blocking (.htaccess) is exercised by
 * scripts/security/fingerprint-smoke.sh against a deployed environment;
 * BrowserTestBase routes every request through index.php and therefore
 * cannot exercise Apache rules.
 *
 * @group ilas_seo
 */
class FingerprintingTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'metatag',
    'ilas_seo',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The front page must not advertise Drupal via <meta name="generator">.
   */
  public function testGeneratorMetaTagRemoved(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotMatches('#<meta[^>]+name="generator"[^>]*content="[^"]*Drupal#i');
  }

}
