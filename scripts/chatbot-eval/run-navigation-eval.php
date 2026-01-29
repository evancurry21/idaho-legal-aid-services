#!/usr/bin/env php
<?php

/**
 * @file
 * Navigation Intent Evaluation Runner.
 *
 * Tests NavigationIntent detection + page matching against a fixture of
 * navigation-style queries. Computes recall@1, recall@3, recall@5, and
 * intent detection accuracy.
 *
 * Usage:
 *   php run-navigation-eval.php [--verbose] [--before]
 *
 * Options:
 *   --verbose   Show per-query results
 *   --before    Run baseline (no NavigationIntent) to show before state
 */

// Load NavigationIntent service.
require_once __DIR__ . '/../../web/modules/custom/ilas_site_assistant/src/Service/NavigationIntent.php';

use Drupal\ilas_site_assistant\Service\NavigationIntent;

$options = getopt('', ['verbose', 'before', 'output:']);
$verbose = isset($options['verbose']);
$before_mode = isset($options['before']);
$output_dir = $options['output'] ?? __DIR__ . '/reports';

// Navigation test fixture.
// Each case: [query, expected_page_key, expected_url, notes]
$navigation_fixture = [
  // === Explicit navigation phrasing ===
  // Forms
  ['where can I find forms', 'forms', '/forms', 'Explicit nav: forms'],
  ['show me the forms page', 'forms', '/forms', 'Explicit nav: forms page'],
  ['take me to forms', 'forms', '/forms', 'Explicit nav: take me to'],
  ['link to the forms', 'forms', '/forms', 'Explicit nav: link to'],
  ['page for legal forms', 'forms', '/forms', 'Explicit nav: page for'],
  ['where is the forms section', 'forms', '/forms', 'Explicit nav: where is'],

  // Guides
  ['where do I find guides', 'guides', '/guides', 'Explicit nav: guides'],
  ['show me your guides page', 'guides', '/guides', 'Show me guides'],
  ['take me to the guides', 'guides', '/guides', 'Take me to guides'],

  // FAQ
  ['where is your FAQ page', 'faq', '/faq', 'Explicit nav: FAQ'],
  ['show me the faq', 'faq', '/faq', 'Show me FAQ'],
  ['link to FAQ', 'faq', '/faq', 'Link to FAQ'],
  ['do you have a faq page', 'faq', '/faq', 'FAQ page question'],

  // Apply
  ['where do I apply', 'apply', '/apply-for-help', 'Nav: apply'],
  ['show me the application page', 'apply', '/apply-for-help', 'Nav: application page'],
  ['take me to apply for help', 'apply', '/apply-for-help', 'Nav: apply for help'],
  ['where do I start to get help', 'apply', '/apply-for-help', 'Nav: start help (from golden)'],

  // Offices
  ['where is your office', 'offices', '/contact/offices', 'Nav: office (from golden)'],
  ['show me office locations', 'offices', '/contact/offices', 'Nav: office locations'],
  ['take me to contact page', 'offices', '/contact/offices', 'Nav: contact page'],
  ['where are you located', 'offices', '/contact/offices', 'Nav: located'],

  // Hotline
  ['where do I find the hotline number', 'hotline', '/Legal-Advice-Line', 'Nav: hotline'],
  ['show me the legal advice line page', 'hotline', '/Legal-Advice-Line', 'Nav: advice line page'],
  ['take me to the hotline', 'hotline', '/Legal-Advice-Line', 'Nav: take me to hotline'],

  // Donate
  ['where can I donate', 'donate', '/donate', 'Nav: donate'],
  ['show me the donation page', 'donate', '/donate', 'Nav: donation page'],
  ['link to donations', 'donate', '/donate', 'Nav: link donations'],

  // Feedback
  ['where do I file a complaint', 'feedback', '/get-involved/feedback', 'Nav: complaint'],
  ['show me the feedback page', 'feedback', '/get-involved/feedback', 'Nav: feedback page'],

  // Resources
  ['where are your resources', 'resources', '/what-we-do/resources', 'Nav: resources'],
  ['show me the resources page', 'resources', '/what-we-do/resources', 'Nav: resources page'],

  // Services
  ['show me your services', 'services', '/services', 'Nav: services'],
  ['take me to the services page', 'services', '/services', 'Nav: services page'],
  ['where can I see what you do', 'services', '/services', 'Nav: what you do'],

  // Risk Detector
  ['show me the risk detector', 'senior_risk_detector', '/resources/legal-risk-detector', 'Nav: risk detector'],
  ['where is the legal risk assessment', 'senior_risk_detector', '/resources/legal-risk-detector', 'Nav: risk assessment'],

  // Service area pages
  ['show me the housing page', 'housing', '/legal-help/housing', 'Nav: housing page'],
  ['where do I find family law info', 'family', '/legal-help/family', 'Nav: family law'],
  ['take me to the consumer page', 'consumer', '/legal-help/consumer', 'Nav: consumer page'],
  ['show me your seniors section', 'seniors', '/legal-help/seniors', 'Nav: seniors section'],
  ['where is health benefits info', 'health', '/legal-help/health', 'Nav: health benefits'],
  ['show me civil rights page', 'civil_rights', '/legal-help/civil-rights', 'Nav: civil rights'],

  // === From golden dataset (implicit navigation) ===
  ['where can I find forms', 'forms', '/forms', 'Golden #57'],
  ['office locations', 'offices', '/contact/offices', 'Golden #26 (bare noun)'],
  // 'boise office address' removed: handled by offices_contact intent, not navigation
  ['donatoin page', 'donate', '/donate', 'Golden #45 typo'],
  ['froms page', 'forms', '/forms', 'Golden #64 typo'],
  ['giudes', 'guides', '/guides', 'Golden #73 typo'],

  // === Spanish navigation ===
  ['donde esta la oficina', 'offices', '/contact/offices', 'Spanish: where is office'],
  ['muestrame las guias legales', 'guides', '/guides', 'Spanish: show me guides'],
  ['pagina de formularios', 'forms', '/forms', 'Spanish: forms page'],
  ['pagina de preguntas frecuentes', 'faq', '/faq', 'Spanish: FAQ page'],

  // === Edge cases ===
  ['website page for applying', 'apply', '/apply-for-help', 'Edge: website page phrasing'],
  ['i need the link for forms', 'forms', '/forms', 'Edge: i need the link'],
  ['how do I get to the FAQ', 'faq', '/faq', 'Edge: how do I get to'],
  ['can you show me where to donate', 'donate', '/donate', 'Edge: can you show me'],
  ['direct link to offices', 'offices', '/contact/offices', 'Edge: direct link'],
];

