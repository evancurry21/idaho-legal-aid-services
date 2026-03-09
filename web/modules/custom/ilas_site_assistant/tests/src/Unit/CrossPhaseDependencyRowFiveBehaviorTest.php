<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\PiiRedactor;
use PHPUnit\Framework\Attributes\Group;

/**
 * Behavioral proof for cross-phase dependency row #5 (`XDP-05`).
 */
#[Group('ilas_site_assistant')]
final class CrossPhaseDependencyRowFiveBehaviorTest extends BehavioralDependencyGateTestBase {

  /**
   * RAG metrics enforced by the promptfoo gate.
   *
   * @var string[]
   */
  private const RAG_METRICS = [
    'rag-contract-meta-present',
    'rag-citation-coverage',
    'rag-low-confidence-refusal',
  ];

  /**
   * Config parity must close only when install, active, and schema align.
   */
  public function testConfigParityPrerequisiteBlocksWhenInstallActiveOrSchemaDrift(): void {
    $install = self::parseYamlFile('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');
    $active = self::parseYamlFile('config/ilas_site_assistant.settings.yml');
    $schema = self::parseYamlFile('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml');

    $this->assertSame([], $this->evaluateConfigParity($install, $active, $schema));

    $driftedActive = $active;
    unset($driftedActive['vector_search']);
    $this->assertContains(
      'active_missing:vector_search',
      $this->evaluateConfigParity($install, $driftedActive, $schema),
    );

    $driftedSchema = $schema;
    unset($driftedSchema['ilas_site_assistant.settings']['mapping']['vector_search']);
    $this->assertContains(
      'schema_missing:vector_search',
      $this->evaluateConfigParity($install, $active, $driftedSchema),
    );
  }

  /**
   * Observability proof must fail closed when credentials or redaction break.
   */
  public function testObservabilitySignalsPrerequisiteBlocksWhenCredentialOrRedactionContractsBreak(): void {
    $settings = self::readFile('web/sites/default/settings.php');
    $install = self::parseYamlFile('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');
    $runtimeArtifact = self::readFile('docs/aila/runtime/phase1-observability-gates.txt');
    $redacted = PiiRedactor::redact('john@example.com 208-555-1234 CV-24-0001');

    $this->assertSame([], $this->evaluateObservabilitySignals($settings, $install, $runtimeArtifact, $redacted));

    $brokenSettings = str_replace('LANGFUSE_PUBLIC_KEY', 'REMOVED_PUBLIC_KEY', $settings);
    $this->assertContains(
      'settings_missing:LANGFUSE_PUBLIC_KEY',
      $this->evaluateObservabilitySignals($brokenSettings, $install, $runtimeArtifact, $redacted),
    );

    $this->assertContains(
      'redaction_failed',
      $this->evaluateObservabilitySignals($settings, $install, $runtimeArtifact, 'john@example.com 208-555-1234 CV-24-0001'),
    );
  }

  /**
   * Eval harness proof must fail closed when retrieval thresholds fail.
   */
  public function testEvalHarnessPrerequisiteBlocksWhenPromptfooThresholdsFail(): void {
    $passingReport = $this->thresholdReport(
      'promptfoo-evals/tests/fixtures/gate-results-rag-pass.json',
      90,
      10,
      self::RAG_METRICS,
    );
    $this->assertSame([], $this->evaluateEvalHarness($passingReport));

    $failingReport = $this->thresholdReport(
      'promptfoo-evals/tests/fixtures/gate-results-rag-missing-count.json',
      90,
      10,
      self::RAG_METRICS,
    );
    $this->assertContains(
      'metric_fail:rag-contract-meta-present',
      $this->evaluateEvalHarness($failingReport),
    );
  }

