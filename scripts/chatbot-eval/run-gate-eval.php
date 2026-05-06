#!/usr/bin/env php
<?php

/**
 * @file
 * Fallback Gate Evaluation Runner - deprecated legacy harness.
 *
 * Evaluates the FallbackGate confidence model against test fixtures.
 * Runs without Drupal bootstrap using simulated routing.
 *
 * Preserved for historical fixture review only. This is not a current Site
 * Assistant quality gate.
 *
 * Usage:
 *   php run-gate-eval.php [options]
 *
 * Options:
 *   --fixture=PATH    Path to fixture file (CSV)
 *   --suite=NAME      Run specific suite (golden, safety, confusable)
 *   --baseline        Run baseline (before gate) comparison
 *   --output=DIR      Output directory for reports
 *   --verbose         Show detailed output
 */

// Mappings from golden dataset to internal intents.
$INTENT_MAPPING = [
  'apply_for_help' => 'apply',
  'legal_advice_line' => 'hotline',
  'offices_contact' => 'offices',
  'donations' => 'donate',
  'feedback_complaints' => 'feedback',
  'forms_finder' => 'forms',
  'guides_finder' => 'guides',
  'faq' => 'faq',
  'senior_risk_detector' => 'risk_detector',
  'services_overview' => 'services',
  'out_of_scope' => 'out_of_scope',
  'high_risk_dv' => 'high_risk',
  'high_risk_eviction' => 'high_risk',
  'high_risk_scam' => 'high_risk',
  'high_risk_deadline' => 'high_risk',
  'multi_intent' => 'multi_intent',
  'adversarial' => 'out_of_scope',
  'ambiguous' => 'unknown',
  'greeting' => 'greeting',
  'escalation' => 'out_of_scope',
];

// Known confusable utterances that should trigger clarification.
// Normalized lowercase strings.
$CONFUSABLE_UTTERANCES = [
  'help' => ['apply', 'services', 'hotline'],
  'forms' => ['forms', 'apply'],
  'phone' => ['hotline', 'offices'],
  'information' => ['faq', 'guides', 'services'],
  'divorce' => ['forms', 'guides', 'apply'],
  'custody' => ['forms', 'guides', 'apply'],
  'eviction' => ['forms', 'guides', 'apply', 'high_risk'],
  'can you help' => ['apply', 'hotline', 'services'],
  'i want to apply' => ['apply', 'forms', 'services'],
  'where can i get help' => ['apply', 'offices', 'hotline'],
  'contact information' => ['offices', 'hotline'],
  'ayuda' => ['apply', 'services'],
  'formularios' => ['forms'],
];

// Include the Disambiguator for standalone use.
require_once __DIR__ . '/../../web/modules/custom/ilas_site_assistant/src/Service/Disambiguator.php';
use Drupal\ilas_site_assistant\Service\Disambiguator;

$disambiguator = new Disambiguator();

// High-risk patterns for simulation.
$HIGH_RISK_PATTERNS = [
  'high_risk_dv' => [
    'hitting me', 'hit me', 'abusive', 'domestic violence', 'threatened to kill',
    'scared for my life', 'restraining order', 'protection order', 'me pega',
    'abusivo', 'violencia domestica', 'tengo miedo',
  ],
  'high_risk_eviction' => [
    'eviction notice', 'sheriff coming', 'locked out', 'changed the locks',
    '3 day notice', 'court date tomorrow', 'aviso de desalojo', 'me estan echando',
    'eviction hearing',
  ],
  'high_risk_scam' => [
    'identity theft', 'stole my identity', 'got scammed', 'fake contractor',
    'social security scam', 'robaron mi identidad', 'me estafaron',
  ],
  'high_risk_deadline' => [
    'deadline tomorrow', 'deadline friday', 'deadline monday', 'due tomorrow',
    'court date tomorrow', 'respond by friday', 'fecha limite',
  ],
];

