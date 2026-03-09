<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Global hourly rate limiter for LLM API calls.
 *
 * Tracks total LLM API calls across all users within a sliding window.
 * When the limit is reached, new calls are skipped and the rule-based
 * fallback path is used instead.
 *
 * State is stored in Drupal's State API (key_value table), which works
 * on all Pantheon tiers without Redis.
 */
class LlmRateLimiter {

  /**
   * State API key for rate limit data.
   */
  const STATE_KEY = 'ilas_site_assistant.llm_rate_limit';

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Shared admission/state coordinator.
   *
   * @var \Drupal\ilas_site_assistant\Service\LlmAdmissionCoordinator
   */
  protected LlmAdmissionCoordinator $admissionCoordinator;

  /**
   * Whether the last isAllowed() call returned FALSE.
   *
   * @var bool
   */
  protected bool $lastWasLimited = FALSE;

  /**
   * Constructs an LlmRateLimiter.
   */
  public function __construct(
    StateInterface $state,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
    LlmAdmissionCoordinator $admission_coordinator,
  ) {
    $this->state = $state;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->admissionCoordinator = $admission_coordinator;
  }

  /**
   * Checks if an LLM call is allowed under the global rate limit.
   *
   * This compatibility method is read-only and does not reserve capacity.
   * Production callers should use tryAcquireAllowance() or CostControlPolicy.
   *
   * @return bool
   *   TRUE if under limit (or limiter disabled), FALSE if limit reached.
   */
  public function isAllowed(): bool {
    $maxPerHour = $this->getConfig('max_per_hour');

    // max_per_hour: 0 disables the limiter entirely.
    if ($maxPerHour === 0) {
      $this->lastWasLimited = FALSE;
      return TRUE;
    }

    $data = $this->getStateData();
    $windowSeconds = $this->getConfig('window_seconds');

    // If the window has expired, the counter would reset on next recordCall().
    // For the check, treat an expired window as allowed.
    if ((time() - $data['window_start']) >= $windowSeconds) {
      $this->lastWasLimited = FALSE;
      return TRUE;
    }

    // Check if current count is at or above the limit.
    if ($data['count'] >= $maxPerHour) {
      $this->lastWasLimited = TRUE;
      return FALSE;
    }

    $this->lastWasLimited = FALSE;
    return TRUE;
  }

  /**
   * Atomically reserves one allowance when capacity remains.
   *
   * @return bool
   *   TRUE when a request slot was reserved, FALSE otherwise.
   */
  public function tryAcquireAllowance(): bool {
    $allowed = $this->admissionCoordinator->tryAcquireRateLimitAllowance();
    $this->lastWasLimited = !$allowed;
    return $allowed;
  }

  /**
   * Records a successful LLM API call.
   *
   * Compatibility increment for callers still using the legacy split API.
   */
  public function recordCall(): void {
    $this->admissionCoordinator->recordRateLimiterCall();
  }

  /**
   * Returns whether the last isAllowed() call was rate-limited.
   *
   * @return bool
   *   TRUE if the last isAllowed() returned FALSE.
   */
  public function wasRateLimited(): bool {
    return $this->lastWasLimited;
  }

  /**
   * Returns the current rate limit state for debugging.
   *
   * @return array
   *   Array with 'count' and 'window_start' keys.
   */
  public function getCurrentState(): array {
    return $this->getStateData();
  }

  /**
   * Force-resets the rate limit counter.
   */
  public function reset(): void {
    $this->admissionCoordinator->resetRateLimiter();
    $this->lastWasLimited = FALSE;
  }

  /**
   * Reads rate limit state from the State API.
   *
   * @return array
   *   State array with 'count' and 'window_start'.
   */
  protected function getStateData(): array {
    $data = $this->state->get(self::STATE_KEY);
    if (!is_array($data) || !isset($data['count'])) {
      return $this->defaultState();
    }
    return $data;
  }

  /**
   * Persists rate limit state.
   *
   * @param array $data
   *   The state data to persist.
   */
  protected function setStateData(array $data): void {
    $this->state->set(self::STATE_KEY, $data);
  }

  /**
   * Returns the default (fresh) state.
   *
   * @return array
   *   Default state array.
   */
  protected function defaultState(): array {
    return [
      'count' => 0,
      'window_start' => time(),
    ];
  }

  /**
   * Gets a rate limit config value.
   *
   * @param string $key
   *   The config key under llm.global_rate_limit.
   *
   * @return int
   *   The config value.
   */
  protected function getConfig(string $key): int {
    $defaults = [
      'max_per_hour' => 500,
      'window_seconds' => 3600,
    ];
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $value = $config->get('llm.global_rate_limit.' . $key);
    return (int) ($value ?? $defaults[$key] ?? 0);
  }

}
