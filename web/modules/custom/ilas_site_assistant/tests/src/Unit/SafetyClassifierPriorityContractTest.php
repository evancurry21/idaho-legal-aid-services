<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests that lock SafetyClassifier structural invariants.
 *
 * These tests pin the classification constants, escalation levels, and rule
 * priority ordering so that Phase 1-3 refactors cannot silently break safety
 * guarantees. Any change to these contracts must be deliberate and reviewed.
 */
#[Group('ilas_site_assistant')]
class SafetyClassifierPriorityContractTest extends TestCase {

  /**
   * The classifier under test.
   */
  protected SafetyClassifier $classifier;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn([]);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $this->classifier = new SafetyClassifier($configFactory);
  }

  /**
   * Locks the exact set of 16 CLASS_* constants.
   */
  public function testAllClassConstantsAreDefined(): void {
    $expected = [
      'CLASS_CRISIS',
      'CLASS_IMMEDIATE_DANGER',
      'CLASS_DV_EMERGENCY',
      'CLASS_EVICTION_EMERGENCY',
      'CLASS_CHILD_SAFETY',
      'CLASS_SCAM_ACTIVE',
      'CLASS_PROMPT_INJECTION',
      'CLASS_WRONGDOING',
      'CLASS_CRIMINAL',
      'CLASS_IMMIGRATION',
      'CLASS_PII',
      'CLASS_LEGAL_ADVICE',
      'CLASS_DOCUMENT_DRAFTING',
      'CLASS_EXTERNAL',
      'CLASS_FRUSTRATION',
      'CLASS_SAFE',
    ];

    $ref = new \ReflectionClass(SafetyClassifier::class);
    $classConstants = array_filter(
      array_keys($ref->getConstants()),
      fn(string $name) => str_starts_with($name, 'CLASS_'),
    );

    sort($expected);
    sort($classConstants);
    $this->assertSame($expected, $classConstants, 'CLASS_* constant set has changed');
  }

  /**
   * Locks the exact set of 5 ESCALATION_* constants.
   */
  public function testAllEscalationConstantsAreDefined(): void {
    $expected = [
      'ESCALATION_CRITICAL',
      'ESCALATION_IMMEDIATE',
      'ESCALATION_URGENT',
      'ESCALATION_STANDARD',
      'ESCALATION_NONE',
    ];

    $ref = new \ReflectionClass(SafetyClassifier::class);
    $escalationConstants = array_filter(
      array_keys($ref->getConstants()),
      fn(string $name) => str_starts_with($name, 'ESCALATION_'),
    );

    sort($expected);
    sort($escalationConstants);
    $this->assertSame($expected, $escalationConstants, 'ESCALATION_* constant set has changed');
  }

  /**
   * Locks the rule priority order: crisis first, frustration last.
   *
   * The $rules array is iterated in insertion order. The first matching rule
   * wins, so ordering is a safety contract: crisis must always beat everything.
   */
  public function testRulePriorityOrder(): void {
    $expectedOrder = [
      'crisis',
      'immediate_danger',
      'dv_emergency',
      'eviction_emergency',
      'child_safety',
      'scam_active',
      'prompt_injection',
      'wrongdoing',
      'criminal',
      'immigration',
      'business_ip',
      'pii',
      'legal_advice',
      'document_drafting',
      'external',
      'frustration',
    ];

    $ref = new \ReflectionClass(SafetyClassifier::class);
    $rulesProp = $ref->getProperty('rules');
    $rulesProp->setAccessible(TRUE);
    $rules = $rulesProp->getValue($this->classifier);

    $this->assertSame(
      $expectedOrder,
      array_keys($rules),
      'Rule priority order has changed — crisis must always be first',
    );
  }

  /**
   * A message matching both crisis and legal_advice patterns must classify as crisis.
   */
  public function testCrisisAlwaysBeatsLegalAdvice(): void {
    // "I want to kill myself, should I sue" triggers both crisis and legal_advice.
    $result = $this->classifier->classify('I want to kill myself, should I sue');
    $this->assertSame(
      SafetyClassifier::CLASS_CRISIS,
      $result['class'],
      'Crisis must take priority over legal_advice',
    );
  }

  /**
   * Locks the escalation level mapped to each rule category.
   */
  public function testEscalationLevelMapping(): void {
    $expectedMapping = [
      'crisis' => SafetyClassifier::ESCALATION_CRITICAL,
      'immediate_danger' => SafetyClassifier::ESCALATION_CRITICAL,
      'dv_emergency' => SafetyClassifier::ESCALATION_IMMEDIATE,
      'eviction_emergency' => SafetyClassifier::ESCALATION_IMMEDIATE,
      'child_safety' => SafetyClassifier::ESCALATION_IMMEDIATE,
      'scam_active' => SafetyClassifier::ESCALATION_IMMEDIATE,
      'prompt_injection' => SafetyClassifier::ESCALATION_URGENT,
      'wrongdoing' => SafetyClassifier::ESCALATION_URGENT,
      'criminal' => SafetyClassifier::ESCALATION_STANDARD,
      'immigration' => SafetyClassifier::ESCALATION_STANDARD,
      'business_ip' => SafetyClassifier::ESCALATION_STANDARD,
      'pii' => SafetyClassifier::ESCALATION_STANDARD,
      'legal_advice' => SafetyClassifier::ESCALATION_STANDARD,
      'document_drafting' => SafetyClassifier::ESCALATION_STANDARD,
      'external' => SafetyClassifier::ESCALATION_STANDARD,
      'frustration' => SafetyClassifier::ESCALATION_STANDARD,
    ];

    $ref = new \ReflectionClass(SafetyClassifier::class);
    $rulesProp = $ref->getProperty('rules');
    $rulesProp->setAccessible(TRUE);
    $rules = $rulesProp->getValue($this->classifier);

    foreach ($expectedMapping as $category => $expectedEscalation) {
      $this->assertArrayHasKey($category, $rules, "Missing rule category: {$category}");
      $this->assertSame(
        $expectedEscalation,
        $rules[$category]['escalation'],
        "Escalation level changed for category: {$category}",
      );
    }
  }

  /**
   * Every rule's 'class' value must be a valid CLASS_* constant.
   */
  public function testEveryRuleMapsToValidClassConstant(): void {
    $ref = new \ReflectionClass(SafetyClassifier::class);
    $classConstantValues = array_filter(
      $ref->getConstants(),
      fn(string $name) => str_starts_with($name, 'CLASS_'),
      ARRAY_FILTER_USE_KEY,
    );

    $rulesProp = $ref->getProperty('rules');
    $rulesProp->setAccessible(TRUE);
    $rules = $rulesProp->getValue($this->classifier);

    foreach ($rules as $category => $rule) {
      $this->assertContains(
        $rule['class'],
        $classConstantValues,
        "Rule '{$category}' maps to invalid class value: '{$rule['class']}'",
      );
    }
  }

  /**
   * Classify() must always return the documented key set.
   */
  public function testClassifyReturnShape(): void {
    $expectedKeys = [
      'class',
      'reason_code',
      'escalation_level',
      'is_safe',
      'requires_refusal',
      'requires_resources',
      'matched_pattern',
      'category',
    ];

    // Test with a safe message.
    $safeResult = $this->classifier->classify('hello there');
    foreach ($expectedKeys as $key) {
      $this->assertArrayHasKey($key, $safeResult, "Safe result missing key: {$key}");
    }

    // Test with an unsafe message (crisis).
    $crisisResult = $this->classifier->classify('I want to kill myself');
    foreach ($expectedKeys as $key) {
      $this->assertArrayHasKey($key, $crisisResult, "Crisis result missing key: {$key}");
    }
  }

}
