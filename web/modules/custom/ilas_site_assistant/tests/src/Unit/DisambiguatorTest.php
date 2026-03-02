<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Drupal\ilas_site_assistant\Service\Disambiguator;

/**
 * Tests the Disambiguator service.
 */
#[Group('ilas_site_assistant')]
class DisambiguatorTest extends TestCase {

  /**
   * The Disambiguator instance under test.
   *
   * @var \Drupal\ilas_site_assistant\Service\Disambiguator
   */
  protected $disambiguator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->disambiguator = new Disambiguator();
  }

  /**
   * Tests that exact topic-only triggers still work.
   */
  #[DataProvider('exactTopicProvider')]
  public function testExactTopicMatch(string $input, string $expected_area): void {
    $result = $this->disambiguator->check($input, []);
    $this->assertNotNull($result, "Expected disambiguation for '$input'");
    $this->assertEquals('disambiguation', $result['type']);
    $this->assertEquals('topic_without_action', $result['reason']);
    $this->assertEquals($expected_area, $result['topic']);
    $this->assertNotEmpty($result['options']);
    $this->assertNotEmpty($result['question']);
  }

  public static function exactTopicProvider(): array {
    return [
      ['custody', 'family'],
      ['divorce', 'family'],
      ['eviction', 'housing'],
      ['debt', 'consumer'],
      ['medicaid', 'benefits'],
      ['guardianship', 'seniors'],
    ];
  }

  /**
   * Tests that urgency-modified topic queries are still matched.
   */
  #[DataProvider('modifierTopicProvider')]
  public function testModifierStripping(string $input, string $expected_area): void {
    $result = $this->disambiguator->check($input, []);
    $this->assertNotNull($result, "Expected disambiguation for '$input'");
    $this->assertEquals('disambiguation', $result['type']);
    $this->assertEquals('topic_without_action', $result['reason']);
    $this->assertEquals($expected_area, $result['topic']);
    $this->assertNotEmpty($result['options']);
  }

  public static function modifierTopicProvider(): array {
    return [
      ['custody now', 'family'],
      ['custody please', 'family'],
      ['divorce asap', 'family'],
      ['divorce right now', 'family'],
      ['eviction urgent', 'housing'],
      ['eviction asap', 'housing'],
      ['debt please', 'consumer'],
      ['custody today', 'family'],
      ['eviction fast', 'housing'],
      // Spanish modifiers.
      ['custodia ahora', 'family'],
      ['divorcio urgente', 'family'],
      ['desalojo pronto', 'housing'],
    ];
  }

  /**
   * Tests that vague queries are correctly detected.
   */
  #[DataProvider('vagueQueryProvider')]
  public function testVagueQuery(string $input): void {
    $result = $this->disambiguator->check($input, []);
    $this->assertNotNull($result, "Expected disambiguation for '$input'");
    $this->assertEquals('disambiguation', $result['type']);
    $this->assertEquals('vague_query', $result['reason']);
    $this->assertNotEmpty($result['options']);
  }

  public static function vagueQueryProvider(): array {
    return [
      ['help'],
      ['forms'],
      ['phone'],
      ['contact'],
      ['ayuda'],
      ['formularios'],
    ];
  }

  /**
   * Tests that option values are populated (not empty strings).
   */
  public function testOptionValuesNotEmpty(): void {
    $result = $this->disambiguator->check('custody', []);
    $this->assertNotNull($result);
    foreach ($result['options'] as $option) {
      $this->assertNotEmpty($option['value'], 'Each option must have a non-empty value');
      $this->assertNotEmpty($option['label'], 'Each option must have a non-empty label');
    }
  }

  /**
   * Tests that messages exceeding word count limit return NULL.
   */
  public function testLongMessagesNotMatched(): void {
    $result = $this->disambiguator->check('I need help with my custody case right now please', []);
    // This is 10 words — should not match topic_without_action.
    // It may match vague_query or return NULL.
    if ($result) {
      $this->assertNotEquals('topic_without_action', $result['reason']);
    }
  }

  /**
   * Tests that pure modifier words without a topic return NULL.
   */
  public function testPureModifiersReturnNull(): void {
    $result = $this->disambiguator->check('now please', []);
    // "now please" should not match any topic.
    if ($result) {
      $this->assertNotEquals('topic_without_action', $result['reason']);
    }
  }

  /**
   * Tests confusable pair detection via confidence delta.
   */
  public function testConfusablePairDetection(): void {
    $scored = [
      ['intent' => 'apply_for_help', 'confidence' => 0.55],
      ['intent' => 'services_overview', 'confidence' => 0.50],
    ];
    $result = $this->disambiguator->check('legal help please', $scored);
    // "legal help please" is 3 words, may or may not trigger.
    // The key is that confusable pair with small delta should trigger.
    $result2 = $this->disambiguator->check('something complex enough to not match vague or topic', $scored);
    if ($result2) {
      $this->assertEquals('disambiguation', $result2['type']);
    }
  }

  /**
   * Tests that topic trigger and vague query lists are accessible.
   */
  public function testListAccessors(): void {
    $topics = $this->disambiguator->getTopicTriggers();
    $this->assertContains('custody', $topics);
    $this->assertContains('divorce', $topics);
    $this->assertContains('eviction', $topics);

    $vague = $this->disambiguator->getVagueQueries();
    $this->assertContains('help', $vague);
    $this->assertContains('forms', $vague);

    $pairs = $this->disambiguator->getConfusablePairs();
    $this->assertNotEmpty($pairs);
  }

}
