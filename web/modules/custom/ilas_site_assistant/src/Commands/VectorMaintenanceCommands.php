<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\Service\VectorIndexHygieneService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for vector index status, resume, and rebuild workflows.
 */
final class VectorMaintenanceCommands extends DrushCommands {

  /**
   * Constructs a VectorMaintenanceCommands handler.
   */
  public function __construct(
    protected VectorIndexHygieneService $vectorIndexHygiene,
  ) {
    parent::__construct();
  }

  /**
   * Print JSON status for one managed vector index or all managed indexes.
   *
   * @param string|null $index_key
   *   Optional managed index key (`faq_vector` or `resource_vector`).
   * @param array $options
   *   Command options.
   *
   * @command ilas:vector-status
   * @aliases vector-status
   * @option probe-now Force a live semantic queryability probe before printing status.
   * @usage ilas:vector-status
   *   Print all managed vector-index status as JSON.
   * @usage ilas:vector-status faq_vector --probe-now
   *   Print FAQ vector status after forcing a live probe.
   */
  public function vectorStatus(?string $index_key = NULL, array $options = ['probe-now' => FALSE]): int {
    if ($index_key !== NULL && $this->vectorIndexHygiene->getManagedIndexId($index_key) === NULL) {
      fwrite(STDERR, sprintf("Unknown managed vector index key: %s\n", $index_key));
      return 1;
    }

    try {
      $snapshot = $this->vectorIndexHygiene->refreshSnapshot((bool) ($options['probe-now'] ?? FALSE), $index_key);
    }
    catch (\Throwable $throwable) {
      fwrite(STDERR, sprintf("Failed to refresh vector status: %s\n", $throwable->getMessage()));
      return 1;
    }

    if ($index_key !== NULL) {
      $index_snapshot = $snapshot['indexes'][$index_key] ?? [];
      $payload = [
        'index_key' => $index_key,
        'overall_status' => $snapshot['status'] ?? 'unknown',
        'thresholds' => $snapshot['thresholds'] ?? [],
      ] + $this->formatIndexStatus($index_snapshot);
    }
    else {
      $indexes = [];
      foreach (($snapshot['indexes'] ?? []) as $managed_key => $index_snapshot) {
        if (!is_array($index_snapshot)) {
          continue;
        }
        $indexes[$managed_key] = $this->formatIndexStatus($index_snapshot);
      }

      $payload = [
        'recorded_at' => $snapshot['recorded_at'] ?? NULL,
        'status' => $snapshot['status'] ?? 'unknown',
        'totals' => $snapshot['totals'] ?? [],
        'thresholds' => $snapshot['thresholds'] ?? [],
        'indexes' => $indexes,
      ];
    }

    print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return 0;
  }

  /**
   * Resume or explicitly rebuild a managed vector index with paced batching.
   *
   * @param string $index_key
   *   Managed index key (`faq_vector` or `resource_vector`).
   * @param array $options
   *   Command options.
   *
   * @command ilas:vector-backfill
   * @aliases vector-backfill
   * @option batch-size Search API items per indexing call (default: 5).
   * @option max-batches Maximum indexing calls in this run (default: 1).
   * @option sleep-seconds Pause between batches when another batch will run.
   * @option until-complete Continue until tracker remaining reaches zero or another stop reason is hit.
   * @option clear-first Explicitly clear the selected Search API index before indexing.
   * @usage ilas:vector-backfill faq_vector
   *   Resume the FAQ vector index with one conservative batch.
   * @usage ilas:vector-backfill resource_vector --until-complete --sleep-seconds=60
   *   Pace a longer resource backfill until completion.
   * @usage ilas:vector-backfill faq_vector --clear-first --until-complete
   *   Explicitly rebuild the FAQ vector index from empty.
   */
  public function vectorBackfill(
    string $index_key,
    array $options = [
      'batch-size' => 5,
      'max-batches' => 1,
      'sleep-seconds' => 0,
      'until-complete' => FALSE,
      'clear-first' => FALSE,
    ],
  ): int {
    if ($this->vectorIndexHygiene->getManagedIndexId($index_key) === NULL) {
      fwrite(STDERR, sprintf("Unknown managed vector index key: %s\n", $index_key));
      return 1;
    }

    try {
      $report = $this->vectorIndexHygiene->backfillIndex(
        $index_key,
        max(1, (int) ($options['batch-size'] ?? 5)),
        max(1, (int) ($options['max-batches'] ?? 1)),
        max(0, (int) ($options['sleep-seconds'] ?? 0)),
        (bool) ($options['until-complete'] ?? FALSE),
        (bool) ($options['clear-first'] ?? FALSE),
      );
    }
    catch (\Throwable $throwable) {
      fwrite(STDERR, sprintf("Vector backfill failed: %s\n", $throwable->getMessage()));
      return 1;
    }

    print json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return 0;
  }