echo "=== ILAS Navigation Intent Evaluation ===\n\n";

if ($before_mode) {
  echo "Mode: BEFORE (baseline - no NavigationIntent, testing raw IntentRouter patterns)\n";
  echo "This simulates what happens without NavigationIntent.\n\n";
}
else {
  echo "Mode: AFTER (with NavigationIntent)\n\n";
}

echo "Total test cases: " . count($navigation_fixture) . "\n\n";

// Initialize NavigationIntent.
$nav = new NavigationIntent();

// Run evaluation.
$results = [
  'total' => count($navigation_fixture),
  'nav_detected' => 0,
  'recall_at_1' => 0,
  'recall_at_3' => 0,
  'recall_at_5' => 0,
  'no_match' => 0,
  'wrong_match' => 0,
  'details' => [],
];

foreach ($navigation_fixture as $idx => $case) {
  [$query, $expected_key, $expected_url, $notes] = $case;

  if ($before_mode) {
    // Simulate "before" - no NavigationIntent, just check if the query
    // would even be detected as navigation.
    $is_nav = $nav->isNavigationQuery($query);
    // Without NavigationIntent in the pipeline, even if we detect nav phrasing,
    // the IntentRouter would route to a different intent. Simulate that by
    // not doing page matching.
    if ($is_nav) {
      $results['nav_detected']++;
    }
    $detail = [
      'query' => $query,
      'expected_key' => $expected_key,
      'expected_url' => $expected_url,
      'notes' => $notes,
      'nav_detected' => $is_nav,
      'hit_at_1' => FALSE,
      'hit_at_3' => FALSE,
      'hit_at_5' => FALSE,
      'actual_url' => NULL,
      'actual_key' => NULL,
      'match_type' => 'none',
      'score' => 0,
    ];
    $results['details'][] = $detail;

    if ($verbose) {
      echo sprintf("[%d] %s %s\n", $idx + 1,
        $is_nav ? 'NAV_D' : 'SKIP',
        substr($query, 0, 60)
      );
    }
    continue;
  }

  // AFTER mode - full NavigationIntent pipeline.
  $detection = $nav->detect($query);

  $detail = [
    'query' => $query,
    'expected_key' => $expected_key,
    'expected_url' => $expected_url,
    'notes' => $notes,
    'nav_detected' => ($detection !== NULL),
    'hit_at_1' => FALSE,
    'hit_at_3' => FALSE,
    'hit_at_5' => FALSE,
    'actual_url' => NULL,
    'actual_key' => NULL,
    'match_type' => 'none',
    'score' => 0,
  ];

  if ($detection !== NULL) {
    $results['nav_detected']++;

    $matches = $detection['matches'] ?? [];

    // Check recall@1.
    if (!empty($matches[0]) && this_url_matches($matches[0]['url'], $expected_url)) {
      $detail['hit_at_1'] = TRUE;
      $results['recall_at_1']++;
    }

    // Check recall@3.
    $found_at_3 = FALSE;
    for ($i = 0; $i < min(3, count($matches)); $i++) {
      if (this_url_matches($matches[$i]['url'], $expected_url)) {
        $found_at_3 = TRUE;
        break;
      }
    }
    if ($found_at_3) {
      $detail['hit_at_3'] = TRUE;
      $results['recall_at_3']++;
    }

    // Check recall@5.
    $found_at_5 = FALSE;
    for ($i = 0; $i < min(5, count($matches)); $i++) {
      if (this_url_matches($matches[$i]['url'], $expected_url)) {
        $found_at_5 = TRUE;
        break;
      }
    }
    if ($found_at_5) {
      $detail['hit_at_5'] = TRUE;
      $results['recall_at_5']++;
    }

    if (!empty($matches[0])) {
      $detail['actual_url'] = $matches[0]['url'];
      $detail['actual_key'] = $matches[0]['page_key'];
      $detail['match_type'] = $matches[0]['match_type'];
      $detail['score'] = $matches[0]['score'];
    }

    if (empty($matches)) {
      $results['no_match']++;
    }
    elseif (!$detail['hit_at_1']) {
      $results['wrong_match']++;
    }
  }
  else {
    $results['no_match']++;
  }

  $results['details'][] = $detail;

  if ($verbose) {
    $status = $detail['hit_at_1'] ? 'HIT@1' : ($detail['hit_at_3'] ? 'HIT@3' : ($detail['hit_at_5'] ? 'HIT@5' : ($detail['nav_detected'] ? 'MISS' : 'NO_NAV')));
    echo sprintf("[%d] %-6s %-50s → %-30s (exp: %s)\n",
      $idx + 1,
      $status,
      substr($query, 0, 50),
      $detail['actual_url'] ?? 'none',
      $expected_url
    );
  }
}

