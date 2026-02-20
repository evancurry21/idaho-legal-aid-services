<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Circuit breaker for LLM API calls.
 *
 * Stops calling the LLM API after detecting sustained failures, immediately
 * returning to the rule-based fallback path. After a cooldown period, allows
 * a single probe request through (half-open state) to test recovery.
 *
 * State machine:
 *   closed  --[failures >= threshold]--> open
 *   open    --[cooldown elapsed]-------> half_open
 *   half_open --[success]--------------> closed
 *   half_open --[failure]--------------> open
 */
class LlmCircuitBreaker {

  /**
   * State API key for circuit breaker data.
   */
  const STATE_KEY = 'ilas_site_assistant.llm_circuit_breaker';

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
   * Constructs an LlmCircuitBreaker.
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
   * Checks if the circuit allows a request through.
   *
   * @return bool
   *   TRUE if a request is allowed (closed or half-open), FALSE if open.
   */
  public function isAvailable(): bool {
    $data = $this->getState();

    if ($data['state'] === 'closed') {
      return TRUE;
    }

    if ($data['state'] === 'open') {
      $cooldown = $this->getConfig('cooldown_seconds');
      if ((time() - $data['opened_at']) >= $cooldown) {
        // Transition to half-open: allow one probe request.
        $data['state'] = 'half_open';
        $this->setState($data);
        $this->logger->notice('LLM circuit breaker transitioning from open to half_open after @cooldown s cooldown.', [
          '@cooldown' => $cooldown,
        ]);
        return TRUE;
      }
      return FALSE;
    }

    // half_open: allow the probe request through.
    return TRUE;
  }

  /**
   * Records a successful API call.
   */
  public function recordSuccess(): void {
    $data = $this->getState();

    if ($data['state'] === 'half_open') {
      // Probe succeeded — close the circuit.
      $this->logger->info('LLM circuit breaker closing after successful half-open probe.');
      $this->setState($this->defaultState());
      return;
    }

    if ($data['state'] === 'closed' && $data['consecutive_failures'] > 0) {
      // Reset failure counter on success.
      $data['consecutive_failures'] = 0;
      $data['last_failure_time'] = 0;
      $this->setState($data);
    }
  }

  /**
   * Records a failed API call (after all retries exhausted).
   */
  public function recordFailure(): void {
    $data = $this->getState();

    if ($data['state'] === 'half_open') {
      // Probe failed — reopen the circuit.
      $data['state'] = 'open';
      $data['opened_at'] = time();
      $data['consecutive_failures'] = $this->getConfig('failure_threshold');
      $this->setState($data);
      $this->logger->warning('LLM circuit breaker reopened after failed half-open probe.');
      return;
    }

    // Closed state: count failures.
    $now = time();
    $window = $this->getConfig('failure_window_seconds');

    // If last failure is outside the window, reset counter.
    if ($data['last_failure_time'] > 0 && ($now - $data['last_failure_time']) > $window) {
      $data['consecutive_failures'] = 0;
    }

    $data['consecutive_failures']++;
    $data['last_failure_time'] = $now;

    $threshold = $this->getConfig('failure_threshold');
    if ($data['consecutive_failures'] >= $threshold) {
      // Trip the circuit.
      $data['state'] = 'open';
      $data['opened_at'] = $now;
      $this->logger->warning('LLM circuit breaker opened after @count consecutive failures within @window s.', [
        '@count' => $data['consecutive_failures'],
        '@window' => $window,
      ]);
    }

    $this->setState($data);
  }

  /**
   * Returns the current circuit breaker state.
   *
   * @return array
   *   State array with keys: state, consecutive_failures, last_failure_time,
   *   opened_at.
   */
  public function getState(): array {
    $data = $this->state->get(self::STATE_KEY);
    if (!is_array($data) || !isset($data['state'])) {
      return $this->defaultState();
    }
    return $data;
  }

  /**
   * Force-resets the circuit breaker to closed state.
   */
  public function reset(): void {
    $this->setState($this->defaultState());
    $this->logger->info('LLM circuit breaker manually reset to closed.');
  }

  /**
   * Returns the default (closed) state.
   *
   * @return array
   *   Default state array.
   */
  protected function defaultState(): array {
    return [
      'state' => 'closed',
      'consecutive_failures' => 0,
      'last_failure_time' => 0,
      'opened_at' => 0,
    ];
  }

  /**
   * Persists circuit breaker state.
   *
   * @param array $data
   *   The state data to persist.
   */
  protected function setState(array $data): void {
    $this->state->set(self::STATE_KEY, $data);
  }

  /**
   * Gets a circuit breaker config value.
   *
   * @param string $key
   *   The config key under llm.circuit_breaker.
   *
   * @return int
   *   The config value.
   */
  protected function getConfig(string $key): int {
    $defaults = [
      'failure_threshold' => 3,
      'failure_window_seconds' => 60,
      'cooldown_seconds' => 300,
    ];
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $value = $config->get('llm.circuit_breaker.' . $key);
    return (int) ($value ?? $defaults[$key] ?? 0);
  }

}