  /**
   * Formats one index snapshot for operator-oriented JSON output.
   *
   * @param array $index_snapshot
   *   Managed index snapshot.
   *
   * @return array<string, mixed>
   *   Operator-friendly status payload.
   */
  protected function formatIndexStatus(array $index_snapshot): array {
    $total_items = (int) ($index_snapshot['total_items'] ?? 0);
    $indexed_items = (int) ($index_snapshot['indexed_items'] ?? 0);
    $observed = is_array($index_snapshot['observed'] ?? NULL) ? $index_snapshot['observed'] : [];
    $expected = is_array($index_snapshot['expected'] ?? NULL) ? $index_snapshot['expected'] : [];

    $embeddings_engine = isset($observed['embeddings_engine']) && is_string($observed['embeddings_engine'])
      ? $observed['embeddings_engine']
      : ($expected['embeddings_engine'] ?? NULL);
    [$embedding_provider, $embedding_model] = $this->splitEmbeddingsEngine((string) ($embeddings_engine ?? ''));

    $probe_evidence = $index_snapshot['probe_evidence'] ?? [];
    $probe_match_summary = $this->summarizeProbeEvidence(is_array($probe_evidence) ? $probe_evidence : []);

    return [
      'index_id' => $index_snapshot['index_id'] ?? '',
      'backend_id' => $observed['server_id'] ?? ($expected['server_id'] ?? NULL),
      'vector_provider' => $this->inferVectorProvider($observed['server_id'] ?? ($expected['server_id'] ?? NULL)),
      'embeddings_engine' => $embeddings_engine,
      'embedding_provider' => $embedding_provider,
      'embedding_model' => $embedding_model,
      'metric' => $observed['metric'] ?? ($expected['metric'] ?? NULL),
      'expected_dimensions' => $expected['dimensions']
        ?? ($index_snapshot['expected_dimensions'] ?? NULL),
      'actual_dimensions' => $observed['dimensions'] ?? NULL,
      'metadata_drift_fields' => array_values(is_array($index_snapshot['drift_fields'] ?? NULL) ? $index_snapshot['drift_fields'] : []),
      'hygiene_status' => $index_snapshot['status'] ?? 'unknown',
      'metadata_status' => $index_snapshot['metadata_status'] ?? 'unknown',
      'indexing_status' => $index_snapshot['indexing_status'] ?? 'unknown',
      'probe_status' => $index_snapshot['probe_status'] ?? 'unknown',
      'last_probe_at' => $index_snapshot['last_probe_at'] ?? NULL,
      'last_refresh_at' => $index_snapshot['last_refresh_at'] ?? NULL,
      'probe_error' => $this->sanitizeError($index_snapshot['probe_error'] ?? NULL),
      'probe_passed_count' => (int) ($index_snapshot['probe_passed_count'] ?? 0),
      'probe_failed_count' => (int) ($index_snapshot['probe_failed_count'] ?? 0),
      'probe_evidence' => $probe_evidence,
      'probe_summary' => $probe_match_summary,
      'total_items' => $total_items,
      'indexed_items' => $indexed_items,
      'remaining_items' => (int) ($index_snapshot['remaining_items'] ?? 0),
      'percent_complete' => $total_items > 0
        ? round(min(100, max(0, ($indexed_items / $total_items) * 100)), 2)
        : 0.0,
      'last_stop_reason' => $index_snapshot['last_stop_reason'] ?? NULL,
      'last_error' => $this->sanitizeError($index_snapshot['last_error'] ?? NULL),
    ];
  }

