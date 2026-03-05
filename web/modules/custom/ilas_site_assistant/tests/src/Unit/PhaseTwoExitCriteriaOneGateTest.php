<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 2 Exit criterion #1 closure artifacts (`P2-EXT-01`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoExitCriteriaOneGateTest extends TestCase {

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
   * Roadmap must contain dated closure for Phase 2 Exit criterion #1.
   */
  public function testRoadmapContainsPhaseTwoExitOneDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 Exit #1 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('Retrieval contract and confidence logic pass regression thresholds.', $roadmap);
    $this->assertStringContainsString('phase2-exit1-retrieval-contract-confidence-thresholds.txt', $roadmap);
    $this->assertStringContainsString('CLAIM-140', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $roadmap);
    $this->assertStringContainsString('no broad platform migration outside current Pantheon baseline', $roadmap);
  }

  /**
   * Current-state must include dated Phase 2 Exit #1 addendum.
   */
  public function testCurrentStateContainsPhaseTwoExitOneAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 2 Exit #1 Retrieval Contract + Confidence Threshold Disposition (2026-03-04)',
      $currentState
    );
    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $currentState);
    $this->assertStringContainsString('`VC-RUNBOOK-PANTHEON`', $currentState);
    $this->assertStringContainsString('phase2-exit1-retrieval-contract-confidence-thresholds.txt', $currentState);
    $this->assertStringContainsString('`rag-contract-meta-present`', $currentState);
    $this->assertStringContainsString('`rag-citation-coverage`', $currentState);
    $this->assertStringContainsString('`rag-low-confidence-refusal`', $currentState);
    $this->assertStringContainsString('[^CLAIM-140]', $currentState);
  }

  /**
   * Runbook section 4 must include reproducible P2-EXT-01 verification bundle.
   */
  public function testRunbookContainsPhaseTwoExitOneVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 exit #1 retrieval contract + confidence threshold verification (`P2-EXT-01`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-RUNBOOK-LOCAL', $runbook);
    $this->assertStringContainsString('# VC-RUNBOOK-PANTHEON', $runbook);
    $this->assertStringContainsString('run-promptfoo-gate.sh', $runbook);
    $this->assertStringContainsString('rag-contract-meta-present', $runbook);
    $this->assertStringContainsString('rag-citation-coverage', $runbook);
    $this->assertStringContainsString('rag-low-confidence-refusal', $runbook);
    $this->assertStringContainsString('phase2-exit1-retrieval-contract-confidence-thresholds.txt', $runbook);
    $this->assertStringContainsString('no live LLM enablement through Phase 2', $runbook);
    $this->assertStringContainsString('[^CLAIM-140]', $runbook);
  }

  /**
   * Evidence index must include addenda and CLAIM-140 closure section.
   */
  public function testEvidenceIndexContainsPhaseTwoExitOneClaim(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-062', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-04): Phase 2 Exit #1 (`P2-EXT-01`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-086', $evidenceIndex);
    $this->assertStringContainsString('## Phase 2 Exit #1 Retrieval Contract + Confidence Thresholds (`P2-EXT-01`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-140', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoExitCriteriaOneGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required verification markers and metric status.
   */
  public function testRuntimeArtifactContainsPhaseTwoExitOneProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase2-exit1-retrieval-contract-confidence-thresholds.txt');

    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $artifact);
    $this->assertStringContainsString('`VC-RUNBOOK-PANTHEON`', $artifact);
    $this->assertStringContainsString('system.cron_last=', $artifact);
    $this->assertStringContainsString('llm.enabled: false', $artifact);
    $this->assertStringContainsString('rag_contract_meta_fail=', $artifact);
    $this->assertStringContainsString('rag_citation_coverage_fail=', $artifact);
    $this->assertStringContainsString('rag_low_confidence_refusal_fail=', $artifact);
  }

  /**
   * Diagram B retrieval/fallback anchors and promptfoo provider anchors remain present.
   */
  public function testSystemMapAndProviderAnchorsRemainPresent(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');
    $provider = self::readFile('promptfoo-evals/providers/ilas-live.js');

    $this->assertStringContainsString('RET[Retrieval', $systemMap);
    $this->assertStringContainsString('Search API + optional vector', $systemMap);
    $this->assertStringContainsString('Early retrieval', $systemMap);
    $this->assertStringContainsString('Fallback gate decision', $systemMap);

    $this->assertStringContainsString('/assistant/api/session/bootstrap', $provider);
    $this->assertStringContainsString('/session/token', $provider);
    $this->assertStringContainsString('[contract_meta]', $provider);
  }

}
