#!/usr/bin/env php
<?php

/**
 * @file
 * ILAS Chatbot Evaluation Runner.
 *
 * Usage:
 *   php run-eval.php [options]
 *
 * Options:
 *   --fixture=PATH    Path to fixture file (CSV or JSON)
 *   --http            Use HTTP mode (default: internal Drupal calls)
 *   --base-url=URL    Base URL for HTTP mode
 *   --output=DIR      Output directory for reports
 *   --category=NAME   Filter to specific intent category
 *   --limit=N         Limit number of test cases
 *   --verbose         Verbose output
 *   --no-adversarial  Exclude adversarial test cases
 *   --stats           Show fixture statistics only
 *   --validate        Validate fixture file only
 *   --help            Show help
 *
 * Examples:
 *   php run-eval.php --fixture=../../chatbot-golden-dataset.csv
 *   php run-eval.php --http --base-url=https://idaholegalaid.ddev.site
 *   php run-eval.php --category=high_risk_dv --verbose
 */

// Autoload evaluation classes.
require_once __DIR__ . '/ChatbotEvaluator.php';
require_once __DIR__ . '/FixtureLoader.php';
require_once __DIR__ . '/ReportGenerator.php';

// Autoload shared ResponseBuilder for internal mode.
$response_builder_path = __DIR__ . '/../../web/modules/custom/ilas_site_assistant/src/Service/ResponseBuilder.php';
if (file_exists($response_builder_path)) {
  require_once $response_builder_path;
}

use IlasChatbotEval\ChatbotEvaluator;
use IlasChatbotEval\FixtureLoader;
use IlasChatbotEval\ReportGenerator;

// Parse command line arguments.
$options = getopt('', [
  'fixture:',
  'http',
  'base-url:',
  'output:',
  'category:',
  'limit:',
  'verbose',
  'no-adversarial',
  'stats',
  'validate',
  'help',
]);

// Show help.
if (isset($options['help'])) {
  echo <<<HELP
ILAS Chatbot Evaluation Runner

Usage:
  php run-eval.php [options]

Options:
  --fixture=PATH    Path to fixture file (CSV or JSON)
                    Default: ../../chatbot-golden-dataset.csv
  --http            Use HTTP mode (requires running Drupal site)
  --base-url=URL    Base URL for HTTP mode
                    Default: https://idaholegalaid.ddev.site
  --output=DIR      Output directory for reports
                    Default: ./reports
  --category=NAME   Filter to specific intent category
  --limit=N         Limit number of test cases
  --verbose         Show progress for each test
  --no-adversarial  Exclude adversarial test cases
  --stats           Show fixture statistics only
  --validate        Validate fixture file only
  --help            Show this help message

Examples:
  # Run full evaluation with golden dataset
  php run-eval.php

  # Run via HTTP against DDEV site
  php run-eval.php --http --verbose

  # Run only high-risk DV tests
  php run-eval.php --category=high_risk_dv --verbose

  # Show fixture statistics
  php run-eval.php --stats


HELP;
  exit(0);
}

// Configuration.
$fixture_path = $options['fixture'] ?? __DIR__ . '/../../chatbot-golden-dataset.csv';
$http_mode = isset($options['http']);
$base_url = $options['base-url'] ?? 'https://idaholegalaid.ddev.site';
$output_dir = $options['output'] ?? __DIR__ . '/reports';
$category_filter = $options['category'] ?? NULL;
$limit = isset($options['limit']) ? (int) $options['limit'] : NULL;
$verbose = isset($options['verbose']);
$exclude_adversarial = isset($options['no-adversarial']);
$stats_only = isset($options['stats']);
$validate_only = isset($options['validate']);

// Resolve fixture path.
if (!file_exists($fixture_path)) {
  // Try relative to script directory.
  $alt_path = __DIR__ . '/' . $fixture_path;
  if (file_exists($alt_path)) {
    $fixture_path = $alt_path;
  }
}

if (!file_exists($fixture_path)) {
  echo "Error: Fixture file not found: {$fixture_path}\n";
  exit(1);
}

echo "=== ILAS Chatbot Evaluation Harness ===\n\n";

// Validate only mode.
if ($validate_only) {
  echo "Validating fixture: {$fixture_path}\n\n";
  $validation = FixtureLoader::validateFixture($fixture_path);

  if ($validation['valid']) {
    echo "Fixture is valid.\n";
    echo "Total cases: {$validation['case_count']}\n";
    exit(0);
  }
  else {
    echo "Fixture has errors:\n";
    foreach ($validation['errors'] as $error) {
      echo "  - {$error}\n";
    }
    exit(1);
  }
}

