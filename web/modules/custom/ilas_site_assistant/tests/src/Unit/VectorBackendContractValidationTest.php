<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Guards FAQ/resource vector backend validation against mixed embeddings drift.
 */
#[Group('ilas_site_assistant')]
final class VectorBackendContractValidationTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {
      public function get(string $channel): NullLogger {
        return new NullLogger();
      }
    });

    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public function testFaqValidatorAcceptsVoyageBackedCosineContract(): void {
    $probe = new class extends FaqIndex {
      public function __construct() {}
      public function validateBackendContract(mixed $index): bool {
        return $this->validateVectorMetric($index);
      }
    };

    $this->assertTrue($probe->validateBackendContract($this->buildVectorIndex([
      'database_settings' => ['metric' => 'cosine_similarity'],
      'embeddings_engine' => 'ilas_voyage__voyage-law-2',
      'embeddings_engine_configuration' => ['dimensions' => 1024],
    ])));
  }

  public function testFaqValidatorRejectsLegacyGoogleEmbeddingsEngine(): void {
    $probe = new class extends FaqIndex {
      public function __construct() {}
      public function validateBackendContract(mixed $index): bool {
        return $this->validateVectorMetric($index);
      }
    };

    $this->assertFalse($probe->validateBackendContract($this->buildVectorIndex([
      'database_settings' => ['metric' => 'cosine_similarity'],
      'embeddings_engine' => 'gemini__models/gemini-embedding-001',
      'embeddings_engine_configuration' => ['dimensions' => 1024],
    ])));
  }

  public function testResourceValidatorRejectsNonCosineMetric(): void {
    $probe = new class extends ResourceFinder {
      public function __construct() {}
      public function validateBackendContract(mixed $index): bool {
        return $this->validateVectorMetric($index);
      }
    };

    $this->assertFalse($probe->validateBackendContract($this->buildVectorIndex([
      'database_settings' => ['metric' => 'dot_product'],
      'embeddings_engine' => 'ilas_voyage__voyage-law-2',
      'embeddings_engine_configuration' => ['dimensions' => 1024],
    ])));
  }

  public function testResourceValidatorRejectsWrongDimensions(): void {
    $probe = new class extends ResourceFinder {
      public function __construct() {}
      public function validateBackendContract(mixed $index): bool {
        return $this->validateVectorMetric($index);
      }
    };

    $this->assertFalse($probe->validateBackendContract($this->buildVectorIndex([
      'database_settings' => ['metric' => 'cosine_similarity'],
      'embeddings_engine' => 'ilas_voyage__voyage-law-2',
      'embeddings_engine_configuration' => ['dimensions' => 3072],
    ])));
  }

  /**
   * Builds a lightweight vector index double with backend config access.
   */
  private function buildVectorIndex(array $backendConfig): object {
    $server = new class($backendConfig) {
      public function __construct(private array $backendConfig) {}
      public function getBackendConfig(): array {
        return $this->backendConfig;
      }
    };

    return new class($server) {
      public function __construct(private object $server) {}
      public function getServerInstance(): object {
        return $this->server;
      }
    };
  }

}
