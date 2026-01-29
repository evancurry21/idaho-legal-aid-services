#!/usr/bin/env php
<?php

/**
 * @file
 * Quick verification script for OOS response template changes.
 *
 * Run: php verify-oos-templates.php
 */

// Bootstrap the module classes.
require_once __DIR__ . '/../src/Service/OutOfScopeClassifier.php';
require_once __DIR__ . '/../src/Service/OutOfScopeResponseTemplates.php';
require_once __DIR__ . '/../src/Service/SafetyClassifier.php';
require_once __DIR__ . '/../src/Service/SafetyResponseTemplates.php';

use Drupal\ilas_site_assistant\Service\OutOfScopeClassifier;
use Drupal\ilas_site_assistant\Service\OutOfScopeResponseTemplates;
use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use Drupal\ilas_site_assistant\Service\SafetyResponseTemplates;

echo "=== OOS Template Verification ===\n\n";

// Test OutOfScopeResponseTemplates.
echo "Testing OutOfScopeResponseTemplates...\n";
$oos_templates = new OutOfScopeResponseTemplates();

$oos_test_cases = [
  [
    'name' => 'Criminal Defense',
    'category' => OutOfScopeClassifier::CATEGORY_CRIMINAL_DEFENSE,
    'reason_code' => 'oos_criminal_arrested',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'Immigration',
    'category' => OutOfScopeClassifier::CATEGORY_IMMIGRATION,
    'reason_code' => 'oos_immigration_visa',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'Non-Idaho',
    'category' => OutOfScopeClassifier::CATEGORY_NON_IDAHO,
    'reason_code' => 'oos_location_western',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'Emergency Services',
    'category' => OutOfScopeClassifier::CATEGORY_EMERGENCY_SERVICES,
    'reason_code' => 'oos_emergency_police',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'Business Commercial',
    'category' => OutOfScopeClassifier::CATEGORY_BUSINESS_COMMERCIAL,
    'reason_code' => 'oos_business_start',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'Federal Matters',
    'category' => OutOfScopeClassifier::CATEGORY_FEDERAL_MATTERS,
    'reason_code' => 'oos_federal_bankruptcy',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'High Value Civil',
    'category' => OutOfScopeClassifier::CATEGORY_HIGH_VALUE_CIVIL,
    'reason_code' => 'oos_civil_personal_injury',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'In Scope (passthrough)',
    'category' => OutOfScopeClassifier::CATEGORY_IN_SCOPE,
    'reason_code' => 'oos_in_scope',
    'expected_type' => 'in_scope',
  ],
];

$oos_passed = 0;
$oos_failed = 0;

foreach ($oos_test_cases as $test) {
  $classification = [
    'category' => $test['category'],
    'reason_code' => $test['reason_code'],
    'suggestions' => [],
  ];

  $response = $oos_templates->getResponse($classification);
  $actual_type = $response['type'] ?? 'null';

  if ($actual_type === $test['expected_type']) {
    echo "  [PASS] {$test['name']}: type='{$actual_type}'\n";
    $oos_passed++;
  }
  else {
    echo "  [FAIL] {$test['name']}: expected '{$test['expected_type']}', got '{$actual_type}'\n";
    $oos_failed++;
  }
}

echo "\nOutOfScopeResponseTemplates: {$oos_passed} passed, {$oos_failed} failed\n\n";

// Test SafetyResponseTemplates.
echo "Testing SafetyResponseTemplates...\n";
$safety_templates = new SafetyResponseTemplates();

$safety_test_cases = [
  [
    'name' => 'Criminal (Safety)',
    'class' => SafetyClassifier::CLASS_CRIMINAL,
    'reason_code' => 'out_of_scope_criminal_arrest',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'Immigration (Safety)',
    'class' => SafetyClassifier::CLASS_IMMIGRATION,
    'reason_code' => 'out_of_scope_immigration',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'External (Safety)',
    'class' => SafetyClassifier::CLASS_EXTERNAL,
    'reason_code' => 'external_gov_website',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'Crisis',
    'class' => SafetyClassifier::CLASS_CRISIS,
    'reason_code' => 'crisis_suicide',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'DV Emergency',
    'class' => SafetyClassifier::CLASS_DV_EMERGENCY,
    'reason_code' => 'emergency_dv',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'Eviction Emergency',
    'class' => SafetyClassifier::CLASS_EVICTION_EMERGENCY,
    'reason_code' => 'emergency_lockout',
    'expected_type' => 'escalation',
  ],
  [
    'name' => 'Safe',
    'class' => SafetyClassifier::CLASS_SAFE,
    'reason_code' => 'safe_no_concerns',
    'expected_type' => 'safe',
  ],
  [
    'name' => 'Wrongdoing',
    'class' => SafetyClassifier::CLASS_WRONGDOING,
    'reason_code' => 'wrongdoing_threat',
    'expected_type' => 'refusal',
  ],
  [
    'name' => 'PII',
    'class' => SafetyClassifier::CLASS_PII,
    'reason_code' => 'pii_ssn',
    'expected_type' => 'privacy',
  ],
];

