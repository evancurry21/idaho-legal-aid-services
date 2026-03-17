<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for runtime observability settings.
 */
#[Group('ilas_site_assistant')]
class ObservabilityRuntimeContractTest extends TestCase {

  /**
   * Returns repo root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Tests settings.php contains the observability runtime contract.
   */
  public function testSettingsContractIncludesBrowserAndReleaseInputs(): void {
    $settings = file_get_contents(self::repoRoot() . '/web/sites/default/settings.php');

    $this->assertIsString($settings);
    $this->assertStringContainsString('SENTRY_BROWSER_DSN', $settings);
    $this->assertStringContainsString('pantheon-multidev-', $settings);
    $this->assertStringContainsString('PANTHEON_DEPLOYMENT_IDENTIFIER', $settings);
    $this->assertStringContainsString('replay_session_sample_rate', $settings);
  }

}
