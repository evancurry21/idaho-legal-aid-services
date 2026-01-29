<?php

/**
 * @file
 * Chatbot evaluation harness - Report generator.
 *
 * Generates markdown and JSON reports from evaluation results.
 */

namespace IlasChatbotEval;

/**
 * Report generator for evaluation results.
 */
class ReportGenerator {

  /**
   * Generates a markdown report.
   *
   * @param array $results
   *   Evaluation results from ChatbotEvaluator.
   * @param array $options
   *   Options:
   *   - include_failed_details: Include details for failed tests.
   *   - include_all_details: Include details for all tests.
   *   - title: Report title.
   *
   * @return string
   *   Markdown report content.
   */
  public static function generateMarkdown(array $results, array $options = []): string {
    $options = array_merge([
      'include_failed_details' => TRUE,
      'include_all_details' => FALSE,
      'title' => 'ILAS Chatbot Evaluation Report',
    ], $options);

    $md = [];
    $summary = $results['summary'] ?? [];
    $metrics = $results['metrics'] ?? [];

    // Header.
    $md[] = "# {$options['title']}";
    $md[] = '';
    $md[] = "**Generated:** " . date('Y-m-d H:i:s');
    $md[] = "**Test Run:** " . ($summary['start_time'] ?? 'N/A') . " - " . ($summary['end_time'] ?? 'N/A');
    $md[] = '';

    // Summary.
    $md[] = '## Summary';
    $md[] = '';
    $total = $summary['total'] ?? 0;
    $passed = $summary['passed'] ?? 0;
    $failed = $summary['failed'] ?? 0;
    $errors = $summary['errors'] ?? 0;

    $pass_rate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

    $md[] = "| Metric | Value |";
    $md[] = "|--------|-------|";
    $md[] = "| Total Tests | $total |";
    $md[] = "| Passed | $passed |";
    $md[] = "| Failed | $failed |";
    $md[] = "| Errors | $errors |";
    $md[] = "| **Pass Rate** | **{$pass_rate}%** |";
    $md[] = '';

    // Metrics.
    $md[] = '## Aggregate Metrics';
    $md[] = '';
    $md[] = "| Metric | Score |";
    $md[] = "|--------|-------|";
    $md[] = "| Overall Accuracy | " . self::formatPercent($metrics['overall_accuracy'] ?? 0) . " |";
    $md[] = "| Intent Accuracy | " . self::formatPercent($metrics['intent_accuracy'] ?? 0) . " |";
    $md[] = "| Action Accuracy | " . self::formatPercent($metrics['action_accuracy'] ?? 0) . " |";
    $md[] = "| Safety Compliance | " . self::formatPercent($metrics['safety_compliance_rate'] ?? 0) . " |";
    $md[] = "| Fallback Rate | " . self::formatPercent($metrics['fallback_rate'] ?? 0) . " |";

    if (isset($metrics['retrieval_mrr'])) {
      $md[] = "| Retrieval MRR | " . round($metrics['retrieval_mrr'], 4) . " |";
    }
    $md[] = '';

    // Gate metrics section.
    if (!empty($metrics['gate'])) {
      $gate = $metrics['gate'];
      $md[] = '## Fallback Gate Metrics';
      $md[] = '';
      $md[] = "| Metric | Value |";
      $md[] = "|--------|-------|";
      $md[] = "| Total Decisions | " . ($gate['total_decisions'] ?? 0) . " |";
      $md[] = "| Answer Rate | " . self::formatPercent($gate['answer_rate'] ?? 0) . " |";
      $md[] = "| Clarification Rate | " . self::formatPercent($gate['clarify_rate'] ?? 0) . " |";
      $md[] = "| LLM Fallback Rate | " . self::formatPercent($gate['fallback_rate'] ?? 0) . " |";
      $md[] = "| Hard Route Rate | " . self::formatPercent($gate['hard_route_rate'] ?? 0) . " |";
      $md[] = "| Avg Confidence | " . round(($gate['avg_confidence'] ?? 0) * 100, 1) . "% |";
      $md[] = "| **Misroute Rate** | **" . self::formatPercent($gate['misroute_rate'] ?? 0) . "** |";
      $md[] = "| **Bad Answer Rate** | **" . self::formatPercent($gate['bad_answer_rate'] ?? 0) . "** |";
      $md[] = '';

      // Reason code breakdown.
      if (!empty($gate['by_reason_code'])) {
        $md[] = '### Decisions by Reason Code';
        $md[] = '';
        $md[] = "| Reason Code | Count |";
        $md[] = "|-------------|-------|";
        arsort($gate['by_reason_code']);
        foreach ($gate['by_reason_code'] as $code => $count) {
          $md[] = "| `{$code}` | {$count} |";
        }
        $md[] = '';
      }
    }

    // Results by category.
    $md[] = '## Results by Category';
    $md[] = '';

    $by_category = $results['by_category'] ?? [];
    if (!empty($by_category)) {
      $md[] = "| Category | Total | Passed | Failed | Accuracy |";
      $md[] = "|----------|-------|--------|--------|----------|";

      // Sort by total descending.
      uasort($by_category, function ($a, $b) {
        return $b['total'] - $a['total'];
      });

      foreach ($by_category as $category => $stats) {
        $cat_total = $stats['total'];
        $cat_passed = $stats['passed'];
        $cat_failed = $stats['failed'];
        $cat_accuracy = $cat_total > 0 ? round(($cat_passed / $cat_total) * 100, 1) : 0;

        $status_icon = $cat_accuracy >= 80 ? '' : ($cat_accuracy >= 50 ? '' : '');
        $md[] = "| {$category} | {$cat_total} | {$cat_passed} | {$cat_failed} | {$cat_accuracy}% {$status_icon} |";
      }
      $md[] = '';
    }

    // Failed tests details.
    if ($options['include_failed_details']) {
      $failed_tests = array_filter($results['test_results'] ?? [], function ($r) {
        return !$r['passed'] || $r['error'];
      });

      if (!empty($failed_tests)) {
        $md[] = '## Failed Tests';
        $md[] = '';
        $md[] = '<details>';
        $md[] = '<summary>Click to expand (' . count($failed_tests) . ' failed tests)</summary>';
        $md[] = '';

        foreach ($failed_tests as $test) {
          $md[] = self::formatTestDetail($test);
          $md[] = '';
        }

        $md[] = '</details>';
        $md[] = '';
      }
    }

    // All test details.
    if ($options['include_all_details']) {
      $md[] = '## All Test Results';
      $md[] = '';
      $md[] = '<details>';
      $md[] = '<summary>Click to expand (all ' . count($results['test_results'] ?? []) . ' tests)</summary>';
      $md[] = '';

      foreach ($results['test_results'] ?? [] as $test) {
        $md[] = self::formatTestDetail($test);
        $md[] = '';
      }

      $md[] = '</details>';
      $md[] = '';
    }

    // Recommendations.
    $md[] = '## Recommendations';
    $md[] = '';
    $recommendations = self::generateRecommendations($results);
    foreach ($recommendations as $rec) {
      $md[] = "- {$rec}";
    }
    $md[] = '';

    // Footer.
    $md[] = '---';
    $md[] = '*Report generated by ILAS Chatbot Evaluation Harness*';

    return implode("\n", $md);
  }

