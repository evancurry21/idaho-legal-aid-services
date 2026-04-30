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
   */
  protected static $modules = [
    'block',
    'ilas_hotspot',
    'taxonomy',
    'field',
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
   */
  public function testHotspotBlock() {
    // Place the hotspot block.
    $this->drupalGet('admin/structure/block');
    $this->clickLink('Place block');

    // Find and place the ILAS Hotspot block.
    $this->assertSession()->pageTextContains('ILAS Hotspot');
    $this->clickLink('Place block', 0);

    // Configure the block.
    $edit = [
      'settings[label]' => 'Test Hotspot Block',
      'region' => 'content',
    ];
    $this->submitForm($edit, 'Save block');

    // Verify block was placed.
    $this->assertSession()->pageTextContains('The block configuration has been saved.');

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
