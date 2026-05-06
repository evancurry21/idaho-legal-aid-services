#!/usr/bin/env php
<?php

/**
 * @file
 * Normalization Evaluation Runner - deprecated legacy harness.
 *
 * Evaluates how acronym expansion and typo correction improve routing accuracy
 * by testing the KeywordExtractor + TopicRouter pipeline on known
 * typo/acronym variants versus their canonical forms.
 *
 * Metrics:
 * - R@1 for acronym queries (does expanded form route correctly?)
 * - R@1 for typo queries (does corrected form route correctly?)
 * - Before/after comparison (with and without normalization)
 *
 * Preserved for historical fixture review only. This is not a current Site
 * Assistant quality gate.
 *
 * Usage:
 *   php run-normalization-eval.php [--verbose]
 */

// Autoload.
$autoload_paths = [
  __DIR__ . '/../../vendor/autoload.php',
  __DIR__ . '/../../../vendor/autoload.php',
];
$loaded = FALSE;
foreach ($autoload_paths as $path) {
  if (file_exists($path)) {
    require_once $path;
    $loaded = TRUE;
    break;
  }
}
if (!$loaded) {
  echo "Error: Cannot find vendor/autoload.php\n";
  exit(1);
}

// Load services.
require_once __DIR__ . '/../../web/modules/custom/ilas_site_assistant/src/Service/AcronymExpander.php';
require_once __DIR__ . '/../../web/modules/custom/ilas_site_assistant/src/Service/TopicRouter.php';
require_once __DIR__ . '/../../web/modules/custom/ilas_site_assistant/src/Service/TypoCorrector.php';

use Drupal\ilas_site_assistant\Service\AcronymExpander;
use Drupal\ilas_site_assistant\Service\TopicRouter;
use Drupal\ilas_site_assistant\Service\TypoCorrector;

$verbose = in_array('--verbose', $argv);

echo "=== ILAS Normalization Evaluation (Deprecated Legacy) ===\n\n";
echo "Warning: this is historical fixture tooling only, not a current Site Assistant quality gate.\n\n";

// Initialize services.
$expander = new AcronymExpander(NULL);
$topic_router = new TopicRouter(NULL);
$corrector = new TypoCorrector(NULL, $topic_router, $expander);

echo "Acronym map: " . count($expander->getAcronymMap()) . " entries\n";
echo "Typo vocabulary: " . $corrector->getVocabularySize() . " entries\n\n";

// =============================================
// TEST FIXTURES
// =============================================
// Each test: [query, expected_topic, expected_service_area, category]
// expected_topic: the topic key that TopicRouter should route to
// If expected_topic is NULL, means the query should NOT route (out of scope etc.)

$acronym_routing_tests = [
  // Acronyms that should route to a topic after expansion.
  ['DV', 'family', 'family', 'acronym', 'DV -> domestic violence -> family'],
  ['help with DV', 'family', 'family', 'acronym', 'DV in phrase -> family'],
  ['POA forms', 'seniors', 'seniors', 'acronym', 'POA -> power of attorney -> seniors'],
  ['SSI denied', 'health', 'health', 'acronym', 'SSI -> benefits -> health'],
  ['SNAP help', 'health', 'health', 'acronym', 'SNAP -> food stamps -> health'],
  ['BK filing', 'consumer', 'consumer', 'acronym', 'BK -> bankruptcy -> consumer'],
  ['CH7', 'consumer', 'consumer', 'acronym', 'CH7 -> chapter 7 bankruptcy -> consumer'],
  ['CS modification', 'family', 'family', 'acronym', 'CS -> child support -> family'],
  ['HUD housing', 'housing', 'housing', 'acronym', 'HUD -> housing and urban development'],
  ['FMLA denied', 'employment', 'civil_rights', 'acronym', 'FMLA -> family medical leave -> employment'],
  ['ADA accommodation', 'health', 'health', 'acronym', 'ADA -> americans with disabilities -> health (disability token)'],
  ['TANF benefits', 'health', 'health', 'acronym', 'TANF -> temporary assistance -> health'],
  ['EBT card', 'health', 'health', 'acronym', 'EBT -> electronic benefits transfer -> health'],
  ['FDCPA violation', 'consumer', 'consumer', 'acronym', 'FDCPA -> fair debt collection -> consumer'],
  ['CPS case', 'family', 'family', 'acronym', 'CPS -> child protective services -> family'],
  ['EEOC complaint', 'employment', 'civil_rights', 'acronym', 'EEOC -> employment discrimination -> employment'],
  ['SSD appeal', 'health', 'health', 'acronym', 'SSD -> social security disability -> health'],
  ['IPV help', 'family', 'family', 'acronym', 'IPV -> intimate partner violence -> family'],
  ['RO filing', 'family', 'family', 'acronym', 'RO -> restraining order -> family'],
];

