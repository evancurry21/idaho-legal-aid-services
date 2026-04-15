<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Drupal\ilas_site_assistant\Service\RuntimeTruthSnapshotBuilder;
use Drupal\search_api\IndexInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for override-aware runtime truth snapshots.
 */
#[Group('ilas_site_assistant')]
class RuntimeTruthSnapshotBuilderTest extends TestCase {

  /**
   * Resets Settings after each test.
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * The snapshot is sanitized and detects stored-versus-effective divergence.
   */
  public function testBuildSnapshotRedactsSecretsAndReportsDivergences(): void {
    new Settings([
      'ilas_observability' => [
        'environment' => 'pantheon-live',
        'pantheon_environment' => 'live',
        'release' => 'live_147',
        'git_sha' => '6bc13fd',
        'pantheon_site_name' => 'idaho-legal-aid-services',
        'pantheon_site_id' => 'site-id-123',
        'public_site_url' => 'https://live-idaho-legal-aid-services.pantheonsite.io',
        'sentry' => [
          'browser' => [
            'replay_session_sample_rate' => 0.01,
            'replay_on_error_sample_rate' => 0.25,
          ],
        ],
      ],
      'ilas_gemini_api_key' => 'gemini-secret-value',
      'ilas_voyage_api_key' => 'voyage-secret-value',
      'ilas_vertex_sa_json' => '{"private_key":"vertex-secret-value"}',
      'ilas_site_assistant_legalserver_online_application_url' => 'https://idoi.legalserver.org/modules/matter/extern_intake.php?pid=60&h=secret',
      'ilas_assistant_diagnostics_token' => 'diag-secret-token',
      'google_tag_id' => 'G-QYT2ZNY442',
      'ilas_site_assistant_debug_metadata_force_disable' => TRUE,
    ]);

    $syncStorage = new MemoryStorage();
    $syncStorage->write('ilas_site_assistant.settings', [
      'llm' => [
        'enabled' => FALSE,
      ],
      'vector_search' => [
        'enabled' => FALSE,
      ],
      'langfuse' => [
        'enabled' => FALSE,
        'public_key' => '',
        'secret_key' => '',
        'environment' => 'production',
        'sample_rate' => 1.0,
        'redacted_preview_enabled' => FALSE,
        'redacted_preview_max_chars' => 160,
      ],
    ]);
    $syncStorage->write('key.key.pinecone_api_key', [
      'key_provider_settings' => [
        'key_value' => '',
      ],
    ]);
    $syncStorage->write('key.key.voyage_ai_api_key', [
      'key_provider' => 'ilas_runtime_site_setting',
      'key_provider_settings' => [
        'settings_key' => 'ilas_voyage_api_key',
      ],
    ]);
    $syncStorage->write('ai.settings', [
      'default_providers' => [
        'embeddings' => [
          'provider_id' => 'ilas_voyage',
          'model_id' => 'voyage-law-2',
        ],
      ],
    ]);
    $syncStorage->write('search_api.server.pinecone_vector_faq', [
      'backend_config' => [
        'embeddings_engine' => 'ilas_voyage__voyage-law-2',
      ],
    ]);
    $syncStorage->write('search_api.server.pinecone_vector_resources', [
      'backend_config' => [
        'embeddings_engine' => 'ilas_voyage__voyage-law-2',
      ],
    ]);

    $builder = new RuntimeTruthSnapshotBuilder($this->buildConfigFactory([
      'ilas_site_assistant.settings' => [
        'llm.enabled' => FALSE,
        'vector_search.enabled' => FALSE,
        'langfuse.enabled' => TRUE,
        'langfuse.public_key' => 'pk-live-secret',
        'langfuse.secret_key' => 'sk-live-secret',
        'langfuse.environment' => 'pantheon-live',
        'langfuse.sample_rate' => 1.0,
        'langfuse.redacted_preview_enabled' => TRUE,
        'langfuse.redacted_preview_max_chars' => 160,
      ],
      'raven.settings' => [
        'client_key' => 'https://sentry-secret@example.ingest.sentry.io/123',
        'public_dsn' => 'https://browser-secret@example.ingest.sentry.io/123',
        'environment' => 'pantheon-live',
        'release' => 'live_147',
        'javascript_error_handler' => TRUE,
        'browser_traces_sample_rate' => 0.02,
        'show_report_dialog' => FALSE,
      ],
      'key.key.pinecone_api_key' => [
        'key_provider_settings' => [
          'key_value' => 'pinecone-secret-value',
        ],
      ],
      'key.key.voyage_ai_api_key' => [
        'key_provider' => 'ilas_runtime_site_setting',
        'key_provider_settings.settings_key' => 'ilas_voyage_api_key',
      ],
      'ai.settings' => [
        'default_providers.embeddings.provider_id' => 'ilas_voyage',
        'default_providers.embeddings.model_id' => 'voyage-law-2',
      ],
      'search_api.server.pinecone_vector_faq' => [
        'backend_config.embeddings_engine' => 'ilas_voyage__voyage-law-2',
      ],
      'search_api.server.pinecone_vector_resources' => [
        'backend_config.embeddings_engine' => 'ilas_voyage__voyage-law-2',
      ],
    ]), $syncStorage);

    $snapshot = $builder->buildSnapshot();

    $this->assertSame([
      'environment',
      'exported_storage',
      'effective_runtime',
      'runtime_site_settings',
      'browser_expected',
      'override_channels',
      'divergences',
    ], array_keys($snapshot));

    $this->assertFalse($snapshot['exported_storage']['langfuse']['enabled']);
    $this->assertTrue($snapshot['effective_runtime']['langfuse']['enabled']);
    $this->assertFalse($snapshot['exported_storage']['langfuse']['redacted_preview_enabled']);
    $this->assertTrue($snapshot['effective_runtime']['langfuse']['redacted_preview_enabled']);
    $this->assertSame(160, $snapshot['effective_runtime']['langfuse']['redacted_preview_max_chars']);
    $this->assertTrue($snapshot['effective_runtime']['sentry']['client_key_present']);
    $this->assertArrayHasKey('conversation_logging', $snapshot['exported_storage']);
    $this->assertArrayHasKey('embeddings', $snapshot['exported_storage']);
    $this->assertArrayHasKey('retrieval', $snapshot['exported_storage']);
    $this->assertArrayHasKey('voyage', $snapshot['exported_storage']);
    $this->assertArrayHasKey('conversation_logging', $snapshot['effective_runtime']);
    $this->assertArrayHasKey('embeddings', $snapshot['effective_runtime']);
    $this->assertArrayHasKey('retrieval', $snapshot['effective_runtime']);
    $this->assertArrayHasKey('voyage', $snapshot['effective_runtime']);
    $this->assertArrayNotHasKey('gemini_api_key_present', $snapshot['runtime_site_settings']);
    $this->assertArrayNotHasKey('vertex_service_account_present', $snapshot['runtime_site_settings']);
    $this->assertArrayNotHasKey('provider', $snapshot['exported_storage']['llm']);
    $this->assertArrayNotHasKey('model', $snapshot['exported_storage']['llm']);
    $this->assertArrayNotHasKey('provider', $snapshot['effective_runtime']['llm']);
    $this->assertArrayNotHasKey('model', $snapshot['effective_runtime']['llm']);
    $this->assertTrue($snapshot['effective_runtime']['llm']['request_time_retired']);
    $this->assertFalse($snapshot['effective_runtime']['llm']['google_generation_reachable']);
    $this->assertFalse($snapshot['exported_storage']['retrieval']['legalserver_online_application_url']['present']);
    $this->assertTrue($snapshot['effective_runtime']['retrieval']['legalserver_online_application_url']['present']);
    $this->assertFalse($snapshot['effective_runtime']['pinecone']['runtime_ready']);
    $this->assertTrue($snapshot['browser_expected']['google_analytics']['loader_expected']);
    $this->assertTrue($snapshot['browser_expected']['google_analytics']['assistant_page_suppressed']);
    $this->assertFalse($snapshot['browser_expected']['google_analytics']['assistant_page_loader_expected']);
    $this->assertFalse($snapshot['browser_expected']['google_analytics']['assistant_page_data_layer_expected']);
    $this->assertSame('config export', $snapshot['override_channels']['vector_search.enabled']);
    $this->assertSame('settings.php secret -> getenv/pantheon_get_secret', $snapshot['override_channels']['langfuse.enabled']);
    $this->assertSame('config export', $snapshot['override_channels']['langfuse.redacted_preview_enabled']);
    $this->assertSame('config export', $snapshot['override_channels']['langfuse.redacted_preview_max_chars']);
    $this->assertSame('ConversationLogger privacy invariants', $snapshot['override_channels']['conversation_logging.redact_pii']);
    $this->assertSame('RetrievalConfigurationService runtime resolution', $snapshot['override_channels']['retrieval.legalserver_online_application_url.status']);

    $divergenceFields = array_column($snapshot['divergences'], 'field');
    $this->assertNotContains('llm.provider', $divergenceFields);
    $this->assertNotContains('llm.model', $divergenceFields);
    $this->assertNotContains('llm.gemini_api_key_present', $divergenceFields);
    $this->assertNotContains('llm.vertex_service_account_present', $divergenceFields);
    $this->assertContains('retrieval.legalserver_online_application_url.present', $divergenceFields);
    $this->assertContains('langfuse.enabled', $divergenceFields);
    $this->assertContains('langfuse.public_key_present', $divergenceFields);
    $this->assertContains('langfuse.redacted_preview_enabled', $divergenceFields);
    $this->assertContains('raven.settings.client_key_present', $divergenceFields);
    $this->assertContains('key.key.pinecone_api_key.key_present', $divergenceFields);
    $this->assertContains('google_tag_id', $divergenceFields);
    $this->assertNotContains('llm.request_time_retired', $divergenceFields);
    $this->assertNotContains('llm.google_generation_reachable', $divergenceFields);

    $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('gemini-secret-value', $json);
    $this->assertStringNotContainsString('vertex-secret-value', $json);
    $this->assertStringNotContainsString('diag-secret-token', $json);
    $this->assertStringNotContainsString('voyage-secret-value', $json);
    $this->assertStringNotContainsString('extern_intake.php?pid=60&h=secret', $json);
    $this->assertStringNotContainsString('pk-live-secret', $json);
    $this->assertStringNotContainsString('sk-live-secret', $json);
    $this->assertStringNotContainsString('sentry-secret@example.ingest.sentry.io', $json);
    $this->assertStringNotContainsString('browser-secret@example.ingest.sentry.io', $json);
    $this->assertStringNotContainsString('pinecone-secret-value', $json);
  }

