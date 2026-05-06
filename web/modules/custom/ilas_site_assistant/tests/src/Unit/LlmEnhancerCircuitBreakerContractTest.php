<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ilas_site_assistant\Service\CostControlPolicy;
use Drupal\ilas_site_assistant\Service\EnvironmentDetector;
use Drupal\ilas_site_assistant\Service\LlmCircuitBreaker;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\LlmRateLimiter;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\RequestTimeLlmTransportInterface;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks the LlmEnhancer ↔ LlmCircuitBreaker method-name contract.
 *
 * Regression coverage for the production HTTP 500 caused by
 * LlmEnhancer::classifyIntent() calling
 * LlmCircuitBreaker::canAttempt(), a method that does not exist
 * on LlmCircuitBreaker. The correct method is isAvailable().
 *
 * Reproducer eval cases (eval-FrE-2026-04-30T20:56:49):
 *   - "is there any way to get my car back"
 *   - "i also gave them my bank account number and theyve already taken $500"
 *   - "what evidence do i need to prove hes using"
 *   - "I am being denied access to my kids tonight"  (es-09 escalation)
 */
#[Group('ilas_site_assistant')]
final class LlmEnhancerCircuitBreakerContractTest extends TestCase {

  /**
   * classifyIntent() must invoke isAvailable() (read-only) on the breaker.
   *
   * Before the fix, LlmEnhancer.php called canAttempt(), which does not
   * exist on LlmCircuitBreaker. PHP raised a fatal Error that bubbled to
   * the controller's outer catch and produced HTTP 500 for every input
   * routed through the LLM fallback path. Asserting the read-only check
   * is named isAvailable() prevents the desync from regressing.
   */
  public function testClassifyIntentUsesIsAvailableNotCanAttempt(): void {
    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->expects($this->atLeastOnce())
      ->method('isAvailable')
      ->willReturn(TRUE);

    $enhancer = $this->buildEnhancer(['llm.enabled' => TRUE], $breaker);
    $enhancer->classifyIntent('whats the difference between guardianship and adoption', 'unknown');
  }

  /**
   * Open breaker must short-circuit via the read-only isAvailable() check.
   *
   * Confirms the open-circuit path does not silently fall through to the
   * transport when the breaker reports unavailable.
   */
  public function testClassifyIntentSkipsTransportWhenBreakerOpen(): void {
    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->expects($this->once())
      ->method('isAvailable')
      ->willReturn(FALSE);

    $transport = new ImmutableContractTransport();
    $enhancer = $this->buildEnhancer(['llm.enabled' => TRUE], $breaker, $transport);

    $result = $enhancer->classifyIntent('I am being denied access to my kids tonight', 'unknown');

    $this->assertSame('unknown', $result, 'Open breaker must return current intent without LLM call.');
    $this->assertSame(0, $transport->calls, 'Transport must not be invoked when breaker is open.');
  }

  /**
   * The four production reproducer phrasings must not throw at the breaker step.
   *
   * Even with a closed breaker, no Throwable should be raised by the
   * LlmEnhancer for these inputs. They are referential, ambiguous, and
   * arrive without prior conversation context — exactly the shapes that
   * triggered the 500 in production.
   *
   * @dataProvider reproducerPhrasings
   */
  public function testReproducerPhrasingsDoNotCrashClassifyIntent(string $phrasing): void {
    $breaker = $this->createMock(LlmCircuitBreaker::class);
    $breaker->method('isAvailable')->willReturn(TRUE);

    $transport = new ImmutableContractTransport();
    $enhancer = $this->buildEnhancer(['llm.enabled' => TRUE], $breaker, $transport);

    // Must complete without throwing. We do not assert the returned intent
    // because the static transport returns 'unknown'; we only assert the
    // pipeline does not produce a fatal Error.
    $enhancer->classifyIntent($phrasing, 'unknown');
    $this->assertTrue(TRUE, 'classifyIntent completed without throwing.');
  }

  /**
   * Reproducer phrasings drawn from eval-FrE-2026-04-30T20:56:49 errors #7-#10.
   */
  public static function reproducerPhrasings(): array {
    return [
      'r07_car_back' => ['is there any way to get my car back'],
      'r08_bank_account_fraud' => ['i also gave them my bank account number and theyve already taken $500'],
      'r09_evidence_substance' => ['what evidence do i need to prove hes using'],
      'r10_es09_kids_tonight' => ['I am being denied access to my kids tonight'],
    ];
  }

  /**
   * Builds an LlmEnhancer with a real-typed circuit breaker.
   */
  private function buildEnhancer(
    array $overrides,
    ?LlmCircuitBreaker $breaker,
    ?RequestTimeLlmTransportInterface $transport = NULL,
  ): LlmEnhancer {
    $configFactory = $this->buildConfigFactory($overrides);
    $transport = $transport ?? new ImmutableContractTransport();
    return new LlmEnhancer(
      $configFactory,
      $this->createStub(ClientInterface::class),
      $this->buildLoggerFactory(),
      new PolicyFilter($configFactory),
      // No cache.
      NULL,
      $breaker,
      // No rate limiter, cost-control, env detector — this test is scoped
      // to the breaker contract only.
      NULL,
      NULL,
      NULL,
      $transport,
    );
  }

  /**
   * Builds a ConfigFactoryInterface mock returning the LLM settings stub.
   */
  private function buildConfigFactory(array $overrides): ConfigFactoryInterface {
    $defaults = [
      'llm.enabled' => FALSE,
      'llm.max_tokens' => 150,
      'llm.temperature' => 0.3,
      'llm.fallback_on_error' => TRUE,
      'llm.safety_threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
      'llm.cache_ttl' => 0,
      'llm.max_retries' => 0,
    ];
    $values = $overrides + $defaults;
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);
    return $factory;
  }

  /**
   * Builds a LoggerChannelFactoryInterface mock returning a stub logger.
   */
  private function buildLoggerFactory(): LoggerChannelFactoryInterface {
    $logger = $this->createMock(LoggerInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    return $factory;
  }

}

/**
 * Static transport that records call counts and returns a benign payload.
 */
final class ImmutableContractTransport implements RequestTimeLlmTransportInterface {

  /**
   * Number of times completeStructuredJson() was invoked.
   */
  public int $calls = 0;

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): string {
    return 'cohere';
  }

  /**
   * {@inheritdoc}
   */
  public function getModelId(): string {
    return 'command-a-03-2025';
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function completeStructuredJson(array $messages, array $schema, array $options = []): array {
    $this->calls++;
    return ['payload' => ['intent' => 'unknown']];
  }

}
