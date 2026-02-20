<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\LlmCircuitBreaker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for LlmCircuitBreaker.
 */
#[Group('ilas_site_assistant')]
class LlmCircuitBreakerTest extends TestCase {

  /**
   * Current state stored in the mock State API.
   *
   * @var array|null
   */
  private ?array $storedState = NULL;

  /**
   * Captured log messages.
   *
   * @var array
   */
  private array $logMessages = [];

  /**
   * Tests that a closed circuit allows requests.
   */
  public function testClosedStateAllowsRequests(): void {
    $breaker = $this->buildBreaker();
    $this->assertTrue($breaker->isAvailable());
  }

  /**
   * Tests that a single failure keeps the circuit closed.
   */
  public function testSingleFailureKeepsCircuitClosed(): void {
    $breaker = $this->buildBreaker();
    $breaker->recordFailure();

    $this->assertTrue($breaker->isAvailable());
    $state = $breaker->getState();
    $this->assertEquals('closed', $state['state']);
    $this->assertEquals(1, $state['consecutive_failures']);
  }

  /**
   * Tests that three consecutive failures open the circuit.
   */
  public function testThreeConsecutiveFailuresOpensCircuit(): void {
    $breaker = $this->buildBreaker();

    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordFailure();

    $this->assertFalse($breaker->isAvailable());
    $state = $breaker->getState();
    $this->assertEquals('open', $state['state']);
    $this->assertGreaterThan(0, $state['opened_at']);
  }

  /**
   * Tests that a success resets the failure counter.
   */
  public function testSuccessResetsFailureCount(): void {
    $breaker = $this->buildBreaker();

    // 2 failures, then success.
    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordSuccess();

    $state = $breaker->getState();
    $this->assertEquals('closed', $state['state']);
    $this->assertEquals(0, $state['consecutive_failures']);

    // Now 2 more failures should NOT trip the circuit (counter was reset).
    $breaker->recordFailure();
    $breaker->recordFailure();
    $this->assertTrue($breaker->isAvailable());
  }

  /**
   * Tests that failures outside the time window reset the counter.
   */
  public function testFailureOutsideWindowResetsCounter(): void {
    $breaker = $this->buildBreaker();

    // Two failures.
    $breaker->recordFailure();
    $breaker->recordFailure();

    // Simulate time passing beyond the 60s window.
    $state = $breaker->getState();
    $state['last_failure_time'] = time() - 61;
    $this->storedState = $state;

    // Next failure should reset counter to 1, not increment to 3.
    $breaker->recordFailure();
    $state = $breaker->getState();
    $this->assertEquals('closed', $state['state']);
    $this->assertEquals(1, $state['consecutive_failures']);
  }

  /**
   * Tests that an open circuit rejects requests.
   */
  public function testOpenCircuitRejectsRequests(): void {
    $breaker = $this->buildBreaker();

    // Trip the circuit.
    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordFailure();

    $this->assertFalse($breaker->isAvailable());
  }

  /**
   * Tests transition from open to half-open after cooldown.
   */
  public function testOpenTransitionsToHalfOpenAfterCooldown(): void {
    $breaker = $this->buildBreaker();

    // Trip the circuit.
    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordFailure();

    // Simulate cooldown elapsed (300s).
    $state = $breaker->getState();
    $state['opened_at'] = time() - 301;
    $this->storedState = $state;

    $this->assertTrue($breaker->isAvailable());
    $state = $breaker->getState();
    $this->assertEquals('half_open', $state['state']);
  }

  /**
   * Tests that success in half-open state closes the circuit.
   */
  public function testHalfOpenSuccessClosesCircuit(): void {
    // Set up half-open state directly.
    $this->storedState = [
      'state' => 'half_open',
      'consecutive_failures' => 3,
      'last_failure_time' => time() - 301,
      'opened_at' => time() - 301,
    ];

    $breaker = $this->buildBreaker();
    $breaker->recordSuccess();

    $state = $breaker->getState();
    $this->assertEquals('closed', $state['state']);
    $this->assertEquals(0, $state['consecutive_failures']);
  }

  /**
   * Tests that failure in half-open state reopens the circuit.
   */
  public function testHalfOpenFailureReopensCircuit(): void {
    $this->storedState = [
      'state' => 'half_open',
      'consecutive_failures' => 3,
      'last_failure_time' => time() - 301,
      'opened_at' => time() - 301,
    ];

    $breaker = $this->buildBreaker();
    $breaker->recordFailure();

    $state = $breaker->getState();
    $this->assertEquals('open', $state['state']);
    // opened_at should be fresh (within last second).
    $this->assertGreaterThanOrEqual(time() - 1, $state['opened_at']);
  }

