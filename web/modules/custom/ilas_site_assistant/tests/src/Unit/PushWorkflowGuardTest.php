<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards strict dual-remote push workflow documentation and scripts.
 */
#[Group('ilas_site_assistant')]
final class PushWorkflowGuardTest extends TestCase {

  /**
   * Returns repository root.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a repository file with existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Runbook must preserve canonical dual-remote push commands.
   */
  public function testRunbookContainsCanonicalDualRemotePushCommands(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('bash scripts/ci/install-pre-push-strict-hook.sh', $runbook);
    $this->assertStringContainsString(
      'ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh',
      $runbook
    );
    $this->assertStringContainsString(
      'CI_BRANCH=master scripts/ci/run-promptfoo-gate.sh --env dev --mode auto',
      $runbook
    );
    $this->assertStringContainsString('git push github master', $runbook);
    $this->assertStringContainsString('git push origin master', $runbook);
  }

  /**
   * Strict pre-push scripts must exist and wire required gate commands.
   */
  public function testStrictPrePushScriptsExistAndContainRequiredCommands(): void {
    $strictHook = self::readFile('scripts/ci/pre-push-strict.sh');
    $installer = self::readFile('scripts/ci/install-pre-push-strict-hook.sh');

    $this->assertStringContainsString('scripts/git/common.sh', $strictHook);
    $this->assertStringContainsString('scripts/git/sync-check.sh', $strictHook);
    $this->assertStringContainsString('run-quality-gate.sh', $strictHook);
    $this->assertStringContainsString(
      'CI_BRANCH="$CURRENT_BRANCH" bash scripts/ci/run-promptfoo-gate.sh --env dev --mode auto',
      $strictHook
    );
    $this->assertStringContainsString('REMOTE_NAME', $strictHook);
    $this->assertStringContainsString('REMOTE_URL', $strictHook);

    $this->assertStringContainsString('.git/hooks/pre-push', $installer);
    $this->assertStringContainsString('pre-push-strict.sh', $installer);
    $this->assertStringContainsString('git push --no-verify', $installer);
  }

}
