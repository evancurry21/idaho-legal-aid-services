<?php

namespace Drupal\Tests\ilas_security\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Asserts donation inquiry and file upload security config values.
 *
 * Reads YAML files from the config sync directory and controller source
 * to verify security requirements. No Drupal bootstrap needed.
 */
#[Group('ilas_security')]
class DonationInquirySecurityTest extends TestCase {

  /**
   * Path to the project root.
   */
  protected string $projectRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->projectRoot = dirname(__DIR__, 7);
    $this->assertDirectoryExists($this->projectRoot . '/config',
      'Config sync directory not found.');
  }

  /**
   * M-4: Filename sanitization options must all be enabled.
   */
  public function testFilenameSanitizationEnabled(): void {
    $file = $this->projectRoot . '/config/file.settings.yml';
    $this->assertFileExists($file);

    $config = Yaml::parseFile($file);
    $sanitization = $config['filename_sanitization'] ?? [];

    $this->assertTrue($sanitization['transliterate'] ?? FALSE,
      'SECURITY M-4: filename_sanitization.transliterate must be true.');
    $this->assertTrue($sanitization['replace_whitespace'] ?? FALSE,
      'SECURITY M-4: filename_sanitization.replace_whitespace must be true.');
    $this->assertTrue($sanitization['replace_non_alphanumeric'] ?? FALSE,
      'SECURITY M-4: filename_sanitization.replace_non_alphanumeric must be true.');
    $this->assertTrue($sanitization['deduplicate_separators'] ?? FALSE,
      'SECURITY M-4: filename_sanitization.deduplicate_separators must be true.');
    $this->assertTrue($sanitization['lowercase'] ?? FALSE,
      'SECURITY M-4: filename_sanitization.lowercase must be true.');
  }

  /**
   * M-10: Donation inquiry controller must use flood control.
   */
  public function testDonationControllerHasFloodControl(): void {
    $file = $this->projectRoot . '/web/modules/custom/ilas_donation_inquiry/src/Controller/DonationInquiryController.php';
    $this->assertFileExists($file);

    $source = file_get_contents($file);

    $this->assertStringContainsString('FloodInterface', $source,
      'SECURITY M-10: DonationInquiryController must inject FloodInterface.');
    $this->assertStringContainsString('isAllowed(', $source,
      'SECURITY M-10: DonationInquiryController must call flood isAllowed().');
    $this->assertStringContainsString("->register('donation_inquiry_submit'", $source,
      'SECURITY M-10: DonationInquiryController must register flood events.');
  }

  /**
   * L-10: source_url must be validated against same host.
   */
  public function testSourceUrlValidation(): void {
    $file = $this->projectRoot . '/web/modules/custom/ilas_donation_inquiry/src/Controller/DonationInquiryController.php';
    $this->assertFileExists($file);

    $source = file_get_contents($file);

    $this->assertStringContainsString('parse_url($sourceUrl)', $source,
      'SECURITY L-10: source_url must be parsed for host validation.');
    $this->assertStringContainsString('getHost()', $source,
      'SECURITY L-10: source_url host must be compared against request host.');
  }

}
