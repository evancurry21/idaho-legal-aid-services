<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Typed accessor for SLO target configuration.
 *
 * Reads from `ilas_site_assistant.settings` `slo.*` keys with hardcoded
 * fallbacks matching install defaults. Used by health checks, alert policies,
 * and monitoring dashboards.
 */
class SloDefinitions {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs SloDefinitions.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Returns the availability target percentage.
   */
  public function getAvailabilityTargetPct(): float {
    return (float) ($this->get('availability_target_pct') ?? 99.5);
  }

  /**
   * Returns the P95 latency target in milliseconds.
   */
  public function getLatencyP95TargetMs(): int {
    return (int) ($this->get('latency_p95_target_ms') ?? 2000);
  }

  /**
   * Returns the P99 latency target in milliseconds.
   */
  public function getLatencyP99TargetMs(): int {
    return (int) ($this->get('latency_p99_target_ms') ?? 5000);
  }

  /**
   * Returns the error rate target percentage.
   */
  public function getErrorRateTargetPct(): float {
    return (float) ($this->get('error_rate_target_pct') ?? 5.0);
  }

  /**
   * Returns the error budget window in hours.
   */
  public function getErrorBudgetWindowHours(): int {
    return (int) ($this->get('error_budget_window_hours') ?? 168);
  }

  /**
   * Returns the maximum cron staleness in seconds.
   */
  public function getCronMaxAgeSeconds(): int {
    return (int) ($this->get('cron_max_age_seconds') ?? 7200);
  }

  /**
   * Returns the expected cron cadence in seconds.
   */
  public function getCronExpectedCadenceSeconds(): int {
    return (int) ($this->get('cron_expected_cadence_seconds') ?? 3600);
  }

  /**
   * Returns the maximum queue depth.
   */
  public function getQueueMaxDepth(): int {
    return (int) ($this->get('queue_max_depth') ?? 10000);
  }

  /**
   * Returns the maximum queue item age in seconds.
   */
  public function getQueueMaxAgeSeconds(): int {
    return (int) ($this->get('queue_max_age_seconds') ?? 3600);
  }

  /**
   * Reads a single SLO config value.
   *
   * @param string $key
   *   The key within the `slo` config block.
   *
   * @return mixed
   *   The config value or NULL if not set.
   */
  protected function get(string $key): mixed {
    return $this->configFactory
      ->get('ilas_site_assistant.settings')
      ->get('slo.' . $key);
  }

}
