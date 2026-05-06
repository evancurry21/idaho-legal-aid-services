<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards that active config stays conservative while live-only vector proof
 * remains available through explicit smoke checks and documentation.
 *
 * Ordinary local and CI runs must not silently enable live vector/rerank
 * providers through active config drift. Live-provider reachability is proven
 * separately by explicit smoke checks and runbook guidance.
 */
#[Group('ilas_site_assistant')]
final class VectorRetrievalLiveEnablementTest extends TestCase {

  /**
   *
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   *
   */
  private static function readYaml(string $relativePath): array {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected YAML file does not exist: {$relativePath}");
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed, "Failed parsing YAML: {$relativePath}");
    return $parsed;
  }

  /**
   *
   */
  public function testActiveConfigKeepsVectorAndVoyageDisabledByDefault(): void {
    $active = self::readYaml('config/ilas_site_assistant.settings.yml');

    $this->assertSame(
      FALSE,
      $active['vector_search']['enabled'] ?? NULL,
      'vector_search.enabled must stay FALSE in the active deployment config so '
      . 'local and CI runs do not silently depend on live Pinecone retrieval.'
    );

    $this->assertSame(
      FALSE,
      $active['voyage']['enabled'] ?? NULL,
      'voyage.enabled must stay FALSE in the active deployment config so '
      . 'local and CI runs do not silently depend on live Voyage reranking.'
    );

    $this->assertSame('rerank-2', $active['voyage']['rerank_model'] ?? NULL);
    $this->assertNotEmpty($active['retrieval']['faq_vector_index_id'] ?? NULL);
    $this->assertNotEmpty($active['retrieval']['resource_vector_index_id'] ?? NULL);
  }

  /**
   *
   */
  public function testProvenanceSmokeAssertsPineconeAndVoyage(): void {
    $script = self::repoRoot() . '/scripts/ci/run-vector-provenance-smoke.js';
    $this->assertFileExists($script);
    $contents = file_get_contents($script) ?: '';

    $this->assertStringContainsString("'pinecone'", $contents,
      'Smoke must assert vector_provider === pinecone.');
    $this->assertStringContainsString("'voyage'", $contents,
      'Smoke must assert embedding_provider === voyage.');
    $this->assertStringContainsString('vector_result_count_zero', $contents,
      'Smoke must fail when vector_result_count is 0 for retrieval-eligible queries.');
    $this->assertStringContainsString('missing_vector_source_class', $contents,
      'Smoke must require at least one source_class ending in _vector.');
    $this->assertStringContainsString('generic_fallback_response', $contents,
      'Smoke must fail when the assistant returns the generic OOS fallback.');
  }

  /**
   *
   */
  public function testRunbookDocumentsLiveEnablement(): void {
    $runbook = self::repoRoot() . '/docs/aila/runbook.md';
    $this->assertFileExists($runbook);
    $contents = file_get_contents($runbook) ?: '';

    $this->assertStringContainsString('vector_provider: pinecone', $contents);
    $this->assertStringContainsString('embedding_provider: voyage', $contents);
    $this->assertStringContainsString('embedding_model: voyage-law-2', $contents);
    $this->assertStringContainsString('run-vector-provenance-smoke.js', $contents);
  }

}
