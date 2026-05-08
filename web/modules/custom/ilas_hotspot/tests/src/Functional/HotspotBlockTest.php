<?php

namespace Drupal\Tests\ilas_hotspot\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests for ILAS Hotspot block functionality.
 *
 * @group ilas_hotspot
 */
class HotspotBlockTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   *
   * Install-pass fix (Phase 3 follow-up): the previous minimal whitelist
   * (block + ilas_hotspot + taxonomy + field) cannot complete BrowserTestBase
   * install because core's node module installs system.action.* config
   * referencing PHP-attribute-discovered action plugins (e.g.
   * node_make_sticky_action) that are not yet registered with ActionManager
   * at install time. The project's ilas_site_assistant_action_compat
   * test-only module + eca cover those legacy plugin IDs; user/filter/text/
   * views are the standard test scaffolding modules core itself relies on.
   * media + media_library_form_element are hard dependencies declared in
   * ilas_hotspot.info.yml (IlasHotspotBlock's settings form uses a
   * media_library form element). Same pattern applied in FingerprintingTest
   * (commit daae2bf42) and SchemaPropertiesTest. No behavior change to the
   * assertions below.
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
    'block',
    'taxonomy',
    'media',
    'media_library_form_element',
    'ilas_hotspot',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with permission to administer blocks.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create and log in admin user.
    $this->adminUser = $this->drupalCreateUser([
      'administer blocks',
      'administer site configuration',
    ]);
    $this->drupalLogin($this->adminUser);
  }

  /**
   * Tests hotspot block placement and rendering.
   *
   * Phase 3 follow-up fix: previously used clickLink('Place block', 0) to
   * navigate the block-library UI, but that idiom is genuinely ambiguous —
   * /admin/structure/block has a "Place block" button per region (header,
   * content, sidebar, ...) AND each block in the library page has its own
   * "Place block" link, so index 0 routinely placed the wrong block. The
   * sibling testLazyLoading() method in this file uses drupalPlaceBlock()
   * (the canonical Drupal test idiom) and works correctly. Aligning this
   * test with that pattern. The UI-flow coverage is preserved via the
   * separate Block UI tests Drupal core already provides.
   */
  public function testHotspotBlock() {
    // Place the hotspot block via the canonical test idiom (avoids the
    // ambiguous clickLink('Place block', 0) flow in the block-library UI).
    $this->drupalPlaceBlock('ilas_hotspot_block', [
      'label' => 'Test Hotspot Block',
      'region' => 'content',
    ]);

    // Visit the front page to see the block.
    $this->drupalGet('<front>');

    // Check for hotspot container.
    $this->assertSession()->elementExists('css', '.ilas-hotspot-container');

    // Check for background image.
    $this->assertSession()->elementExists('css', '.hotspot-background');

    // Check for hotspot items.
    $this->assertSession()->elementExists('css', '.hotspot-item');
  }

  /**
   * Tests hotspot configuration form.
   */
  public function testHotspotConfiguration() {
    // Go to configuration page.
    $this->drupalGet('admin/config/content/ilas-hotspot');

    // Check that the form exists.
    $this->assertSession()->fieldExists('hotspot_image');
    $this->assertSession()->fieldExists('hotspot_data');
    $this->assertSession()->fieldExists('enable_analytics');

    // Test saving configuration.
    $hotspot_data = json_encode([
      [
        'title' => 'Test Hotspot',
        'content' => 'Test content',
        'category' => 'test',
        'icon' => '/test-icon.svg',
        'placement' => 'top',
      ],
    ]);

    $edit = [
      'hotspot_image' => '/test-image.svg',
      'hotspot_data' => $hotspot_data,
      'enable_analytics' => TRUE,
    ];

    $this->submitForm($edit, 'Save configuration');

    // Verify configuration was saved.
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Reload and verify values.
    $this->drupalGet('admin/config/content/ilas-hotspot');
    $this->assertSession()->fieldValueEquals('hotspot_image', '/test-image.svg');
  }

  /**
   * Tests lazy loading functionality.
   */
  public function testLazyLoading() {
    // Place the hotspot block.
    $block = $this->drupalPlaceBlock('ilas_hotspot_block');

    // Visit page with block.
    $this->drupalGet('<front>');

    // Check for lazy loading attributes.
    $this->assertSession()->elementAttributeContains('css', '.ilas-hotspot-container', 'data-lazy-load', 'hotspot');

    // Check that background image has data-src instead of src.
    $this->assertSession()->elementAttributeExists('css', '.hotspot-background', 'data-src');
  }

}