$typo_routing_tests = [
  // Typos that should route correctly after correction.
  ['custdy', 'family', 'family', 'typo', 'custdy -> custody -> family'],
  ['divorse', 'family', 'family', 'typo', 'divorse -> divorce -> family'],
  ['evicton', 'housing', 'housing', 'typo', 'evicton -> eviction -> housing'],
  ['bankrupcy', 'consumer', 'consumer', 'typo', 'bankrupcy -> bankruptcy -> consumer'],
  ['landord', 'housing', 'housing', 'typo', 'landord -> landlord -> housing'],
  ['forclosure', 'housing', 'housing', 'typo', 'forclosure -> foreclosure -> housing'],
  ['gaurdianship', 'family', 'family', 'typo', 'gaurdianship -> guardianship -> family'],
  ['laywer', NULL, NULL, 'typo', 'laywer -> lawyer (general, may not topic-route)'],
  ['cusotdy', 'family', 'family', 'typo', 'cusotdy -> custody -> family'],
  ['divorec', 'family', 'family', 'typo', 'divorec -> divorce -> family'],
  ['eviciton', 'housing', 'housing', 'typo', 'eviciton -> eviction -> housing'],
  ['bankruptsy', 'consumer', 'consumer', 'typo', 'bankruptsy -> bankruptcy -> consumer'],
  ['morgage', 'housing', 'housing', 'typo', 'morgage -> mortgage -> housing'],
  ['garnishmet', 'consumer', 'consumer', 'typo', 'garnishmet -> garnishment -> consumer'],
  ['reposession', 'consumer', 'consumer', 'typo', 'reposession -> repossession -> consumer'],
  ['custidy forms', 'family', 'family', 'typo', 'custidy -> custody + forms -> family'],
  ['divorse papers', 'family', 'family', 'typo', 'divorse papers -> divorce -> family'],
  ['evicton notice', 'housing', 'housing', 'typo', 'evicton notice -> eviction -> housing'],
  ['bankrupcy help', 'consumer', 'consumer', 'typo', 'bankrupcy help -> bankruptcy -> consumer'],
  ['landord problems', 'housing', 'housing', 'typo', 'landord problems -> landlord -> housing'],
];

// =============================================
// EVALUATION: Run each query through TopicRouter
// with and without normalization
// =============================================

function normalize_query(string $query, AcronymExpander $expander, TypoCorrector $corrector): string {
  // Step 1: Expand acronyms.
  $result = $expander->expand($query);
  $text = $result['text'];

  // Step 2: Correct typos.
  $result = $corrector->correct($text);
  return $result['text'];
}

/**
 * Routes a query through TopicRouter, trying the full string first,
 * then individual tokens if the string is too long for TopicRouter.
 *
 * This simulates how IntentRouter uses TopicRouter: it tries the full
 * message, but also extracts keywords that can match individually.
 */
function route_with_fallback(string $text, TopicRouter $router): ?array {
  // Try full text first.
  $result = $router->route($text);
  if ($result) {
    return $result;
  }

  // If full text is too long (>3 words), try individual tokens.
  $words = preg_split('/\s+/', trim($text));
  if (count($words) <= 3) {
    return NULL;
  }

  $best = NULL;
  foreach ($words as $word) {
    if (strlen($word) < 3) {
      continue;
    }
    $result = $router->route($word);
    if ($result && (!$best || $result['confidence'] > $best['confidence'])) {
      $best = $result;
    }
  }

  // Reduce confidence for individual token match from longer text.
  if ($best) {
    $best['confidence'] = max(0.60, $best['confidence'] - 0.10);
  }

  return $best;
}

function evaluate_set(
  array $tests,
  TopicRouter $router,
  AcronymExpander $expander,
  TypoCorrector $corrector,
  bool $verbose,
  string $label
): array {
  $before_hits = 0;
  $after_hits = 0;
  $total = 0;
  $details = [];

  foreach ($tests as $test) {
    [$query, $expected_topic, $expected_area, $category, $description] = $test;

    // Skip tests where we expect no topic match.
    if ($expected_topic === NULL) {
      continue;
    }

    $total++;

    // BEFORE normalization: route raw query.
    $before_result = route_with_fallback($query, $router);
    $before_hit = ($before_result !== NULL && $before_result['topic'] === $expected_topic);
    if ($before_hit) {
      $before_hits++;
    }

    // AFTER normalization: try raw first, then fall back to normalized.
    // This is the real pipeline: TopicRouter sees the raw message first,
    // and normalization provides additional keywords if needed.
    $normalized = normalize_query($query, $expander, $corrector);
    $after_result = route_with_fallback($query, $router);
    if (!$after_result) {
      $after_result = route_with_fallback($normalized, $router);
    }
    $after_hit = ($after_result !== NULL && $after_result['topic'] === $expected_topic);
    if ($after_hit) {
      $after_hits++;
    }

    $status = $before_hit ? ($after_hit ? 'BOTH' : 'REGR') : ($after_hit ? 'FIXED' : 'MISS');

    if ($verbose || !$after_hit) {
      $details[] = [
        'query' => $query,
        'normalized' => $normalized,
        'expected' => $expected_topic,
        'before' => $before_result ? $before_result['topic'] : 'NULL',
        'after' => $after_result ? $after_result['topic'] : 'NULL',
        'status' => $status,
        'description' => $description,
      ];
    }
  }

  $before_r1 = $total > 0 ? $before_hits / $total : 0;
  $after_r1 = $total > 0 ? $after_hits / $total : 0;

  return [
    'label' => $label,
    'total' => $total,
    'before_hits' => $before_hits,
    'after_hits' => $after_hits,
    'before_r1' => $before_r1,
    'after_r1' => $after_r1,
    'delta' => $after_r1 - $before_r1,
    'details' => $details,
  ];
}

