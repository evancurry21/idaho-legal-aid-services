<?php

/**
 * @file
 * Test harness for TopicRouter single-token routing validation.
 *
 * Usage:
 *   php TopicRouterHarness.php [--report=path] [--verbose]
 *
 * Options:
 *   --report=path  Write JSON report to specified path
 *   --verbose      Show detailed output for each test case
 *
 * This harness tests the TopicRouter standalone, without Drupal bootstrap.
 */

// Autoload Symfony YAML if available.
$autoload_paths = [
  __DIR__ . '/../../../../vendor/autoload.php',
  __DIR__ . '/../../../../../vendor/autoload.php',
];
foreach ($autoload_paths as $path) {
  if (file_exists($path)) {
    require_once $path;
    break;
  }
}

// Include the TopicRouter class.
require_once __DIR__ . '/../src/Service/TopicRouter.php';

use Drupal\ilas_site_assistant\Service\TopicRouter;

/**
 * Runs all TopicRouter tests from the fixture file.
 *
 * @param bool $verbose
 *   Whether to print verbose output.
 *
 * @return array
 *   Test results.
 */
function runTopicRouterTests(bool $verbose = FALSE): array {
  $fixture_path = __DIR__ . '/fixtures/topic_router_test_cases.json';

  if (!file_exists($fixture_path)) {
    return ['error' => "Fixture file not found: $fixture_path"];
  }

  $fixtures = json_decode(file_get_contents($fixture_path), TRUE);
  if (json_last_error() !== JSON_ERROR_NONE) {
    return ['error' => 'Failed to parse fixture JSON: ' . json_last_error_msg()];
  }

  // Create TopicRouter without cache (standalone mode).
  $router = new TopicRouter(NULL);

  $results = [
    'timestamp' => date('c'),
    'total' => 0,
    'matches' => 0,
    'misroutes' => 0,
    'by_match_type' => [
      'exact' => ['total' => 0, 'matches' => 0],
      'stem' => ['total' => 0, 'matches' => 0],
      'synonym' => ['total' => 0, 'matches' => 0],
      'phrase' => ['total' => 0, 'matches' => 0],
      'fuzzy' => ['total' => 0, 'matches' => 0],
      'negative' => ['total' => 0, 'matches' => 0],
    ],
    'by_topic' => [],
    'failures' => [],
    'confidence_stats' => [
      'min' => 1.0,
      'max' => 0.0,
      'sum' => 0.0,
      'count' => 0,
    ],
  ];

  // Test categories.
  $test_sections = [
    'single_token_cases',
    'stem_cases',
    'synonym_cases',
    'phrase_cases',
    'fuzzy_cases',
  ];

  foreach ($test_sections as $section) {
    foreach ($fixtures[$section] ?? [] as $case) {
      $results['total']++;
      $match_type = $case['match_type'];
      $results['by_match_type'][$match_type]['total']++;

      $expected_topic = $case['expected_topic'];
      $expected_url = $case['expected_url'];

      if (!isset($results['by_topic'][$expected_topic])) {
        $results['by_topic'][$expected_topic] = ['total' => 0, 'matches' => 0];
      }
      $results['by_topic'][$expected_topic]['total']++;

      $result = $router->route($case['utterance']);

      $passed = FALSE;
      if ($result !== NULL) {
        $topic_match = $result['topic'] === $expected_topic;
        $url_match = $result['url'] === $expected_url;
        $passed = $topic_match && $url_match;

        // Track confidence stats.
        $results['confidence_stats']['min'] = min($results['confidence_stats']['min'], $result['confidence']);
        $results['confidence_stats']['max'] = max($results['confidence_stats']['max'], $result['confidence']);
        $results['confidence_stats']['sum'] += $result['confidence'];
        $results['confidence_stats']['count']++;
      }

      if ($passed) {
        $results['matches']++;
        $results['by_match_type'][$match_type]['matches']++;
        $results['by_topic'][$expected_topic]['matches']++;
      }
      else {
        $results['misroutes']++;
        $results['failures'][] = [
          'id' => $case['id'],
          'utterance' => $case['utterance'],
          'expected_topic' => $expected_topic,
          'expected_url' => $expected_url,
          'expected_match_type' => $match_type,
          'got_topic' => $result['topic'] ?? 'NULL',
          'got_url' => $result['url'] ?? 'NULL',
          'got_match_type' => $result['match_type'] ?? 'NULL',
          'got_confidence' => $result['confidence'] ?? 0,
        ];
      }

      if ($verbose) {
        $status = $passed ? 'PASS' : 'FAIL';
        $conf = $result ? sprintf('%.2f', $result['confidence']) : 'N/A';
        $actual_type = $result['match_type'] ?? 'NULL';
        echo sprintf("[%s] #%d (%s→%s conf=%s): %s\n",
          $status, $case['id'], $match_type, $actual_type, $conf,
          $case['utterance']);
        if (!$passed) {
          echo sprintf("       Expected: %s (%s) → Got: %s (%s)\n",
            $expected_topic, $expected_url,
            $result['topic'] ?? 'NULL', $result['url'] ?? 'NULL');
        }
      }
    }
  }

  // Test negative cases (should NOT match any topic).
  foreach ($fixtures['negative_cases'] ?? [] as $case) {
    $results['total']++;
    $results['by_match_type']['negative']['total']++;

    $result = $router->route($case['utterance']);
    $passed = ($result === NULL);

    if ($passed) {
      $results['matches']++;
      $results['by_match_type']['negative']['matches']++;
    }
    else {
      $results['misroutes']++;
      $results['failures'][] = [
        'id' => $case['id'],
        'utterance' => $case['utterance'],
        'expected_topic' => 'NULL (no match)',
        'got_topic' => $result['topic'] ?? 'unknown',
        'got_url' => $result['url'] ?? 'unknown',
        'got_match_type' => $result['match_type'] ?? 'unknown',
        'got_confidence' => $result['confidence'] ?? 0,
        'note' => $case['note'] ?? '',
      ];
    }

    if ($verbose) {
      $status = $passed ? 'PASS' : 'FAIL';
      echo sprintf("[%s] #%d (negative): %s → %s\n",
        $status, $case['id'], $case['utterance'],
        $passed ? 'correctly rejected' : 'incorrectly matched to ' . ($result['topic'] ?? '?'));
    }
  }

  // Calculate metrics.
  $results['accuracy'] = $results['total'] > 0 ? round(($results['matches'] / $results['total']) * 100, 2) : 0;
  $results['misroute_rate'] = $results['total'] > 0 ? round(($results['misroutes'] / $results['total']) * 100, 2) : 0;
  if ($results['confidence_stats']['count'] > 0) {
    $results['confidence_stats']['avg'] = round($results['confidence_stats']['sum'] / $results['confidence_stats']['count'], 3);
  }
  else {
    $results['confidence_stats']['avg'] = 0;
  }

  return $results;
}

