<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\ilas_site_assistant\Service\QueueHealthMonitor;
use Drupal\ilas_site_assistant\Service\SloDefinitions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QueueHealthMonitor.
 *
 * @group ilas_site_assistant
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\QueueHealthMonitor
 */
#[Group('ilas_site_assistant')]
class QueueHealthMonitorTest extends TestCase {

  /**
   * In-memory state store.
   *
   * @var array
   */
  private array $stateStore = [];

  /**
   * Builds a mock StateInterface.
   */
  private function buildState(): \Drupal\Core\State\StateInterface {
    $state = $this->createMock(\Drupal\Core\State\StateInterface::class);

    $state->method('get')
      ->willReturnCallback(function (string $key, $default = NULL) {
        return $this->stateStore[$key] ?? $default;
      });

    $state->method('set')
      ->willReturnCallback(function (string $key, $value) {
        $this->stateStore[$key] = $value;
      });

    $state->method('delete')
      ->willReturnCallback(function (string $key) {
        unset($this->stateStore[$key]);
      });

    return $state;
  }

  /**
   * Builds a mock QueueFactory returning a queue with given depth.
   */
  private function buildQueueFactory(int $depth): QueueFactory {
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('numberOfItems')->willReturn($depth);

    $factory = $this->createMock(QueueFactory::class);
    $factory->method('get')
      ->with(QueueHealthMonitor::QUEUE_NAME)
      ->willReturn($queue);

    return $factory;
  }

  /**
   * Builds a SloDefinitions with given overrides.
   */
  private function buildSlo(array $overrides = []): SloDefinitions {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function ($key) use ($overrides) {
        if (str_starts_with($key, 'slo.')) {
          $sloKey = substr($key, 4);
          return $overrides[$sloKey] ?? NULL;
        }
        return NULL;
      });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    return new SloDefinitions($configFactory);
  }

  /**
   * Tests healthy status when queue depth is low.
   *
   * @covers ::getQueueHealthStatus
   */
  public function testHealthyWhenDepthBelowThreshold(): void {
    $factory = $this->buildQueueFactory(100);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);
    $slo = $this->buildSlo(['queue_max_depth' => 10000]);

    $status = $monitor->getQueueHealthStatus($slo);

    $this->assertSame('healthy', $status['status']);
    $this->assertSame(100, $status['depth']);
    $this->assertSame(10000, $status['max_depth']);
    $this->assertSame(1.0, $status['utilization_pct']);
  }

  /**
   * Tests backlogged status when depth exceeds 80% threshold.
   *
   * @covers ::getQueueHealthStatus
   */
  public function testBackloggedWhenDepthExceedsThreshold(): void {
    // 8001 out of 10000 is 80.01% — exceeds 80% threshold.
    $factory = $this->buildQueueFactory(8001);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);
    $slo = $this->buildSlo(['queue_max_depth' => 10000]);

    $status = $monitor->getQueueHealthStatus($slo);

    $this->assertSame('backlogged', $status['status']);
    $this->assertSame(8001, $status['depth']);
    $this->assertSame(80.01, $status['utilization_pct']);
  }

  /**
   * Tests healthy at exactly 80% threshold (boundary).
   *
   * @covers ::getQueueHealthStatus
   */
  public function testHealthyAtExactThreshold(): void {
    // 8000 out of 10000 is exactly 80% — not exceeding.
    $factory = $this->buildQueueFactory(8000);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);
    $slo = $this->buildSlo(['queue_max_depth' => 10000]);

    $status = $monitor->getQueueHealthStatus($slo);

    $this->assertSame('healthy', $status['status']);
  }

  /**
   * Tests that recordDrain increments the total count.
   *
   * @covers ::recordDrain
   * @covers ::getTotalDrained
   */
  public function testRecordDrainIncrements(): void {
    $factory = $this->buildQueueFactory(0);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);

    $this->assertSame(0, $monitor->getTotalDrained());

    $monitor->recordDrain(1);
    $this->assertSame(1, $monitor->getTotalDrained());

    $monitor->recordDrain(5);
    $this->assertSame(6, $monitor->getTotalDrained());
  }

  /**
   * Tests stale status when oldest queue item age exceeds SLO max age.
   *
   * @covers ::getQueueHealthStatus
   */
  public function testStaleWhenOldestAgeExceedsThreshold(): void {
    $factory = $this->buildQueueFactory(100);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);
    $monitor->recordEnqueue(time() - 4000, 0);
    $slo = $this->buildSlo([
      'queue_max_depth' => 10000,
      'queue_max_age_seconds' => 3600,
    ]);

    $status = $monitor->getQueueHealthStatus($slo);

    $this->assertSame('stale', $status['status']);
    $this->assertNotNull($status['oldest_item_age_seconds']);
    $this->assertGreaterThan(3600, $status['oldest_item_age_seconds']);
    $this->assertSame(3600, $status['max_age_seconds']);
  }

  /**
   * Tests combined backlogged + stale status.
   *
   * @covers ::getQueueHealthStatus
   */
  public function testBackloggedStaleStatus(): void {
    $factory = $this->buildQueueFactory(9000);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);
    $monitor->recordEnqueue(time() - 5000, 0);
    $slo = $this->buildSlo([
      'queue_max_depth' => 10000,
      'queue_max_age_seconds' => 3600,
    ]);

    $status = $monitor->getQueueHealthStatus($slo);

    $this->assertSame('backlogged_stale', $status['status']);
  }

  /**
   * Tests enqueue tracking initializes oldest timestamp only when queue empty.
   *
   * @covers ::recordEnqueue
   * @covers ::getOldestEnqueuedAt
   */
  public function testRecordEnqueueTracksOldestTimestamp(): void {
    $factory = $this->buildQueueFactory(1);
    $state = $this->buildState();
    $monitor = new QueueHealthMonitor($factory, $state);

    $first = time() - 120;
    $second = time() - 30;

    $monitor->recordEnqueue($first, 0);
    $monitor->recordEnqueue($second, 2);

    $this->assertSame($first, $monitor->getOldestEnqueuedAt());
  }

}
