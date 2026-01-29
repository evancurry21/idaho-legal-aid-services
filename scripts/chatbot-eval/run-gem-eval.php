#!/usr/bin/env php
<?php

/**
 * @file
 * Exploratory evaluation of Gem bot historical queries against current chatbot.
 *
 * Sends each query via HTTP to the running DDEV site and records:
 * - intent_selected, intent_confidence
 * - response_mode (navigate/topic/answer/clarify/fallback/hard_route)
 * - primary_action url
 * - retrieval_top_k ids/urls + scores
 * - reason_code
 * - safety_flags
 *
 * Usage:
 *   php run-gem-eval.php [options]
 *
 * Options:
 *   --fixture=PATH    Path to gem fixture JSON (default: ../../fixtures/real_queries_gem_export.json)
 *   --base-url=URL    Base URL (default: https://ilas-pantheon.ddev.site)
 *   --output=DIR      Output directory (default: ../../reports/<date>)
 *   --limit=N         Limit queries
 *   --verbose         Show progress
 *   --run-id=ID       Custom run ID (default: gem_export_eval)
 */

$options = getopt('', [
  'fixture:',
  'base-url:',
  'output:',
  'limit:',
  'verbose',
  'run-id:',
  'help',
]);

if (isset($options['help'])) {
  echo "Usage: php run-gem-eval.php [--fixture=PATH] [--base-url=URL] [--limit=N] [--verbose]\n";
  exit(0);
}

$fixture_path = $options['fixture'] ?? __DIR__ . '/../../fixtures/real_queries_gem_export.json';
$base_url = rtrim($options['base-url'] ?? 'https://ilas-pantheon.ddev.site', '/');
$run_id = $options['run-id'] ?? 'gem_export_eval';
$date_dir = date('Y-m-d');
$output_dir = $options['output'] ?? __DIR__ . '/../../reports/' . $date_dir . '/' . $run_id;
$limit = isset($options['limit']) ? (int) $options['limit'] : NULL;
$verbose = isset($options['verbose']);

// Load fixture.
if (!file_exists($fixture_path)) {
  echo "Error: Fixture not found: $fixture_path\n";
  exit(1);
}

$fixture = json_decode(file_get_contents($fixture_path), TRUE);
if (!$fixture || empty($fixture['queries'])) {
  echo "Error: Invalid fixture or no queries.\n";
  exit(1);
}

$queries = $fixture['queries'];
if ($limit) {
  $queries = array_slice($queries, 0, $limit);
}

echo "=== Gem Export Exploratory Evaluation ===\n\n";
echo "Fixture: $fixture_path\n";
echo "Queries: " . count($queries) . "\n";
echo "Base URL: $base_url\n";
echo "Output: $output_dir\n\n";

// Create output directory.
if (!is_dir($output_dir)) {
  mkdir($output_dir, 0755, TRUE);
}

// Test connectivity.
echo "Testing connectivity...\n";
$test_ch = curl_init($base_url . '/assistant/api/message');
curl_setopt_array($test_ch, [
  CURLOPT_POST => TRUE,
  CURLOPT_POSTFIELDS => json_encode(['message' => 'hello', 'debug' => TRUE]),
  CURLOPT_RETURNTRANSFER => TRUE,
  CURLOPT_TIMEOUT => 10,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Debug-Mode: 1'],
  CURLOPT_SSL_VERIFYPEER => FALSE,
]);
$test_resp = curl_exec($test_ch);
$test_code = curl_getinfo($test_ch, CURLINFO_HTTP_CODE);
curl_close($test_ch);

if ($test_code !== 200) {
  echo "Error: Cannot reach chatbot API (HTTP $test_code).\n";
  echo "Response: " . substr($test_resp, 0, 200) . "\n";
  exit(1);
}
echo "Connectivity OK.\n\n";

// Run evaluation.
$results = [];
$intent_counts = [];
$response_mode_counts = [];
$safety_flag_counts = [];
$action_counts = [];
$gate_decision_counts = [];
$reason_code_counts = [];
$errors = 0;
$fallback_count = 0;
$total = count($queries);

echo "Running $total queries...\n\n";