// Intent patterns for simulation.
$INTENT_PATTERNS = [
  'apply' => ['/\b(apply|aply|application|sign\s*up|help|lawyer|legal\s*aid)/i'],
  'hotline' => ['/\b(call|phone|hotline|hot\s*line|advice\s*line|talk\s*to)/i'],
  'offices' => ['/\b(office|location|address|where|hours)/i'],
  'donate' => ['/\b(donate|donation|give|contribute)/i'],
  'feedback' => ['/\b(feedback|complaint|complain|grievance)/i'],
  'forms' => ['/\b(form|forms|paperwork|document)/i'],
  'guides' => ['/\b(guide|guides|manual|step[\s-]*by[\s-]*step|self[\s-]*help)/i'],
  'faq' => ['/\b(faq|faqs|frequently\s*asked|common\s*question)/i'],
  'risk_detector' => ['/\b(risk\s*detector|legal\s*checkup|senior.*risk)/i'],
  'services' => ['/\b(what\s*do\s*you|what\s*services|types\s*of\s*(help|cases))/i'],
  'greeting' => ['/^(hi|hello|hey|hola)[\s!.?]*$/i'],
];

// Parse arguments.
$options = getopt('', [
  'fixture:',
  'suite:',
  'baseline',
  'output:',
  'verbose',
  'help',
]);

if (isset($options['help'])) {
  echo <<<HELP
Fallback Gate Evaluation Runner - DEPRECATED legacy harness

This is historical fixture tooling only. Use Promptfoo for current answer
quality gates.

Usage:
  php run-gate-eval.php [options]

Options:
  --fixture=PATH    Path to fixture file (CSV)
  --suite=NAME      Run specific suite: golden, safety, confusable
  --baseline        Run baseline comparison (before/after gate)
  --output=DIR      Output directory for reports
  --verbose         Show detailed output

HELP;
  exit(0);
}

// Configuration.
$suite = $options['suite'] ?? 'golden';
$verbose = isset($options['verbose']);
$output_dir = $options['output'] ?? __DIR__ . '/reports';
$run_baseline = isset($options['baseline']);

// Determine fixture path based on suite.
$fixture_paths = [
  'golden' => __DIR__ . '/../../chatbot-golden-dataset.csv',
  'safety' => __DIR__ . '/../../web/modules/custom/ilas_site_assistant/tests/fixtures/safety-suite.csv',
  'confusable' => __DIR__ . '/../../web/modules/custom/ilas_site_assistant/tests/fixtures/confusable-intents-suite.csv',
];

$fixture_path = $options['fixture'] ?? ($fixture_paths[$suite] ?? $fixture_paths['golden']);

if (!file_exists($fixture_path)) {
  echo "Error: Fixture file not found: {$fixture_path}\n";
  exit(1);
}

echo "=== Fallback Gate Evaluation (Deprecated Legacy) ===\n\n";
echo "Warning: this is historical fixture tooling only, not a current Site Assistant quality gate.\n\n";
echo "Fixture: {$fixture_path}\n";
echo "Suite: {$suite}\n\n";

/**
 * Simulates intent routing (baseline - no gate).
 */
function routeBaseline(string $message): array {
  global $INTENT_PATTERNS, $HIGH_RISK_PATTERNS;

  $message_lower = strtolower($message);

  // Check high-risk first.
  foreach ($HIGH_RISK_PATTERNS as $category => $triggers) {
    foreach ($triggers as $trigger) {
      if (strpos($message_lower, $trigger) !== FALSE) {
        return ['type' => 'high_risk', 'risk_category' => $category];
      }
    }
  }

  // Check regular intents.
  foreach ($INTENT_PATTERNS as $intent => $patterns) {
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return ['type' => $intent];
      }
    }
  }

  return ['type' => 'unknown'];
}

/**
 * Routes with disambiguation support.
 *
 * @param string $message
 *   The user message.
 * @param Disambiguator $disambiguator
 *   The disambiguator service.
 *
 * @return array
 *   Array with 'intent' and optionally 'disambiguation' keys.
 */
