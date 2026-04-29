<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\State\StateInterface;

/**
 * Runs explicit, sanitized Cohere generation readiness probes.
 */
final class CohereGenerationProbe {

  public const EXPECTED_TEXT = 'ILAS_COHERE_PROBE_OK';
  private const STATE_KEY = 'ilas_site_assistant.llm_generation_probe.last_result';

  public function __construct(
    private readonly LlmRuntimeConfigResolver $runtimeConfig,
    private readonly CohereLlmTransport $transport,
    private readonly StateInterface $state,
  ) {}

  /**
   * Returns the last stored probe result without making a provider request.
   *
   * @return array<string, mixed>
   *   Sanitized last probe result, or an empty array if no probe has run.
   */
  public function getLastResult(): array {
    $result = $this->state->get(self::STATE_KEY, []);
    return is_array($result) ? $result : [];
  }

  /**
   * Builds a no-network readiness summary plus the last explicit probe state.
   *
   * @return array<string, mixed>
   *   Sanitized readiness summary.
   */
  public function getReadinessSummary(): array {
    $summary = $this->buildBaseResult();
    $last = $this->getLastResult();
    if ($last !== []) {
      $summary['generation_attempted'] = (bool) ($last['generation_attempted'] ?? FALSE);
      $summary['reachable'] = (bool) ($last['reachable'] ?? FALSE);
      $summary['request_time_generation_reachable'] = (bool) ($last['request_time_generation_reachable'] ?? FALSE);
      $summary['generation_probe_passed'] = (bool) ($last['generation_probe_passed'] ?? FALSE);
      $summary['latency_ms'] = $last['latency_ms'] ?? NULL;
      $summary['last_error'] = $last['last_error'] ?? NULL;
      $summary['last_probe_at'] = $last['probe_at'] ?? NULL;
    }

    return $summary;
  }

  /**
   * Runs the active Cohere proof request.
   *
   * @return array<string, mixed>
   *   Sanitized probe result suitable for JSON output.
   */
  public function probe(): array {
    $result = $this->buildBaseResult();

    if (!$result['enabled']) {
      $result['reason'] = 'disabled';
      return $this->storeResult($result);
    }
    if (!$result['provider_is_cohere']) {
      $result['reason'] = 'provider_not_cohere';
      return $this->storeResult($result);
    }
    if (!$result['model_configured']) {
      $result['reason'] = 'model_missing';
      return $this->storeResult($result);
    }
    if (!$result['key_present']) {
      $result['reason'] = 'key_missing';
      return $this->storeResult($result);
    }

    $started = microtime(TRUE);
    $result['generation_attempted'] = TRUE;
    $result['probe_at'] = gmdate('Y-m-d\TH:i:s+00:00');

    try {
      $response = $this->transport->completeText([
        [
          'role' => 'system',
          'content' => 'You are responding to a machine connectivity check. Follow the user instruction exactly.',
        ],
        [
          'role' => 'user',
          'content' => 'Reply with exactly: ' . self::EXPECTED_TEXT,
        ],
      ], [
        'max_tokens' => 16,
        'temperature' => 0.0,
        'timeout' => 5.0,
        'connect_timeout' => 2.0,
        'safety_mode' => 'CONTEXTUAL',
      ]);

      $text = trim((string) ($response['text'] ?? ''));
      $passed = $text === self::EXPECTED_TEXT;
      $result['reachable'] = TRUE;
      $result['request_time_generation_reachable'] = TRUE;
      $result['generation_probe_passed'] = $passed;
      $result['success'] = $passed;
      $result['reason'] = $passed ? 'expected_content' : 'unexpected_content';
      $result['latency_ms'] = round((microtime(TRUE) - $started) * 1000, 1);
      $result['usage'] = is_array($response['usage'] ?? NULL) ? $response['usage'] : [];
      if (!$passed) {
        $result['last_error'] = [
          'class' => 'UnexpectedProbeContent',
          'message' => 'Cohere returned content that did not match the expected probe token.',
        ];
      }
    }
    catch (\Throwable $e) {
      $result['reachable'] = FALSE;
      $result['request_time_generation_reachable'] = FALSE;
      $result['generation_probe_passed'] = FALSE;
      $result['success'] = FALSE;
      $result['reason'] = 'exception';
      $result['latency_ms'] = round((microtime(TRUE) - $started) * 1000, 1);
      $result['last_error'] = [
        'class' => get_class($e),
        'message' => $this->sanitizeErrorMessage($e->getMessage()),
        'signature' => ObservabilityPayloadMinimizer::exceptionSignature($e),
      ];
    }

    return $this->storeResult($result);
  }

  /**
   * Builds common sanitized probe/readiness fields.
   *
   * @return array<string, mixed>
   *   Base result.
   */
  private function buildBaseResult(): array {
    $config = $this->runtimeConfig->resolve();

    return [
      'provider' => (string) ($config['provider'] ?? LlmRuntimeConfigResolver::DEFAULT_PROVIDER),
      'model' => (string) ($config['model'] ?? LlmRuntimeConfigResolver::DEFAULT_MODEL),
      'enabled' => (bool) ($config['enabled'] ?? FALSE),
      'provider_is_cohere' => (bool) ($config['provider_is_cohere'] ?? FALSE),
      'model_configured' => (bool) ($config['model_configured'] ?? FALSE),
      'key_present' => (bool) ($config['key_present'] ?? FALSE),
      'runtime_ready' => (bool) ($config['runtime_ready'] ?? FALSE),
      'generation_attempted' => FALSE,
      'reachable' => FALSE,
      'request_time_generation_reachable' => FALSE,
      'generation_probe_passed' => FALSE,
      'success' => FALSE,
      'reason' => 'not_attempted',
      'latency_ms' => NULL,
      'last_error' => NULL,
      'sources' => is_array($config['sources'] ?? NULL) ? $config['sources'] : [],
    ];
  }

  /**
   * Stores and returns a sanitized probe result.
   *
   * @param array<string, mixed> $result
   *   Probe result.
   *
   * @return array<string, mixed>
   *   Stored result.
   */
  private function storeResult(array $result): array {
    $result['probe_at'] ??= gmdate('Y-m-d\TH:i:s+00:00');
    $this->state->set(self::STATE_KEY, $result);
    return $result;
  }

  /**
   * Sanitizes exception text for operator diagnostics.
   */
  private function sanitizeErrorMessage(string $message): string {
    $message = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $message) ?? $message;
    $message = preg_replace('/(api[_-]?key|token|secret)=([^&\s]+)/i', '$1=[redacted]', $message) ?? $message;
    $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    return mb_substr($message, 0, 220);
  }

}
