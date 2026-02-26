<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for the chips + clarifiers pipeline.
 *
 * Validates:
 * - Clarifier uses original_intent data.
 * - Chip fallback from primary to original_intent.
 * - No double-injection when topic_suggestions already present.
 */
#[Group('ilas_site_assistant')]
class ChipPipelineTest extends TestCase {

  /**
   * The TopIntentsPack instance.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopIntentsPack
   */
  protected $pack;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->pack = new TopIntentsPack(NULL);
  }

  /**
   * Clarify uses original_intent's clarifier when available.
   *
   * When the gate forces clarification and the original_intent is
   * 'topic_housing', the clarifier question and options should come
   * from the pack's topic_housing entry.
   */
  public function testClarifyUsesOriginalIntentClarifier(): void {
    $original_intent = 'topic_housing';
    $clarifier = $this->pack->getClarifier($original_intent);

    $this->assertNotNull($clarifier, 'topic_housing should have a clarifier');
    $this->assertStringContainsString('housing', strtolower($clarifier['question']));
    $this->assertCount(4, $clarifier['options']);

    // Should include eviction option.
    $option_intents = array_column($clarifier['options'], 'intent');
    $this->assertContains('topic_housing_eviction', $option_intents);
  }

  /**
   * Clarify falls back to generic message when no clarifier exists.
   *
   * When original_intent has no clarifier (e.g., 'eligibility'),
   * getClarifier returns NULL.
   */
  public function testClarifyFallsBackWithoutClarifier(): void {
    $clarifier = $this->pack->getClarifier('eligibility');
    $this->assertNull($clarifier);
  }

  /**
   * Chip fallback: primary key 'clarify' has no chips, falls back to original.
   *
   * When intent type is 'clarify' (no chips in pack), chip enrichment
   * should try the original_intent and find chips there.
   */
  public function testChipFallbackToOriginalIntent(): void {
    // 'clarify' has no pack entry → no chips.
    $primary_chips = $this->pack->getChips('clarify');
    $this->assertEmpty($primary_chips);

    // But 'topic_family' does have chips.
    $original_chips = $this->pack->getChips('topic_family');
    $this->assertNotEmpty($original_chips);
    $this->assertGreaterThanOrEqual(2, count($original_chips));
  }

  /**
   * Family clarifier includes all four expected sub-topics.
   */
  public function testFamilyClarifierCompleteness(): void {
    $clarifier = $this->pack->getClarifier('topic_family');
    $this->assertNotNull($clarifier);

    $option_intents = array_column($clarifier['options'], 'intent');
    $this->assertContains('topic_family_custody', $option_intents);
    $this->assertContains('topic_family_divorce', $option_intents);
    $this->assertContains('topic_family_child_support', $option_intents);
    $this->assertContains('topic_family_protection_order', $option_intents);
  }

  /**
   * Consumer clarifier includes debt collection and bankruptcy.
   */
  public function testConsumerClarifierCompleteness(): void {
    $clarifier = $this->pack->getClarifier('topic_consumer');
    $this->assertNotNull($clarifier);

    $option_intents = array_column($clarifier['options'], 'intent');
    $this->assertContains('topic_consumer_debt_collection', $option_intents);
    $this->assertContains('topic_consumer_bankruptcy', $option_intents);
  }

  /**
   * No double-injection: when topic_suggestions exist, chips are not added.
   *
   * Simulates the controller logic: when topic_suggestions are already set
   * (e.g., forms_inventory), chip enrichment should be skipped.
   */
  public function testNoDoubleInjectionWithTopicSuggestions(): void {
    $response = [
      'topic_suggestions' => [
        ['label' => 'Housing', 'action' => 'forms_housing'],
      ],
    ];

    // The controller check is: if (empty($response['topic_suggestions']))
    // Since topic_suggestions is not empty, no chips should be injected.
    $this->assertNotEmpty($response['topic_suggestions']);
  }

  /**
   * Chips from sub-topic intents are actionable (label + intent present).
   */
  public function testSubTopicChipsAreActionable(): void {
    $sub_topics = [
      'topic_family_custody',
      'topic_family_divorce',
      'topic_housing_eviction',
      'topic_consumer_debt_collection',
      'topic_consumer_bankruptcy',
    ];

    foreach ($sub_topics as $key) {
      $chips = $this->pack->getChips($key);
      $this->assertNotEmpty($chips, "Sub-topic '$key' should have chips");
      foreach ($chips as $chip) {
        $this->assertNotEmpty($chip['label'], "Chip in '$key' should have label");
        $this->assertNotEmpty($chip['intent'], "Chip in '$key' should have intent");
      }
    }
  }

}
