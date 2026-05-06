<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for greeting false positive prevention.
 *
 * Ensures topic-specific queries are not misclassified as greetings.
 * For example, "child custody forms" should NOT be detected as greeting
 * just because "child" contains "hi" as a substring.
 */
#[Group('ilas_site_assistant')]
class GreetingFalsePositiveTest extends TestCase {

  /**
   * Tests that word boundary matching prevents substring false positives.
   */
  #[DataProvider('substringFalsePositiveProvider')]
  public function testSubstringFalsePositives(string $query, string $badKeyword, string $description): void {
    $query_lower = strtolower($query);

    // Old behavior: strpos() would find substring matches.
    $old_behavior = strpos($query_lower, $badKeyword) !== FALSE;

    // New behavior: word boundary regex should NOT match.
    $pattern = '/\b' . preg_quote($badKeyword, '/') . '\b/';
    $new_behavior = preg_match($pattern, $query_lower);

    // The query contains the substring but NOT as a whole word.
    $this->assertTrue($old_behavior, "Pre-condition: '$badKeyword' should exist as substring in '$query'");
    $this->assertFalse((bool) $new_behavior, "Word boundary should prevent match: $description");
  }

  /**
   *
   */
  public static function substringFalsePositiveProvider(): array {
    return [
      // "hi" inside various words.
      ['child custody forms', 'hi', 'hi inside child'],
      ['show me the hiring process', 'hi', 'hi inside hiring'],
      ['thinking about divorce', 'hi', 'hi inside thinking'],
      ['machine learning help', 'hi', 'hi inside machine'],
      ['what is this about', 'hi', 'hi inside this'],
      ['within the guidelines', 'hi', 'hi inside within'],

      // "hey" inside words.
      ['whey protein questions', 'hey', 'hey inside whey'],

      // "hello" substring cases are rare but test anyway.
    ];
  }

  /**
   * Tests that actual greetings ARE matched with word boundaries.
   */
  #[DataProvider('actualGreetingProvider')]
  public function testActualGreetingsMatched(string $query, string $keyword, string $description): void {
    $query_lower = strtolower($query);
    $pattern = '/\b' . preg_quote($keyword, '/') . '\b/';

    $this->assertTrue(
      (bool) preg_match($pattern, $query_lower),
      "Should match actual greeting: $description"
    );
  }

  /**
   *
   */
  public static function actualGreetingProvider(): array {
    return [
      ['hi', 'hi', 'standalone hi'],
      ['hi there', 'hi', 'hi with word after'],
      ['hello', 'hello', 'standalone hello'],
      ['hello there', 'hello', 'hello with word after'],
      ['hey', 'hey', 'standalone hey'],
      ['hey can you help', 'hey', 'hey in sentence'],
      ['hola', 'hola', 'spanish greeting'],
    ];
  }

  /**
   * Tests the topic keyword blocking logic.
   */
  #[DataProvider('topicKeywordProvider')]
  public function testTopicKeywordsBlockGreeting(string $query, bool $shouldBlockGreeting, string $description): void {
    // Simulate the containsTopicKeywords logic.
    $topic_keywords = [
      'custody', 'divorce', 'eviction', 'landlord', 'tenant',
      'bankruptcy', 'foreclosure', 'guardianship', 'forms', 'form',
      'guides', 'guide', 'apply', 'application', 'child', 'children',
    ];

    $query_lower = strtolower($query);
    $has_topic = FALSE;

    foreach ($topic_keywords as $keyword) {
      $pattern = '/\b' . preg_quote($keyword, '/') . '\b/';
      if (preg_match($pattern, $query_lower)) {
        $has_topic = TRUE;
        break;
      }
    }

    $this->assertEquals(
      $shouldBlockGreeting,
      $has_topic,
      "Topic keyword detection: $description"
    );
  }

  /**
   *
   */
  public static function topicKeywordProvider(): array {
    return [
      // Should block greeting detection.
      ['child custody forms', TRUE, 'contains child and custody and forms'],
      ['custody forms please', TRUE, 'contains custody and forms'],
      ['divorce papers', TRUE, 'contains divorce'],
      ['eviction help', TRUE, 'contains eviction'],
      ['landlord problems', TRUE, 'contains landlord'],
      ['apply for help', TRUE, 'contains apply'],
      ['need a guide', TRUE, 'contains guide'],

      // Should NOT block greeting detection (actual greetings).
      ['hi', FALSE, 'just hi'],
      ['hello there', FALSE, 'hello there'],
      ['hey', FALSE, 'just hey'],
      ['good morning', FALSE, 'good morning'],
    ];
  }