// Stats only mode.
if ($stats_only) {
  echo "Fixture statistics: {$fixture_path}\n\n";
  $stats = FixtureLoader::getFixtureStats($fixture_path);

  echo "Total test cases: {$stats['total_cases']}\n";
  echo "Safety required:  {$stats['safety_required_count']}\n";
  echo "With secondary:   {$stats['with_secondary_action']}\n";
  echo "\n";

  echo "Languages:\n";
  foreach ($stats['languages'] as $lang => $count) {
    echo "  {$lang}: {$count}\n";
  }
  echo "\n";

  echo "By intent:\n";
  foreach ($stats['by_intent'] as $intent => $count) {
    echo "  {$intent}: {$count}\n";
  }

  exit(0);
}

// Load fixtures.
echo "Loading fixtures from: {$fixture_path}\n";

$loader_options = [
  'filter_category' => $category_filter,
  'limit' => $limit,
  'exclude_adversarial' => $exclude_adversarial,
];

$extension = pathinfo($fixture_path, PATHINFO_EXTENSION);
if ($extension === 'json') {
  $test_cases = FixtureLoader::loadFromJson($fixture_path, $loader_options);
}
else {
  $test_cases = FixtureLoader::loadFromCsv($fixture_path, $loader_options);
}

echo "Loaded " . count($test_cases) . " test cases\n";

if ($category_filter) {
  echo "Filtered to category: {$category_filter}\n";
}

if ($exclude_adversarial) {
  echo "Excluded adversarial tests\n";
}

echo "\n";

// Initialize evaluator.
$config = [
  'http_mode' => $http_mode,
  'base_url' => $base_url,
  'verbose' => $verbose,
  'debug' => TRUE,
];

$evaluator = new ChatbotEvaluator($config);

// For internal mode, we need to bootstrap Drupal.
if (!$http_mode) {
  echo "Mode: Internal (Drupal bootstrap required)\n";

  // Try to bootstrap Drupal.
  $drupal_root = realpath(__DIR__ . '/../../web');

  if (!$drupal_root || !file_exists($drupal_root . '/index.php')) {
    echo "\nWarning: Cannot find Drupal root. Switching to HTTP mode.\n";
    echo "To use internal mode, run from within the Drupal installation.\n\n";
    $http_mode = TRUE;
    $evaluator = new ChatbotEvaluator(array_merge($config, ['http_mode' => TRUE]));
  }
  else {
    // Attempt Drupal bootstrap.
    try {
      $autoloader = require_once $drupal_root . '/autoload.php';
      $kernel = new \Drupal\Core\DrupalKernel('prod', $autoloader);
      $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
      $kernel->boot();
      $kernel->preHandle($request);

      // Get services.
      $container = $kernel->getContainer();
      $intent_router = $container->get('ilas_site_assistant.intent_router');
      $policy_filter = $container->get('ilas_site_assistant.policy_filter');

      $evaluator->setServices($intent_router, $policy_filter);
      echo "Drupal bootstrapped successfully.\n";
    }
    catch (\Exception $e) {
      echo "\nWarning: Drupal bootstrap failed: " . $e->getMessage() . "\n";
      echo "Switching to HTTP mode.\n\n";
      $http_mode = TRUE;
      $evaluator = new ChatbotEvaluator(array_merge($config, ['http_mode' => TRUE]));
    }
  }
}

if ($http_mode) {
  echo "Mode: HTTP\n";
  echo "Base URL: {$base_url}\n";
}

echo "\n";
echo "Starting evaluation...\n\n";

// Run evaluation.
$results = $evaluator->runEvaluation($test_cases);

// Print summary.
ReportGenerator::printSummary($results);

// Save reports.
echo "Saving reports to: {$output_dir}\n";
$files = ReportGenerator::saveReports($results, $output_dir, 'chatbot');

echo "\nGenerated reports:\n";
foreach ($files as $type => $path) {
  if (strpos($type, 'latest') === FALSE) {
    echo "  - {$path}\n";
  }
}

// Exit with appropriate code.
$passed = $results['summary']['passed'] ?? 0;
$total = $results['summary']['total'] ?? 0;
$pass_rate = $total > 0 ? $passed / $total : 0;

// Exit 0 if >80% pass rate, 1 otherwise.
exit($pass_rate >= 0.8 ? 0 : 1);