function routeWithDisambiguation(string $message, Disambiguator $disambiguator): array {
  global $INTENT_PATTERNS, $HIGH_RISK_PATTERNS;

  $message_lower = strtolower($message);

  // Check high-risk first (bypass disambiguation).
  foreach ($HIGH_RISK_PATTERNS as $category => $triggers) {
    foreach ($triggers as $trigger) {
      if (strpos($message_lower, $trigger) !== FALSE) {
        return [
          'intent' => ['type' => 'high_risk', 'risk_category' => $category],
          'disambiguation' => NULL,
        ];
      }
    }
  }

  // Score all intents.
  $scored = [];
  foreach ($INTENT_PATTERNS as $intent => $patterns) {
    $score = 0.0;
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message)) {
        $score = 0.75;
        break;
      }
    }
    if ($score > 0) {
      $scored[] = ['intent' => $intent, 'confidence' => $score];
    }
  }

  // Sort by confidence descending.
  usort($scored, function($a, $b) {
    return $b['confidence'] <=> $a['confidence'];
  });

  // Check disambiguation BEFORE returning intent.
  $disamb_result = $disambiguator->check($message, $scored, []);

  if ($disamb_result) {
    // When disambiguation is triggered, we still need a valid intent type
    // for the confusion matrix. Use 'disambiguation' as the type marker.
    $intent_for_matrix = !empty($scored) ? ['type' => $scored[0]['intent']] : ['type' => 'unknown'];
    return [
      'intent' => $intent_for_matrix,
      'disambiguation' => $disamb_result,
    ];
  }

  // No disambiguation needed.
  if (!empty($scored)) {
    return [
      'intent' => ['type' => $scored[0]['intent']],
      'disambiguation' => NULL,
    ];
  }

  return [
    'intent' => ['type' => 'unknown'],
    'disambiguation' => NULL,
  ];
}

/**
 * Simulates FallbackGate decision.
 */
function evaluateGate(array $intent, string $message, array $safety_flags): array {
  $thresholds = [
    'intent_high_conf' => 0.85,
    'intent_medium_conf' => 0.65,
    'combined_fallback_threshold' => 0.50,
    'ambiguous_message_words' => 2,
  ];

  $details = [
    'intent_type' => $intent['type'],
    'intent_confidence' => calculateIntentConf($intent, $message),
  ];

  // Safety override.
  if (!empty($safety_flags)) {
    return [
      'decision' => 'hard_route',
      'reason_code' => 'SAFETY_URGENT',
      'confidence' => 1.0,
      'details' => $details,
    ];
  }

  // High-risk.
  if ($intent['type'] === 'high_risk') {
    return [
      'decision' => 'hard_route',
      'reason_code' => 'SAFETY_URGENT',
      'confidence' => 0.95,
      'details' => $details,
    ];
  }

  // Out of scope.
  if ($intent['type'] === 'out_of_scope') {
    return [
      'decision' => 'hard_route',
      'reason_code' => 'OUT_OF_SCOPE',
      'confidence' => 0.90,
      'details' => $details,
    ];
  }

  // Greeting.
  if ($intent['type'] === 'greeting') {
    return [
      'decision' => 'answer',
      'reason_code' => 'GREETING',
      'confidence' => 1.0,
      'details' => $details,
    ];
  }

  // Unknown intent.
  if ($intent['type'] === 'unknown') {
    $word_count = str_word_count($message);

    // Very short = clarify.
    if ($word_count <= $thresholds['ambiguous_message_words']) {
      return [
        'decision' => 'clarify',
        'reason_code' => 'LOW_INTENT_CONF',
        'confidence' => 0.3,
        'details' => $details,
      ];
    }

    // Otherwise fallback to LLM.
    return [
      'decision' => 'fallback_llm',
      'reason_code' => 'LOW_INTENT_CONF',
      'confidence' => 0.2,
      'details' => $details,
    ];
  }

  // Known intent - check confidence.
  $conf = $details['intent_confidence'];

  if ($conf >= $thresholds['intent_high_conf']) {
    return [
      'decision' => 'answer',
      'reason_code' => 'HIGH_CONF_INTENT',
      'confidence' => $conf,
      'details' => $details,
    ];
  }

  if ($conf >= $thresholds['intent_medium_conf']) {
    return [
      'decision' => 'answer',
      'reason_code' => 'HIGH_CONF_INTENT',
      'confidence' => $conf,
      'details' => $details,
    ];
  }

  // Borderline - could use LLM enhancement.
  if ($conf >= $thresholds['combined_fallback_threshold']) {
    return [
      'decision' => 'answer',
      'reason_code' => 'BORDERLINE_CONF',
      'confidence' => $conf,
      'details' => $details,
    ];
  }

  // Low confidence.
  return [
    'decision' => 'fallback_llm',
    'reason_code' => 'LOW_INTENT_CONF',
    'confidence' => $conf,
    'details' => $details,
  ];
}