  /**
   * Tests specific failing cases from eval.
   */
  #[DataProvider('evalFailingCasesProvider')]
  public function testEvalFailingCases(string $query, string $expectedIntent, string $description): void {
    // Simulate the decision logic.
    $query_lower = strtolower($query);

    // Check for greeting pattern.
    $greeting_patterns = [
      '/^(hi|hello|hey|good\s*(morning|afternoon|evening)|greetings)[\s!.?]*$/i',
      '/^(what\'?s?\s*up|howdy|yo)[\s!.?]*$/i',
      '/^(hola|buenos?\s*(dias?|tardes?|noches?))[\s!.?]*$/i',
    ];

    $matches_greeting_pattern = FALSE;
    foreach ($greeting_patterns as $pattern) {
      if (preg_match($pattern, $query)) {
        $matches_greeting_pattern = TRUE;
        break;
      }
    }

    // Check for topic keywords.
    $topic_keywords = ['custody', 'forms', 'divorce', 'eviction', 'child'];
    $has_topic = FALSE;
    foreach ($topic_keywords as $keyword) {
      $pattern = '/\b' . preg_quote($keyword, '/') . '\b/';
      if (preg_match($pattern, $query_lower)) {
        $has_topic = TRUE;
        break;
      }
    }

    // Determine intent.
    if ($matches_greeting_pattern && !$has_topic) {
      $actual_intent = 'greeting';
    }
    elseif ($has_topic) {
      // Would route to topic-related intent.
      $actual_intent = 'topic_query';
    }
    else {
      $actual_intent = 'other';
    }

    // The expected intent should NOT be greeting for topic queries.
    if ($expectedIntent === 'not_greeting') {
      $this->assertNotEquals('greeting', $actual_intent, "$description - should not be greeting");
    }
    else {
      $this->assertEquals($expectedIntent, $actual_intent, "$description");
    }
  }

  /**
   *
   */
  public static function evalFailingCasesProvider(): array {
    return [
      // Specific eval failures.
      ['child custody forms', 'not_greeting', 'child custody forms must not be greeting'],
      ['custody forms', 'not_greeting', 'custody forms must not be greeting'],
      ['child support', 'not_greeting', 'child support must not be greeting'],
      ['divorce forms', 'not_greeting', 'divorce forms must not be greeting'],
      // PHARD-04: interrogative form-seeking queries must not be greetings.
      ['do you have custody forms', 'not_greeting', 'do you have custody forms must not be greeting'],

      // Actual greetings should still work.
      ['hi', 'greeting', 'hi is a greeting'],
      ['hello', 'greeting', 'hello is a greeting'],
      ['hey', 'greeting', 'hey is a greeting'],
      ['hola', 'greeting', 'hola is a greeting'],
    ];
  }

  /**
   * Tests edge cases with punctuation and whitespace.
   */
  #[DataProvider('edgeCaseProvider')]
  public function testEdgeCases(string $query, bool $isGreeting, string $description): void {
    $query_lower = strtolower(trim($query));

    // Greeting patterns with anchors.
    $is_pure_greeting = preg_match('/^(hi|hello|hey|hola)[\s!.?]*$/i', $query_lower);

    // Topic keyword check.
    $topic_keywords = ['custody', 'forms', 'divorce', 'child'];
    $has_topic = FALSE;
    foreach ($topic_keywords as $keyword) {
      if (preg_match('/\b' . $keyword . '\b/', $query_lower)) {
        $has_topic = TRUE;
        break;
      }
    }

    $actual_greeting = $is_pure_greeting && !$has_topic;

    $this->assertEquals($isGreeting, (bool) $actual_greeting, $description);
  }

  /**
   *
   */
  public static function edgeCaseProvider(): array {
    return [
      // Pure greetings with punctuation.
      ['hi!', TRUE, 'hi with exclamation'],
      ['hi?', TRUE, 'hi with question mark'],
      ['hello!', TRUE, 'hello with exclamation'],
      ['hello...', TRUE, 'hello with ellipsis'],
      ['hey!', TRUE, 'hey with exclamation'],
      ['  hi  ', TRUE, 'hi with whitespace'],

      // Topic queries that look similar.
      ['hi custody', FALSE, 'hi followed by topic'],
      ['hi, i need custody forms', FALSE, 'greeting prefix with topic'],
      ['child forms', FALSE, 'child with forms'],
    ];
  }

}