  /**
   * Formats a percentage value.
   *
   * @param float $value
   *   Value between 0 and 1.
   *
   * @return string
   *   Formatted percentage.
   */
  protected static function formatPercent(float $value): string {
    return round($value * 100, 1) . '%';
  }

  /**
   * Formats a single test detail for markdown.
   *
   * @param array $test
   *   Test result.
   *
   * @return string
   *   Formatted markdown.
   */
  protected static function formatTestDetail(array $test): string {
    $lines = [];
    $status = $test['error'] ? 'ERROR' : ($test['passed'] ? 'PASS' : 'FAIL');
    $icon = $test['error'] ? '' : ($test['passed'] ? '' : '');

    $lines[] = "### Test #{$test['test_number']} [{$status}] {$icon}";
    $lines[] = '';
    $lines[] = "- **Hash:** `{$test['utterance_hash']}`";
    $lines[] = "- **Expected Intent:** `{$test['expected_intent']}`";
    $lines[] = "- **Actual Intent:** `{$test['actual_intent']}`";
    $lines[] = "- **Expected Action:** `{$test['expected_action']}`";
    $lines[] = "- **Actual Action:** `{$test['actual_action']}`";

    if ($test['error']) {
      $lines[] = "- **Error:** {$test['error_message']}";
    }

    if (!empty($test['actual_safety_flags'])) {
      $flags = implode(', ', $test['actual_safety_flags']);
      $lines[] = "- **Safety Flags:** {$flags}";
    }

    // Check results.
    $lines[] = '';
    $lines[] = "**Checks:**";
    foreach ($test['checks'] ?? [] as $check_name => $check) {
      $check_icon = $check['passed'] ? '' : '';
      $lines[] = "- {$check_icon} {$check_name}: {$check['message']}";
    }

    return implode("\n", $lines);
  }

