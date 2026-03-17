<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the host-safe PHPUnit entrypoint contract.
 */
#[Group('ilas_site_assistant')]
final class HostSafePhpunitEntryPointTest extends TestCase {

  /**
   * Returns repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from repository root.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * The wrapper must default host-shell kernel runs to SQLite.
   */
  public function testHostWrapperDefaultsSimpletestDbToSqlite(): void {
    $script = self::readFile('scripts/ci/run-host-phpunit.sh');

    $this->assertStringContainsString('if [[ -z "${SIMPLETEST_DB:-}" ]]', $script);
    $this->assertStringContainsString('ilas-host-phpunit.sqlite', $script);
    $this->assertStringContainsString('module=sqlite', $script);
    $this->assertStringContainsString('rm -f', $script);
    $this->assertStringContainsString('vendor/bin/phpunit', $script);
    $this->assertStringContainsString('--configuration "$REPO_ROOT/phpunit.xml"', $script);
  }

  /**
   * Package scripts and runbook must advertise the host-safe command.
   */
  public function testPackageAndRunbookAdvertiseHostSafePhpunitCommand(): void {
    $package = self::readFile('package.json');
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '"test:phpunit:host": "bash scripts/ci/run-host-phpunit.sh"',
      $package,
    );
    $this->assertStringContainsString('### Host-shell PHPUnit entrypoint', $runbook);
    $this->assertStringContainsString('npm run test:phpunit:host -- --testsuite kernel --stop-on-failure', $runbook);
    $this->assertStringContainsString('bash scripts/ci/run-host-phpunit.sh', $runbook);
    $this->assertStringContainsString('Raw host `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml`', $runbook);
    $this->assertStringContainsString('is container-oriented because `phpunit.xml` expects the DDEV/Docker MySQL', $runbook);
    $this->assertStringContainsString('ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml', $runbook);
  }

}
