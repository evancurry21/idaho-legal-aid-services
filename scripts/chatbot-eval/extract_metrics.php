<?php
/**
 * Extracts metrics and URL drift cases from an eval report.
 */

if (empty($argv[1])) {
    echo "Usage: php extract_metrics.php <report.json>\n";
    exit(1);
}

$data = json_decode(file_get_contents($argv[1]), true);

if (!$data) {
    echo "Error: Could not parse JSON file\n";
    exit(1);
}

echo "=== METRICS ===\n";
foreach ($data['metrics'] as $key => $value) {
    if (is_array($value)) {
        echo "$key:\n";
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                echo "  $k: " . json_encode($v) . "\n";
            } else {
                echo "  $k: $v\n";
            }
        }
    } else {
        echo "$key: $value\n";
    }
}

echo "\n=== URL DRIFT CASES (" . count($data['url_drift_cases'] ?? []) . " total) ===\n";
echo "(Cases where intent was correct but URL was wrong)\n\n";

foreach ($data['url_drift_cases'] ?? [] as $case) {
    echo "Test #{$case['test_number']}: Intent '{$case['expected_intent']}' -> '{$case['actual_intent']}'\n";
    echo "  Expected URL: {$case['expected_action']}\n";
    echo "  Actual URL:   {$case['actual_action']}\n";
    if (!empty($case['hard_route_expected'])) {
        echo "  Hard Route: expected={$case['hard_route_expected']}, actual={$case['hard_route_actual']}\n";
    }
    echo "  Is Hard Route: " . ($case['is_hard_route'] ? 'yes' : 'no') . "\n";
    echo "\n";
}

echo "=== SUMMARY ===\n";
echo "Hard Route URL Accuracy: " . ($data['metrics']['hard_route_url_accuracy'] ?? 'N/A') . "\n";
echo "Hard Route Total: " . ($data['metrics']['hard_route_total'] ?? 'N/A') . "\n";
echo "Hard Route Correct: " . ($data['metrics']['hard_route_correct'] ?? 'N/A') . "\n";
echo "Intent Right, URL Wrong Count: " . ($data['metrics']['intent_right_url_wrong_count'] ?? 'N/A') . "\n";