$safety_passed = 0;
$safety_failed = 0;

foreach ($safety_test_cases as $test) {
  $classification = [
    'class' => $test['class'],
    'reason_code' => $test['reason_code'],
  ];

  $response = $safety_templates->getResponse($classification);
  $actual_type = $response['type'] ?? 'null';

  if ($actual_type === $test['expected_type']) {
    echo "  [PASS] {$test['name']}: type='{$actual_type}'\n";
    $safety_passed++;
  }
  else {
    echo "  [FAIL] {$test['name']}: expected '{$test['expected_type']}', got '{$actual_type}'\n";
    $safety_failed++;
  }
}

echo "\nSafetyResponseTemplates: {$safety_passed} passed, {$safety_failed} failed\n\n";

// Golden dataset edge cases.
echo "Testing Golden Dataset Edge Cases...\n";

// Mock config factory for classifiers.
class MockConfig {
  public function get($key) { return []; }
}
class MockConfigFactory {
  public function get($name) { return new MockConfig(); }
}

$config_factory = new MockConfigFactory();
$oos_classifier = new OutOfScopeClassifier($config_factory);
$safety_classifier = new SafetyClassifier($config_factory);

$golden_cases = [
  ['message' => 'I need a criminal defense lawyer', 'expected_oos' => true],
  ['message' => 'can you help with my DUI', 'expected_oos' => true],
  ['message' => 'I was arrested last night', 'expected_oos' => true],
  ['message' => 'I live in Oregon can you help', 'expected_oos' => true],
  ['message' => 'help with my washington state case', 'expected_oos' => true],
  ['message' => 'immigration lawyer needed', 'expected_oos' => true],
  ['message' => 'green card application help', 'expected_oos' => true],
  ['message' => 'i want to sue for a million dollars', 'expected_oos' => true],
  ['message' => 'help me start a business', 'expected_oos' => true],
  ['message' => 'patent my invention', 'expected_oos' => true],
  // Edge cases.
  ['message' => 'criminal record expungement', 'expected_oos' => true],
  ['message' => 'asylum help needed', 'expected_oos' => true],
  ['message' => 'startup LLC formation', 'expected_oos' => true],
  // In-scope should pass.
  ['message' => 'help with my eviction', 'expected_oos' => false],
  ['message' => 'I need a divorce', 'expected_oos' => false],
];

$golden_passed = 0;
$golden_failed = 0;

foreach ($golden_cases as $case) {
  // Try safety classifier first.
  $safety_result = $safety_classifier->classify($case['message']);
  $safety_is_oos = in_array($safety_result['class'], [
    SafetyClassifier::CLASS_CRIMINAL,
    SafetyClassifier::CLASS_IMMIGRATION,
    SafetyClassifier::CLASS_EXTERNAL,
  ]);

  // Then try OOS classifier.
  $oos_result = $oos_classifier->classify($case['message']);
  $oos_is_oos = $oos_result['is_out_of_scope'];

  $actual_oos = $safety_is_oos || $oos_is_oos;

  if ($actual_oos === $case['expected_oos']) {
    $indicator = $actual_oos ? 'OOS' : 'in-scope';
    echo "  [PASS] '{$case['message']}' → {$indicator}\n";
    $golden_passed++;
  }
  else {
    $expected = $case['expected_oos'] ? 'OOS' : 'in-scope';
    $actual = $actual_oos ? 'OOS' : 'in-scope';
    echo "  [FAIL] '{$case['message']}': expected {$expected}, got {$actual}\n";
    $golden_failed++;
  }
}

echo "\nGolden Dataset Cases: {$golden_passed} passed, {$golden_failed} failed\n\n";

// Summary.
$total_passed = $oos_passed + $safety_passed + $golden_passed;
$total_failed = $oos_failed + $safety_failed + $golden_failed;
$total = $total_passed + $total_failed;

echo "=== Summary ===\n";
echo "Total: {$total_passed}/{$total} tests passed\n";

if ($total_failed > 0) {
  echo "\nFAILED: {$total_failed} tests failed\n";
  exit(1);
}
else {
  echo "\nSUCCESS: All tests passed!\n";
  exit(0);
}