  /**
   * Row #5 closure must remain blocked until every prerequisite passes.
   */
  public function testXdp05DependencyClosureBlocksWhenAnyPrerequisiteFails(): void {
    $install = self::parseYamlFile('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');
    $active = self::parseYamlFile('config/ilas_site_assistant.settings.yml');
    $schema = self::parseYamlFile('web/modules/custom/ilas_site_assistant/config/schema/ilas_site_assistant.schema.yml');
    $settings = self::readFile('web/sites/default/settings.php');
    $runtimeArtifact = self::readFile('docs/aila/runtime/phase1-observability-gates.txt');
    $redacted = PiiRedactor::redact('john@example.com 208-555-1234 CV-24-0001');
    $evalReport = $this->thresholdReport(
      'promptfoo-evals/tests/fixtures/gate-results-rag-pass.json',
      90,
      10,
      self::RAG_METRICS,
    );

    $closed = $this->evaluateDependencyClosure(array_merge(
      $this->evaluateConfigParity($install, $active, $schema),
      $this->evaluateObservabilitySignals($settings, $install, $runtimeArtifact, $redacted),
      $this->evaluateEvalHarness($evalReport),
    ));

    $this->assertSame('closed', $closed['status']);
    $this->assertSame(0, $closed['unresolved_count']);

    $blocked = $this->evaluateDependencyClosure([
      'config_missing:vector_search',
      'metric_fail:rag-citation-coverage',
    ]);
    $this->assertSame('blocked', $blocked['status']);
    $this->assertSame(2, $blocked['unresolved_count']);
  }

  /**
   * Evaluates config parity prerequisite failures.
   *
   * @return string[]
   *   The unresolved prerequisite failures.
   */
  private function evaluateConfigParity(array $install, array $active, array $schema): array {
    $failures = [];

    $installKeys = array_diff(array_keys($install), ['_core']);
    $activeKeys = array_diff(array_keys($active), ['_core']);
    $schemaKeys = array_keys($schema['ilas_site_assistant.settings']['mapping'] ?? []);

    foreach (array_diff($installKeys, $activeKeys) as $missing) {
      $failures[] = "active_missing:{$missing}";
    }
    foreach (array_diff($installKeys, $schemaKeys) as $missing) {
      $failures[] = "schema_missing:{$missing}";
    }
    foreach (array_diff($activeKeys, $installKeys) as $orphan) {
      $failures[] = "active_orphan:{$orphan}";
    }

    sort($failures);
    return $failures;
  }

  /**
   * Evaluates observability prerequisite failures.
   *
   * @return string[]
   *   The unresolved prerequisite failures.
   */
  private function evaluateObservabilitySignals(string $settings, array $install, string $runtimeArtifact, string $redacted): array {
    $failures = [];

    foreach (['LANGFUSE_PUBLIC_KEY', 'LANGFUSE_SECRET_KEY', 'SENTRY_DSN'] as $token) {
      if (!str_contains($settings, $token)) {
        $failures[] = "settings_missing:{$token}";
      }
    }

    $langfuse = $install['langfuse'] ?? [];
    foreach (['public_key', 'secret_key', 'sample_rate'] as $key) {
      if (!array_key_exists($key, $langfuse)) {
        $failures[] = "install_missing:langfuse.{$key}";
      }
    }

    foreach ([
      'langfuse_public_key=present' => 3,
      'langfuse_secret_key=present' => 3,
      'raven_client_key=present' => 3,
    ] as $marker => $expectedCount) {
      if (substr_count($runtimeArtifact, $marker) !== $expectedCount) {
        $failures[] = "runtime_missing:{$marker}";
      }
    }

    if (
      str_contains($redacted, 'john@example.com') ||
      str_contains($redacted, '208-555-1234') ||
      str_contains($redacted, 'CV-24-0001')
    ) {
      $failures[] = 'redaction_failed';
    }

    sort($failures);
    return $failures;
  }

  /**
   * Evaluates eval-harness prerequisite failures.
   *
   * @param array{
   *   overall_fail: bool,
   *   metrics: array<string, array{
   *     rate: float,
   *     score: int,
   *     count: int,
   *     count_fail: bool,
   *     fail: bool
   *   }>
   * } $report
   *   The parsed metric report.
   *
   * @return string[]
   *   The unresolved prerequisite failures.
   */
  private function evaluateEvalHarness(array $report): array {
    $failures = [];

    foreach ($report['metrics'] as $metricName => $metric) {
      if ($metric['fail']) {
        $failures[] = "metric_fail:{$metricName}";
      }
    }

    sort($failures);
    return $failures;
  }

  /**
   * Computes row closure status from unresolved prerequisite failures.
   *
   * @param string[] $failures
   *   The unresolved prerequisite failures.
   *
   * @return array{status: string, unresolved_count: int, unresolved: string[]}
   *   Row closure state.
   */
  private function evaluateDependencyClosure(array $failures): array {
    $normalized = array_values(array_unique(array_filter($failures)));
    sort($normalized);

    return [
      'status' => $normalized === [] ? 'closed' : 'blocked',
      'unresolved_count' => count($normalized),
      'unresolved' => $normalized,
    ];
  }

}
