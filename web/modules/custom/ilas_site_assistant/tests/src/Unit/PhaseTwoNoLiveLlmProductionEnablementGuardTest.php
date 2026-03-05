<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards P2-NDO-01: "No live production LLM enablement" for Phase 2 scope.
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoNoLiveLlmProductionEnablementGuardTest extends TestCase {

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
   * Roadmap must contain dated P2-NDO-01 disposition.
   */
  public function testRoadmapContainsPhaseTwoNdo01Disposition(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString('### Phase 2 NDO #1 disposition (2026-03-04)', $roadmap);
    $this->assertStringContainsString('no live production LLM enablement in this phase', $roadmap);
    $this->assertStringContainsString('CLAIM-145', $roadmap);
    $this->assertStringContainsString('PhaseTwoNoLiveLlmProductionEnablementGuardTest.php', $roadmap);
  }

  /**
   * Current-state must include dated P2-NDO-01 addendum.
   */
  public function testCurrentStateContainsPhaseTwoNdo01Addendum(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '### Phase 2 NDO #1 No Live Production LLM Enablement Disposition (2026-03-04)',
      $currentState
    );
    $this->assertStringContainsString('P2-NDO-01', $currentState);
    $this->assertStringContainsString('phase2-ndo1-no-live-llm-production-enablement.txt', $currentState);
    $this->assertStringContainsString('[^CLAIM-145]', $currentState);
  }

  /**
   * Evidence index must include CLAIM-145 for P2-NDO-01 closure.
   */
  public function testEvidenceIndexContainsClaim145(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-145', $evidenceIndex);
    $this->assertStringContainsString('P2-NDO-01', $evidenceIndex);
    $this->assertStringContainsString('no live production LLM enablement in Phase 2', $evidenceIndex);
    $this->assertStringContainsString('PhaseTwoNoLiveLlmProductionEnablementGuardTest.php', $evidenceIndex);
  }

  /**
   * Runbook must include P2-NDO-01 verification bundle.
   */
  public function testRunbookContainsPhaseTwoNdo01VerificationBundle(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString(
      '### Phase 2 NDO #1 no live production LLM enablement verification (`P2-NDO-01`)',
      $runbook
    );
    $this->assertStringContainsString('# VC-TOGGLE-CHECK', $runbook);
    $this->assertStringContainsString('phase2-ndo1-no-live-llm-production-enablement.txt', $runbook);
    $this->assertStringContainsString('[^CLAIM-145]', $runbook);
  }

  /**
   * Runtime artifact must contain required proof markers.
   */
  public function testRuntimeArtifactContainsPhaseTwoNdo01ProofMarkers(): void {
    $artifact = self::readFile('docs/aila/runtime/phase2-ndo1-no-live-llm-production-enablement.txt');

    $this->assertStringContainsString('VC-TOGGLE-CHECK', $artifact);
    $this->assertStringContainsString('llm.enabled', $artifact);
    $this->assertStringContainsString('p2-ndo-01-status=closed', $artifact);
    $this->assertStringContainsString('guard-anchor-settings-php=present', $artifact);
    $this->assertStringContainsString('guard-anchor-assistant-settings-form=present', $artifact);
    $this->assertStringContainsString('guard-anchor-llm-enhancer=present', $artifact);
    $this->assertStringContainsString('guard-anchor-fallback-gate=present', $artifact);
  }

  /**
   * Runtime guard anchors must remain present in code paths.
   */
  public function testRuntimeGuardAnchorsRemainPresent(): void {
    $settings = self::readFile('web/sites/default/settings.php');
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');
    $enhancer = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/LlmEnhancer.php');
    $gate = self::readFile('web/modules/custom/ilas_site_assistant/src/Service/FallbackGate.php');

    $this->assertStringContainsString("\$config['ilas_site_assistant.settings']['llm.enabled'] = FALSE;", $settings);
    $this->assertStringContainsString('LLM enhancement cannot be enabled in the live environment through Phase 2.', $form);
    $this->assertStringContainsString('protected function isLiveEnvironment(): bool', $enhancer);
    $this->assertStringContainsString('protected function isLiveEnvironment(): bool', $gate);
    $this->assertStringContainsString('protected function isLlmEffectivelyEnabled(): bool', $gate);
  }

  /**
   * VC-TOGGLE-CHECK alias must remain in implementation-prompt-pack.
   */
  public function testImplementationPromptPackRetainsVcToggleCheckAlias(): void {
    $promptPack = self::readFile('docs/aila/implementation-prompt-pack.md');

    $this->assertStringContainsString('| `VC-TOGGLE-CHECK` |', $promptPack);
    $this->assertStringContainsString(
      'llm.enabled|vector_search|rate_limit_per_minute|conversation_logging',
      $promptPack
    );
  }

}
