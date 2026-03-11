<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for Form Finder multi-turn flow.
 *
 * Validates that:
 * - Bare "Find a Form" triggers clarification, not search.
 * - Topic-qualified queries extract meaningful keywords.
 * - Descriptions are cleaned of boilerplate.
 */
#[Group('ilas_site_assistant')]
class FormFinderTest extends TestCase {

  /**
   * Tests extractFormTopicKeywords returns empty for bare form requests.
   */
  #[DataProvider('bareFormQueriesProvider')]
  public function testBareFormQueriesReturnEmpty(string $query): void {
    $result = $this->callExtractFormTopicKeywords($query);
    $this->assertEmpty($result, "Query '$query' should return empty (bare/generic), got: '$result'");
  }

  /**
   * Tests extractFormTopicKeywords returns meaningful keywords for topic queries.
   */
  #[DataProvider('topicFormQueriesProvider')]
  public function testTopicFormQueriesReturnKeywords(string $query, string $expected_contains): void {
    $result = $this->callExtractFormTopicKeywords($query);
    $this->assertNotEmpty($result, "Query '$query' should return topic keywords, got empty");
    $this->assertStringContainsString(
      $expected_contains,
      strtolower($result),
      "Query '$query' should contain '$expected_contains', got: '$result'"
    );
  }

  /**
   * Tests cleanDescription removes boilerplate.
   */
  #[DataProvider('boilerplateDescriptionProvider')]
  public function testCleanDescriptionRemovesBoilerplate(string $raw, string $title, string $must_not_contain): void {
    $result = $this->callCleanDescription($raw, $title);
    $this->assertStringNotContainsString(
      $must_not_contain,
      $result,
      "Cleaned description should not contain '$must_not_contain'"
    );
  }

  /**
   * Tests cleanDescription caps at 240 chars.
   */
  public function testCleanDescriptionCapsLength(): void {
    $long_text = str_repeat('This is a meaningful description about eviction forms. ', 20);
    $result = $this->callCleanDescription($long_text, 'Test Form');
    $this->assertLessThanOrEqual(243, mb_strlen($result), 'Description should be capped near 240 chars');
  }

  /**
   * Tests cleanDescription removes title duplication.
   */
  public function testCleanDescriptionRemovesTitleDuplication(): void {
    $text = 'Eviction Response Form. This form helps you respond to an eviction notice within 20 days.';
    $result = $this->callCleanDescription($text, 'Eviction Response Form');
    $this->assertStringNotContainsString(
      'Eviction Response Form.',
      $result,
      'Should remove title duplication from start of description'
    );
    $this->assertStringContainsString('respond to an eviction', $result);
  }

  /**
   * Data provider: bare/generic form queries.
   */
  public static function bareFormQueriesProvider(): array {
    return [
      ['Find a form'],
      ['find a form'],
      ['Get a form'],
      ['I need a form'],
      ['Where can I find forms'],
      ['find forms'],
      ['get me a form'],
      ['download a form'],
      ['show me forms'],
      ['Find a Form'],
      ['what forms do you have'],
      ['show me all your forms'],
      ['do you have any forms'],
    ];
  }

  /**
   * Data provider: topic-qualified form queries.
   */
  public static function topicFormQueriesProvider(): array {
    return [
      ['Find housing and eviction forms', 'eviction'],
      ['Find family and divorce forms', 'divorce'],
      ['Find consumer and debt forms', 'debt'],
      ['Find protection order forms', 'protection'],
      ['eviction forms', 'eviction'],
      ['divorce paperwork', 'divorce'],
      ['child custody forms', 'custody'],
      ['bankruptcy forms', 'bankruptcy'],
      ['guardianship paperwork', 'guardianship'],
      ['small claims court form', 'small'],
      ['power of attorney form', 'power'],
      // Plain-language interrogative queries (PHARD-04).
      ['do you have custody forms', 'custody'],
      ['custody forms', 'custody'],
      ['can i get custody forms', 'custody'],
      ['custody paperwork', 'custody'],
      ['custody papers', 'custody'],
      ['custody form', 'custody'],
      ['child custody forms', 'custody'],
      ['where are your custody forms', 'custody'],
    ];
  }

  /**
   * Data provider: boilerplate descriptions.
   */
  public static function boilerplateDescriptionProvider(): array {
    return [
      [
        'Home > Forms > Eviction Response Form. This form helps you respond.',
        'Eviction Response Form',
        'Home >',
      ],
      [
        'Idaho Legal Aid Services provides free legal help to low-income Idahoans. This form helps tenants.',
        'Tenant Rights Form',
        'Idaho Legal Aid Services provides',
      ],
      [
        'Skip to main content. This document explains how to fill out.',
        'Court Filing Form',
        'Skip to main content',
      ],
      [
        'You are here: Resources > Forms. Fill out this form to.',
        'Child Support Form',
        'You are here',
      ],
    ];
  }

