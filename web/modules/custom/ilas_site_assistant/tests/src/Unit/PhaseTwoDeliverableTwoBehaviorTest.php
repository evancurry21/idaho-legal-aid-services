<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;

/**
 * Behavioral proof for Phase 2 Deliverable #2 (`P2-DEL-02`).
 */
#[Group('ilas_site_assistant')]
final class PhaseTwoDeliverableTwoBehaviorTest extends BehavioralDependencyGateTestBase {

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
   * Shared runtime output must append contract metadata for eval assertions.
   */
  public function testSharedRuntimeRenderAppendsContractMetaWithRequiredFields(): void {
    $rendered = self::runNodeJson([
      self::gateMetricsScript(),
      'render-output',
      self::repoRoot() . '/promptfoo-evals/tests/fixtures/assistant-response-derived-citations.json',
    ]);

    $this->assertTrue($rendered['hasContractMetaLine']);
    $this->assertIsArray($rendered['contractMeta']);
    $this->assertSame(0, $rendered['contractMeta']['citations_count']);
    $this->assertSame(3, $rendered['contractMeta']['derived_citation_count']);
    $this->assertSame('unsupported_link_or_result_only', $rendered['contractMeta']['grounding_status']);
    $this->assertSame('search_results', $rendered['contractMeta']['response_type']);
    $this->assertSame('grounded_answer', $rendered['contractMeta']['response_mode']);
    $this->assertSame('retrieval_match', $rendered['contractMeta']['reason_code']);
    $this->assertSame('ranked_search_match', $rendered['contractMeta']['decision_reason']);
  }

  /**
   * Retrieval thresholds must pass when the required metrics are satisfied.
   */
  public function testRetrievalThresholdsPassWhenMetricsMeetRateAndCountFloors(): void {
    $report = $this->thresholdReport(
      'promptfoo-evals/tests/fixtures/gate-results-rag-pass.json',
      90,
      10,
      self::RAG_METRICS,
    );

    $this->assertFalse($report['overall_fail']);
    foreach (self::RAG_METRICS as $metricName) {
      $this->assertArrayHasKey($metricName, $report['metrics']);
      $this->assertFalse($report['metrics'][$metricName]['count_fail']);
      $this->assertFalse($report['metrics'][$metricName]['fail']);
    }
  }

  /**
   * Retrieval thresholds must fail when a required metric rate drops.
   */
  public function testRetrievalThresholdsFailWhenMetricRateDropsBelowThreshold(): void {
    $report = $this->thresholdReport(
      'promptfoo-evals/tests/fixtures/gate-results-rag-low-rate.json',
      90,
      10,
      self::RAG_METRICS,
    );

    $this->assertTrue($report['overall_fail']);
    $this->assertSame(70.0, $report['metrics']['rag-citation-coverage']['rate']);
    $this->assertFalse($report['metrics']['rag-citation-coverage']['count_fail']);
    $this->assertTrue($report['metrics']['rag-citation-coverage']['fail']);
  }

  /**
   * Retrieval thresholds must fail when a required metric count is absent.
   */
  public function testRetrievalThresholdsFailWhenMetricCountsAreMissing(): void {
    $report = $this->thresholdReport(
      'promptfoo-evals/tests/fixtures/gate-results-rag-missing-count.json',
      90,
      10,
      self::RAG_METRICS,
    );

    $this->assertTrue($report['overall_fail']);
    $this->assertSame(0, $report['metrics']['rag-contract-meta-present']['count']);
    $this->assertTrue($report['metrics']['rag-contract-meta-present']['count_fail']);
    $this->assertTrue($report['metrics']['rag-contract-meta-present']['fail']);
    $this->assertSame(0, $report['metrics']['rag-citation-coverage']['count']);
    $this->assertTrue($report['metrics']['rag-citation-coverage']['count_fail']);
    $this->assertTrue($report['metrics']['rag-citation-coverage']['fail']);
  }

}
