<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 1 Sprint 3 closure artifacts (`P1-SBD-02`).
 */
#[Group('ilas_site_assistant')]
final class PhaseOneSprintThreeGateTest extends TestCase {

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
   * Roadmap must include Sprint 3 closure and resolved CI blocker.
   */
  public function testRoadmapContainsSprintThreeDispositionAndResolvedBlocker(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 1 Sprint 3 disposition (2026-03-03)', $roadmap);
    $this->assertStringContainsString('Sprint 3: Alert policy finalization, CI gate rollout, reliability failure matrix completion.', $roadmap);
    $this->assertStringContainsString('**Blocker B-03 (RESOLVED 2026-03-03):**', $roadmap);
    $this->assertStringContainsString('B-04 (sustained cron/queue load behavior) stays open', $roadmap);
    $this->assertStringContainsString('no live LLM rollout and no full redesign of retrieval architecture', $roadmap);
    $this->assertStringContainsString('CLAIM-130', $roadmap);
  }

  /**
   * Current state must include Sprint 3 addendum and updated CI harness row.
   */
  public function testCurrentStateContainsSprintThreeClosureAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString('### Phase 1 Sprint 3 Closure Addendum (2026-03-03)', $currentState);
    $this->assertStringContainsString('`P1-SBD-02` completion for Sprint 3 scope', $currentState);
    $this->assertStringContainsString('.github/workflows/quality-gate.yml', $currentState);
    $this->assertStringContainsString('branch protection requires', $currentState);
    $this->assertStringContainsString('DependencyFailureDegradeContractTest.php', $currentState);
    $this->assertStringContainsString('LlmEnhancerHardeningTest.php', $currentState);
    $this->assertStringContainsString('[^CLAIM-130]', $currentState);
  }

  /**
   * Runbook must include Sprint 3 verification using required aliases.
   */
  public function testRunbookContainsSprintThreeVerificationSection(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('### Phase 1 Sprint 3 verification (`P1-SBD-02`)', $runbook);
    $this->assertStringContainsString('# VC-UNIT', $runbook);
    $this->assertStringContainsString('# VC-QUALITY-GATE', $runbook);
    $this->assertStringContainsString('docs/aila/runtime/phase1-sprint3-closure.txt', $runbook);
    $this->assertStringContainsString('phase1-exit1-alerts-dashboards.txt', $runbook);
    $this->assertStringContainsString('phase1-exit3-reliability-failure-matrix.txt', $runbook);
  }

  /**
   * Evidence index must include CLAIM-130 and test/runtime references.
   */
  public function testEvidenceIndexContainsClaim130(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('## Phase 1 Sprint 3 Gap Closure (`P1-SBD-02`)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-130', $evidenceIndex);
    $this->assertStringContainsString('docs/aila/runtime/phase1-sprint3-closure.txt', $evidenceIndex);
    $this->assertStringContainsString('.github/workflows/quality-gate.yml', $evidenceIndex);
    $this->assertStringContainsString('PhaseOneSprintThreeGateTest.php', $evidenceIndex);
  }

  /**
   * Backlog rows for CI gate and reliability matrix must be marked done.
   */
  public function testBacklogMarksSprintThreeRowsDone(): void {
    $backlog = self::readFile('docs/aila/backlog.md');

    $this->assertStringContainsString('**Done (IMP-REL-01 / P1-EXT-03 / P1-SBD-02, 2026-03-03).**', $backlog);
    $this->assertStringContainsString('**Done (IMP-TST-01 / P1-EXT-02 / P1-SBD-02, 2026-03-03).**', $backlog);
    $this->assertStringContainsString('CLAIM-130', $backlog);
  }

  /**
   * Runtime artifact must record Sprint 3 command aliases and linked evidence.
   */
  public function testRuntimeArtifactContainsSprintThreeProofLines(): void {
    $artifact = self::readFile('docs/aila/runtime/phase1-sprint3-closure.txt');

    $this->assertStringContainsString('# Phase 1 Sprint 3 Runtime Evidence (P1-SBD-02)', $artifact);
    $this->assertStringContainsString('### VC-UNIT', $artifact);
    $this->assertStringContainsString('### VC-QUALITY-GATE', $artifact);
    $this->assertStringContainsString('exit_code=0', $artifact);
    $this->assertStringContainsString('docs/aila/runtime/phase1-exit1-alerts-dashboards.txt', $artifact);
    $this->assertStringContainsString('docs/aila/runtime/phase1-exit3-reliability-failure-matrix.txt', $artifact);
    $this->assertStringContainsString('`llm.enabled=false` remains enforced through Phase 2.', $artifact);
  }

}
