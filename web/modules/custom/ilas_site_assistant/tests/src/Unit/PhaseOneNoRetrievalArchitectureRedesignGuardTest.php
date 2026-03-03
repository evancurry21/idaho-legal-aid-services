<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards P1-NDO-02: no full retrieval-architecture redesign in Phase 1.
 */
#[Group('ilas_site_assistant')]
final class PhaseOneNoRetrievalArchitectureRedesignGuardTest extends TestCase {

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
   * Roadmap must retain the explicit Phase 1 retrieval-architecture boundary.
   */
  public function testRoadmapRetainsPhaseOneNoRetrievalRedesignBoundary(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      '## Phase 1 (Sprints 2-3): Observability + reliability baseline',
      $roadmap,
    );
    $this->assertStringContainsString(
      '2. No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4)',
      $roadmap,
    );
    $this->assertStringContainsString(
      '### Phase 1 NDO #2 disposition (2026-03-03)',
      $roadmap,
    );
  }

  /**
   * Current-state retrieval section must preserve lexical/vector/fallback shape.
   */
  public function testCurrentStateRetainsRetrievalArchitectureShapeLanguage(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      'Retrieval services combine Search API lexical results with optional vector supplementation and legacy fallback paths.',
      $currentState,
    );
    $this->assertStringContainsString(
      '### Phase 1 NDO #2 Boundary Enforcement Addendum (2026-03-03)',
      $currentState,
    );
    $this->assertStringContainsString('[^CLAIM-131]', $currentState);
  }

  /**
   * Evidence index must preserve retrieval claims and add boundary claim.
   */
  public function testEvidenceIndexRetainsRetrievalClaimsAndBoundaryClaim(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-060', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-065', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-131', $evidenceIndex);
    $this->assertStringContainsString('## Phase 1 NDO #2 Retrieval Architecture Boundary (`P1-NDO-02`)', $evidenceIndex);
  }

  /**
   * Diagram B must continue documenting retrieval and downstream gate anchors.
   */
  public function testSystemMapRetainsDiagramBRetrievalAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('flowchart TD', $systemMap);
    $this->assertStringContainsString('Early retrieval', $systemMap);
    $this->assertStringContainsString('FAQ/resources', $systemMap);
    $this->assertStringContainsString('Fallback gate decision', $systemMap);
    $this->assertStringContainsString('RET[Retrieval', $systemMap);
    $this->assertStringContainsString('FaqIndex + ResourceFinder', $systemMap);
    $this->assertStringContainsString('Search API + optional vector', $systemMap);
  }

  /**
   * Retrieval service anchors must remain declared in service wiring.
   */
  public function testServicesRetainRetrievalAnchors(): void {
    $services = self::readFile('web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml');

    $this->assertStringContainsString('ilas_site_assistant.faq_index:', $services);
    $this->assertStringContainsString('ilas_site_assistant.resource_finder:', $services);
    $this->assertStringContainsString('ilas_site_assistant.ranking_enhancer:', $services);
  }

  /**
   * Runbook must include a dedicated reproducible P1-NDO-02 check section.
   */
  public function testRunbookContainsDedicatedP1Ndo2VerificationSection(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 1 retrieval architecture boundary verification (`P1-NDO-02`)',
      $runbook,
    );
    $this->assertStringContainsString(
      'No full redesign of retrieval architecture',
      $runbook,
    );
    $this->assertStringContainsString(
      'docs/aila/roadmap.md',
      $runbook,
    );
    $this->assertStringContainsString(
      'docs/aila/current-state.md',
      $runbook,
    );
    $this->assertStringContainsString(
      'docs/aila/evidence-index.md',
      $runbook,
    );
    $this->assertStringContainsString(
      'docs/aila/system-map.mmd',
      $runbook,
    );
    $this->assertStringContainsString(
      'web/modules/custom/ilas_site_assistant/ilas_site_assistant.services.yml',
      $runbook,
    );
    $this->assertStringContainsString(
      'Treat any command failure as a scope-boundary violation for `P1-NDO-02`',
      $runbook,
    );
  }

}
