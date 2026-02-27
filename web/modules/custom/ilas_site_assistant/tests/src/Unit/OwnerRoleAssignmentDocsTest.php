<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Phase 0 owner-role assignment documentation.
 *
 * These tests lock the role assignments required by roadmap Phase 0
 * entry criterion #2 across the canonical and mirrored audit docs.
 */
#[Group('ilas_site_assistant')]
class OwnerRoleAssignmentDocsTest extends TestCase {

  /**
   * Returns the repository root path.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    return dirname(__DIR__, 7);
  }

  /**
   * Returns a file's content after asserting it exists.
   */
  private static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Runbook section 1 must include the canonical owner-role matrix.
   */
  public function testRunbookContainsPhaseZeroOwnerRoleAssignments(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 0 owner-role assignments (Entry criteria #2)',
      $runbook,
      'Runbook is missing the Phase 0 owner-role assignment subsection',
    );
    $this->assertStringContainsString(
      '| CSRF hardening (`IMP-SEC-01`) | Security Engineer + Drupal Lead |',
      $runbook,
      'Runbook owner matrix missing CSRF hardening owner roles',
    );
    $this->assertStringContainsString(
      '| Policy governance (`IMP-GOV-01` prep) | Compliance Lead + Security Engineer |',
      $runbook,
      'Runbook owner matrix missing policy governance owner roles',
    );
  }

  /**
   * Roadmap owner matrix must include CSRF and policy ownership rows.
   */
  public function testRoadmapOwnerMatrixContainsCsrfAndPolicyRows(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      '| CSRF hardening (`IMP-SEC-01`) | Authenticated test matrix and route enforcement verification | Phase 0 -> prerequisite for Phases 1-3 | Security Engineer + Drupal Lead |',
      $roadmap,
      'Roadmap owner matrix missing CSRF hardening row with owner roles',
    );
    $this->assertStringContainsString(
      '| Policy governance (`IMP-GOV-01` prep) | Audit reporting scope, policy boundary definitions, and restricted signoff workflow | Phase 0 -> prerequisite for governance/compliance execution in Phases 1-3 | Compliance Lead + Security Engineer |',
      $roadmap,
      'Roadmap owner matrix missing policy governance row with owner roles',
    );
  }

  /**
   * Current-state section 7 must mirror owner-role assignment summary.
   */
  public function testCurrentStateSectionSevenMentionsOwnerRoleAssignments(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      'Owner-role assignments for Phase 0 entry criterion #2 are documented in runbook',
      $currentState,
      'Current state section 7 is missing owner-role assignment summary',
    );
  }

  /**
   * CLAIM-013 must preserve permissions evidence for governance ownership.
   */
  public function testEvidenceIndexClaim013MentionsRestrictedGovernanceAccess(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString(
      '### CLAIM-013',
      $evidenceIndex,
      'Evidence index is missing CLAIM-013 heading',
    );
    $this->assertStringContainsString(
      'restricted admin/report/conversation governance access',
      $evidenceIndex,
      'CLAIM-013 must describe restricted governance access',
    );
    $this->assertStringContainsString(
      '`web/modules/custom/ilas_site_assistant/ilas_site_assistant.permissions.yml:1-13`',
      $evidenceIndex,
      'CLAIM-013 must retain permissions.yml evidence linkage',
    );
  }

}