  /**
   * Missing required sync config fails loudly.
   */
  public function testBuildSnapshotThrowsWhenRequiredSyncConfigIsMissing(): void {
    new Settings([]);
    $builder = new RuntimeTruthSnapshotBuilder(
      $this->buildConfigFactory([]),
      new MemoryStorage(),
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('ilas_site_assistant.settings');
    $builder->buildSnapshot();
  }

  /**
   * Live vector runtime enablement reports the runtime toggle as
   * authoritative while leaving stored config default-off.
   */
  public function testBuildSnapshotReportsLiveVectorRuntimeToggleOverride(): void {
    new Settings([
      'ilas_observability' => [
        'environment' => 'pantheon-live',
        'pantheon_environment' => 'live',
      ],
      'ilas_vector_search_override_channel' => 'settings.php runtime toggle -> getenv/pantheon_get_secret',
      'ilas_voyage_api_key' => 'voyage-secret-value',
    ]);

    $syncStorage = new MemoryStorage();
    $syncStorage->write('ilas_site_assistant.settings', [
      'llm' => [
        'enabled' => FALSE,
      ],
      'vector_search' => [
        'enabled' => FALSE,
      ],
      'retrieval' => [
        'faq_vector_index_id' => 'faq_accordion_vector',
        'resource_vector_index_id' => 'assistant_resources_vector',
      ],
      'langfuse' => [
        'enabled' => FALSE,
        'public_key' => '',
        'secret_key' => '',
        'environment' => '',
        'sample_rate' => 0.0,
        'redacted_preview_enabled' => FALSE,
        'redacted_preview_max_chars' => 160,
      ],
    ]);
    $syncStorage->write('key.key.pinecone_api_key', [
      'key_provider_settings' => [
        'key_value' => '',
      ],
    ]);
    $syncStorage->write('key.key.voyage_ai_api_key', [
      'key_provider' => 'ilas_runtime_site_setting',
      'key_provider_settings' => [
        'settings_key' => 'ilas_voyage_api_key',
      ],
    ]);
    $syncStorage->write('ai.settings', [
      'default_providers' => [
        'embeddings' => [
          'provider_id' => 'ilas_voyage',
          'model_id' => 'voyage-law-2',
        ],
      ],
    ]);
    $syncStorage->write('search_api.server.pinecone_vector_faq', [
      'backend_config' => [
        'embeddings_engine' => 'ilas_voyage__voyage-law-2',
      ],
    ]);
    $syncStorage->write('search_api.server.pinecone_vector_resources', [
      'backend_config' => [
        'embeddings_engine' => 'ilas_voyage__voyage-law-2',
      ],
    ]);

    $builder = new RuntimeTruthSnapshotBuilder($this->buildConfigFactory([
      'ilas_site_assistant.settings' => [
        'llm.enabled' => FALSE,
        'vector_search.enabled' => TRUE,
        'retrieval.faq_vector_index_id' => 'faq_accordion_vector',
        'retrieval.resource_vector_index_id' => 'assistant_resources_vector',
        'langfuse.enabled' => FALSE,
        'langfuse.public_key' => '',
        'langfuse.secret_key' => '',
        'langfuse.environment' => '',
        'langfuse.sample_rate' => 0.0,
        'langfuse.redacted_preview_enabled' => FALSE,
        'langfuse.redacted_preview_max_chars' => 160,
      ],
      'raven.settings' => [],
      'key.key.pinecone_api_key' => [
        'key_provider_settings' => [
          'key_value' => 'pinecone-secret-value',
        ],
      ],
      'key.key.voyage_ai_api_key' => [
        'key_provider' => 'ilas_runtime_site_setting',
        'key_provider_settings.settings_key' => 'ilas_voyage_api_key',
      ],
      'ai.settings' => [
        'default_providers.embeddings.provider_id' => 'ilas_voyage',
        'default_providers.embeddings.model_id' => 'voyage-law-2',
      ],
      'search_api.server.pinecone_vector_faq' => [
        'backend_config.embeddings_engine' => 'ilas_voyage__voyage-law-2',
      ],
      'search_api.server.pinecone_vector_resources' => [
        'backend_config.embeddings_engine' => 'ilas_voyage__voyage-law-2',
      ],
    ]), $syncStorage);

    $snapshot = $builder->buildSnapshot();

    $this->assertSame(
      'settings.php runtime toggle -> getenv/pantheon_get_secret',
      $snapshot['override_channels']['vector_search.enabled'],
    );
    $this->assertSame(
      'settings.php runtime toggle -> getenv/pantheon_get_secret',
      $snapshot['effective_runtime']['vector_search']['override_channel'],
    );
    $this->assertTrue($snapshot['effective_runtime']['embeddings']['runtime_ready']);
    $this->assertTrue($snapshot['effective_runtime']['pinecone']['key_present']);
    $this->assertTrue($snapshot['effective_runtime']['pinecone']['runtime_ready']);
    $this->assertTrue($snapshot['effective_runtime']['llm']['request_time_retired']);
    $this->assertFalse($snapshot['effective_runtime']['llm']['google_generation_reachable']);

    $vectorDivergence = array_values(array_filter(
      $snapshot['divergences'],
      static fn(array $divergence): bool => $divergence['field'] === 'vector_search.enabled',
    ));
    $this->assertCount(1, $vectorDivergence);
    $this->assertSame(FALSE, $vectorDivergence[0]['stored_value']);
    $this->assertSame(TRUE, $vectorDivergence[0]['effective_value']);
    $this->assertSame(
      'settings.php runtime toggle -> getenv/pantheon_get_secret',
      $vectorDivergence[0]['authoritative_source'],
    );

    $pineconeDivergence = array_values(array_filter(
      $snapshot['divergences'],
      static fn(array $divergence): bool => $divergence['field'] === 'pinecone.runtime_ready',
    ));
    $this->assertCount(1, $pineconeDivergence);
    $this->assertSame(FALSE, $pineconeDivergence[0]['stored_value']);
    $this->assertSame(TRUE, $pineconeDivergence[0]['effective_value']);
  }

  /**
   * Private flag file fallback reports the file-based override channel.
   */
  public function testBuildSnapshotReportsVectorPrivateFlagFileOverride(): void {
    new Settings([
      'ilas_observability' => [
        'environment' => 'test',
        'pantheon_environment' => 'test',
      ],
      'ilas_vector_search_override_channel' => 'settings.php runtime toggle -> private flag file',
    ]);

    $syncStorage = new MemoryStorage();
    $syncStorage->write('ilas_site_assistant.settings', [
      'llm' => [
        'enabled' => FALSE,
      ],
      'vector_search' => [
        'enabled' => FALSE,
      ],
      'langfuse' => [
        'enabled' => FALSE,
        'public_key' => '',
        'secret_key' => '',
        'environment' => '',
        'sample_rate' => 0.0,
        'redacted_preview_enabled' => FALSE,
        'redacted_preview_max_chars' => 160,
      ],
    ]);
    $syncStorage->write('key.key.pinecone_api_key', [
      'key_provider_settings' => [
        'key_value' => '',
      ],
    ]);

    $builder = new RuntimeTruthSnapshotBuilder($this->buildConfigFactory([
      'ilas_site_assistant.settings' => [
        'llm.enabled' => FALSE,
        'vector_search.enabled' => TRUE,
        'langfuse.enabled' => FALSE,
        'langfuse.public_key' => '',
        'langfuse.secret_key' => '',
        'langfuse.environment' => '',
        'langfuse.sample_rate' => 0.0,
        'langfuse.redacted_preview_enabled' => FALSE,
        'langfuse.redacted_preview_max_chars' => 160,
      ],
      'raven.settings' => [],
      'key.key.pinecone_api_key' => [
        'key_provider_settings' => [
          'key_value' => '',
        ],
      ],
    ]), $syncStorage);

    $snapshot = $builder->buildSnapshot();

    $this->assertSame(
      'settings.php runtime toggle -> private flag file',
      $snapshot['override_channels']['vector_search.enabled'],
    );

    $vectorDivergence = array_values(array_filter(
      $snapshot['divergences'],
      static fn(array $divergence): bool => $divergence['field'] === 'vector_search.enabled',
    ));
    $this->assertCount(1, $vectorDivergence);
    $this->assertSame(
      'settings.php runtime toggle -> private flag file',
      $vectorDivergence[0]['authoritative_source'],
    );
  }

  /**
   * Live Voyage runtime truth reports the explicit toggle and readiness state.
   */
  public function testBuildSnapshotReportsLiveVoyageRuntimeToggleReadiness(): void {
    new Settings([
      'ilas_observability' => [
        'environment' => 'pantheon-live',
        'pantheon_environment' => 'live',
      ],
      'ilas_voyage_api_key' => 'voyage-secret-value',
    ]);

    $syncStorage = new MemoryStorage();
    $syncStorage->write('ilas_site_assistant.settings', [
      'llm' => [
        'enabled' => FALSE,
      ],
      'vector_search' => [
        'enabled' => FALSE,
      ],
      'voyage' => [
        'enabled' => FALSE,
        'rerank_model' => 'rerank-2',
        'api_timeout' => 3.0,
        'max_candidates' => 20,
        'top_k' => 5,
        'min_results_to_rerank' => 2,
        'fallback_on_error' => TRUE,
        'circuit_breaker' => [
          'failure_threshold' => 3,
          'cooldown_seconds' => 300,
        ],
      ],
      'langfuse' => [
        'enabled' => FALSE,
        'public_key' => '',
        'secret_key' => '',
        'environment' => '',
        'sample_rate' => 0.0,
        'redacted_preview_enabled' => FALSE,
        'redacted_preview_max_chars' => 160,
      ],
    ]);
    $syncStorage->write('key.key.pinecone_api_key', [
      'key_provider_settings' => [
        'key_value' => '',
      ],
    ]);

    $builder = new RuntimeTruthSnapshotBuilder($this->buildConfigFactory([
      'ilas_site_assistant.settings' => [
        'llm.enabled' => FALSE,
        'vector_search.enabled' => FALSE,
        'voyage.enabled' => TRUE,
        'voyage.rerank_model' => 'rerank-2',
        'voyage.api_timeout' => 3.0,
        'voyage.max_candidates' => 20,
        'voyage.top_k' => 5,
        'voyage.min_results_to_rerank' => 2,
        'voyage.fallback_on_error' => TRUE,
        'voyage.circuit_breaker.failure_threshold' => 3,
        'voyage.circuit_breaker.cooldown_seconds' => 300,
        'langfuse.enabled' => FALSE,
        'langfuse.public_key' => '',
        'langfuse.secret_key' => '',
        'langfuse.environment' => '',
        'langfuse.sample_rate' => 0.0,
        'langfuse.redacted_preview_enabled' => FALSE,
        'langfuse.redacted_preview_max_chars' => 160,
      ],
      'raven.settings' => [],
      'key.key.pinecone_api_key' => [
        'key_provider_settings' => [
          'key_value' => '',
        ],
      ],
      'key.key.voyage_ai_api_key' => [
        'key_provider' => 'ilas_runtime_site_setting',
        'key_provider_settings.settings_key' => 'ilas_voyage_api_key',
      ],
      'ai.settings' => [
        'default_providers.embeddings.provider_id' => 'ilas_voyage',
        'default_providers.embeddings.model_id' => 'voyage-law-2',
      ],
      'search_api.server.pinecone_vector_faq' => [
        'backend_config.embeddings_engine' => 'ilas_voyage__voyage-law-2',
      ],
      'search_api.server.pinecone_vector_resources' => [
        'backend_config.embeddings_engine' => 'ilas_voyage__voyage-law-2',
      ],
    ]), $syncStorage);

    $snapshot = $builder->buildSnapshot();

    $this->assertSame('ilas_voyage', $snapshot['effective_runtime']['embeddings']['provider_id']);
    $this->assertSame('voyage-law-2', $snapshot['effective_runtime']['embeddings']['model_id']);
    $this->assertTrue($snapshot['effective_runtime']['embeddings']['api_key_present']);
    $this->assertTrue($snapshot['effective_runtime']['embeddings']['runtime_ready']);
    $this->assertTrue($snapshot['effective_runtime']['voyage']['enabled']);
    $this->assertTrue($snapshot['effective_runtime']['voyage']['api_key_present']);
    $this->assertTrue($snapshot['effective_runtime']['voyage']['runtime_ready']);
    $this->assertSame(
      'settings.php runtime site setting ILAS_VOYAGE_API_KEY via key.key.voyage_ai_api_key',
      $snapshot['override_channels']['embeddings.api_key_present'],
    );
    $this->assertSame(
      'ai.settings + search_api.server.pinecone_vector_* + runtime Voyage key',
      $snapshot['override_channels']['embeddings.runtime_ready'],
    );
    $this->assertSame(
      'settings.php runtime toggle ILAS_VOYAGE_ENABLED -> getenv/pantheon_get_secret',
      $snapshot['override_channels']['voyage.enabled'],
    );
    $this->assertSame(
      'settings.php runtime site setting ILAS_VOYAGE_API_KEY',
      $snapshot['override_channels']['voyage.api_key_present'],
    );

    $embeddingsReadyDivergence = array_values(array_filter(
      $snapshot['divergences'],
      static fn(array $divergence): bool => $divergence['field'] === 'embeddings.runtime_ready',
    ));
    $this->assertCount(1, $embeddingsReadyDivergence);
    $this->assertSame(FALSE, $embeddingsReadyDivergence[0]['stored_value']);
    $this->assertSame(TRUE, $embeddingsReadyDivergence[0]['effective_value']);

    $voyageEnabledDivergence = array_values(array_filter(
      $snapshot['divergences'],
      static fn(array $divergence): bool => $divergence['field'] === 'voyage.enabled',
    ));
    $this->assertCount(1, $voyageEnabledDivergence);
    $this->assertSame(FALSE, $voyageEnabledDivergence[0]['stored_value']);
    $this->assertSame(TRUE, $voyageEnabledDivergence[0]['effective_value']);
    $this->assertSame(
      'settings.php runtime toggle ILAS_VOYAGE_ENABLED -> getenv/pantheon_get_secret',
      $voyageEnabledDivergence[0]['authoritative_source'],
    );

    $voyageReadyDivergence = array_values(array_filter(
      $snapshot['divergences'],
      static fn(array $divergence): bool => $divergence['field'] === 'voyage.runtime_ready',
    ));
    $this->assertCount(1, $voyageReadyDivergence);
    $this->assertSame(FALSE, $voyageReadyDivergence[0]['stored_value']);
    $this->assertSame(TRUE, $voyageReadyDivergence[0]['effective_value']);

    $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('voyage-secret-value', $json);
  }

  /**
   * Retrieval health sanitization retains dependency classifications and gates.
   */
  public function testBuildSnapshotRetainsRetrievalDependencyMetadata(): void {
    new Settings([
      'ilas_site_assistant_legalserver_online_application_url' => 'https://example.com/intake?pid=60&h=test',
    ]);

    $syncStorage = new MemoryStorage();
    $syncStorage->write('ilas_site_assistant.settings', [
      'llm' => [
        'enabled' => FALSE,
      ],
      'vector_search' => [
        'enabled' => FALSE,
      ],
        'langfuse' => [
          'enabled' => FALSE,
          'public_key' => '',
          'secret_key' => '',
          'environment' => '',
          'sample_rate' => 0.0,
          'redacted_preview_enabled' => FALSE,
          'redacted_preview_max_chars' => 160,
        ],
    ]);
    $syncStorage->write('key.key.pinecone_api_key', [
      'key_provider_settings' => [
        'key_value' => '',
      ],
    ]);

    $retrievalConfig = [
      'faq_index_id' => 'faq_accordion',
      'resource_index_id' => 'assistant_resources',
      'resource_fallback_index_id' => 'content',
      'faq_vector_index_id' => 'faq_accordion_vector',
      'resource_vector_index_id' => 'assistant_resources_vector',
    ];

    $retrievalServiceConfig = $this->createStub(ImmutableConfig::class);
    $retrievalServiceConfig->method('get')->willReturnCallback(static function (string $key) use ($retrievalConfig) {
      return match ($key) {
        'retrieval' => $retrievalConfig,
        'canonical_urls' => [
          'service_areas' => [
            'housing' => '/legal-help/housing',
            'family' => '/legal-help/family',
            'seniors' => '/legal-help/seniors',
            'health' => '/legal-help/health',
            'consumer' => '/legal-help/consumer',
            'civil_rights' => '/legal-help/civil-rights',
          ],
        ],
        'enable_faq' => TRUE,
        'enable_resources' => TRUE,
        'vector_search.enabled' => FALSE,
        default => NULL,
      };
    });

    $retrievalServiceFactory = $this->createStub(ConfigFactoryInterface::class);
    $retrievalServiceFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($retrievalServiceConfig);

    $enabledDatabaseIndex = $this->createMock(IndexInterface::class);
    $enabledDatabaseIndex->method('status')->willReturn(TRUE);
    $enabledDatabaseIndex->method('getServerId')->willReturn('database');

    $disabledDatabaseIndex = $this->createMock(IndexInterface::class);
    $disabledDatabaseIndex->method('status')->willReturn(FALSE);
    $disabledDatabaseIndex->method('getServerId')->willReturn('database');

    $enabledFaqVectorIndex = $this->createMock(IndexInterface::class);
    $enabledFaqVectorIndex->method('status')->willReturn(TRUE);
    $enabledFaqVectorIndex->method('getServerId')->willReturn('pinecone_vector_faq');

    $enabledResourceVectorIndex = $this->createMock(IndexInterface::class);
    $enabledResourceVectorIndex->method('status')->willReturn(TRUE);
    $enabledResourceVectorIndex->method('getServerId')->willReturn('pinecone_vector_resources');

    $indexStorage = $this->createMock(EntityStorageInterface::class);
    $indexStorage->method('load')->willReturnCallback(static function (string $id) use ($enabledDatabaseIndex, $disabledDatabaseIndex, $enabledFaqVectorIndex, $enabledResourceVectorIndex) {
      return match ($id) {
        'faq_accordion', 'assistant_resources' => $enabledDatabaseIndex,
        'content' => $disabledDatabaseIndex,
        'faq_accordion_vector' => $enabledFaqVectorIndex,
        'assistant_resources_vector' => $enabledResourceVectorIndex,
        default => NULL,
      };
    });

    $enabledServer = new class {
      public function status(): bool {
        return TRUE;
      }
    };

    $serverStorage = $this->createMock(EntityStorageInterface::class);
    $serverStorage->method('load')->willReturnCallback(static function (string $id) use ($enabledServer) {
      return match ($id) {
        'database', 'pinecone_vector_faq', 'pinecone_vector_resources' => $enabledServer,
        default => NULL,
      };
    });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(static function (string $entityTypeId) use ($indexStorage, $serverStorage) {
      return match ($entityTypeId) {
        'search_api_index' => $indexStorage,
        'search_api_server' => $serverStorage,
        default => throw new \InvalidArgumentException('Unexpected storage request: ' . $entityTypeId),
      };
    });

    $retrievalConfiguration = new RetrievalConfigurationService(
      $retrievalServiceFactory,
      $entityTypeManager,
    );

    $builder = new RuntimeTruthSnapshotBuilder(
      $this->buildConfigFactory([
        'ilas_site_assistant.settings' => [
          'llm.enabled' => FALSE,
          'vector_search.enabled' => FALSE,
          'langfuse.enabled' => FALSE,
          'langfuse.public_key' => '',
          'langfuse.secret_key' => '',
          'langfuse.environment' => '',
          'langfuse.sample_rate' => 0.0,
          'langfuse.redacted_preview_enabled' => FALSE,
          'langfuse.redacted_preview_max_chars' => 160,
        ],
        'raven.settings' => [],
        'key.key.pinecone_api_key' => [
          'key_provider_settings' => [
            'key_value' => '',
          ],
        ],
      ]),
      $syncStorage,
      $retrievalConfiguration,
    );

    $snapshot = $builder->buildSnapshot();
    $health = $snapshot['effective_runtime']['retrieval']['health'];

    $this->assertSame('required', $health['retrieval']['database_server']['classification']);
    $this->assertSame('server', $health['retrieval']['database_server']['dependency_type']);
    $this->assertTrue($health['retrieval']['database_server']['active']);
    $this->assertArrayNotHasKey('machine_name_valid', $health['retrieval']['database_server']);
    $this->assertSame('feature_gated', $health['retrieval']['faq_vector_index']['classification']);
    $this->assertFalse($health['retrieval']['faq_vector_index']['active']);
    $this->assertSame('faq_accordion_vector', $health['retrieval']['faq_vector_index']['index_id']);
    $this->assertSame('explicit_content_fallback', $health['retrieval']['resource_fallback_index']['allowed_degraded_mode']);
    $this->assertSame('index_disabled', $health['retrieval']['resource_fallback_index']['failure_code']);
  }

  /**
   * Builds a config factory stub keyed by config object name.
   *
   * @param array<string, array<string, mixed>> $configValues
   *   The config values keyed by config object name.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The stubbed config factory.
   */
  private function buildConfigFactory(array $configValues): ConfigFactoryInterface {
    $configs = [];

    foreach ($configValues as $configName => $values) {
      $config = $this->createStub(ImmutableConfig::class);
      $config->method('get')->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);
      $configs[$configName] = $config;
    }

    $empty = $this->createStub(ImmutableConfig::class);
    $empty->method('get')->willReturn(NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(static fn(string $name) => $configs[$name] ?? $empty);

    return $configFactory;
  }

}
