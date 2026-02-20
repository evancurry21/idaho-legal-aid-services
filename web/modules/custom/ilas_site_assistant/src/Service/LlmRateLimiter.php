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
  ) {
    $this->state = $state;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Checks if an LLM call is allowed under the global rate limit.
   *
   * This is a read-only check — it does NOT increment the counter.
   * Call recordCall() after a successful API call to increment.
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
   * Records a successful LLM API call.
   *
   * Called ONLY after a successful API response (not on cache hits or errors).
   * Resets the window lazily if expired, then increments the counter.
   * Logs at 80% (notice) and 100% (warning) of the limit.
   */
  public function recordCall(): void {
    $maxPerHour = $this->getConfig('max_per_hour');

    // If limiter is disabled, nothing to record.
    if ($maxPerHour === 0) {
      return;
    }

    $data = $this->getStateData();
    $windowSeconds = $this->getConfig('window_seconds');

    // Lazy window reset: if window expired, start fresh.
    if ((time() - $data['window_start']) >= $windowSeconds) {
      $data = [
        'count' => 1,
        'window_start' => time(),
      ];
      $this->setStateData($data);
      return;
    }

    // Increment counter.
    $data['count']++;
    $this->setStateData($data);

    // Log at threshold milestones.
    $threshold80 = (int) ceil($maxPerHour * 0.8);
    if ($data['count'] === $threshold80) {
      $this->logger->notice('LLM global rate limit at 80% (@count/@max) within current window.', [
        '@count' => $data['count'],
        '@max' => $maxPerHour,
      ]);
    }

    if ($data['count'] >= $maxPerHour) {
      $this->logger->warning('LLM global rate limit reached (@count/@max). Subsequent calls will be skipped until window resets.', [
        '@count' => $data['count'],
        '@max' => $maxPerHour,
      ]);
    }
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
    $this->setStateData($this->defaultState());
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
