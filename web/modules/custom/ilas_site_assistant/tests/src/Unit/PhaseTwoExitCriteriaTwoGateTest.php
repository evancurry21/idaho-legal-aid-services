<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 2 Exit criterion #2 closure artifacts (`P2-EXT-02`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoExitCriteriaTwoGateTest extends TestCase {

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
   * Roadmap must contain dated closure for Phase 2 Exit criterion #2.
   */
  public function testRoadmapContainsPhaseTwoExitTwoDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 Exit #2 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('CLAIM-141', $roadmap);
    $this->assertStringContainsString('phase2-exit2-citation-coverage-refusal-targets.txt', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);
  }

  /**
   * Current-state must include dated Phase 2 Exit #2 addendum.
   */
  public function testCurrentStateContainsPhaseTwoExitTwoAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 2 Exit #2 Citation Coverage + Low-Confidence Refusal Targets Disposition (2026-03-04)',
      $currentState
    );
    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $currentState);
    $this->assertStringContainsString('`VC-RUNBOOK-PANTHEON`', $currentState);
    $this->assertStringContainsString('`rag-citation-coverage`', $currentState);
    $this->assertStringContainsString('`rag-low-confidence-refusal`', $currentState);
    $this->assertStringContainsString('[^CLAIM-141]', $currentState);
  }

  /**
   * Runbook section 4 must include reproducible P2-EXT-02 verification bundle.
   */
  public function testRunbookContainsPhaseTwoExitTwoVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 exit #2 citation coverage + low-confidence refusal target verification (`P2-EXT-02`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-RUNBOOK-LOCAL', $runbook);
    $this->assertStringContainsString('# VC-RUNBOOK-PANTHEON', $runbook);
    $this->assertStringContainsString('run-promptfoo-gate.sh', $runbook);
    $this->assertStringContainsString('rag-citation-coverage', $runbook);
    $this->assertStringContainsString('rag-low-confidence-refusal', $runbook);
    $this->assertStringContainsString('phase2-exit2-citation-coverage-refusal-targets.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-141]', $runbook);
  }

  /**
   * Evidence index must include addenda and CLAIM-141 closure section.
   */
  public function testEvidenceIndexContainsPhaseTwoExitTwoClaim(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-065', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-086', $evidenceIndex);
    $this->assertStringContainsString('## Phase 2 Exit #2', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-141', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoExitCriteriaTwoGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required verification markers and metric status.
   */
  public function testRuntimeArtifactContainsPhaseTwoExitTwoProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase2-exit2-citation-coverage-refusal-targets.txt');

    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $artifact);
    $this->assertStringContainsString('`VC-RUNBOOK-PANTHEON`', $artifact);
    $this->assertStringContainsString('system.cron_last=', $artifact);
    $this->assertStringContainsString('llm.enabled: false', $artifact);
    $this->assertStringContainsString('rag_citation_coverage_fail=', $artifact);
    $this->assertStringContainsString('rag_low_confidence_refusal_fail=', $artifact);
  }

  /**
   * Diagram B retrieval anchors and metric scenario anchors remain present.
   */
  public function testSystemMapAndMetricScenarioAnchorsRemainPresent(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');
    $thresholds = self::readFile('promptfoo-evals/tests/retrieval-confidence-thresholds.yaml');

    $this->assertStringContainsString('RET[Retrieval', $systemMap);
    $this->assertStringContainsString('Search API + optional vector', $systemMap);
    $this->assertStringContainsString('Early retrieval', $systemMap);
    $this->assertStringContainsString('Fallback gate decision', $systemMap);

    $this->assertStringContainsString('metric: rag-citation-coverage', $thresholds);
    $this->assertStringContainsString('metric: rag-low-confidence-refusal', $thresholds);
  }

}
