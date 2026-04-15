<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the Voyage-backed Pinecone embeddings contract.
 */
#[Group('ilas_site_assistant')]
final class VoyageEmbeddingsConfigGuardTest extends TestCase {

  /**
   * Returns repository root.
   */
  private static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Reads a YAML file from the repo.
   */
  private static function readYaml(string $relativePath): array {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected YAML file does not exist: {$relativePath}");

    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed, "Failed parsing YAML file: {$relativePath}");
    return $parsed;
  }

  /**
   * Synced embeddings config must point Pinecone at Voyage, not Google.
   */
  public function testEmbeddingsConfigUsesVoyageForPineconeServers(): void {
    $aiSettings = self::readYaml('config/ai.settings.yml');
    $faqServer = self::readYaml('config/search_api.server.pinecone_vector_faq.yml');
    $resourceServer = self::readYaml('config/search_api.server.pinecone_vector_resources.yml');
    $voyageKey = self::readYaml('config/key.key.voyage_ai_api_key.yml');
    $activeAssistantSettings = self::readYaml('config/ilas_site_assistant.settings.yml');
    $installAssistantSettings = self::readYaml('web/modules/custom/ilas_site_assistant/config/install/ilas_site_assistant.settings.yml');

    $this->assertSame('ilas_voyage', $aiSettings['default_providers']['embeddings']['provider_id'] ?? NULL);
    $this->assertSame('voyage-law-2', $aiSettings['default_providers']['embeddings']['model_id'] ?? NULL);

    foreach ([$faqServer, $resourceServer] as $server) {
      $this->assertSame('ilas_voyage__voyage-law-2', $server['backend_config']['embeddings_engine'] ?? NULL);
      $this->assertSame(1024, $server['backend_config']['embeddings_engine_configuration']['dimensions'] ?? NULL);
      $this->assertStringNotContainsString('gemini__', (string) ($server['backend_config']['embeddings_engine'] ?? ''));
    }

    $this->assertSame('ilas_runtime_site_setting', $voyageKey['key_provider'] ?? NULL);
    $this->assertSame('ilas_voyage_api_key', $voyageKey['key_provider_settings']['settings_key'] ?? NULL);

    foreach ([$activeAssistantSettings, $installAssistantSettings] as $assistantSettings) {
      $managedIndexes = $assistantSettings['vector_index_hygiene']['managed_indexes'] ?? [];
      $this->assertSame('ilas_voyage__voyage-law-2', $managedIndexes['faq_vector']['expected_embeddings_engine'] ?? NULL);
      $this->assertSame('ilas_voyage__voyage-law-2', $managedIndexes['resource_vector']['expected_embeddings_engine'] ?? NULL);
    }
  }

}
