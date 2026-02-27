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
 * Contract tests for deterministic dependency-failure degrade behavior.
 */
#[Group('ilas_site_assistant')]
class DependencyFailureDegradeContractTest extends TestCase {

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

  /**
   * Search API unavailable in FAQ path degrades to legacy retrieval.
   */
  public function testFaqSearchApiUnavailableFallsBackToLegacy(): void {
    $legacy = [['id' => 'faq_1', 'score' => 10.0, 'source' => 'legacy']];
    $faq = new ContractFaqIndex(NULL, $legacy);

    $result = $faq->search('eviction notice', 3);

    $this->assertSame($legacy, $result);
    $this->assertSame(1, $faq->legacyCallCount);
  }

  /**
   * Search API query exception in FAQ path degrades to legacy retrieval.
   */
  public function testFaqSearchApiQueryFailureFallsBackToLegacy(): void {
    $legacy = [['id' => 'faq_legacy', 'score' => 7.5, 'source' => 'legacy']];
    $index = new class {
      public function status(): bool {
        return TRUE;
      }
      public function query(): object {
        return new class {
          public function keys(string $query): self {
            return $this;
          }
          public function range(int $start, int $length): self {
            return $this;
          }
          public function addCondition(string $field, string $value): self {
            return $this;
          }
          public function execute(): object {
            throw new \RuntimeException('Search backend timeout');
          }
        };
      }
    };

    $faq = new ContractFaqIndex($index, $legacy);

    $result = $faq->search('tenant rights', 3);

    $this->assertSame($legacy, $result);
    $this->assertSame(1, $faq->legacyCallCount);
  }

  /**
   * Search API unavailable in resource path degrades to legacy retrieval.
   */
  public function testResourceSearchApiUnavailableFallsBackToLegacy(): void {
    $legacy = [['id' => 101, 'score' => 9.0, 'source' => 'legacy']];
    $finder = new ContractResourceFinder(NULL, $legacy);

    $result = $finder->findResources('forms');

    $this->assertSame($legacy, $result);
    $this->assertSame(1, $finder->legacyCallCount);
  }

  /**
   * Search API query exception in resource path degrades to legacy retrieval.
   */
  public function testResourceSearchApiQueryFailureFallsBackToLegacy(): void {
    $legacy = [['id' => 202, 'score' => 8.0, 'source' => 'legacy']];
    $index = new class {
      public function id(): string {
        return 'assistant_resources';
      }
      public function status(): bool {
        return TRUE;
      }
      public function query(): object {
        return new class {
          public function keys(string $query): self {
            return $this;
          }
          public function addCondition(string $field, string|int $value): self {
            return $this;
          }
          public function range(int $start, int $length): self {
            return $this;
          }
          public function execute(): object {
            throw new \RuntimeException('Search API transport failure');
          }
        };
      }
    };

    $finder = new ContractResourceFinder($index, $legacy);

    $result = $finder->findResources('housing');

    $this->assertSame($legacy, $result);
    $this->assertSame(1, $finder->legacyCallCount);
  }

  /**
   * FAQ vector unavailable/failure preserves lexical results deterministically.
   */
  public function testFaqVectorUnavailablePreservesLexicalResults(): void {
    $lexical = [['paragraph_id' => 1, 'id' => 'faq_1', 'score' => 5.0, 'source' => 'lexical']];
    $faq = new ContractFaqIndex(
      NULL,
      [],
      ['enabled' => TRUE, 'fallback_threshold' => 2, 'min_lexical_score' => 0],
      []
    );

    $result = $faq->contractSupplement($lexical);

    $this->assertTrue($faq->vectorAttempted);
    $this->assertSame($lexical, $result);
  }

  /**
   * Resource vector unavailable/failure preserves lexical results deterministically.
   */
  public function testResourceVectorUnavailablePreservesLexicalResults(): void {
    $lexical = [['id' => 1, 'score' => 4.0, 'source' => 'lexical']];
    $finder = new ContractResourceFinder(
      NULL,
      [],
      ['enabled' => TRUE, 'fallback_threshold' => 2, 'min_lexical_score' => 0],
      []
    );

    $result = $finder->contractSupplement($lexical);

    $this->assertTrue($finder->vectorAttempted);
    $this->assertSame($lexical, $result);
  }

}

/**
 * FAQ contract test double with deterministic legacy/vector controls.
 */
class ContractFaqIndex extends FaqIndex {

  public int $legacyCallCount = 0;
  public bool $vectorAttempted = FALSE;

  /**
   * @param mixed $index
   */
  public function __construct(
    private mixed $contractIndex,
    private array $legacyResults,
    private array $vectorConfig = ['enabled' => FALSE],
    private array $vectorResults = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function buildQueryCacheKey(string $query, int $limit, ?string $type): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function getCurrentLanguage() {
    return 'en';
  }

  /**
   * {@inheritdoc}
   */
  protected function getIndex() {
    return $this->contractIndex;
  }

  /**
   * {@inheritdoc}
   */
  protected function searchLegacy(string $query, int $limit) {
    $this->legacyCallCount++;
    return array_slice($this->legacyResults, 0, $limit);
  }

  /**
   * {@inheritdoc}
   */
  protected function getVectorSearchConfig(): array {
    return $this->vectorConfig;
  }

  /**
   * {@inheritdoc}
   */
  protected function searchVector(string $query, int $limit, ?string $type = NULL): array {
    $this->vectorAttempted = TRUE;
    return $this->vectorResults;
  }

  /**
   * Exposes protected vector supplement method for contract assertions.
   */
  public function contractSupplement(array $lexical): array {
    return $this->supplementWithVectorResults($lexical, 'contract query', 3, NULL);
  }

}

/**
 * Resource finder contract test double with deterministic legacy/vector controls.
 */
class ContractResourceFinder extends ResourceFinder {

  public int $legacyCallCount = 0;
  public bool $vectorAttempted = FALSE;

  /**
   * @param mixed $index
   */
  public function __construct(
    private mixed $contractIndex,
    private array $legacyResults,
    private array $vectorConfig = ['enabled' => FALSE],
    private array $vectorResults = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function buildQueryCacheKey(string $query, ?string $type, int $limit): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function getCurrentLanguage() {
    return 'en';
  }

  /**
   * {@inheritdoc}
   */
  protected function getIndex() {
    return $this->contractIndex;
  }

  /**
   * {@inheritdoc}
   */
  protected function findByTypeLegacy(string $query, ?string $type, int $limit) {
    $this->legacyCallCount++;
    return array_slice($this->legacyResults, 0, $limit);
  }

  /**
   * {@inheritdoc}
   */
  protected function getVectorSearchConfig(): array {
    return $this->vectorConfig;
  }

  /**
   * {@inheritdoc}
   */
  protected function findByTypeVector(string $query, ?string $type, int $limit): array {
    $this->vectorAttempted = TRUE;
    return $this->vectorResults;
  }

  /**
   * Exposes protected vector supplement method for contract assertions.
   */
  public function contractSupplement(array $lexical): array {
    return $this->supplementWithVectorResults($lexical, 'contract query', NULL, 3);
  }

}