  /**
   * Tests that custom thresholds from config are respected.
   */
  public function testCustomThresholdsFromConfig(): void {
    $breaker = $this->buildBreaker(configOverrides: [
      'llm.circuit_breaker.failure_threshold' => 5,
      'llm.circuit_breaker.failure_window_seconds' => 120,
      'llm.circuit_breaker.cooldown_seconds' => 600,
    ]);

    // 4 failures should NOT trip with threshold=5.
    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordFailure();
    $this->assertTrue($breaker->isAvailable());

    // 5th failure should trip.
    $breaker->recordFailure();
    $this->assertFalse($breaker->isAvailable());

    // With cooldown=600, after 301s it should still be open.
    $state = $breaker->getState();
    $state['opened_at'] = time() - 301;
    $this->storedState = $state;
    $this->assertFalse($breaker->isAvailable());

    // After 601s it should transition to half-open.
    $state = $breaker->getState();
    $state['opened_at'] = time() - 601;
    $this->storedState = $state;
    $this->assertTrue($breaker->isAvailable());
  }

  /**
   * Tests that state transitions emit appropriate log messages.
   */
  public function testTransitionLogsEmitted(): void {
    $breaker = $this->buildBreaker();

    // Trip the circuit — should log warning.
    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordFailure();
    $this->assertLogContains('warning', 'circuit breaker opened');

    // Simulate cooldown for half-open transition — should log notice.
    $state = $breaker->getState();
    $state['opened_at'] = time() - 301;
    $this->storedState = $state;
    $breaker->isAvailable();
    $this->assertLogContains('notice', 'half_open');

    // Record success to close — should log info.
    $breaker->recordSuccess();
    $this->assertLogContains('info', 'closing');
  }

  /**
   * Tests that reset() force-closes the circuit.
   */
  public function testResetForcesCircuitClosed(): void {
    $breaker = $this->buildBreaker();

    // Trip the circuit.
    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordFailure();
    $this->assertFalse($breaker->isAvailable());

    // Force reset.
    $breaker->reset();
    $this->assertTrue($breaker->isAvailable());
    $state = $breaker->getState();
    $this->assertEquals('closed', $state['state']);
    $this->assertEquals(0, $state['consecutive_failures']);
  }

  /**
   * Builds a circuit breaker with mocked dependencies.
   *
   * @param array $configOverrides
   *   Config values to override.
   *
   * @return \Drupal\ilas_site_assistant\Service\LlmCircuitBreaker
   *   The circuit breaker instance.
   */
  private function buildBreaker(array $configOverrides = []): LlmCircuitBreaker {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->with(LlmCircuitBreaker::STATE_KEY)
      ->willReturnCallback(fn() => $this->storedState);
    $state->method('set')
      ->with(LlmCircuitBreaker::STATE_KEY, $this->anything())
      ->willReturnCallback(function ($key, $value) {
        $this->storedState = $value;
      });

    $configValues = [
      'llm.circuit_breaker.failure_threshold' => 3,
      'llm.circuit_breaker.failure_window_seconds' => 60,
      'llm.circuit_breaker.cooldown_seconds' => 300,
    ];
    foreach ($configOverrides as $key => $value) {
      $configValues[$key] = $value;
    }

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn($key) => $configValues[$key] ?? NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);
    foreach (['warning', 'notice', 'info'] as $level) {
      $logger->method($level)
        ->willReturnCallback(function ($message) use ($level) {
          $this->logMessages[] = ['level' => $level, 'message' => $message];
        });
    }

    return new LlmCircuitBreaker($state, $configFactory, $logger);
  }

  /**
   * Asserts that a log message at the given level contains the expected text.
   */
  private function assertLogContains(string $level, string $needle): void {
    foreach ($this->logMessages as $log) {
      if ($log['level'] === $level && stripos($log['message'], $needle) !== FALSE) {
        $this->addToAssertionCount(1);
        return;
      }
    }
    $logged = array_map(fn($l) => "[{$l['level']}] {$l['message']}", $this->logMessages);
    $this->fail("Expected a '$level' log containing '$needle'. Logged: " . implode('; ', $logged));
  }

}
