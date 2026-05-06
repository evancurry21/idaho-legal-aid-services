#!/usr/bin/env php
<?php

/**
 * @file
 * ILAS Chatbot Retrieval Evaluation Runner - deprecated legacy harness.
 *
 * Evaluates FAQ and resource retrieval quality using standard IR metrics:
 * - Recall@K (K=1, 3, 5)
 * - Mean Reciprocal Rank (MRR)
 * - Normalized Discounted Cumulative Gain (nDCG)
 *
 * Preserved for historical fixture review only. HTTP mode does not honor the
 * current Site Assistant bootstrap/session/CSRF/conversation contract.
 *
 * Usage:
 *   php run-retrieval-eval.php [options]
 *
 * Options:
 *   --fixture=PATH       Path to retrieval fixture JSON
 *   --http               Use legacy HTTP mode (default: internal)
 *   --base-url=URL       Base URL for HTTP mode
 *   --output=DIR         Output directory for reports
 *   --category=NAME      Filter to specific category
 *   --type=TYPE          Filter to expected_type (faq, resource, navigation)
 *   --limit=N            Limit number of test cases
 *   --verbose            Show progress for each test
 *   --compare=PATH       Compare with baseline results JSON
 *   --help               Show help
 */

// Autoload evaluation classes.
require_once __DIR__ . '/RetrievalEvaluator.php';

use IlasChatbotEval\RetrievalEvaluator;

// Parse command line arguments.
$options = getopt('', [
  'fixture:',
  'http',
  'base-url:',
  'output:',
  'category:',
  'type:',
  'limit:',
  'verbose',
  'compare:',
  'help',
]);

// Show help.
if (isset($options['help'])) {
  echo <<<HELP
ILAS Chatbot Retrieval Evaluation Runner - DEPRECATED legacy harness

This is not a current Site Assistant quality gate. Use Promptfoo retrieval and
grounding suites for current answer-quality coverage.

Evaluates FAQ and resource retrieval quality using standard IR metrics.

Usage:
  php run-retrieval-eval.php [options]

Options:
  --fixture=PATH       Path to retrieval fixture JSON
                       Default: ./retrieval-fixture.json
  --http               Use legacy HTTP mode (requires running Drupal site)
  --base-url=URL       Base URL for HTTP mode
                       Default: https://ilas-pantheon.ddev.site
  --output=DIR         Output directory for reports
                       Default: ./reports
  --category=NAME      Filter to specific category
  --type=TYPE          Filter to expected_type (faq, resource, navigation, any)
  --limit=N            Limit number of test cases
  --verbose            Show progress for each test
  --compare=PATH       Compare results with baseline JSON file
  --help               Show this help message

Metrics Computed:
  - Recall@1   : % of queries where correct result is #1
  - Recall@3   : % of queries where correct result is in top 3
  - Recall@5   : % of queries where correct result is in top 5
  - MRR        : Mean Reciprocal Rank (average of 1/rank)
  - nDCG@5     : Normalized Discounted Cumulative Gain at 5

Examples:
  # Run full retrieval evaluation
  php run-retrieval-eval.php --verbose

  # Run via HTTP against DDEV site
  php run-retrieval-eval.php --http --verbose

  # Run only FAQ tests
  php run-retrieval-eval.php --type=faq --verbose

  # Compare with baseline
  php run-retrieval-eval.php --compare=reports/retrieval-baseline.json


HELP;
  exit(0);
}

// Configuration.
$fixture_path = $options['fixture'] ?? __DIR__ . '/retrieval-fixture.json';
$http_mode = isset($options['http']);
$base_url = $options['base-url'] ?? 'https://ilas-pantheon.ddev.site';
$output_dir = $options['output'] ?? __DIR__ . '/reports';
$category_filter = $options['category'] ?? NULL;
$type_filter = $options['type'] ?? NULL;
$limit = isset($options['limit']) ? (int) $options['limit'] : NULL;
$verbose = isset($options['verbose']);
$compare_path = $options['compare'] ?? NULL;

// Resolve fixture path.
if (!file_exists($fixture_path)) {
  $alt_path = __DIR__ . '/' . $fixture_path;
  if (file_exists($alt_path)) {
    $fixture_path = $alt_path;
  }
}

if (!file_exists($fixture_path)) {
  echo "Error: Fixture file not found: {$fixture_path}\n";
  exit(1);
}

echo "=== ILAS Retrieval Evaluation Harness (Deprecated Legacy) ===\n\n";
echo "Warning: this harness is historical fixture material only, not a current Site Assistant quality gate.\n";
echo "HTTP mode does not exercise the current bootstrap/session/CSRF/conversation contract.\n\n";