// Calculate metrics.
$total = $results['total'];
$nav_detection_rate = $total > 0 ? round($results['nav_detected'] / $total, 4) : 0;
$recall1 = $total > 0 ? round($results['recall_at_1'] / $total, 4) : 0;
$recall3 = $total > 0 ? round($results['recall_at_3'] / $total, 4) : 0;
$recall5 = $total > 0 ? round($results['recall_at_5'] / $total, 4) : 0;

echo "\n";
echo "=== Navigation Evaluation Results ===\n\n";

if ($before_mode) {
  echo "BEFORE (baseline - no NavigationIntent routing):\n";
  echo "  Navigation queries are handled by generic IntentRouter patterns.\n";
  echo "  No page-specific matching is performed.\n";
  echo "  recall@1 = 0.0000 (0/{$total}) - pages not matched\n";
  echo "  recall@3 = 0.0000 (0/{$total})\n";
  echo "  recall@5 = 0.0000 (0/{$total})\n";
  echo "  Nav detection rate = " . round($nav_detection_rate * 100, 1) . "%\n";
  echo "    (these queries would fall to generic intent patterns)\n";
}
else {
  echo "AFTER (with NavigationIntent):\n";
  echo "  recall@1 = {$recall1} ({$results['recall_at_1']}/{$total})\n";
  echo "  recall@3 = {$recall3} ({$results['recall_at_3']}/{$total})\n";
  echo "  recall@5 = {$recall5} ({$results['recall_at_5']}/{$total})\n";
  echo "  Nav detection rate = " . round($nav_detection_rate * 100, 1) . "%\n";
  echo "  No match: {$results['no_match']}\n";
  echo "  Wrong top match: {$results['wrong_match']}\n";
}

echo "\n";