foreach ($queries as $i => $query) {
  $utterance = $query['utterance'];
  $query_id = $query['id'];

  // Send to chatbot.
  $ch = curl_init($base_url . '/assistant/api/message');
  curl_setopt_array($ch, [
    CURLOPT_POST => TRUE,
    CURLOPT_POSTFIELDS => json_encode(['message' => $utterance, 'debug' => TRUE]),
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Debug-Mode: 1'],
    CURLOPT_SSL_VERIFYPEER => FALSE,
  ]);

  $resp_raw = curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $result = [
    'id' => $query_id,
    'utterance' => $utterance,
    'count' => $query['count'],
    'language_guess' => $query['language_guess'],
    'http_code' => $http_code,
    'intent_selected' => NULL,
    'intent_confidence' => NULL,
    'response_type' => NULL,
    'response_mode' => NULL,
    'primary_action_url' => NULL,
    'primary_action_label' => NULL,
    'retrieval_results' => [],
    'reason_code' => NULL,
    'safety_flags' => [],
    'gate_decision' => NULL,
    'error' => NULL,
  ];

  if ($http_code !== 200 || $resp_raw === FALSE) {
    $result['error'] = "HTTP $http_code";
    $errors++;
  }
  else {
    $resp = json_decode($resp_raw, TRUE);
    if (!$resp) {
      $result['error'] = 'Invalid JSON response';
      $errors++;
    }
    else {
      $result['response_type'] = $resp['type'] ?? NULL;
      $result['response_mode'] = $resp['response_mode'] ?? NULL;

      // Primary action.
      if (!empty($resp['primary_action']['url'])) {
        $result['primary_action_url'] = $resp['primary_action']['url'];
        $result['primary_action_label'] = $resp['primary_action']['label'] ?? NULL;
      }
      elseif (!empty($resp['url'])) {
        $result['primary_action_url'] = $resp['url'];
      }

      $result['reason_code'] = $resp['reason_code'] ?? NULL;

      // Debug metadata.
      if (!empty($resp['_debug'])) {
        $debug = $resp['_debug'];
        $result['intent_selected'] = $debug['intent_selected'] ?? NULL;
        $result['intent_confidence'] = $debug['intent_confidence'] ?? NULL;
        $result['safety_flags'] = $debug['safety_flags'] ?? [];
        $result['gate_decision'] = $debug['gate_decision'] ?? NULL;

        if (!empty($debug['retrieval_results'])) {
          $result['retrieval_results'] = array_map(function ($r) {
            return [
              'id' => $r['id'] ?? NULL,
              'url' => $r['url'] ?? NULL,
              'score' => $r['score'] ?? NULL,
              'title' => $r['title'] ?? NULL,
            ];
          }, array_slice($debug['retrieval_results'], 0, 5));
        }
      }

      // Track distributions.
      $intent = $result['intent_selected'] ?? 'null';
      $intent_counts[$intent] = ($intent_counts[$intent] ?? 0) + $query['count'];

      $mode = $result['response_mode'] ?? $result['response_type'] ?? 'null';
      $response_mode_counts[$mode] = ($response_mode_counts[$mode] ?? 0) + $query['count'];

      $action = $result['primary_action_url'] ?? 'none';
      $action_counts[$action] = ($action_counts[$action] ?? 0) + $query['count'];

      $gate = $result['gate_decision'] ?? 'null';
      $gate_decision_counts[$gate] = ($gate_decision_counts[$gate] ?? 0) + $query['count'];

      $rc = $result['reason_code'] ?? 'null';
      $reason_code_counts[$rc] = ($reason_code_counts[$rc] ?? 0) + $query['count'];

      foreach ($result['safety_flags'] as $flag) {
        $safety_flag_counts[$flag] = ($safety_flag_counts[$flag] ?? 0) + $query['count'];
      }

      if (in_array($intent, ['unknown', 'fallback', NULL])) {
        $fallback_count += $query['count'];
      }
    }
  }

  $results[] = $result;

  if ($verbose) {
    $status = $result['error'] ? 'ERR' : 'OK';
    $intent_display = $result['intent_selected'] ?? '???';
    $action_display = $result['primary_action_url'] ?? '-';
    printf("[%d/%d] %s | %-15s | %-25s | %s\n",
      $i + 1, $total, $status,
      substr($intent_display, 0, 15),
      substr($action_display, 0, 25),
      substr($utterance, 0, 50)
    );
  }
  elseif (($i + 1) % 50 === 0) {
    echo "  Processed " . ($i + 1) . "/$total\n";
  }

  // Small delay to avoid hammering the server.
  usleep(50000); // 50ms
}

echo "\n";

// Sort distributions.
arsort($intent_counts);
arsort($response_mode_counts);
arsort($action_counts);
arsort($gate_decision_counts);
arsort($reason_code_counts);

// Calculate weighted total (by query count).
$weighted_total = array_sum(array_column($queries, 'count'));

// Identify failure categories.
$failures = [
  'fallback_unknown' => [],
  'no_action_url' => [],
  'safety_triggered' => [],
  'error' => [],
];