/**
 * Formats TopicRouter results as a text report.
 *
 * @param array $results
 *   Test results.
 *
 * @return string
 *   Formatted report.
 */
function formatTopicRouterReport(array $results): string {
  $output = [];
  $output[] = "═══════════════════════════════════════════════════════════════════════════";
  $output[] = "  ILAS TOPIC ROUTER TEST HARNESS - VALIDATION REPORT";
  $output[] = "═══════════════════════════════════════════════════════════════════════════";
  $output[] = sprintf("  Generated: %s", $results['timestamp']);
  $output[] = "";

  // Summary.
  $output[] = "SUMMARY METRICS";
  $output[] = "───────────────────────────────────────────────────────────────────────────";
  $output[] = sprintf("  Total Test Cases:       %d", $results['total']);
  $output[] = sprintf("  Accuracy:               %6.2f%% (Target: >= 95%%)", $results['accuracy']);
  $output[] = sprintf("  Misroute Rate:          %6.2f%%", $results['misroute_rate']);
  $output[] = sprintf("  Avg Confidence:         %5.3f", $results['confidence_stats']['avg']);
  $output[] = sprintf("  Min Confidence:         %5.3f", $results['confidence_stats']['min']);
  $output[] = sprintf("  Max Confidence:         %5.3f", $results['confidence_stats']['max']);
  $output[] = "";

  // By match type.
  $output[] = "ACCURACY BY MATCH TYPE";
  $output[] = "┌────────────────────┬───────┬─────────┬───────────┐";
  $output[] = "│ Match Type         │ Total │ Matches │ Accuracy  │";
  $output[] = "├────────────────────┼───────┼─────────┼───────────┤";
  foreach ($results['by_match_type'] as $type => $data) {
    if ($data['total'] > 0) {
      $acc = round(($data['matches'] / $data['total']) * 100, 1);
      $output[] = sprintf("│ %-18s │ %5d │ %7d │ %7.1f%% │",
        $type, $data['total'], $data['matches'], $acc);
    }
  }
  $output[] = "└────────────────────┴───────┴─────────┴───────────┘";
  $output[] = "";

  // By topic.
  $output[] = "ACCURACY BY TOPIC";
  $output[] = "┌────────────────────┬───────┬─────────┬───────────┐";
  $output[] = "│ Topic              │ Total │ Matches │ Accuracy  │";
  $output[] = "├────────────────────┼───────┼─────────┼───────────┤";
  foreach ($results['by_topic'] as $topic => $data) {
    if ($data['total'] > 0) {
      $acc = round(($data['matches'] / $data['total']) * 100, 1);
      $output[] = sprintf("│ %-18s │ %5d │ %7d │ %7.1f%% │",
        $topic, $data['total'], $data['matches'], $acc);
    }
  }
  $output[] = "└────────────────────┴───────┴─────────┴───────────┘";
  $output[] = "";

  // Failures.
  if (!empty($results['failures'])) {
    $output[] = sprintf("FAILURES (%d total)", count($results['failures']));
    $output[] = "───────────────────────────────────────────────────────────────────────────";
    foreach ($results['failures'] as $failure) {
      $output[] = sprintf("  #%d: \"%s\"", $failure['id'], $failure['utterance']);
      $output[] = sprintf("       Expected: %s → Got: %s (conf: %s)",
        $failure['expected_topic'] ?? 'NULL',
        $failure['got_topic'] ?? 'NULL',
        isset($failure['got_confidence']) ? sprintf('%.2f', $failure['got_confidence']) : 'N/A');
    }
  }

  $output[] = "";
  $output[] = "═══════════════════════════════════════════════════════════════════════════";

  return implode("\n", $output);
}

// Main execution.
if (php_sapi_name() === 'cli') {
  $options = getopt('', ['report:', 'verbose']);
  $verbose = isset($options['verbose']);
  $report_path = $options['report'] ?? NULL;

  echo "Running TopicRouter validation tests...\n\n";

  $results = runTopicRouterTests($verbose);

  if (isset($results['error'])) {
    echo "Error: " . $results['error'] . "\n";
    exit(1);
  }

  echo "\n";
  echo formatTopicRouterReport($results);
  echo "\n";

  // Write JSON report if requested.
  if ($report_path) {
    $json = json_encode($results, JSON_PRETTY_PRINT);
    file_put_contents($report_path, $json);
    echo "JSON report written to: $report_path\n";
  }

  // Exit code.
  $passed = $results['accuracy'] >= 95;
  exit($passed ? 0 : 1);
}
