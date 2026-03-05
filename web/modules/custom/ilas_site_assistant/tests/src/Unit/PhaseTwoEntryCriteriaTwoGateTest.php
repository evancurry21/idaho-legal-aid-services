<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 2 Entry criteria #2 closure artifacts (`P2-ENT-02`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoEntryCriteriaTwoGateTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a file from repo root after existence checks.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Roadmap must include dated closure disposition for Phase 2 Entry #2.
   */
  public function testRoadmapContainsPhaseTwoEntryTwoDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 Entry #2 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('Entry criterion #2 is closed', $roadmap);
    $this->assertStringContainsString('phase2-entry2-config-parity-retrieval-tuning.txt', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);
    $this->assertStringContainsString('CLAIM-139', $roadmap);
  }

  /**
   * Current-state must include the dated P2-ENT-02 config parity addendum.
   */
  public function testCurrentStateContainsEntryTwoConfigParityAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 2 Entry #2 Config Parity + Retrieval Tuning Stability Disposition (2026-03-04)',
      $currentState
    );
    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`, `VC-TOGGLE-CHECK`', $currentState);
    $this->assertStringContainsString('phase2-entry2-config-parity-retrieval-tuning.txt', $currentState);
    $this->assertStringContainsString('B-02', $currentState);
    $this->assertStringContainsString('[^CLAIM-139]', $currentState);
  }

  /**
   * Runbook section 3 must include reproducible P2-ENT-02 verification steps.
   */
  public function testRunbookContainsEntryTwoVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 entry #2 config parity + retrieval tuning stability verification (`P2-ENT-02`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-RUNBOOK-LOCAL', $runbook);
    $this->assertStringContainsString('# VC-TOGGLE-CHECK', $runbook);
    $this->assertStringContainsString('VectorSearchConfigSchemaTest', $runbook);
    $this->assertStringContainsString('ConfigCompletenessDriftTest', $runbook);
    $this->assertStringContainsString('phase2-entry2-config-parity-retrieval-tuning.txt', $runbook);
    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
    $this->assertStringContainsString('no broad platform migration outside the current Pantheon baseline', $runbook);
    $this->assertStringContainsString('[^CLAIM-139]', $runbook);
  }

  /**
   * Evidence index must include addenda and the new P2-ENT-02 closure claim.
   */
  public function testEvidenceIndexContainsEntryTwoClosureClaimAndAddenda(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-095', $evidenceIndex);
    $this->assertStringContainsString('CLAIM-124', $evidenceIndex);
    $this->assertStringContainsString('P2-ENT-02', $evidenceIndex);
    $this->assertStringContainsString('## Phase 2 Entry #2 Config Parity + Retrieval Tuning Stability (`P2-ENT-02`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-139', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoEntryCriteriaTwoGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain the required VC and config parity markers.
   */
  public function testRuntimeArtifactContainsRequiredVerificationMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase2-entry2-config-parity-retrieval-tuning.txt');

    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $artifact);
    $this->assertStringContainsString('`VC-TOGGLE-CHECK`', $artifact);
    $this->assertStringContainsString('system.cron_last=', $artifact);
    $this->assertStringContainsString('VectorSearchConfigSchemaTest', $artifact);
    $this->assertStringContainsString('ConfigCompletenessDriftTest', $artifact);
    $this->assertStringContainsString('vector_search', $artifact);
    $this->assertStringContainsString('fallback_gate', $artifact);
  }

}