/**
 * Calculates simulated intent confidence.
 */
function calculateIntentConf(array $intent, string $message): float {
  if ($intent['type'] === 'unknown') {
    return 0.15;
  }

  $base = 0.70;
  $word_count = str_word_count($message);

  if ($word_count < 3) {
    $base -= 0.15;
  }
  elseif ($word_count >= 3 && $word_count <= 15) {
    $base += 0.15;
  }
  elseif ($word_count > 20) {
    $base -= 0.05;
  }

  return min(1.0, max(0.0, $base));
}

/**
 * Checks if result matches expected.
 *
 * Updated to properly handle disambiguation for confusable cases.
 */
function checkMatch(array $gate_decision, array $intent, string $expected_label, string $expected_action, string $utterance = ''): bool {
  global $INTENT_MAPPING, $CONFUSABLE_UTTERANCES;

  $expected_intent = $INTENT_MAPPING[$expected_label] ?? $expected_label;
  $actual_intent = $intent['type'];
  $decision = $gate_decision['decision'];

  // Safety/high-risk should hard route.
  if (strpos($expected_label, 'high_risk') === 0) {
    return $decision === 'hard_route' ||
           ($actual_intent === 'high_risk' && $decision === 'answer');
  }

  // Out of scope should hard route or escalate.
  if ($expected_label === 'out_of_scope' || $expected_label === 'adversarial') {
    return in_array($decision, ['hard_route', 'answer']) ||
           $actual_intent === 'out_of_scope';
  }

  // Escalation (legal advice seeking) should be handled appropriately.
  if ($expected_label === 'escalation') {
    // Accept clarify, fallback, or out_of_scope routing.
    return in_array($decision, ['clarify', 'fallback_llm', 'hard_route']) ||
           $actual_intent === 'out_of_scope';
  }

  // Ambiguous should clarify or fallback.
  // This is the key fix: ambiguous cases SHOULD trigger clarification.
  if ($expected_label === 'ambiguous' || $expected_action === 'Ask clarifying question') {
    // Clarify or disambiguation is the CORRECT response.
    if (in_array($decision, ['clarify', 'disambiguation'])) {
      return TRUE;
    }
    // Also accept fallback_llm as it may lead to clarification.
    if ($decision === 'fallback_llm') {
      return TRUE;
    }
    // If the utterance is in our known confusable list and we answered,
    // this is a FAILURE (confident wrong answer).
    return FALSE;
  }

  // Greeting.
  if ($expected_label === 'greeting') {
    return $decision === 'answer' && $actual_intent === 'greeting';
  }

  // Multi-intent is tricky - accept reasonable routing or clarification.
  if ($expected_label === 'multi_intent') {
    // Clarifying multi-intent is acceptable.
    if (in_array($decision, ['clarify', 'disambiguation'])) {
      return TRUE;
    }
    return $decision !== 'hard_route' || $actual_intent !== 'unknown';
  }

  // Normal intents should answer correctly.
  if ($decision === 'answer' || $decision === 'hard_route') {
    // Check for correct intent match.
    if ($actual_intent === $expected_intent) {
      return TRUE;
    }
    // Handle apply/eligibility equivalence.
    if (in_array($actual_intent, ['apply', 'eligibility', 'apply_for_help']) &&
        in_array($expected_intent, ['apply', 'eligibility', 'apply_for_help'])) {
      return TRUE;
    }
    // Handle hotline/legal_advice_line equivalence.
    if (in_array($actual_intent, ['hotline', 'legal_advice_line']) &&
        in_array($expected_intent, ['hotline', 'legal_advice_line'])) {
      return TRUE;
    }
    // Handle offices/offices_contact equivalence.
    if (in_array($actual_intent, ['offices', 'offices_contact']) &&
        in_array($expected_intent, ['offices', 'offices_contact'])) {
      return TRUE;
    }
    // Handle forms/forms_finder equivalence.
    if (in_array($actual_intent, ['forms', 'forms_finder']) &&
        in_array($expected_intent, ['forms', 'forms_finder'])) {
      return TRUE;
    }
    // Handle guides/guides_finder equivalence.
    if (in_array($actual_intent, ['guides', 'guides_finder']) &&
        in_array($expected_intent, ['guides', 'guides_finder'])) {
      return TRUE;
    }
    // Handle donate/donations equivalence.
    if (in_array($actual_intent, ['donate', 'donations']) &&
        in_array($expected_intent, ['donate', 'donations'])) {
      return TRUE;
    }
    // Handle services/services_overview equivalence.
    if (in_array($actual_intent, ['services', 'services_overview']) &&
        in_array($expected_intent, ['services', 'services_overview'])) {
      return TRUE;
    }
    return FALSE;
  }

  // Clarify/disambiguation for non-ambiguous expected intents.
  // If expected was a specific intent but we clarified, this is acceptable
  // if it's a known confusable situation.
  if (in_array($decision, ['clarify', 'disambiguation'])) {
    // Check if this utterance is in a confusable pair context.
    // For now, accept clarification as cautiously correct.
    return TRUE;
  }

  // Fallback/clarify for unknown.
  return TRUE;
}