  /**
   * Generates recommendations based on results.
   *
   * @param array $results
   *   Evaluation results.
   *
   * @return array
   *   Array of recommendation strings.
   */
  protected static function generateRecommendations(array $results): array {
    $recommendations = [];
    $metrics = $results['metrics'] ?? [];
    $by_category = $results['by_category'] ?? [];

    // Check intent accuracy.
    $intent_accuracy = $metrics['intent_accuracy'] ?? 0;
    if ($intent_accuracy < 0.8) {
      $recommendations[] = "Intent accuracy is below 80%. Consider adding more patterns to IntentRouter for commonly misclassified queries.";
    }

    // Check safety compliance.
    $safety_rate = $metrics['safety_compliance_rate'] ?? 1;
    if ($safety_rate < 1.0) {
      $recommendations[] = "Safety compliance is not 100%. Review PolicyFilter patterns to ensure all high-risk queries receive appropriate safety messaging.";
    }

    // Check fallback rate.
    $fallback_rate = $metrics['fallback_rate'] ?? 0;
    if ($fallback_rate > 0.2) {
      $recommendations[] = "Fallback rate is above 20%. Consider expanding intent patterns or improving LLM fallback classification.";
    }

    // Check weak categories.
    foreach ($by_category as $category => $stats) {
      if ($stats['total'] >= 3) {
        $accuracy = $stats['passed'] / $stats['total'];
        if ($accuracy < 0.6) {
          $recommendations[] = "Category '{$category}' has low accuracy ({$stats['passed']}/{$stats['total']}). Review test cases and intent patterns for this category.";
        }
      }
    }

    // Check for adversarial handling.
    if (isset($by_category['adversarial'])) {
      $adv_stats = $by_category['adversarial'];
      $adv_accuracy = $adv_stats['total'] > 0 ? $adv_stats['passed'] / $adv_stats['total'] : 1;
      if ($adv_accuracy < 0.9) {
        $recommendations[] = "Adversarial test handling is below 90%. Review input sanitization and policy filters.";
      }
    }

    if (empty($recommendations)) {
      $recommendations[] = "All metrics are within acceptable ranges. Continue monitoring for regression.";
    }

    return $recommendations;
  }

  /**
   * Generates a JSON report.
   *
   * @param array $results
   *   Evaluation results.
   * @param bool $pretty
   *   Whether to pretty-print JSON.
   *
   * @return string
   *   JSON report content.
   */
  public static function generateJson(array $results, bool $pretty = TRUE): string {
    $report = [
      'meta' => [
        'generator' => 'ILAS Chatbot Evaluation Harness',
        'version' => '1.0.0',
        'generated_at' => date('c'),
      ],
      'summary' => $results['summary'] ?? [],
      'metrics' => $results['metrics'] ?? [],
      'by_category' => $results['by_category'] ?? [],
      'test_results' => [],
    ];

    // Include test results but strip debug metadata to reduce size.
    foreach ($results['test_results'] ?? [] as $test) {
      $test_copy = $test;
      // Remove verbose debug data from JSON export.
      unset($test_copy['debug_meta']);
      $report['test_results'][] = $test_copy;
    }

    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if ($pretty) {
      $flags |= JSON_PRETTY_PRINT;
    }

    return json_encode($report, $flags);
  }

  /**
   * Generates a JUnit XML report for CI integration.
   *
   * @param array $results
   *   Evaluation results.
   *
   * @return string
   *   JUnit XML content.
   */
  public static function generateJunit(array $results): string {
    $summary = $results['summary'] ?? [];
    $tests = $results['test_results'] ?? [];

    $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><testsuites/>');

    $suite = $xml->addChild('testsuite');
    $suite->addAttribute('name', 'ILAS Chatbot Evaluation');
    $suite->addAttribute('tests', $summary['total'] ?? 0);
    $suite->addAttribute('failures', $summary['failed'] ?? 0);
    $suite->addAttribute('errors', $summary['errors'] ?? 0);
    $suite->addAttribute('time', '0');
    $suite->addAttribute('timestamp', $summary['start_time'] ?? date('c'));

    foreach ($tests as $test) {
      $case = $suite->addChild('testcase');
      $case->addAttribute('name', "Test #{$test['test_number']} - {$test['expected_intent']}");
      $case->addAttribute('classname', 'IlasChatbotEval.ChatbotEvaluator');
      $case->addAttribute('time', '0');

      if ($test['error']) {
        $error = $case->addChild('error', htmlspecialchars($test['error_message'] ?? 'Unknown error'));
        $error->addAttribute('type', 'Error');
      }
      elseif (!$test['passed']) {
        // Build failure message from checks.
        $failed_checks = array_filter($test['checks'] ?? [], function ($c) {
          return !$c['passed'];
        });
        $messages = array_map(function ($c) {
          return $c['message'];
        }, $failed_checks);

        $failure = $case->addChild('failure', htmlspecialchars(implode('; ', $messages)));
        $failure->addAttribute('type', 'AssertionError');
      }
    }

    // Format XML nicely.
    $dom = new \DOMDocument('1.0');
    $dom->preserveWhiteSpace = FALSE;
    $dom->formatOutput = TRUE;
    $dom->loadXML($xml->asXML());

    return $dom->saveXML();
  }

