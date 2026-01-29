<?php

namespace Drupal\Tests\ilas_site_assistant\DrupalUnit;

use Drupal\Tests\UnitTestCase;
use Drupal\ilas_site_assistant\Service\KeywordExtractor;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression tests for keyword extraction.
 *
 * These tests cover previously failing cases from the evaluation harness.
 *
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\KeywordExtractor
 * @group ilas_site_assistant
 */
class KeywordExtractionRegressionTest extends UnitTestCase {

  /**
   * The keyword extractor service.
   *
   * @var \Drupal\ilas_site_assistant\Service\KeywordExtractor
   */
  protected $keywordExtractor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Load YAML configurations.
    $module_path = dirname(__DIR__, 3);
    $phrases_file = $module_path . '/config/routing/phrases.yml';
    $synonyms_file = $module_path . '/config/routing/synonyms.yml';
    $negatives_file = $module_path . '/config/routing/negatives.yml';

    $phrases = file_exists($phrases_file) ? Yaml::parseFile($phrases_file) : [];
    $synonyms = file_exists($synonyms_file) ? Yaml::parseFile($synonyms_file) : [];
    $negatives = file_exists($negatives_file) ? Yaml::parseFile($negatives_file) : [];

    // Create mock config factory.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function ($key) use ($phrases, $synonyms, $negatives) {
        switch ($key) {
          case 'phrases':
            return $phrases['phrases'] ?? [];
          case 'synonyms':
            return $synonyms;
          case 'negatives':
            return $negatives;
          default:
            return [];
        }
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $this->keywordExtractor = new KeywordExtractor($configFactory);
  }

  /**
   * Tests typo correction in apply queries.
   *
   * @covers ::extract
   * @dataProvider typoApplyProvider
   */
  public function testTypoCorrectionApply(string $query, string $expectedKeyword): void {
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];
    $synonyms_applied = $result['synonyms_applied'] ?? [];

    // Either the keyword should be extracted or synonym should be applied.
    $this->assertTrue(
      in_array($expectedKeyword, $keywords) || !empty($synonyms_applied),
      "Expected '$expectedKeyword' in keywords or synonym correction for: $query"
    );
  }

  /**
   * Data provider for typo apply tests.
   */
  public static function typoApplyProvider(): array {
    return [
      'aply typo' => ['how do i aply for help', 'apply'],
      'aplly typo' => ['aplly for assistance', 'apply'],
      'lawer typo' => ['i need a lawer', 'lawyer'],
      'laywer typo' => ['looking for a laywer', 'lawyer'],
      'halp typo' => ['i need halp', 'help'],
      'quailfy typo' => ['do i quailfy', 'qualify'],
      'qualfy typo' => ['how do i qualfy', 'qualify'],
      'representaion typo' => ['i need legal representaion', 'representation'],
    ];
  }

  /**
   * Tests Spanish keyword extraction.
   *
   * @covers ::extract
   * @dataProvider spanishQueryProvider
   */
  public function testSpanishKeywordExtraction(string $query, string $expectedKeyword): void {
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];
    $synonyms_applied = $result['synonyms_applied'] ?? [];
    $phrases = $result['phrases'] ?? [];

    $found = in_array($expectedKeyword, $keywords)
      || !empty($synonyms_applied)
      || !empty($phrases);

    $this->assertTrue($found, "Expected Spanish processing for: $query");
  }

  /**
   * Data provider for Spanish queries.
   */
  public static function spanishQueryProvider(): array {
    return [
      'ayuda legal' => ['necesito ayuda legal', 'ayuda'],
      'abogado gratis' => ['busco abogado gratis', 'abogado'],
      'aplicar' => ['como puedo aplicar', 'aplicar'],
      'llamar' => ['puedo llamar a alguien', 'llamar'],
      'oficina' => ['donde esta la oficina', 'oficina'],
      'formulario' => ['necesito un formulario', 'formulario'],
      'preguntas frecuentes' => ['preguntas frecuentes', 'preguntas'],
    ];
  }

  /**
   * Tests phrase detection for legal-aid terms.
   *
   * @covers ::extract
   * @dataProvider phraseDetectionProvider
   */
  public function testPhraseDetection(string $query, string $expectedPhrase): void {
    $result = $this->keywordExtractor->extract($query);

    $phrases = $result['phrases'] ?? [];
    $normalized = $result['normalized'] ?? '';

    // Check if phrase was detected.
    $phrase_found = in_array($expectedPhrase, $phrases)
      || strpos($normalized, str_replace(' ', '_', $expectedPhrase)) !== FALSE;

    $this->assertTrue($phrase_found, "Expected phrase '$expectedPhrase' to be detected in: $query");
  }

  /**
   * Data provider for phrase detection tests.
   */
  public static function phraseDetectionProvider(): array {
    return [
      'protection order' => ['i need a protection order', 'protection order'],
      'eviction notice' => ['i got an eviction notice', 'eviction notice'],
      'identity theft' => ['victim of identity theft', 'identity theft'],
      'child support' => ['help with child support', 'child support'],
      'domestic violence' => ['escaping domestic violence', 'domestic violence'],
      'legal advice line' => ['call the legal advice line', 'legal advice line'],
      'apply for help' => ['how do i apply for help', 'apply for help'],
      'office location' => ['where is the office location', 'office location'],
      'court date' => ['i have a court date', 'court date'],
      'tenant rights' => ['what are my tenant rights', 'tenant rights'],
    ];
  }

  /**
   * Tests high-risk situation detection.
   *
   * @covers ::extract
   * @dataProvider highRiskProvider
   */
  public function testHighRiskDetection(string $query, string $expectedCategory): void {
    $result = $this->keywordExtractor->extract($query);

    $high_risk = $result['high_risk'] ?? [];

    $this->assertContains($expectedCategory, $high_risk,
      "Expected high-risk category '$expectedCategory' for: $query");
  }

  /**
   * Data provider for high-risk tests.
   */
  public static function highRiskProvider(): array {
    return [
      'dv hitting' => ['my husband is hitting me', 'high_risk_dv'],
      'dv abusive' => ['i have an abusive partner', 'high_risk_dv'],
      'dv threatened' => ['he threatened to kill me', 'high_risk_dv'],
      'eviction sheriff' => ['the sheriff is coming tomorrow', 'high_risk_eviction'],
      'eviction locked out' => ['landlord changed the locks', 'high_risk_eviction'],
      'eviction 3 day' => ['i got a 3 day notice', 'high_risk_eviction'],
      'scam identity' => ['someone stole my identity', 'high_risk_scam'],
      'scam got scammed' => ['i got scammed', 'high_risk_scam'],
      'deadline tomorrow' => ['my deadline is tomorrow', 'high_risk_deadline'],
      'deadline court' => ['court date tomorrow', 'high_risk_deadline'],
    ];
  }

  /**
   * Tests out-of-scope detection.
   *
   * @covers ::extract
   * @dataProvider outOfScopeProvider
   */
  public function testOutOfScopeDetection(string $query): void {
    $result = $this->keywordExtractor->extract($query);

    $out_of_scope = $result['out_of_scope'] ?? FALSE;

    $this->assertTrue($out_of_scope, "Expected out-of-scope detection for: $query");
  }

  /**
   * Data provider for out-of-scope tests.
   */
  public static function outOfScopeProvider(): array {
    return [
      'criminal defense' => ['i need a criminal defense lawyer'],
      'dui' => ['help with my dui case'],
      'immigration' => ['immigration lawyer'],
      'green card' => ['help getting a green card'],
      'oregon' => ['i live in oregon'],
      'washington state' => ['legal help in washington state'],
      'business formation' => ['help me start a business'],
      'patent' => ['file a patent'],
    ];
  }

  /**
   * Tests negative keyword blocking.
   *
   * @covers ::hasNegativeKeyword
   * @dataProvider negativeKeywordProvider
   */
  public function testNegativeKeywordBlocking(string $query, string $intent, bool $shouldBlock): void {
    $result = $this->keywordExtractor->extract($query);
    $hasNegative = $this->keywordExtractor->hasNegativeKeyword($query, $intent);

    $this->assertEquals($shouldBlock, $hasNegative,
      "Negative keyword check for intent '$intent' failed for: $query");
  }

  /**
   * Data provider for negative keyword tests.
   */
  public static function negativeKeywordProvider(): array {
    return [
      // Should block.
      'criminal apply' => ['apply for help with criminal case', 'apply', TRUE],
      'oregon apply' => ['apply for help in oregon', 'apply', TRUE],
      'emergency hotline' => ['call 911 emergency', 'hotline', TRUE],
      'child support donate' => ['help with child support payments', 'donations', TRUE],
      'my case faq' => ['what should i do about my case', 'faq', TRUE],
      // Should NOT block.
      'clean apply' => ['i want to apply for legal help', 'apply', FALSE],
      'clean hotline' => ['what is the hotline number', 'hotline', FALSE],
      'clean donate' => ['i want to donate money', 'donations', FALSE],
      'clean faq' => ['frequently asked questions', 'faq', FALSE],
    ];
  }

  /**
   * Tests multi-intent detection scenarios.
   *
   * @covers ::extract
   */
  public function testMultiIntentScenarios(): void {
    // Query that could match multiple intents.
    $query = 'i need to apply for help with my eviction case and get forms';
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];
    $phrases = $result['phrases'] ?? [];

    // Should detect both apply and eviction/forms related terms.
    $hasApply = in_array('apply', $keywords) || in_array('apply for help', $phrases);
    $hasEviction = in_array('eviction', $keywords) || strpos(implode(' ', $phrases), 'eviction') !== FALSE;
    $hasForms = in_array('forms', $keywords) || in_array('form', $keywords);

    $this->assertTrue($hasApply, "Should detect 'apply' in multi-intent query");
    $this->assertTrue($hasEviction, "Should detect 'eviction' in multi-intent query");
    $this->assertTrue($hasForms, "Should detect 'forms' in multi-intent query");
  }

  /**
   * Tests handling of very short queries.
   *
   * @covers ::extract
   * @dataProvider shortQueryProvider
   */
  public function testShortQueryHandling(string $query): void {
    $result = $this->keywordExtractor->extract($query);

    // Short queries should still return a valid structure.
    $this->assertIsArray($result);
    $this->assertArrayHasKey('normalized', $result);
    $this->assertArrayHasKey('keywords', $result);
  }

  /**
   * Data provider for short query tests.
   */
  public static function shortQueryProvider(): array {
    return [
      'one word' => ['help'],
      'two words' => ['need help'],
      'single letter' => ['hi'],
      'empty-ish' => ['   '],
    ];
  }

  /**
   * Tests hotline variations.
   *
   * @covers ::extract
   * @dataProvider hotlineVariationsProvider
   */
  public function testHotlineVariations(string $query): void {
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];
    $phrases = $result['phrases'] ?? [];
    $synonyms = $result['synonyms_applied'] ?? [];

    // Should have hotline-related terms.
    $hasHotline = in_array('hotline', $keywords)
      || in_array('phone', $keywords)
      || in_array('call', $keywords)
      || !empty($phrases)
      || !empty($synonyms);

    $this->assertTrue($hasHotline, "Should detect hotline intent for: $query");
  }

  /**
   * Data provider for hotline variation tests.
   */
  public static function hotlineVariationsProvider(): array {
    return [
      'standard hotline' => ['what is the hotline number'],
      'phone number' => ['whats your phone number'],
      'call someone' => ['can i call someone'],
      'talk to person' => ['i want to talk to a person'],
      'speak with someone' => ['speak with someone please'],
      'hot line split' => ['what is the hot line'],
      'advice line' => ['legal advice line'],
      'spanish llamar' => ['puedo llamar a alguien'],
      'typo speek' => ['i want to speek to someone'],
    ];
  }

  /**
   * Tests office/location variations.
   *
   * @covers ::extract
   * @dataProvider officeVariationsProvider
   */
  public function testOfficeVariations(string $query): void {
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];
    $phrases = $result['phrases'] ?? [];

    $hasOffice = in_array('office', $keywords)
      || in_array('location', $keywords)
      || in_array('hours', $keywords)
      || in_array('address', $keywords)
      || !empty($phrases);

    $this->assertTrue($hasOffice, "Should detect office intent for: $query");
  }

  /**
   * Data provider for office variation tests.
   */
  public static function officeVariationsProvider(): array {
    return [
      'office location' => ['where is your office location'],
      'office hours' => ['what are your office hours'],
      'near me' => ['office near me'],
      'boise office' => ['boise office address'],
      'twin falls' => ['twin falls office'],
      'spanish oficina' => ['donde esta la oficina'],
      'hours typo' => ['hours of opperation'],
    ];
  }

  /**
   * Tests donation variations.
   *
   * @covers ::extract
   * @dataProvider donationVariationsProvider
   */
  public function testDonationVariations(string $query): void {
    $result = $this->keywordExtractor->extract($query);

    $keywords = $result['keywords'] ?? [];

    $hasDonate = in_array('donate', $keywords)
      || in_array('donation', $keywords)
      || in_array('give', $keywords)
      || in_array('support', $keywords)
      || in_array('contribute', $keywords);

    $this->assertTrue($hasDonate, "Should detect donation intent for: $query");
  }

  /**
   * Data provider for donation variation tests.
   */
  public static function donationVariationsProvider(): array {
    return [
      'want to donate' => ['i want to donate'],
      'make donation' => ['how to make a donation'],
      'give money' => ['i want to give money'],
      'support work' => ['support your work'],
      'tax deductible' => ['is it tax deductible'],
      'spanish donar' => ['quiero donar'],
      'typo donatoin' => ['how do i make a donatoin'],
    ];
  }

}