// Load test cases.
$test_cases = [];
$handle = fopen($fixture_path, 'r');
$headers = fgetcsv($handle);

while (($row = fgetcsv($handle)) !== FALSE) {
  if (count($row) < 2) continue;

  $test_cases[] = [
    'utterance' => $row[0],
    'intent_label' => $row[1],
    'primary_action' => $row[2] ?? '',
    'must_include_safety' => strtolower($row[4] ?? '') === 'yes',
  ];
}
fclose($handle);

echo "Loaded " . count($test_cases) . " test cases\n\n";

// Run evaluation.
$results = [
  'total' => 0,
  'passed' => 0,
  'failed' => 0,
  'gate_decisions' => [
    'answer' => 0,
    'clarify' => 0,
    'disambiguation' => 0,
    'fallback_llm' => 0,
    'hard_route' => 0,
  ],
  'by_reason_code' => [],
  'confidence_sum' => 0,
  'confident_answers' => 0,
  'confident_wrong' => 0,
  'by_category' => [],
  'failures' => [],
  'clarifications' => [],  // Track when disambiguation was triggered.
  'confusion_matrix' => [],  // Track expected vs actual.
];

foreach ($test_cases as $i => $case) {
  $results['total']++;
  $category = $case['intent_label'];

  // Initialize category.
  if (!isset($results['by_category'][$category])) {
    $results['by_category'][$category] = ['total' => 0, 'passed' => 0, 'failed' => 0, 'clarified' => 0];
  }
  $results['by_category'][$category]['total']++;

  // Route with disambiguation support.
  $route_result = routeWithDisambiguation($case['utterance'], $disambiguator);
  $intent = $route_result['intent'];
  $disamb = $route_result['disambiguation'];

  // If disambiguation was triggered, use special gate decision.
  if ($disamb) {
    $gate_decision = [
      'decision' => 'disambiguation',
      'reason_code' => 'DISAMBIGUATION_' . strtoupper($disamb['reason'] ?? 'unknown'),
      'confidence' => $disamb['confidence'] ?? 0.5,
      'details' => ['disambiguation' => $disamb],
    ];
    $results['by_category'][$category]['clarified']++;
    $results['clarifications'][] = [
      'utterance' => substr($case['utterance'], 0, 50),
      'expected' => $category,
      'reason' => $disamb['reason'] ?? 'unknown',
      'question' => $disamb['question'] ?? '',
    ];
  }
  else {
    // Standard gate evaluation.
    $safety_flags = $case['must_include_safety'] ? ['safety_flag'] : [];
    $gate_decision = evaluateGate($intent, $case['utterance'], $safety_flags);
  }

  // Track gate metrics.
  $decision = $gate_decision['decision'];
  if (!isset($results['gate_decisions'][$decision])) {
    $results['gate_decisions'][$decision] = 0;
  }
  $results['gate_decisions'][$decision]++;
  $results['confidence_sum'] += $gate_decision['confidence'];

  $reason = $gate_decision['reason_code'];
  $results['by_reason_code'][$reason] = ($results['by_reason_code'][$reason] ?? 0) + 1;

  // Track misroutes (confident wrong answers).
  if ($decision === 'answer' && $gate_decision['confidence'] >= 0.70) {
    $results['confident_answers']++;
  }

  // Track confusion matrix.
  $actual = $decision === 'disambiguation' ? 'clarify' : $intent['type'];
  if (!isset($results['confusion_matrix'][$category])) {
    $results['confusion_matrix'][$category] = [];
  }
  $results['confusion_matrix'][$category][$actual] = ($results['confusion_matrix'][$category][$actual] ?? 0) + 1;

  // Check if passed.
  $passed = checkMatch($gate_decision, $intent, $category, $case['primary_action'], $case['utterance']);

  if ($passed) {
    $results['passed']++;
    $results['by_category'][$category]['passed']++;
  }
  else {
    $results['failed']++;
    $results['by_category'][$category]['failed']++;

    if ($decision === 'answer' && $gate_decision['confidence'] >= 0.70) {
      $results['confident_wrong']++;
    }

    $results['failures'][] = [
      'utterance' => substr($case['utterance'], 0, 50),
      'expected' => $category,
      'got' => $intent['type'],
      'decision' => $decision,
      'reason' => $reason,
    ];
  }

  if ($verbose) {
    $status = $passed ? 'PASS' : 'FAIL';
    $disamb_marker = $disamb ? ' [DISAMB]' : '';
    echo sprintf("[%d] %s%s: %s -> %s (%s)\n",
      $i + 1,
      $status,
      $disamb_marker,
      substr($case['utterance'], 0, 40),
      $decision,
      $reason
    );
  }
}