// Initialize evaluator.
$config = [
  'http_mode' => $http_mode,
  'base_url' => $base_url,
  'verbose' => $verbose,
  'max_results' => 10,
];

$evaluator = new RetrievalEvaluator($config);

// For internal mode, try to bootstrap Drupal.
if (!$http_mode) {
  echo "Mode: Internal (Drupal bootstrap required)\n";

  $drupal_root = realpath(__DIR__ . '/../../web');

  if (!$drupal_root || !file_exists($drupal_root . '/index.php')) {
    echo "\nWarning: Cannot find Drupal root. Switching to HTTP mode.\n";
    echo "To use internal mode, run from within the Drupal installation.\n\n";
    $http_mode = TRUE;
    $evaluator = new RetrievalEvaluator(array_merge($config, ['http_mode' => TRUE]));
  }
  else {
    try {
      $autoloader = require_once $drupal_root . '/autoload.php';
      $kernel = new \Drupal\Core\DrupalKernel('prod', $autoloader);
      $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
      $kernel->boot();
      $kernel->preHandle($request);

      // Get services.
      $container = $kernel->getContainer();
      $faq_index = $container->get('ilas_site_assistant.faq_index');
      $resource_finder = $container->get('ilas_site_assistant.resource_finder');
      $intent_router = $container->get('ilas_site_assistant.intent_router');

      $evaluator->setServices($faq_index, $resource_finder, $intent_router);
      echo "Drupal bootstrapped successfully.\n";
    }
    catch (\Exception $e) {
      echo "\nWarning: Drupal bootstrap failed: " . $e->getMessage() . "\n";
      echo "Switching to HTTP mode.\n\n";
      $http_mode = TRUE;
      $evaluator = new RetrievalEvaluator(array_merge($config, ['http_mode' => TRUE]));
    }
  }
}

if ($http_mode) {
  echo "Mode: HTTP\n";
  echo "Base URL: {$base_url}\n";
}

echo "\n";

// Load fixture.
echo "Loading fixture: {$fixture_path}\n";

$load_options = [];
if ($category_filter) {
  $load_options['filter_category'] = $category_filter;
  echo "Filtering by category: {$category_filter}\n";
}
if ($type_filter) {
  $load_options['filter_type'] = $type_filter;
  echo "Filtering by type: {$type_filter}\n";
}
if ($limit) {
  $load_options['limit'] = $limit;
  echo "Limiting to: {$limit} test cases\n";
}

$test_cases = $evaluator->loadFixture($fixture_path, $load_options);
echo "Loaded " . count($test_cases) . " test cases\n\n";

// Run evaluation.
echo "Starting evaluation...\n\n";
$results = $evaluator->runEvaluation($test_cases);

// Print summary.
RetrievalEvaluator::printSummary($results);

// Compare with baseline if provided.
if ($compare_path && file_exists($compare_path)) {
  echo "Comparing with baseline: {$compare_path}\n\n";

  $baseline = json_decode(file_get_contents($compare_path), TRUE);
  if ($baseline) {
    printComparison($baseline['metrics'], $results['metrics']);
  }
}

// Save reports.
echo "Saving reports to: {$output_dir}\n";
$files = RetrievalEvaluator::saveResults($results, $output_dir, 'retrieval');

echo "\nGenerated reports:\n";
foreach ($files as $type => $path) {
  if (strpos($type, 'latest') === FALSE) {
    echo "  - {$path}\n";
  }
}

// Exit with appropriate code.
$recall_5 = $results['metrics']['recall_at_5'];
exit($recall_5 >= 0.7 ? 0 : 1);

/**
 * Prints comparison between baseline and current results.
 *
 * @param array $baseline
 *   Baseline metrics.
 * @param array $current
 *   Current metrics.
 */
function printComparison(array $baseline, array $current): void {
  echo "=== Comparison with Baseline ===\n\n";
  echo "| Metric | Baseline | Current | Delta |\n";
  echo "|--------|----------|---------|-------|\n";

  $metrics = ['recall_at_1', 'recall_at_3', 'recall_at_5', 'mrr', 'ndcg_at_5'];

  foreach ($metrics as $metric) {
    $base_val = $baseline[$metric] ?? 0;
    $curr_val = $current[$metric] ?? 0;
    $delta = $curr_val - $base_val;

    $delta_str = sprintf('%+.1f%%', $delta * 100);
    $indicator = $delta > 0.01 ? ' [+]' : ($delta < -0.01 ? ' [-]' : '');

    printf("| %-13s | %.1f%% | %.1f%% | %s%s |\n",
      str_replace('_', '@', $metric),
      $base_val * 100,
      $curr_val * 100,
      $delta_str,
      $indicator
    );
  }

  echo "\n";
}
