<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\EnvironmentDetector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers shared Pantheon environment detection.
 */
#[Group('ilas_site_assistant')]
final class EnvironmentDetectorTest extends TestCase {

  /**
   * Restores environment variables after each test.
   */
  protected function tearDown(): void {
    putenv('PANTHEON_ENVIRONMENT');
    unset($_ENV['PANTHEON_ENVIRONMENT']);
    parent::tearDown();
  }

  /**
   * getenv() wins when a live environment value is present.
   */
  public function testDetectsLiveViaGetenv(): void {
    putenv('PANTHEON_ENVIRONMENT=live');
    $_ENV['PANTHEON_ENVIRONMENT'] = 'dev';

    $detector = new EnvironmentDetector();

    $this->assertSame('live', $detector->getPantheonEnvironment());
    $this->assertTrue($detector->isLiveEnvironment());
  }

  /**
   * Falls back to $_ENV when getenv() is unset.
   */
  public function testDetectsLiveViaEnvFallback(): void {
    putenv('PANTHEON_ENVIRONMENT');
    $_ENV['PANTHEON_ENVIRONMENT'] = ' LIVE ';

    $detector = new EnvironmentDetector();

    $this->assertSame('live', $detector->getPantheonEnvironment());
    $this->assertTrue($detector->isLiveEnvironment());
  }

  /**
   * Returns false for non-live Pantheon environments.
   */
  public function testDetectsNonLiveEnvironment(): void {
    putenv('PANTHEON_ENVIRONMENT=test');
    $_ENV['PANTHEON_ENVIRONMENT'] = 'test';

    $detector = new EnvironmentDetector();

    $this->assertSame('test', $detector->getPantheonEnvironment());
    $this->assertFalse($detector->isLiveEnvironment());
  }

  /**
   * Returns NULL/FALSE when no Pantheon environment is available.
   */
  public function testDetectsUnsetEnvironment(): void {
    putenv('PANTHEON_ENVIRONMENT');
    unset($_ENV['PANTHEON_ENVIRONMENT']);

    $detector = new EnvironmentDetector();

    $this->assertNull($detector->getPantheonEnvironment());
    $this->assertFalse($detector->isLiveEnvironment());
  }

}