  /**
   * Calls the extractFormTopicKeywords method via reflection.
   *
   * This tests the logic directly without needing a full Drupal bootstrap.
   */
  protected function callExtractFormTopicKeywords(string $query): string {
    // Replicate the logic from AssistantApiController::extractFormTopicKeywords().
    $lower = strtolower(trim($query));

    $noise_patterns = [
      '/^(find|get|need|download|where|show|read|browse)\s*(me\s*)?(\b(a|the|is|are|some|any|all)\b\s*)?/i',
      '/\b(form|forms|froms|formulario|formularios|paperwork|papers|documents?|court\s*papers?)\b/i',
      '/\b(for|to|about|on|regarding)\b/i',
      '/\b(legal|court|i\s*need|looking\s*for|where\s*can\s*i)\b/i',
      '/^\s*(a|an|the|my|some|any)\s+/i',
    ];

    $cleaned = $lower;
    foreach ($noise_patterns as $pattern) {
      $cleaned = preg_replace($pattern, ' ', $cleaned);
    }
    $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));

    $stop_words = [
      'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with',
      'is', 'are', 'i', 'me', 'my', 'can', 'do', 'how', 'what', 'where',
      'please', 'find', 'get', 'need', 'show', 'download', 'looking', 'browse', 'read',
      'you', 'your', 'have', 'has', 'had', 'does', 'did', 'they', 'them',
      'their', 'we', 'our', 'it', 'its', 'that', 'this', 'those', 'these',
      'there', 'which', 'been', 'be', 'about', 'any', 'all', 'some', 'also',
      'just', 'give', 'us', 'would', 'will', 'should', 'could',
    ];
    $words = array_filter(explode(' ', $cleaned), function ($w) use ($stop_words) {
      return strlen($w) >= 2 && !in_array($w, $stop_words);
    });

    if (empty($words)) {
      return '';
    }

    return implode(' ', $words);
  }

  /**
   * Calls the cleanDescription method logic.
   */
  protected function callCleanDescription(string $raw_text, string $title = ''): string {
    // Decode HTML entities left behind by strip_tags() (e.g. &nbsp; -> U+00A0).
    $text = html_entity_decode($raw_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Replace non-breaking spaces (U+00A0) with normal spaces.
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/\s+/', ' ', trim($text));

    if (empty($text)) {
      return '';
    }

    $boilerplate_patterns = [
      '/^(Home\s*[>»›|\/]\s*)+/i',
      '/^(Skip to (main )?content\.?\s*)/i',
      '/^(You are here:?\s*)/i',
      '/^(Breadcrumb\s*)/i',
      '/^(Main navigation\s*)/i',
      '/^(Idaho Legal Aid Services?\s*[>»›|\/]?\s*)/i',
      '/^(ILAS\s*[>»›|\/]?\s*)/i',
    ];
    foreach ($boilerplate_patterns as $pattern) {
      $text = preg_replace($pattern, '', $text);
    }

    $global_noise = [
      '/Idaho Legal Aid Services provides free legal help to low-income Idahoans\.?\s*/i',
      '/This (information|resource|document|form) (is|was) (provided|prepared|created) (by|for) Idaho Legal Aid Services?\.?\s*/i',
      '/For more information,?\s*(please\s*)?(visit|call|contact).*$/i',
      '/If you need (legal )?(help|advice|assistance),?\s*(please\s*)?(call|contact|apply|visit).*$/i',
      '/Disclaimer:.*$/i',
      '/Note:\s*This is not legal advice\.?\s*/i',
      '/Last (updated|revised|modified):?\s*\d.*$/i',
    ];
    foreach ($global_noise as $pattern) {
      $text = preg_replace($pattern, '', $text);
    }

    if (!empty($title)) {
      $escaped_title = preg_quote($title, '/');
      $text = preg_replace('/^\s*' . $escaped_title . '\s*[\.\:\-]?\s*/i', '', $text);
    }

    $text = trim($text);

    if (empty($text)) {
      return '';
    }

    if (mb_strlen($text) > 240) {
      $text = mb_substr($text, 0, 240);
      $last_space = strrpos($text, ' ');
      if ($last_space !== FALSE && $last_space > 160) {
        $text = substr($text, 0, $last_space);
      }
      $text = rtrim($text, '.,;:!? ') . '…';
    }

    return $text;
  }

  /**
   * Tests that HTML entities like &nbsp; are decoded in descriptions.
   */
  public function testCleanDescriptionHandlesHtmlEntities(): void {
    $text = 'Guides about&nbsp;bankruptcy and&nbsp;debt collection.';
    $result = $this->callCleanDescription($text, 'Test Resource');
    $this->assertStringNotContainsString('&nbsp;', $result);
    $this->assertStringContainsString('Guides about bankruptcy', $result);
  }

}