  /**
   * Saves reports to files.
   *
   * @param array $results
   *   Evaluation results.
   * @param string $output_dir
   *   Output directory.
   * @param string $prefix
   *   Filename prefix.
   *
   * @return array
   *   Array of created file paths.
   */
  public static function saveReports(array $results, string $output_dir, string $prefix = 'eval'): array {
    if (!is_dir($output_dir)) {
      mkdir($output_dir, 0755, TRUE);
    }

    $timestamp = date('Y-m-d_His');
    $files = [];

    // Markdown report.
    $md_path = "{$output_dir}/{$prefix}-report-{$timestamp}.md";
    file_put_contents($md_path, self::generateMarkdown($results));
    $files['markdown'] = $md_path;

    // JSON report.
    $json_path = "{$output_dir}/{$prefix}-report-{$timestamp}.json";
    file_put_contents($json_path, self::generateJson($results));
    $files['json'] = $json_path;

    // JUnit report.
    $junit_path = "{$output_dir}/{$prefix}-junit-{$timestamp}.xml";
    file_put_contents($junit_path, self::generateJunit($results));
    $files['junit'] = $junit_path;

    // Latest symlinks.
    $latest_md = "{$output_dir}/{$prefix}-report-latest.md";
    $latest_json = "{$output_dir}/{$prefix}-report-latest.json";
    $latest_junit = "{$output_dir}/{$prefix}-junit-latest.xml";

    @unlink($latest_md);
    @unlink($latest_json);
    @unlink($latest_junit);

    symlink(basename($md_path), $latest_md);
    symlink(basename($json_path), $latest_json);
    symlink(basename($junit_path), $latest_junit);

    $files['latest_markdown'] = $latest_md;
    $files['latest_json'] = $latest_json;
    $files['latest_junit'] = $latest_junit;

    return $files;
  }

  /**
   * Prints a summary to stdout.
   *
   * @param array $results
   *   Evaluation results.
   */
  public static function printSummary(array $results): void {
    $summary = $results['summary'] ?? [];
    $metrics = $results['metrics'] ?? [];

    echo "\n";
    echo "=== ILAS Chatbot Evaluation Results ===\n";
    echo "\n";

    $total = $summary['total'] ?? 0;
    $passed = $summary['passed'] ?? 0;
    $failed = $summary['failed'] ?? 0;
    $errors = $summary['errors'] ?? 0;

    $pass_rate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

    echo "Total:  {$total}\n";
    echo "Passed: {$passed}\n";
    echo "Failed: {$failed}\n";
    echo "Errors: {$errors}\n";
    echo "Rate:   {$pass_rate}%\n";
    echo "\n";

    echo "--- Metrics ---\n";
    echo "Intent Accuracy:    " . self::formatPercent($metrics['intent_accuracy'] ?? 0) . "\n";
    echo "Action Accuracy:    " . self::formatPercent($metrics['action_accuracy'] ?? 0) . "\n";
    echo "Safety Compliance:  " . self::formatPercent($metrics['safety_compliance_rate'] ?? 0) . "\n";
    echo "Fallback Rate:      " . self::formatPercent($metrics['fallback_rate'] ?? 0) . "\n";
    echo "\n";

    // Gate metrics.
    if (!empty($metrics['gate'])) {
      $gate = $metrics['gate'];
      echo "--- Fallback Gate ---\n";
      echo "Answer Rate:        " . self::formatPercent($gate['answer_rate'] ?? 0) . "\n";
      echo "Clarify Rate:       " . self::formatPercent($gate['clarify_rate'] ?? 0) . "\n";
      echo "LLM Fallback Rate:  " . self::formatPercent($gate['fallback_rate'] ?? 0) . "\n";
      echo "Hard Route Rate:    " . self::formatPercent($gate['hard_route_rate'] ?? 0) . "\n";
      echo "Misroute Rate:      " . self::formatPercent($gate['misroute_rate'] ?? 0) . "\n";
      echo "Bad Answer Rate:    " . self::formatPercent($gate['bad_answer_rate'] ?? 0) . "\n";
      echo "Avg Confidence:     " . round(($gate['avg_confidence'] ?? 0) * 100, 1) . "%\n";
      echo "\n";
    }

    // Print failed test numbers.
    $failed_tests = array_filter($results['test_results'] ?? [], function ($r) {
      return !$r['passed'];
    });

    if (!empty($failed_tests)) {
      $failed_nums = array_map(function ($t) {
        return $t['test_number'];
      }, $failed_tests);
      echo "Failed tests: " . implode(', ', array_slice($failed_nums, 0, 10));
      if (count($failed_nums) > 10) {
        echo " ..." . (count($failed_nums) - 10) . " more";
      }
      echo "\n";
    }

    echo "\n";
  }

}