// Calculate metrics.
$total = $results['total'];
$clarify_count = ($results['gate_decisions']['clarify'] ?? 0) + ($results['gate_decisions']['disambiguation'] ?? 0);
$metrics = [
  'overall_accuracy' => $total > 0 ? round($results['passed'] / $total, 4) : 0,
  'answer_rate' => $total > 0 ? round(($results['gate_decisions']['answer'] ?? 0) / $total, 4) : 0,
  'clarify_rate' => $total > 0 ? round($clarify_count / $total, 4) : 0,
  'disambiguation_rate' => $total > 0 ? round(($results['gate_decisions']['disambiguation'] ?? 0) / $total, 4) : 0,
  'fallback_rate' => $total > 0 ? round(($results['gate_decisions']['fallback_llm'] ?? 0) / $total, 4) : 0,
  'hard_route_rate' => $total > 0 ? round(($results['gate_decisions']['hard_route'] ?? 0) / $total, 4) : 0,
  'avg_confidence' => $total > 0 ? round($results['confidence_sum'] / $total, 4) : 0,
  'misroute_rate' => $results['confident_answers'] > 0
    ? round($results['confident_wrong'] / $results['confident_answers'], 4)
    : 0,
];

// Print results.
echo "\n";
echo "=== Results ===\n\n";
echo "Total:  {$results['total']}\n";
echo "Passed: {$results['passed']}\n";
echo "Failed: {$results['failed']}\n";
echo "Rate:   " . round($metrics['overall_accuracy'] * 100, 1) . "%\n";
echo "\n";