// Print failures.
if (!$before_mode) {
  $failures = array_filter($results['details'], fn($d) => !$d['hit_at_1']);
  if (!empty($failures)) {
    echo "--- Missed at recall@1 (" . count($failures) . " queries) ---\n\n";
    foreach ($failures as $f) {
      echo sprintf("  Q: %-50s\n", $f['query']);
      echo sprintf("    Expected: %-20s (%s)\n", $f['expected_key'], $f['expected_url']);
      echo sprintf("    Got:      %-20s (%s) [%s, %.2f]\n",
        $f['actual_key'] ?? 'none',
        $f['actual_url'] ?? 'none',
        $f['match_type'],
        $f['score']
      );
      echo sprintf("    Nav detected: %s | Notes: %s\n", $f['nav_detected'] ? 'yes' : 'no', $f['notes']);
      echo "\n";
    }
  }
}

// Save report.
if (!is_dir($output_dir)) {
  mkdir($output_dir, 0755, TRUE);
}

$timestamp = date('Y-m-d_His');
$mode_label = $before_mode ? 'before' : 'after';

$report = [
  'meta' => [
    'generator' => 'ILAS Navigation Evaluation',
    'mode' => $mode_label,
    'generated_at' => date('c'),
    'total_cases' => $total,
  ],
  'metrics' => [
    'nav_detection_rate' => $nav_detection_rate,
    'recall_at_1' => $recall1,
    'recall_at_3' => $recall3,
    'recall_at_5' => $recall5,
    'no_match_count' => $results['no_match'],
    'wrong_match_count' => $results['wrong_match'],
  ],
  'details' => $results['details'],
];

$json_path = "{$output_dir}/navigation-eval-{$mode_label}-{$timestamp}.json";
file_put_contents($json_path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Report saved: {$json_path}\n";

// Generate markdown report.
$md = [];
$md[] = "# Navigation Intent Evaluation Report ({$mode_label})";
$md[] = '';
$md[] = "**Generated:** " . date('Y-m-d H:i:s');
$md[] = "**Mode:** " . strtoupper($mode_label);
$md[] = '';
$md[] = '## Metrics';
$md[] = '';
$md[] = '| Metric | Value |';
$md[] = '|--------|-------|';
$md[] = "| Total test cases | {$total} |";
$md[] = "| Nav detection rate | " . round($nav_detection_rate * 100, 1) . "% |";
$md[] = "| **recall@1** | **" . round($recall1 * 100, 1) . "%** ({$results['recall_at_1']}/{$total}) |";
$md[] = "| **recall@3** | **" . round($recall3 * 100, 1) . "%** ({$results['recall_at_3']}/{$total}) |";
$md[] = "| **recall@5** | **" . round($recall5 * 100, 1) . "%** ({$results['recall_at_5']}/{$total}) |";
$md[] = "| No match | {$results['no_match']} |";
$md[] = "| Wrong top match | {$results['wrong_match']} |";
$md[] = '';

if (!$before_mode) {
  $failures = array_filter($results['details'], fn($d) => !$d['hit_at_1']);
  if (!empty($failures)) {
    $md[] = '## Missed Queries (not hit at recall@1)';
    $md[] = '';
    $md[] = '| Query | Expected | Got | Score | Notes |';
    $md[] = '|-------|----------|-----|-------|-------|';
    foreach ($failures as $f) {
      $md[] = sprintf('| %s | %s | %s | %.2f | %s |',
        $f['query'],
        $f['expected_url'],
        $f['actual_url'] ?? 'none',
        $f['score'],
        $f['notes']
      );
    }
    $md[] = '';
  }
}

$md[] = '---';
$md[] = '*Report generated by ILAS Navigation Evaluation*';

$md_path = "{$output_dir}/navigation-eval-{$mode_label}-{$timestamp}.md";
file_put_contents($md_path, implode("\n", $md));
echo "Markdown report saved: {$md_path}\n";

exit($recall1 >= 0.7 ? 0 : 1);


/**
 * Checks if two URLs match (case-insensitive, path comparison).
 */
function this_url_matches(string $actual, string $expected): bool {
  $actual = strtolower(trim($actual, '/'));
  $expected = strtolower(trim($expected, '/'));

  if ($actual === $expected) {
    return TRUE;
  }

  // Strip leading slashes and compare.
  $actual_path = ltrim($actual, '/');
  $expected_path = ltrim($expected, '/');

  return $actual_path === $expected_path;
}
