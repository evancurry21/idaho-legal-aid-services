<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;

/**
 * Monitors Langfuse export queue depth and health.
 *
 * Tracks queue depth relative to SLO-defined thresholds and records
 * drain counts for throughput monitoring.
 */
class QueueHealthMonitor {

  /**
   * State key for total items drained.
   */
  const STATE_TOTAL_DRAINED = 'ilas_site_assistant.queue_total_drained';

  /**
   * The Drupal queue name for Langfuse export.
   */
  const QUEUE_NAME = 'ilas_langfuse_export';

  /**
   * Backlog warning threshold as a fraction of max depth.
   */
  const BACKLOG_THRESHOLD_RATIO = 0.8;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Constructs a QueueHealthMonitor.
   */
  public function __construct(QueueFactory $queue_factory, StateInterface $state) {
    $this->queueFactory = $queue_factory;
    $this->state = $state;
  }

  /**
   * Returns the current queue health status.
   *
   * @param \Drupal\ilas_site_assistant\Service\SloDefinitions $slo
   *   SLO definitions for threshold values.
   *
   * @return array
   *   Associative array with keys:
   *   - status: 'healthy' or 'backlogged'
   *   - depth: current queue item count
   *   - max_depth: SLO max depth threshold
   *   - utilization_pct: depth as percentage of max_depth
   */
  public function getQueueHealthStatus(SloDefinitions $slo): array {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $depth = $queue->numberOfItems();
    $maxDepth = $slo->getQueueMaxDepth();

    $utilizationPct = $maxDepth > 0 ? round(($depth / $maxDepth) * 100, 2) : 0;
    $threshold = $maxDepth * self::BACKLOG_THRESHOLD_RATIO;

    $status = $depth > $threshold ? 'backlogged' : 'healthy';

    return [
      'status' => $status,
      'depth' => $depth,
      'max_depth' => $maxDepth,
      'utilization_pct' => $utilizationPct,
    ];
  }

  /**
   * Records that items have been drained from the queue.
   *
   * @param int $count
   *   Number of items drained.
   */
  public function recordDrain(int $count): void {
    $current = (int) $this->state->get(self::STATE_TOTAL_DRAINED, 0);
    $this->state->set(self::STATE_TOTAL_DRAINED, $current + $count);
  }

  /**
   * Returns the total items drained since last reset.
   */
  public function getTotalDrained(): int {
    return (int) $this->state->get(self::STATE_TOTAL_DRAINED, 0);
  }

}
