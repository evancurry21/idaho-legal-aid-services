<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards P1-NDO-01: "No live LLM rollout" for Phase 1 scope.
 */
#[Group('ilas_site_assistant')]
final class PhaseOneNoLiveLlmRolloutGuardTest extends TestCase {

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
   * Roadmap must retain the Phase 1 no-live-LLM and no-redesign boundaries.
   */
  public function testRoadmapRetainsPhaseOneNdoBoundaries(): void {
    $roadmap = self::readFile('docs/aila/roadmap.md');

    $this->assertStringContainsString(
      '## Phase 1 (Sprints 2-3): Observability + reliability baseline',
      $roadmap,
    );
    $this->assertStringContainsString(
      '1. No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)',
      $roadmap,
    );
    $this->assertStringContainsString(
      '2. No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4)',
      $roadmap,
    );
  }

  /**
   * Current-state section 5 must keep llm.enabled disabled with live override.
   */
  public function testCurrentStateRetainsLlmDisabledToggleMatrix(): void {
    $currentState = self::readFile('docs/aila/current-state.md');

    $this->assertStringContainsString(
      '| LLM master switch | `llm.enabled` | `false` | `false` |',
      $currentState,
    );
    $this->assertStringContainsString(
      'Verified `false` on dev/test/live; live runtime override enforces `false`',
      $currentState,
    );
  }

  /**
   * Evidence index must keep CLAIM-119 for live llm.enabled=false posture.
   */
  public function testEvidenceIndexRetainsClaim119LlmDisabledEvidence(): void {
    $evidenceIndex = self::readFile('docs/aila/evidence-index.md');

    $this->assertStringContainsString('### CLAIM-119', $evidenceIndex);
    $this->assertStringContainsString('`dev`/`test`/`live`', $evidenceIndex);
    $this->assertStringContainsString('`llm.enabled=false`', $evidenceIndex);
  }

  /**
   * Diagram B must retain the LLM gate and deterministic no-LLM branch.
   */
  public function testSystemMapRetainsDiagramBGatingForLlmPath(): void {
    $systemMap = self::readFile('docs/aila/system-map.mmd');

    $this->assertStringContainsString('flowchart TD', $systemMap);
    $this->assertStringContainsString('N{LLM enabled + allowed?}', $systemMap);
    $this->assertStringContainsString('N -->|no| O[Rule-based response]', $systemMap);
  }

  /**
   * Runbook section 3 must preserve live llm.enabled policy expectation.
   */
  public function testRunbookRetainsLivePolicyExpectation(): void {
    $runbook = self::readFile('docs/aila/runbook.md');

    $this->assertStringContainsString('for ENV in dev test live; do', $runbook);
    $this->assertStringContainsString(
      'Expected policy result: the `live` `config:get ilas_site_assistant.settings -y`',
      $runbook,
    );
    $this->assertStringContainsString('output must show effective `llm.enabled: false`', $runbook);
  }

  /**
   * Live settings override must hard-disable llm.enabled.
   */
  public function testSettingsPhpRetainsLiveLlmHardDisable(): void {
    $settings = self::readFile('web/sites/default/settings.php');

    $this->assertStringContainsString(
      "if (isset(\$_ENV['PANTHEON_ENVIRONMENT']) && \$_ENV['PANTHEON_ENVIRONMENT'] === 'live') {",
      $settings,
    );
    $this->assertStringContainsString(
      "\$config['ilas_site_assistant.settings']['llm.enabled'] = FALSE;",
      $settings,
    );
  }

  /**
   * Settings form must enforce live guard at UI, validation, and submit paths.
   */
  public function testAssistantSettingsFormRetainsLiveLlmGuardrails(): void {
    $form = self::readFile('web/modules/custom/ilas_site_assistant/src/Form/AssistantSettingsForm.php');

    $this->assertStringContainsString("'#disabled' => \$is_live_environment", $form);
    $this->assertStringContainsString(
      "if (\$this->isLiveEnvironment() && (bool) \$form_state->getValue('llm_enabled')) {",
      $form,
    );
    $this->assertStringContainsString(
      'LLM enhancement cannot be enabled in the live environment through Phase 2.',
      $form,
    );
    $this->assertStringContainsString("if (\$this->isLiveEnvironment()) {\n      \$llm_enabled = FALSE;", $form);
    $this->assertStringContainsString("'enabled' => \$llm_enabled", $form);
  }

  /**
   * VC-TOGGLE-CHECK alias must continue scanning canonical toggle evidence docs.
   */
  public function testImplementationPromptPackRetainsVcToggleCheckAlias(): void {
    $promptPack = self::readFile('docs/aila/implementation-prompt-pack.md');

    $this->assertStringContainsString('| `VC-TOGGLE-CHECK` |', $promptPack);
    $this->assertStringContainsString(
      'llm.enabled|vector_search|rate_limit_per_minute|conversation_logging',
      $promptPack,
    );
    $this->assertStringContainsString(
      'docs/aila/current-state.md docs/aila/evidence-index.md',
      $promptPack,
    );
  }

}

