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
   *
   * Install-pass fix (Phase 3 follow-up): the minimal 4-module whitelist
   * (system + node + metatag + ilas_seo) cannot complete BrowserTestBase
   * install because core's node module installs system.action.* config
   * referencing PHP-attribute-discovered action plugins (e.g.
   * node_make_sticky_action) that are not yet registered with
   * ActionManager at install time. The project's ilas_site_assistant_action_compat
   * test-only module + eca cover those legacy plugin IDs; user/field/filter/
   * text/views are the standard test scaffolding modules core itself relies
   * on. Same pattern used in SchemaPropertiesTest. No behavior change to the
   * generator-meta-tag assertion below.
   */
  protected static $modules = [
    'ilas_site_assistant_action_compat',
    'eca',
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'views',
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
