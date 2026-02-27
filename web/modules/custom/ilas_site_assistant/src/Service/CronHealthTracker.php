<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\State\StateInterface;

/**
 * Tracks cron execution health using Drupal state.
 *
 * Records last run time, duration, success/failure, and consecutive failure
 * count. Provides a health status method that evaluates staleness against
 * SLO-defined thresholds.
 */
class CronHealthTracker {

  /**
   * State key for last cron run timestamp.
   */
  const STATE_LAST_RUN = 'ilas_site_assistant.cron_last_run';

  /**
   * State key for last cron run duration in milliseconds.
   */
  const STATE_LAST_DURATION_MS = 'ilas_site_assistant.cron_last_duration_ms';

  /**
   * State key for whether the last cron run succeeded.
   */
  const STATE_LAST_SUCCESS = 'ilas_site_assistant.cron_last_success';

  /**
   * State key for consecutive failure count.
   */
  const STATE_CONSECUTIVE_FAILURES = 'ilas_site_assistant.cron_consecutive_failures';

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Constructs a CronHealthTracker.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * Records a cron execution.
   *
   * @param float $duration_ms
   *   Cron execution duration in milliseconds.
   * @param bool $success
   *   Whether the cron run completed without error.
   */
  public function recordRun(float $duration_ms, bool $success): void {
    $this->state->setMultiple([
      self::STATE_LAST_RUN => time(),
      self::STATE_LAST_DURATION_MS => $duration_ms,
      self::STATE_LAST_SUCCESS => $success,
      self::STATE_CONSECUTIVE_FAILURES => $success
        ? 0
        : ($this->state->get(self::STATE_CONSECUTIVE_FAILURES, 0) + 1),
    ]);
  }

  /**
   * Returns the current cron health status.
   *
   * @param \Drupal\ilas_site_assistant\Service\SloDefinitions $slo
   *   SLO definitions for threshold values.
   *
   * @return array
   *   Associative array with keys:
   *   - status: 'healthy', 'stale', or 'failing'
   *   - age: seconds since last run (NULL if never run)
   *   - last_run: timestamp of last run (NULL if never run)
   *   - duration_ms: last run duration (NULL if never run)
   *   - consecutive_failures: count of consecutive failures
   */
  public function getHealthStatus(SloDefinitions $slo): array {
    $lastRun = $this->state->get(self::STATE_LAST_RUN);
    $lastDuration = $this->state->get(self::STATE_LAST_DURATION_MS);
    $lastSuccess = $this->state->get(self::STATE_LAST_SUCCESS);
    $consecutiveFailures = (int) $this->state->get(self::STATE_CONSECUTIVE_FAILURES, 0);

    $age = $lastRun !== NULL ? (time() - (int) $lastRun) : NULL;
    $maxAge = $slo->getCronMaxAgeSeconds();

    // Determine status.
    $status = 'healthy';
    if ($lastRun === NULL || ($age !== NULL && $age > $maxAge)) {
      $status = 'stale';
    }
    if ($consecutiveFailures > 0 || $lastSuccess === FALSE) {
      $status = 'failing';
    }

    return [
      'status' => $status,
      'age' => $age,
      'last_run' => $lastRun !== NULL ? (int) $lastRun : NULL,
      'duration_ms' => $lastDuration !== NULL ? (float) $lastDuration : NULL,
      'consecutive_failures' => $consecutiveFailures,
    ];
  }

}