  /**
   * Splits a Search API embeddings engine identifier into provider+model.
   *
   * @return array{0:?string,1:?string}
   */
  protected function splitEmbeddingsEngine(string $engine): array {
    if ($engine === '') {
      return [NULL, NULL];
    }
    if (strpos($engine, '__') !== FALSE) {
      [$provider, $model] = explode('__', $engine, 2);
      $provider = preg_replace('/^ilas_/', '', $provider) ?: $provider;
      return [$provider, $model];
    }
    return [$engine, $engine];
  }

  /**
   * Infers the vector provider from the Search API server identifier.
   */
  protected function inferVectorProvider(?string $server_id): ?string {
    if (!is_string($server_id) || $server_id === '') {
      return NULL;
    }
    if (str_contains($server_id, 'pinecone')) {
      return 'pinecone';
    }
    return $server_id;
  }

  /**
   * Summarizes probe evidence into top match IDs/scores + metadata key set.
   *
   * @param array $probe_evidence
   *   Array of probe records produced by VectorIndexHygieneService.
   *
   * @return array<string, mixed>
   */
  protected function summarizeProbeEvidence(array $probe_evidence): array {
    $top_ids = [];
    $top_scores = [];
    $metadata_keys = [];
    $unpublished_matches = 0;
    $probe_query = NULL;
    $topk_requested = NULL;
    foreach ($probe_evidence as $record) {
      if (!is_array($record)) {
        continue;
      }
      $probe_query = $probe_query ?? ($record['query'] ?? $record['probe_query'] ?? NULL);
      $topk_requested = $topk_requested ?? ($record['topk'] ?? $record['top_k'] ?? NULL);
      foreach (($record['matches'] ?? []) as $match) {
        if (!is_array($match)) {
          continue;
        }
        if (isset($match['id']) && (is_string($match['id']) || is_int($match['id']))) {
          $top_ids[] = (string) $match['id'];
        }
        if (isset($match['score']) && is_numeric($match['score'])) {
          $top_scores[] = (float) $match['score'];
        }
        foreach (($match['metadata'] ?? []) as $key => $_) {
          if (is_string($key)) {
            $metadata_keys[$key] = TRUE;
          }
        }
        if (isset($match['published']) && $match['published'] === FALSE) {
          $unpublished_matches += 1;
        }
      }
    }
    rsort($top_scores, SORT_NUMERIC);
    return [
      'probe_query' => $probe_query,
      'topk_requested' => $topk_requested,
      'top_match_ids' => array_slice(array_values(array_unique($top_ids)), 0, 5),
      'top_match_scores' => array_slice($top_scores, 0, 5),
      'metadata_keys' => array_values(array_keys($metadata_keys)),
      'matches_unpublished' => $unpublished_matches,
    ];
  }

  /**
   * Sanitizes error strings to remove anything resembling a secret/key.
   */
  protected function sanitizeError(mixed $error): mixed {
    if (!is_string($error) || $error === '') {
      return $error;
    }
    // Redact bearer tokens, API keys, and high-entropy hex/base64 strings.
    $sanitized = preg_replace('/Bearer\s+[A-Za-z0-9\-_.]+/i', 'Bearer [REDACTED]', $error) ?? $error;
    $sanitized = preg_replace('/[A-Za-z0-9_\-]{32,}/', '[REDACTED]', $sanitized) ?? $sanitized;
    return $sanitized;
  }

}