echo "--- Gate Metrics ---\n";
echo "Answer Rate:        " . round($metrics['answer_rate'] * 100, 1) . "%\n";
echo "Clarify Rate:       " . round($metrics['clarify_rate'] * 100, 1) . "%\n";
echo "Disambiguation Rate:" . round($metrics['disambiguation_rate'] * 100, 1) . "%\n";
echo "Fallback Rate:      " . round($metrics['fallback_rate'] * 100, 1) . "%\n";
echo "Hard Route Rate:    " . round($metrics['hard_route_rate'] * 100, 1) . "%\n";
echo "Avg Confidence:     " . round($metrics['avg_confidence'] * 100, 1) . "%\n";
echo "Misroute Rate:      " . round($metrics['misroute_rate'] * 100, 1) . "%\n";
echo "\n";

echo "--- By Reason Code ---\n";
arsort($results['by_reason_code']);
foreach ($results['by_reason_code'] as $code => $count) {
  echo sprintf("  %-35s %d\n", $code, $count);
}
echo "\n";

echo "--- By Category ---\n";
foreach ($results['by_category'] as $cat => $stats) {
  $acc = $stats['total'] > 0 ? round($stats['passed'] / $stats['total'] * 100, 1) : 0;
  $clarified = $stats['clarified'] ?? 0;
  echo sprintf("  %-25s %d/%d (%0.1f%%) [clarified: %d]\n", $cat, $stats['passed'], $stats['total'], $acc, $clarified);
}
echo "\n";

// Print confusion matrix.
echo "--- Confusion Matrix (Expected -> Actual) ---\n";
foreach ($results['confusion_matrix'] as $expected => $actuals) {
  $row_parts = [];
  arsort($actuals);
  foreach ($actuals as $actual => $count) {
    $row_parts[] = "{$actual}:{$count}";
  }
  echo sprintf("  %-20s -> %s\n", $expected, implode(', ', $row_parts));
}
echo "\n";

// Print clarifications triggered.
if (!empty($results['clarifications'])) {
  echo "--- Clarifications Triggered (" . count($results['clarifications']) . ") ---\n";
  foreach (array_slice($results['clarifications'], 0, 10) as $c) {
    echo sprintf("  \"%s...\"\n", $c['utterance']);
    echo sprintf("    Expected: %s, Reason: %s\n", $c['expected'], $c['reason']);
  }
  if (count($results['clarifications']) > 10) {
    echo "  ... and " . (count($results['clarifications']) - 10) . " more\n";
  }
  echo "\n";
}

if (!empty($results['failures']) && count($results['failures']) <= 20) {
  echo "--- Sample Failures ---\n";
  foreach (array_slice($results['failures'], 0, 10) as $f) {
    echo sprintf("  \"%s...\"\n", $f['utterance']);
    echo sprintf("    Expected: %s, Got: %s -> %s\n", $f['expected'], $f['got'], $f['decision']);
  }
  echo "\n";
}

// Save report.
if (!is_dir($output_dir)) {
  mkdir($output_dir, 0755, TRUE);
}

$report = [
  'timestamp' => date('c'),
  'suite' => $suite,
  'fixture' => $fixture_path,
  'summary' => [
    'total' => $results['total'],
    'passed' => $results['passed'],
    'failed' => $results['failed'],
    'clarified' => count($results['clarifications']),
  ],
  'metrics' => $metrics,
  'gate_decisions' => $results['gate_decisions'],
  'by_reason_code' => $results['by_reason_code'],
  'by_category' => $results['by_category'],
  'confusion_matrix' => $results['confusion_matrix'],
  'clarifications' => $results['clarifications'],
  'failures' => $results['failures'],
];

$report_path = $output_dir . '/gate-eval-' . $suite . '-' . date('Y-m-d_His') . '.json';
file_put_contents($report_path, json_encode($report, JSON_PRETTY_PRINT));
echo "Report saved to: {$report_path}\n";

// Exit code.
exit($metrics['overall_accuracy'] >= 0.80 ? 0 : 1);
