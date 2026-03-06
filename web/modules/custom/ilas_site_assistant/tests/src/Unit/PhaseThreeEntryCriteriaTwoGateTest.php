<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Phase 3 Entry criteria #2 closure artifacts (`P3-ENT-02`).
 */
#[Group('ilas_site_assistant')]
final class PhaseThreeEntryCriteriaTwoGateTest extends TestCase {

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
   * Roadmap must include dated closure disposition for Phase 3 Entry #2.
   */
  public function testRoadmapContainsPhaseThreeEntryTwoDisposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 3 Entry #2 disposition (2026-03-05)', $roadmap);
    $this->assertStringContainsString('SLO/alert operational data now has at least one sprint of trend history', $roadmap);
    $this->assertStringContainsString('10 business days', $roadmap);
    $this->assertStringContainsString('2026-02-20 through 2026-03-05', $roadmap);
    $this->assertStringContainsString('phase3-entry2-slo-alert-trend-history.txt', $roadmap);
    $this->assertStringContainsString('no net-new assistant channels or third-party model expansion', $roadmap);
    $this->assertStringContainsString('no platform-wide refactor of unrelated Drupal subsystems', $roadmap);
    $this->assertStringContainsString('B-04', $roadmap);
    $this->assertStringContainsString('CLAIM-152', $roadmap);
  }

  /**
   * Current-state must include dated P3-ENT-02 trend-history addendum.
   */
  public function testCurrentStateContainsEntryTwoTrendHistoryAddendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 3 Entry #2 SLO/Alert Trend History Disposition (2026-03-05)',
      $currentState
    );
    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`, `VC-TOGGLE-CHECK`', $currentState);
    $this->assertStringContainsString('10 business days', $currentState);
    $this->assertStringContainsString('phase3-entry2-slo-alert-trend-history.txt', $currentState);
    $this->assertStringContainsString('B-04', $currentState);
    $this->assertStringContainsString('[^CLAIM-152]', $currentState);
  }

  /**
   * Runbook must include reproducible P3-ENT-02 verification steps.
   */
  public function testRunbookContainsEntryTwoVerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 3 entry #2 SLO/alert operational trend history verification (P3-ENT-02)',
      $runbook
    );
    $this->assertStringContainsString('# VC-RUNBOOK-LOCAL', $runbook);
    $this->assertStringContainsString('# VC-TOGGLE-CHECK', $runbook);
    $this->assertStringContainsString('Local watchdog trend min/max + span hours/days', $runbook);
    $this->assertStringContainsString('WITH RECURSIVE bounds AS', $runbook);
    $this->assertStringContainsString('slo_violation_count', $runbook);
    $this->assertStringContainsString('phase3-entry2-slo-alert-trend-history.txt', $runbook);
    $this->assertStringContainsString('PhaseThreeEntryCriteriaTwoGateTest.php', $runbook);
    $this->assertStringContainsString('[^CLAIM-152]', $runbook);
  }

  /**
   * Evidence index must include CLAIM-152 and addenda to CLAIM-084/CLAIM-121.
   */
  public function testEvidenceIndexContainsEntryTwoClosureClaimAndAddenda(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-084', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-121', $evidenceIndex);
    $this->assertStringContainsString('Addendum (2026-03-05): Phase 3 Entry #2 (`P3-ENT-02`)', $evidenceIndex);
    $this->assertStringContainsString('## Phase 3 Entry #2 SLO/Alert Operational Trend History (P3-ENT-02)', $evidenceIndex);
    $this->assertStringContainsString('### CLAIM-152', $evidenceIndex);
    $this->assertStringContainsString('PhaseThreeEntryCriteriaTwoGateTest.php', $evidenceIndex);
  }

  /**
   * Runtime artifact must contain required trend-history markers.
   */
  public function testRuntimeArtifactContainsRequiredTrendHistoryMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase3-entry2-slo-alert-trend-history.txt');

    $this->assertStringContainsString('`VC-RUNBOOK-LOCAL`', $artifact);
    $this->assertStringContainsString('`VC-TOGGLE-CHECK`', $artifact);
    $this->assertStringContainsString('p3-ent-02-status=closed', $artifact);
    $this->assertStringContainsString('p3-ent-02-definition=10-business-days', $artifact);
    $this->assertStringContainsString('trend-window-start=', $artifact);
    $this->assertStringContainsString('trend-window-end=', $artifact);
    $this->assertStringContainsString('trend-calendar-days=', $artifact);
    $this->assertStringContainsString('trend-business-days=', $artifact);
    $this->assertStringContainsString('trend-anchor-claim-084=present', $artifact);
    $this->assertStringContainsString('trend-anchor-claim-121=present', $artifact);
    $this->assertStringContainsString('b04-status=open', $artifact);
  }

  /**
   * Diagram B continuity anchors must remain present.
   */
  public function testSystemMapRetainsDiagramBContinuityAnchors(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('flowchart TD', $systemMap);
    $this->assertStringContainsString('Early retrieval', $systemMap);
    $this->assertStringContainsString('Fallback gate decision', $systemMap);
    $this->assertStringContainsString('Queue worker on cron', $systemMap);
  }

}
