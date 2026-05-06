<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pinned regression that the publish-gate library blocks on failed gates.
 *
 * The strict pre-push hook (scripts/ci/pre-push-strict.sh) and the local
 * preflight (scripts/ci/publish-gate-local.sh) both depend on
 * publish_gates_install_summary_trap preserving the failing exit code. If the
 * trap, the summary script, or downstream consumers ever silently reset the
 * exit code to 0, a failed phpunit/composer/promptfoo gate would no longer
 * abort `git push` and `npm run gate:publish-local`.
 *
 * This test spawns a real bash subprocess that sources the shared library,
 * installs the summary trap, then triggers a failure. It asserts the parent
 * shell observes the exact failing exit code. No live providers, no Drupal
 * kernel — pure shell-contract proof.
 */
#[Group('ilas_site_assistant')]
final class PublishGateBlockingTest extends TestCase {

  /**
   * Repository root resolved from this test's location.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Runs a bash snippet inside the repo root and returns its exit code.
   */
  private static function runBash(string $snippet): int {
    if (!is_executable('/bin/bash') && !is_executable('/usr/bin/bash')) {
      self::markTestSkipped('bash not available on this host.');
    }

    $repoRoot = self::repoRoot();
    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $proc = proc_open(
      ['bash', '-c', $snippet],
      $descriptors,
      $pipes,
      $repoRoot,
      // Inherit a minimal env — the gate functions need PATH for composer
      // detection, but otherwise we keep it deterministic.
      ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin']
    );

    if (!is_resource($proc)) {
      self::fail('Failed to spawn bash subprocess.');
    }

    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($proc);
  }

  /**
   * Trap must preserve the failing exit code from a failed gate.
   *
   * Locks: publish_gates_install_summary_trap captures $? before running the
   * summary script and re-exits with the captured code. Regression guard
   * against the trap accidentally returning the summary script's exit code
   * (which is always 0) instead of the original gate failure.
   */
  public function testTrapPreservesFailingGateExitCode(): void {
    $snippet = <<<'BASH'
set -euo pipefail
source scripts/git/common.sh >/dev/null 2>&1
source scripts/ci/publish-gates.lib.sh >/dev/null 2>&1
publish_gates_init_run "test-blocking-probe" >/dev/null 2>&1
publish_gates_install_summary_trap
fake_failed_gate() {
  exit 42
}
fake_failed_gate >/dev/null 2>&1
BASH;

    $exit = self::runBash($snippet);
    $this->assertSame(
      42,
      $exit,
      'publish_gates_install_summary_trap must propagate the original failing exit code (42), not reset it to 0 or to the summary script exit code.'
    );
  }

  /**
   * A clean run must exit 0 — the trap does not invent failures.
   */
  public function testTrapPropagatesSuccessExitCode(): void {
    $snippet = <<<'BASH'
set -euo pipefail
source scripts/git/common.sh >/dev/null 2>&1
source scripts/ci/publish-gates.lib.sh >/dev/null 2>&1
publish_gates_init_run "test-blocking-probe" >/dev/null 2>&1
publish_gates_install_summary_trap
exit 0
BASH;

    $exit = self::runBash($snippet);
    $this->assertSame(
      0,
      $exit,
      'A clean run with no gate failures must exit 0.'
    );
  }

  /**
   * `gate_composer_dryrun` must exit non-zero when composer is not on PATH.
   *
   * Uses the existing missing-composer branch in scripts/ci/publish-gates.lib.sh
   * (`exit 1` when `command -v composer` fails) as a deterministic, no-network
   * proof that an individual gate function blocks on failure.
   */
  public function testGateComposerDryrunBlocksWhenComposerMissing(): void {
    $snippet = <<<'BASH'
set -uo pipefail
# Strip PATH so composer cannot be found. The gate is documented to exit 1
# when `command -v composer` returns nothing.
export PATH=/nonexistent
source scripts/git/common.sh >/dev/null 2>&1
source scripts/ci/publish-gates.lib.sh >/dev/null 2>&1
publish_gates_init_run "test-blocking-probe" >/dev/null 2>&1
publish_gates_install_summary_trap
gate_composer_dryrun >/dev/null 2>&1
BASH;

    $exit = self::runBash($snippet);
    $this->assertNotSame(
      0,
      $exit,
      'gate_composer_dryrun must exit non-zero when composer is not on PATH — otherwise a missing tool would silently let a publish through.'
    );
  }

}
