<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Commands\RuntimeTruthCommands;
use Drupal\ilas_site_assistant\Service\RuntimeTruthSnapshotBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for the ilas:runtime-truth Drush command.
 */
#[Group('ilas_site_assistant')]
class RuntimeTruthCommandTest extends TestCase {

  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

  /**
   * Returns the repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * The command class and method exist.
   */
  public function testCommandClassAndMethodExist(): void {
    $this->assertTrue(class_exists(RuntimeTruthCommands::class));
    $this->assertTrue(method_exists(RuntimeTruthCommands::class, 'runtimeTruth'));
  }

  /**
   * The command prints a machine-readable JSON snapshot.
   */
  public function testCommandPrintsJsonSnapshot(): void {
    $builder = $this->createMock(RuntimeTruthSnapshotBuilder::class);
    $builder->expects($this->once())
      ->method('buildSnapshot')
      ->willReturn([
        'environment' => ['effective_environment' => 'local'],
        'exported_storage' => [],
        'effective_runtime' => [],
        'runtime_site_settings' => [],
        'browser_expected' => [],
        'override_channels' => [],
        'divergences' => [],
      ]);

    $command = new RuntimeTruthCommands($builder);

    ob_start();
    $result = $command->runtimeTruth();
    $output = ob_get_clean();

    $this->assertSame(0, $result);
    $this->assertIsString($output);
    $decoded = json_decode($output, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('local', $decoded['environment']['effective_environment']);
    $this->assertArrayHasKey('divergences', $decoded);
  }

  /**
   * Builder failures surface as non-zero command failures.
   */
  public function testCommandReturnsNonZeroWhenSnapshotBuildFails(): void {
    $builder = $this->createMock(RuntimeTruthSnapshotBuilder::class);
    $builder->expects($this->once())
      ->method('buildSnapshot')
      ->willThrowException(new \RuntimeException('sync config missing'));

    $command = new RuntimeTruthCommands($builder);

    ob_start();
    $result = $command->runtimeTruth();
    $output = ob_get_clean();

    $this->assertSame(1, $result);
    $this->assertSame('', $output);
  }

  /**
   * The command and builder are registered in service definitions.
   */
  public function testServicesRegisterCommandAndSnapshotBuilder(): void {
    $drushServicesPath = self::repoRoot() . '/' . self::MODULE_PATH . '/drush.services.yml';
    $moduleServicesPath = self::repoRoot() . '/' . self::MODULE_PATH . '/ilas_site_assistant.services.yml';

    $this->assertFileExists($drushServicesPath);
    $this->assertFileExists($moduleServicesPath);

    $drushServices = Yaml::parseFile($drushServicesPath);
    $moduleServices = Yaml::parseFile($moduleServicesPath);

    $this->assertArrayHasKey('ilas_site_assistant.runtime_truth_commands', $drushServices['services']);
    $this->assertSame(
      '\\' . RuntimeTruthCommands::class,
      $drushServices['services']['ilas_site_assistant.runtime_truth_commands']['class'],
    );

    $this->assertArrayHasKey('ilas_site_assistant.runtime_truth_snapshot_builder', $moduleServices['services']);
    $this->assertSame(
      RuntimeTruthSnapshotBuilder::class,
      $moduleServices['services']['ilas_site_assistant.runtime_truth_snapshot_builder']['class'],
    );
  }

}
