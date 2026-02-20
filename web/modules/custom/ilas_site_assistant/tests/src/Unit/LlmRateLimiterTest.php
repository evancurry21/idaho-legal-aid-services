<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\LlmRateLimiter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for LlmRateLimiter.
 */
#[Group('ilas_site_assistant')]
class LlmRateLimiterTest extends TestCase {

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
   * Tests that isAllowed returns TRUE when under the limit.
   */
  public function testAllowedWhenUnderLimit(): void {
    $limiter = $this->buildLimiter();

    $this->assertTrue($limiter->isAllowed());
    $this->assertFalse($limiter->wasRateLimited());
  }

  /**
   * Tests that recordCall increments the counter.
   */
  public function testRecordCallIncrementsCounter(): void {
    $limiter = $this->buildLimiter();

    $limiter->recordCall();
    $limiter->recordCall();
    $limiter->recordCall();

    $state = $limiter->getCurrentState();
    $this->assertEquals(3, $state['count']);
  }

  /**
   * Tests that the limit is enforced at max.
   */
  public function testLimitEnforcedAtMax(): void {
    $limiter = $this->buildLimiter(configOverrides: [
      'llm.global_rate_limit.max_per_hour' => 5,
    ]);

    // Record 5 calls to reach the limit.
    for ($i = 0; $i < 5; $i++) {
      $limiter->recordCall();
    }

    // 6th call should be blocked.
    $this->assertFalse($limiter->isAllowed());
    $this->assertTrue($limiter->wasRateLimited());
  }

  /**
   * Tests that an expired window resets and allows calls.
   */
  public function testWindowResetsAfterExpiry(): void {
    $limiter = $this->buildLimiter(configOverrides: [
      'llm.global_rate_limit.max_per_hour' => 5,
      'llm.global_rate_limit.window_seconds' => 3600,
    ]);

    // Fill up to limit.
    for ($i = 0; $i < 5; $i++) {
      $limiter->recordCall();
    }
    $this->assertFalse($limiter->isAllowed());

    // Simulate window expiry by backdating window_start.
    $this->storedState['window_start'] = time() - 3601;

    // Should be allowed again (window expired).
    $this->assertTrue($limiter->isAllowed());
    $this->assertFalse($limiter->wasRateLimited());

    // recordCall should reset the window.
    $limiter->recordCall();
    $state = $limiter->getCurrentState();
    $this->assertEquals(1, $state['count']);
    $this->assertGreaterThanOrEqual(time() - 1, $state['window_start']);
  }

  /**
   * Tests that max_per_hour: 0 disables the limiter.
   */
  public function testZeroMaxDisablesLimiting(): void {
    $limiter = $this->buildLimiter(configOverrides: [
      'llm.global_rate_limit.max_per_hour' => 0,
    ]);

    // Should always be allowed.
    $this->assertTrue($limiter->isAllowed());
    $this->assertFalse($limiter->wasRateLimited());

    // recordCall with 0 should be a no-op.
    $limiter->recordCall();
    // State should remain NULL (never written).
    $this->assertNull($this->storedState);
  }

  /**
   * Tests that wasRateLimited reflects the last isAllowed call.
   */
  public function testWasRateLimitedReflectsLastCall(): void {
    $limiter = $this->buildLimiter(configOverrides: [
      'llm.global_rate_limit.max_per_hour' => 2,
    ]);

    // Under limit.
    $limiter->isAllowed();
    $this->assertFalse($limiter->wasRateLimited());

    // Hit the limit.
    $limiter->recordCall();
    $limiter->recordCall();
    $limiter->isAllowed();
    $this->assertTrue($limiter->wasRateLimited());

    // Expire the window — should flip back.
    $this->storedState['window_start'] = time() - 3601;
    $limiter->isAllowed();
    $this->assertFalse($limiter->wasRateLimited());
  }

  /**
   * Tests that a warning is logged when the limit is reached.
   */
  public function testWarningLoggedAtLimit(): void {
    $limiter = $this->buildLimiter(configOverrides: [
      'llm.global_rate_limit.max_per_hour' => 3,
    ]);

    $limiter->recordCall();
    $limiter->recordCall();
    $limiter->recordCall();

    $this->assertLogContains('warning', 'rate limit reached');
  }

  /**
   * Tests that a notice is logged at 80% of the limit.
   */
  public function testNoticeLoggedAt80Percent(): void {
    $limiter = $this->buildLimiter(configOverrides: [
      'llm.global_rate_limit.max_per_hour' => 5,
    ]);

    // 80% of 5 = ceil(4.0) = 4.
    for ($i = 0; $i < 4; $i++) {
      $limiter->recordCall();
    }

    $this->assertLogContains('notice', '80%');
  }

  /**
   * Tests that reset clears the counter and allows calls.
   */
  public function testResetClearsCounter(): void {
    $limiter = $this->buildLimiter(configOverrides: [
      'llm.global_rate_limit.max_per_hour' => 2,
    ]);

    $limiter->recordCall();
    $limiter->recordCall();
    $this->assertFalse($limiter->isAllowed());

    $limiter->reset();

    $this->assertTrue($limiter->isAllowed());
    $state = $limiter->getCurrentState();
    $this->assertEquals(0, $state['count']);
  }

  /**
   * Tests that custom window_seconds is respected.
   */
  public function testCustomWindowSeconds(): void {
    $limiter = $this->buildLimiter(configOverrides: [
      'llm.global_rate_limit.max_per_hour' => 3,
      'llm.global_rate_limit.window_seconds' => 60,
    ]);

    // Fill up to limit.
    for ($i = 0; $i < 3; $i++) {
      $limiter->recordCall();
    }
    $this->assertFalse($limiter->isAllowed());

    // 60s window — backdate by 61s should reset.
    $this->storedState['window_start'] = time() - 61;
    $this->assertTrue($limiter->isAllowed());
  }

  /**
   * Tests that NULL state (first run) defaults to count=0.
   */
  public function testInitialStateFromNull(): void {
    // storedState is NULL by default (simulates first run).
    $limiter = $this->buildLimiter();

    $this->assertTrue($limiter->isAllowed());
    $state = $limiter->getCurrentState();
    $this->assertEquals(0, $state['count']);
  }

  /**
   * Builds a rate limiter with mocked dependencies.
   *
   * @param array $configOverrides
   *   Config values to override.
   *
   * @return \Drupal\ilas_site_assistant\Service\LlmRateLimiter
   *   The rate limiter instance.
   */
  private function buildLimiter(array $configOverrides = []): LlmRateLimiter {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->with(LlmRateLimiter::STATE_KEY)
      ->willReturnCallback(fn() => $this->storedState);
    $state->method('set')
      ->with(LlmRateLimiter::STATE_KEY, $this->anything())
      ->willReturnCallback(function ($key, $value) {
        $this->storedState = $value;
      });

    $configValues = [
      'llm.global_rate_limit.max_per_hour' => 500,
      'llm.global_rate_limit.window_seconds' => 3600,
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

    return new LlmRateLimiter($state, $configFactory, $logger);
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