foreach ($results as $r) {
  if ($r['error']) {
    $failures['error'][] = $r;
  }
  elseif (in_array($r['intent_selected'], ['unknown', 'fallback', NULL])) {
    $failures['fallback_unknown'][] = $r;
  }
  if (empty($r['primary_action_url']) && !in_array($r['response_type'], ['greeting', 'eligibility'])) {
    $failures['no_action_url'][] = $r;
  }
  if (!empty($r['safety_flags'])) {
    $failures['safety_triggered'][] = $r;
  }
}

// --- Save results.json ---
$full_results = [
  'metadata' => [
    'run_id' => $run_id,
    'fixture' => $fixture_path,
    'base_url' => $base_url,
    'total_unique_queries' => $total,
    'weighted_total_queries' => $weighted_total,
    'errors' => $errors,
    'timestamp' => date('c'),
  ],
  'distributions' => [
    'intent' => $intent_counts,
    'response_mode' => $response_mode_counts,
    'primary_action' => $action_counts,
    'gate_decision' => $gate_decision_counts,
    'reason_code' => $reason_code_counts,
    'safety_flags' => $safety_flag_counts,
  ],
  'failure_summary' => [
    'fallback_unknown' => count($failures['fallback_unknown']),
    'no_action_url' => count($failures['no_action_url']),
    'safety_triggered' => count($failures['safety_triggered']),
    'errors' => count($failures['error']),
  ],
  'results' => $results,
];

