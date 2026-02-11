<?php

namespace Drupal\Tests\ilas_security\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Asserts information disclosure hardening is in place.
 *
 * Verifies source code patterns for L-1, L-2, L-5, M-3.
 * No Drupal bootstrap needed.
 */
#[Group('ilas_security')]
class InfoDisclosureSecurityTest extends TestCase {

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
  }

  /**
   * L-1: ilas_seo must remove the system_meta_generator html_head element.
   */
  public function testGeneratorMetaTagRemoval(): void {
    $file = $this->projectRoot . '/web/modules/custom/ilas_seo/ilas_seo.module';
    $this->assertFileExists($file);

    $source = file_get_contents($file);
    $this->assertStringContainsString('system_meta_generator', $source,
      'SECURITY L-1: ilas_seo.module must unset the system_meta_generator html_head element.');
  }

  /**
   * L-1: Event subscriber must strip X-Generator response header.
   */
  public function testXGeneratorHeaderStripped(): void {
    $file = $this->projectRoot . '/web/modules/custom/ilas_seo/src/EventSubscriber/ResponseSubscriber.php';
    $this->assertFileExists($file);

    $source = file_get_contents($file);
    $this->assertStringContainsString("headers->remove('X-Generator')", $source,
      'SECURITY L-1: ResponseSubscriber must remove X-Generator header.');
  }

  /**
   * L-1: Event subscriber must be registered as a service.
   */
  public function testResponseSubscriberRegistered(): void {
    $file = $this->projectRoot . '/web/modules/custom/ilas_seo/ilas_seo.services.yml';
    $this->assertFileExists($file);

    $source = file_get_contents($file);
    $this->assertStringContainsString('ResponseSubscriber', $source,
      'SECURITY L-1: ilas_seo.services.yml must register the ResponseSubscriber.');
    $this->assertStringContainsString('event_subscriber', $source,
      'SECURITY L-1: ResponseSubscriber must be tagged as event_subscriber.');
  }

  /**
   * L-2: settings.php must block core documentation text files.
   */
  public function testCoreTextFilesBlocked(): void {
    $file = $this->projectRoot . '/web/sites/default/settings.php';
    $this->assertFileExists($file);

    $source = file_get_contents($file);
    $this->assertStringContainsString('CHANGELOG', $source,
      'SECURITY L-2: settings.php must block /core/CHANGELOG.txt.');
    $this->assertStringContainsString('INSTALL', $source,
      'SECURITY L-2: settings.php must block /core/INSTALL.txt.');
  }

  /**
   * L-5: security.txt must exist in .well-known directory.
   */
  public function testSecurityTxtExists(): void {
    $file = $this->projectRoot . '/web/.well-known/security.txt';
    $this->assertFileExists($file);

    $content = file_get_contents($file);
    $this->assertStringContainsString('Contact:', $content,
      'SECURITY L-5: security.txt must contain a Contact field.');
    $this->assertStringContainsString('Expires:', $content,
      'SECURITY L-5: security.txt must contain an Expires field.');
  }

  /**
   * M-3: Event subscriber must normalize user profile responses for anonymous.
   */
  public function testUserEnumerationPrevention(): void {
    $file = $this->projectRoot . '/web/modules/custom/ilas_seo/src/EventSubscriber/ResponseSubscriber.php';
    $this->assertFileExists($file);

    $source = file_get_contents($file);
    $this->assertStringContainsString('entity.user.canonical', $source,
      'SECURITY M-3: ResponseSubscriber must check for user canonical route.');
    $this->assertStringContainsString('isAnonymous()', $source,
      'SECURITY M-3: ResponseSubscriber must check for anonymous users.');
    $this->assertStringContainsString('setStatusCode(403)', $source,
      'SECURITY M-3: ResponseSubscriber must normalize 404 to 403 on user routes.');
  }

}
