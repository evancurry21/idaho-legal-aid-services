<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 3 Entry criteria #1 closure artifacts (`P3-ENT-01`).
 */
#[Group('ilas_site_assistant_docs')]
final class PhaseThreeEntryCriteriaOneGateTest extends TestCase {

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
   * Roadmap must include dated closure disposition for Phase 3 Entry #1.
   */
  public function testRoadmapContainsPhaseThreeEntryOneDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 3 Entry #1 disposition (2026-03-05)', $roadmap);
    $this->assertStringContainsString('Entry criterion #1 is closed as documented', $roadmap);
    $this->assertStringContainsString('Phase 2 retrieval quality targets are met and documented', $roadmap);
    $this->assertStringContainsString('phase3-entry1-retrieval-quality-targets.txt', $roadmap);
    $this->assertStringContainsString('no net-new assistant channels or third-party model expansion', $roadmap);
    $this->assertStringContainsString('no platform-wide refactor of unrelated Drupal subsystems', $roadmap);
    $this->assertStringContainsString('CLAIM-151', $roadmap);
  }

  /**
   * Current-state must include the dated P3-ENT-01 retrieval quality addendum.
   */
  public function testCurrentStateContainsEntryOneRetrievalQualityAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 Entry #1 Retrieval Quality Targets Met + Documented Disposition (2026-03-05)',
      $currentState
    );
    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`, `VC-TOGGLE-CHECK`', $currentState);
    $this->assertStringContainsString('phase3-entry1-retrieval-quality-targets.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-151]', $currentState);
  }

  /**
   * Runbook must include reproducible P3-ENT-01 verification steps.
   */
  public function testRunbookContainsEntryOneVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 entry #1 retrieval quality targets met + documented verification (P3-ENT-01)',
      $runbook
    );
    $this->assertStringContainsString('# VC-RUNBOOK-LOCAL', $runbook);
    $this->assertStringContainsString('# VC-TOGGLE-CHECK', $runbook);
    $this->assertStringContainsString('Phase 2 retrieval quality closure continuity checks', $runbook);
    $this->assertStringContainsString('phase3-entry1-retrieval-quality-targets.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-151]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-151 and addenda to CLAIM-065/CLAIM-086.
   */
  public function testEvidenceIndexContainsEntryOneClosureClaim(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-065', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-086', $evidenceIndex);
    $this->assertStringContainsString('## Phase 3 Entry #1 Retrieval Quality Targets Met + Documented (P3-ENT-01)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-151', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeEntryCriteriaOneGateTest.php', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-05): Phase 3 Entry #1 (`P3-ENT-01`)', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain the required VC markers.
   */
  public function testRuntimeArtifactContainsRequiredVerificationMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-entry1-retrieval-quality-targets.txt');

    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $artifact);
    $this->assertStringContainsString('`VC-TOGGLE-CHECK`', $artifact);
    $this->assertStringContainsString('system.cron_last=', $artifact);
    $this->assertStringContainsString('CLAIM-065', $artifact);
    $this->assertStringContainsString('CLAIM-086', $artifact);
    $this->assertStringContainsString('Early retrieval', $artifact);
    $this->assertStringContainsString('Fallback gate decision', $artifact);
  }

  /**
   * All Phase 2 retrieval quality dispositions must be present in roadmap.
   */
  public function testPhaseTwoRetrievalClosureDispositionsPresent(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('Phase 2 Objective #2 disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString('Phase 2 Objective #3 disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString('Phase 2 Deliverable #1 disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString('Phase 2 Deliverable #2 disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString('Phase 2 Deliverable #3 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('Phase 2 Deliverable #4 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('Phase 2 Exit #1 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('Phase 2 Exit #2 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('Phase 2 Sprint 4 disposition (2026-03-05)', $roadmap);
    $this->assertStringContainsString('Phase 2 Sprint 5 disposition (2026-03-05)', $roadmap);
  }

  /**
   * Diagram B retrieval pipeline anchors must remain present.
   */
  public function testDiagramBRetrievalAnchorsPresent(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('flowchart TD', $systemMap);
    $this->assertStringContainsString('Early retrieval', $systemMap);
    $this->assertStringContainsString('Fallback gate decision', $systemMap);
  }

}