file_put_contents($output_dir . '/results.json', json_encode($full_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// --- Save top_failures.csv ---
$csv_handle = fopen($output_dir . '/top_failures.csv', 'w');
fputcsv($csv_handle, ['utterance_id', 'redacted_utterance', 'count', 'failure_type', 'intent_selected', 'response_type', 'primary_action', 'expected_fix_category']);

foreach ($failures['fallback_unknown'] as $f) {
  $fix_cat = categorizeFixNeeded($f['utterance']);
  fputcsv($csv_handle, [
    $f['id'], $f['utterance'], $f['count'], 'fallback_unknown',
    $f['intent_selected'], $f['response_type'], $f['primary_action_url'] ?? '',
    $fix_cat,
  ]);
}
foreach ($failures['no_action_url'] as $f) {
  if (in_array($f['intent_selected'], ['unknown', 'fallback', NULL])) continue; // already counted
  fputcsv($csv_handle, [
    $f['id'], $f['utterance'], $f['count'], 'no_action_url',
    $f['intent_selected'], $f['response_type'], '',
    'action_mapping',
  ]);
}
foreach ($failures['error'] as $f) {
  fputcsv($csv_handle, [
    $f['id'], $f['utterance'], $f['count'], 'http_error',
    '', '', '', 'infrastructure',
  ]);
}
fclose($csv_handle);

// --- Save summary.md ---
$summary = "# Gem Export Evaluation Summary\n\n";
$summary .= "**Date:** " . date('Y-m-d H:i:s') . "\n";
$summary .= "**Run ID:** $run_id\n";
$summary .= "**Fixture:** $fixture_path\n";
$summary .= "**Base URL:** $base_url\n\n";
$summary .= "## Overview\n\n";
$summary .= "| Metric | Value |\n";
$summary .= "|--------|-------|\n";
$summary .= "| Unique queries | $total |\n";
$summary .= "| Weighted total (with duplicates) | $weighted_total |\n";
$summary .= "| HTTP errors | $errors |\n";
$summary .= "| Fallback/unknown | " . count($failures['fallback_unknown']) . " (" . round(count($failures['fallback_unknown']) / $total * 100, 1) . "%) |\n";
$summary .= "| No action URL | " . count($failures['no_action_url']) . " (" . round(count($failures['no_action_url']) / $total * 100, 1) . "%) |\n";
$summary .= "| Safety triggered | " . count($failures['safety_triggered']) . " (" . round(count($failures['safety_triggered']) / $total * 100, 1) . "%) |\n\n";

$summary .= "## Intent Distribution\n\n";
$summary .= "| Intent | Weighted Count | % |\n";
$summary .= "|--------|---------------|---|\n";
foreach ($intent_counts as $intent => $count) {
  $pct = round($count / $weighted_total * 100, 1);
  $summary .= "| $intent | $count | $pct% |\n";
}

$summary .= "\n## Response Mode Distribution\n\n";
$summary .= "| Mode | Weighted Count | % |\n";
$summary .= "|------|---------------|---|\n";
foreach ($response_mode_counts as $mode => $count) {
  $pct = round($count / $weighted_total * 100, 1);
  $summary .= "| $mode | $count | $pct% |\n";
}

$summary .= "\n## Gate Decision Distribution\n\n";
$summary .= "| Decision | Weighted Count | % |\n";
$summary .= "|----------|---------------|---|\n";
foreach ($gate_decision_counts as $gate => $count) {
  $pct = round($count / $weighted_total * 100, 1);
  $summary .= "| $gate | $count | $pct% |\n";
}

$summary .= "\n## Top Action URLs\n\n";
$summary .= "| Action | Weighted Count | % |\n";
$summary .= "|--------|---------------|---|\n";
$top_actions = array_slice($action_counts, 0, 20, TRUE);
foreach ($top_actions as $action => $count) {
  $pct = round($count / $weighted_total * 100, 1);
  $summary .= "| $action | $count | $pct% |\n";
}

$summary .= "\n## Reason Code Distribution\n\n";
$summary .= "| Reason Code | Weighted Count | % |\n";
$summary .= "|-------------|---------------|---|\n";
foreach ($reason_code_counts as $rc => $count) {
  $pct = round($count / $weighted_total * 100, 1);
  $summary .= "| $rc | $count | $pct% |\n";
}

if (!empty($safety_flag_counts)) {
  $summary .= "\n## Safety Flags\n\n";
  $summary .= "| Flag | Weighted Count |\n";
  $summary .= "|------|---------------|\n";
  foreach ($safety_flag_counts as $flag => $count) {
    $summary .= "| $flag | $count |\n";
  }
}

$summary .= "\n## Top Fallback Queries (need routing improvements)\n\n";
$summary .= "| # | Query | Count | Fix Category |\n";
$summary .= "|---|-------|-------|-------------|\n";
$sorted_fallbacks = $failures['fallback_unknown'];
usort($sorted_fallbacks, function ($a, $b) { return $b['count'] - $a['count']; });
foreach (array_slice($sorted_fallbacks, 0, 40) as $i => $f) {
  $fix = categorizeFixNeeded($f['utterance']);
  $summary .= "| " . ($i + 1) . " | " . truncate($f['utterance'], 60) . " | " . $f['count'] . " | $fix |\n";
}

file_put_contents($output_dir . '/summary.md', $summary);

echo "=== Results ===\n\n";
echo "Unique queries evaluated: $total\n";
echo "Weighted total: $weighted_total\n";
echo "Errors: $errors\n";
echo "Fallback/unknown: " . count($failures['fallback_unknown']) . " (" . round(count($failures['fallback_unknown']) / $total * 100, 1) . "%)\n";
echo "No action URL: " . count($failures['no_action_url']) . " (" . round(count($failures['no_action_url']) / $total * 100, 1) . "%)\n";
echo "Safety triggered: " . count($failures['safety_triggered']) . " (" . round(count($failures['safety_triggered']) / $total * 100, 1) . "%)\n\n";

echo "Top intents:\n";
foreach (array_slice($intent_counts, 0, 10, TRUE) as $intent => $count) {
  printf("  %-20s %d (%.1f%%)\n", $intent, $count, $count / $weighted_total * 100);
}

echo "\nReports saved to: $output_dir/\n";
echo "  - summary.md\n";
echo "  - results.json\n";
echo "  - top_failures.csv\n";

// --- Helper functions ---

function categorizeFixNeeded(string $utterance): string {
  $lower = strtolower($utterance);
  $word_count = str_word_count($lower);

  // Navigation queries
  if (preg_match('/\b(page|website|site|find|where|show|go to|navigate|link)\b/i', $lower)) {
    return 'navigation_routing';
  }

  // Topic queries (single word or short)
  if ($word_count <= 2) {
    return 'single_word_topic';
  }

  // Form/guide finder
  if (preg_match('/\b(form|forms|document|paperwork|filing|motion|petition)\b/i', $lower)) {
    return 'forms_routing';
  }

  // Specific legal topics
  if (preg_match('/\b(divorce|custody|child support|eviction|landlord|tenant|debt|bankruptcy|will|probate|estate|guardianship)\b/i', $lower)) {
    return 'topic_synonym';
  }

  // Acronyms
  if (preg_match('/\b[A-Z]{2,5}\b/', $utterance)) {
    return 'acronym_expansion';
  }

  // Spanish
  if (preg_match('/\b(necesito|ayuda|donde|como|abogado|formulario)\b/i', $lower)) {
    return 'spanish_coverage';
  }

  // About/general
  if (preg_match('/\b(what|how|does|can|do|about|tell me)\b/i', $lower)) {
    return 'faq_coverage';
  }

  return 'unclassified';
}

function truncate(string $s, int $max): string {
  return strlen($s) > $max ? substr($s, 0, $max - 3) . '...' : $s;
}
