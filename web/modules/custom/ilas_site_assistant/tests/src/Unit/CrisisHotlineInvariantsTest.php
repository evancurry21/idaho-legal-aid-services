<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use Drupal\ilas_site_assistant\Service\SafetyResponseTemplates;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests locking life-safety response content.
 *
 * These tests pin the crisis hotline numbers and response content that must
 * never be removed or altered without explicit safety review. Removing a
 * hotline number could have life-safety consequences.
 */
#[Group('ilas_site_assistant')]
class CrisisHotlineInvariantsTest extends TestCase {

  /**
   * Response templates under test.
   */
  protected SafetyResponseTemplates $templates;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Stub the translation service so StringTranslationTrait works in pure
    // PHPUnit without a Drupal container. The stub returns the raw string
    // unchanged, which is sufficient for asserting hotline numbers/content.
    $translation = $this->createStub(TranslationInterface::class);
    $translation->method('translateString')
      ->willReturnCallback(fn($markup) => (string) $markup->getUntranslatedString());

    $this->templates = new SafetyResponseTemplates();
    $this->templates->setStringTranslation($translation);
  }

  /**
   * Gets a response for a given classification class.
   */
  private function getResponseForClass(string $class, string $reasonCode = 'test_reason'): array {
    return $this->templates->getResponse([
      'class' => $class,
      'reason_code' => $reasonCode,
    ]);
  }

  /**
   * Collects all URL values from a response's links array.
   */
  private function collectLinkUrls(array $response): array {
    return array_map(fn(array $link) => $link['url'], $response['links'] ?? []);
  }

  /**
   * Crisis response must include 988 (Suicide & Crisis Lifeline).
   */
  public function testCrisisResponseContains988(): void {
    $response = $this->getResponseForClass(SafetyClassifier::CLASS_CRISIS);
    $this->assertStringContainsString(
      '988',
      $response['message'],
      'Crisis response must mention 988 Suicide & Crisis Lifeline',
    );
    $urls = $this->collectLinkUrls($response);
    $this->assertContains('tel:988', $urls, 'Crisis response must include tel:988 link');
  }

  /**
   * Crisis response must include 911.
   */
  public function testCrisisResponseContains911(): void {
    $response = $this->getResponseForClass(SafetyClassifier::CLASS_CRISIS);
    $urls = $this->collectLinkUrls($response);
    $this->assertContains('tel:911', $urls, 'Crisis response must include tel:911 link');
  }

  /**
   * DV emergency response must include National DV Hotline number.
   */
  public function testDvResponseContainsDvHotline(): void {
    $response = $this->getResponseForClass(SafetyClassifier::CLASS_DV_EMERGENCY);
    $this->assertStringContainsString(
      '1-800-799-7233',
      $response['message'],
      'DV response must contain National DV Hotline number',
    );
    $urls = $this->collectLinkUrls($response);
    $this->assertContains(
      'tel:1-800-799-7233',
      $urls,
      'DV response must include tel:1-800-799-7233 link',
    );
  }

  /**
   * Child safety response must include Idaho Child Protection Hotline.
   */
  public function testChildSafetyResponseContainsChildProtectionHotline(): void {
    $response = $this->getResponseForClass(SafetyClassifier::CLASS_CHILD_SAFETY);

    // Must contain either the number or the KIDS abbreviation.
    $message = $response['message'];
    $hasNumber = str_contains($message, '1-855-552-5437') || str_contains($message, '5437');
    $this->assertTrue($hasNumber, 'Child safety response must contain Idaho Child Protection number (5437)');

    $urls = $this->collectLinkUrls($response);
    $this->assertContains(
      'tel:1-855-552-5437',
      $urls,
      'Child safety response must include tel:1-855-552-5437 link',
    );
  }

  /**
   * Immediate danger response must include 911.
   */
  public function testImmediateDangerResponseContains911(): void {
    $response = $this->getResponseForClass(SafetyClassifier::CLASS_IMMEDIATE_DANGER);
    $this->assertStringContainsString(
      '911',
      $response['message'],
      'Immediate danger response must mention 911',
    );
    $urls = $this->collectLinkUrls($response);
    $this->assertContains('tel:911', $urls, 'Immediate danger response must include tel:911 link');
  }

  /**
   * Every critical and immediate classification must have non-empty action links.
   */
  public function testAllCriticalAndImmediateClassesHaveActionLinks(): void {
    $criticalClasses = [
      SafetyClassifier::CLASS_CRISIS,
      SafetyClassifier::CLASS_IMMEDIATE_DANGER,
    ];
    $immediateClasses = [
      SafetyClassifier::CLASS_DV_EMERGENCY,
      SafetyClassifier::CLASS_EVICTION_EMERGENCY,
      SafetyClassifier::CLASS_CHILD_SAFETY,
      SafetyClassifier::CLASS_SCAM_ACTIVE,
    ];

    foreach (array_merge($criticalClasses, $immediateClasses) as $class) {
      $response = $this->getResponseForClass($class);
      $this->assertNotEmpty(
        $response['links'],
        "Classification '{$class}' must have non-empty action links",
      );
    }
  }

  /**
   * Legal advice response must contain refusal language.
   */
  public function testLegalAdviceRefusalMessagePresent(): void {
    $response = $this->getResponseForClass(SafetyClassifier::CLASS_LEGAL_ADVICE);
    $message = strtolower($response['message']);
    $this->assertStringContainsString("can't", $message, 'Legal advice response must contain refusal word');
    $this->assertStringContainsString('legal advice', $message, 'Legal advice response must mention "legal advice"');
  }

}