// Run evaluations.
$acronym_results = evaluate_set($acronym_routing_tests, $topic_router, $expander, $corrector, $verbose, 'Acronym Queries');
$typo_results = evaluate_set($typo_routing_tests, $topic_router, $expander, $corrector, $verbose, 'Typo Queries');

// =============================================
// REPORT
// =============================================

echo "=== Normalization Evaluation Results ===\n\n";

echo "| Subset | Total | Before R@1 | After R@1 | Delta |\n";
echo "|--------|-------|------------|-----------|-------|\n";

foreach ([$acronym_results, $typo_results] as $r) {
  printf("| %-14s | %5d | %9.1f%% | %8.1f%% | %+5.1f%% |\n",
    $r['label'],
    $r['total'],
    $r['before_r1'] * 100,
    $r['after_r1'] * 100,
    $r['delta'] * 100
  );
}

// Combined.
$combined_total = $acronym_results['total'] + $typo_results['total'];
$combined_before = $acronym_results['before_hits'] + $typo_results['before_hits'];
$combined_after = $acronym_results['after_hits'] + $typo_results['after_hits'];
$combined_before_r1 = $combined_total > 0 ? $combined_before / $combined_total : 0;
$combined_after_r1 = $combined_total > 0 ? $combined_after / $combined_total : 0;

printf("| %-14s | %5d | %9.1f%% | %8.1f%% | %+5.1f%% |\n",
  'Combined',
  $combined_total,
  $combined_before_r1 * 100,
  $combined_after_r1 * 100,
  ($combined_after_r1 - $combined_before_r1) * 100
);

echo "\n";

// Print failures.
$all_details = array_merge($acronym_results['details'], $typo_results['details']);
$failures = array_filter($all_details, function ($d) {
  return $d['status'] === 'MISS' || $d['status'] === 'REGR';
});

if (!empty($failures)) {
  echo "--- Remaining Failures ---\n\n";
  foreach ($failures as $f) {
    printf("  [%s] %-30s => expected %-12s got %-12s (normalized: '%s')\n",
      $f['status'],
      $f['query'],
      $f['expected'],
      $f['after'],
      $f['normalized']
    );
  }
  echo "\n";
}

// Print fixes (queries that went from MISS to HIT).
$fixes = array_filter($all_details, function ($d) {
  return $d['status'] === 'FIXED';
});

if (!empty($fixes)) {
  echo "--- Fixed by Normalization ---\n\n";
  foreach ($fixes as $f) {
    printf("  [FIXED] %-30s => %-12s (was: %-12s, normalized: '%s')\n",
      $f['query'],
      $f['after'],
      $f['before'],
      $f['normalized']
    );
  }
  echo "\n";
}

// Print regressions.
$regressions = array_filter($all_details, function ($d) {
  return $d['status'] === 'REGR';
});

if (!empty($regressions)) {
  echo "--- REGRESSIONS (worked before, broken now) ---\n\n";
  foreach ($regressions as $f) {
    printf("  [REGR] %-30s => was %-12s now %-12s\n",
      $f['query'],
      $f['before'],
      $f['after']
    );
  }
  echo "\n";
}

// Save JSON results.
$output_dir = __DIR__ . '/reports';
if (!is_dir($output_dir)) {
  mkdir($output_dir, 0755, TRUE);
}

$results = [
  'timestamp' => date('c'),
  'summary' => [
    'acronym_before_r1' => round($acronym_results['before_r1'], 4),
    'acronym_after_r1' => round($acronym_results['after_r1'], 4),
    'typo_before_r1' => round($typo_results['before_r1'], 4),
    'typo_after_r1' => round($typo_results['after_r1'], 4),
    'combined_before_r1' => round($combined_before_r1, 4),
    'combined_after_r1' => round($combined_after_r1, 4),
    'acronym_delta' => round($acronym_results['delta'], 4),
    'typo_delta' => round($typo_results['delta'], 4),
    'combined_delta' => round($combined_after_r1 - $combined_before_r1, 4),
  ],
  'acronym_details' => $acronym_results['details'],
  'typo_details' => $typo_results['details'],
];

$json_path = $output_dir . '/normalization-eval-' . date('Y-m-d_His') . '.json';
file_put_contents($json_path, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$latest_path = $output_dir . '/normalization-eval-latest.json';
@unlink($latest_path);
symlink(basename($json_path), $latest_path);

echo "Results saved to: $json_path\n";

exit(count($regressions) > 0 ? 1 : 0);
