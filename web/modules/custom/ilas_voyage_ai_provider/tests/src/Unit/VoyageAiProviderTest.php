<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_voyage_ai_provider\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ilas_voyage_ai_provider\Plugin\AiProvider\VoyageAiProvider;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Unit tests for the Voyage embeddings provider.
 */
#[Group('ilas_site_assistant')]
final class VoyageAiProviderTest extends TestCase {

  /**
   * Builds a Voyage provider with test doubles.
   */
  private function buildProvider(
    object $httpClient,
    ?KeyRepositoryInterface $keyRepository = NULL,
    array $settings = ['api_key' => 'voyage_ai_api_key'],
  ): VoyageAiProvider {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => $settings[$key] ?? NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_voyage_ai_provider.settings')
      ->willReturn($config);

    $loggerChannel = $this->createStub(LoggerChannelInterface::class);
    $loggerFactory = $this->createStub(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($loggerChannel);

    $keyRepository ??= $this->buildKeyRepository('voyage-test-key');

    $moduleHandler = $this->createStub(ModuleHandlerInterface::class);
    $fileSystem = $this->createStub(FileSystemInterface::class);
    $cacheBackend = $this->createStub(CacheBackendInterface::class);

    return new VoyageAiProvider(
      'ilas_voyage',
      [
        'provider' => 'ilas_voyage_ai_provider',
        'label' => 'Voyage AI',
      ],
      $httpClient,
      $configFactory,
      $loggerFactory,
      $cacheBackend,
      $keyRepository,
      $moduleHandler,
      new EventDispatcher(),
      $fileSystem,
    );
  }

  /**
   * Builds a repository stub that returns a Key entity.
   */
  private function buildKeyRepository(?string $keyValue): KeyRepositoryInterface {
    $key = $this->createStub(KeyInterface::class);
    $key->method('getKeyValue')->willReturn($keyValue);

    $repository = $this->createStub(KeyRepositoryInterface::class);
    $repository->method('getKey')->willReturn($keyValue === NULL ? NULL : $key);

    return $repository;
  }

  /**
   * The provider advertises the single legal embeddings model and vector size.
   */
  public function testModelRegistryAndVectorSize(): void {
    $httpClient = new class implements PsrClientInterface {
      public function sendRequest(RequestInterface $request): ResponseInterface {
        throw new \BadMethodCallException('Not used in this test.');
      }

      public function request(string $method, string $uri, array $options = []): ResponseInterface {
        throw new \BadMethodCallException('Not used in this test.');
      }
    };

    $provider = $this->buildProvider($httpClient);

    $this->assertSame(['voyage-law-2' => 'Voyage Law 2'], $provider->getConfiguredModels('embeddings'));
    $this->assertSame(['embeddings'], $provider->getSupportedOperationTypes());
    $this->assertSame(1024, $provider->embeddingsVectorSize('voyage-law-2'));
    $this->assertSame(16000, $provider->maxEmbeddingsInput('voyage-law-2'));
  }

  /**
   * Embeddings requests use the Voyage endpoint and expected payload.
   */
  public function testEmbeddingsRequestFormation(): void {
    $captured = [];
    $response = new Response(200, [], '{"data":[{"embedding":[0.1,0.2,0.3]}]}');

    $httpClient = new class($captured, $response) implements PsrClientInterface {
      private $captured;

      public function __construct(
        &$captured,
        private ResponseInterface $response,
      ) {
        $this->captured =& $captured;
      }

      public function sendRequest(RequestInterface $request): ResponseInterface {
        throw new \BadMethodCallException('Not used in this test.');
      }

      public function request(string $method, string $uri, array $options = []): ResponseInterface {
        $this->captured = [
          'method' => $method,
          'uri' => $uri,
          'options' => $options,
        ];
        return $this->response;
      }
    };

    $provider = $this->buildProvider($httpClient);
    $provider->embeddings('Idaho eviction defense', 'voyage-law-2');

    $this->assertSame('POST', $captured['method']);
    $this->assertSame('https://api.voyageai.com/v1/embeddings', $captured['uri']);
    $this->assertSame('Bearer voyage-test-key', $captured['options']['headers']['Authorization'] ?? NULL);
    $this->assertSame('application/json', $captured['options']['headers']['Content-Type'] ?? NULL);
    $this->assertSame('voyage-law-2', $captured['options']['json']['model'] ?? NULL);
    $this->assertSame(['Idaho eviction defense'], $captured['options']['json']['input'] ?? NULL);
  }

  /**
   * The provider normalizes the first returned embedding vector.
   */
  public function testEmbeddingsResponseNormalization(): void {
    $response = new Response(200, [], '{"data":[{"embedding":[0.1,0.2,0.3]}],"usage":{"total_tokens":12}}');

    $httpClient = new class($response) implements PsrClientInterface {
      public function __construct(private ResponseInterface $response) {}

      public function sendRequest(RequestInterface $request): ResponseInterface {
        throw new \BadMethodCallException('Not used in this test.');
      }

      public function request(string $method, string $uri, array $options = []): ResponseInterface {
        return $this->response;
      }
    };

    $provider = $this->buildProvider($httpClient);
    $output = $provider->embeddings(new EmbeddingsInput('Child custody resources'), 'voyage-law-2', ['ai_search']);

    $this->assertSame([0.1, 0.2, 0.3], $output->getNormalized());
  }

  /**
   * Missing key configuration fails loudly.
   */
  public function testEmbeddingsThrowsWhenApiKeyMissing(): void {
    $httpClient = new class implements PsrClientInterface {
      public function sendRequest(RequestInterface $request): ResponseInterface {
        throw new \BadMethodCallException('Not used in this test.');
      }

      public function request(string $method, string $uri, array $options = []): ResponseInterface {
        throw new \BadMethodCallException('Not used in this test.');
      }
    };

    $provider = $this->buildProvider($httpClient, $this->buildKeyRepository(NULL));

    $this->expectException(AiSetupFailureException::class);
    $provider->embeddings('housing', 'voyage-law-2');
  }

  /**
   * Malformed Voyage responses are rejected.
   */
  public function testEmbeddingsThrowsOnMalformedResponse(): void {
    $response = new Response(200, [], '{"data":[]}');

    $httpClient = new class($response) implements PsrClientInterface {
      public function __construct(private ResponseInterface $response) {}

      public function sendRequest(RequestInterface $request): ResponseInterface {
        throw new \BadMethodCallException('Not used in this test.');
      }

      public function request(string $method, string $uri, array $options = []): ResponseInterface {
        return $this->response;
      }
    };

    $provider = $this->buildProvider($httpClient);

    $this->expectException(AiResponseErrorException::class);
    $provider->embeddings('benefits appeal', 'voyage-law-2');
  }

}
