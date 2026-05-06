<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\Disambiguator;
use Drupal\ilas_site_assistant\Service\HistoryIntentResolver;
use Drupal\ilas_site_assistant\Service\TurnClassifier;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit fixture coverage for turn classification and intent helpers.
 *
 * This is not an API-level golden transcript test. It does not call
 * /assistant/api/message, retrieval, the LLM layer, or public response
 * rendering. It only replays fixture turns through TurnClassifier,
 * HistoryIntentResolver, Disambiguator, and TopIntentsPack.
 */
#[Group('ilas_site_assistant')]
class ConversationIntentFixtureUnitTest extends TestCase {

  /**
   * Parsed conversation intent fixtures.
   *
   * @var array
   */
  protected static $fixtures;

  /**
   * The TopIntentsPack instance.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopIntentsPack
   */
  protected $pack;

  /**
   * {@inheritdoc}
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    $yaml_path = dirname(__DIR__, 2) . '/goldens/conversation-intent-fixtures.yml';
    if (!file_exists($yaml_path)) {
      self::markTestSkipped("Conversation intent fixture file not found: $yaml_path");
    }
    self::$fixtures = Yaml::parseFile($yaml_path);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->pack = new TopIntentsPack(NULL);
  }

  /**
   * Validates the YAML structure loads correctly.
   */
  public function testFixtureFileLoads(): void {
    $this->assertNotEmpty(self::$fixtures);
    $this->assertArrayHasKey('fixtures', self::$fixtures);
    $this->assertNotEmpty(self::$fixtures['fixtures']);
  }

  /**
   * Validates each fixture has required fields.
   */
  public function testFixtureStructure(): void {
    foreach (self::$fixtures['fixtures'] as $i => $fixture) {
      $this->assertArrayHasKey('id', $fixture, "Fixture #$i missing id");
      $this->assertArrayHasKey('turns', $fixture, "Fixture '{$fixture['id']}' missing turns");
      $this->assertNotEmpty($fixture['turns'], "Fixture '{$fixture['id']}' has no turns");

      foreach ($fixture['turns'] as $j => $turn) {
        $this->assertArrayHasKey('message', $turn,
          "Fixture '{$fixture['id']}' turn #$j missing message");
        $this->assertArrayHasKey('expected_turn_type', $turn,
          "Fixture '{$fixture['id']}' turn #$j missing expected_turn_type");
      }
    }
  }

  /**
   * Replays all fixtures and validates turn types.
   */
  public function testTurnTypeClassification(): void {
    $now = 1000000;

    foreach (self::$fixtures['fixtures'] as $fixture) {
      $id = $fixture['id'];
      $server_history = [];
      $turn_time = $now;

      foreach ($fixture['turns'] as $j => $turn) {
        $message = $turn['message'];
        $expected_type = $turn['expected_turn_type'];

        $actual_type = TurnClassifier::classifyTurn($message, $server_history, $turn_time);

        $this->assertEquals($expected_type, $actual_type,
          "Fixture '$id' turn #$j ('$message'): expected $expected_type, got $actual_type");

        // Build simulated history entry for next turn.
        $intent = 'unknown';
        if ($actual_type === TurnClassifier::TURN_INVENTORY) {
          $intent = TurnClassifier::resolveInventoryType($message);
        }

        // Check synonym matching for intent resolution.
        $synonym_match = $this->pack->matchSynonyms($message);
        if ($synonym_match) {
          $intent = $synonym_match;
        }

        $server_history[] = [
          'role' => 'user',
          'text' => $message,
          'intent' => $intent,
          'timestamp' => $turn_time,
          'safety_flags' => [],
        ];

        $turn_time += 30;
      }
    }
  }

  /**
   * Validates expected intents match when specified.
   */
  public function testExpectedIntents(): void {
    $disambiguator = new Disambiguator();

    foreach (self::$fixtures['fixtures'] as $fixture) {
      $id = $fixture['id'];

      foreach ($fixture['turns'] as $j => $turn) {
        $expected_intent = $turn['expected_intent'] ?? NULL;
        if ($expected_intent === NULL) {
          continue;
        }

        $message = $turn['message'];

        // For INVENTORY turns, check resolveInventoryType.
        if ($turn['expected_turn_type'] === 'INVENTORY') {
          $actual = TurnClassifier::resolveInventoryType($message);
          $this->assertEquals($expected_intent, $actual,
            "Fixture '$id' turn #$j: expected inventory type '$expected_intent', got '$actual'");
        }

        // For disambiguation turns, verify Disambiguator triggers.
        if ($turn['expected_turn_type'] === 'NEW' && $expected_intent === 'disambiguation') {
          $result = $disambiguator->check($message, []);
          $this->assertNotNull($result,
            "Fixture '$id' turn #$j: '$message' should trigger disambiguation");
          $this->assertSame('disambiguation', $result['type'],
            "Fixture '$id' turn #$j: '$message' should return type=disambiguation");
        }
      }
    }
  }

  /**
   * Validates disambiguation options expose canonical 'intent' key, no 'value'.
   */
  public function testDisambiguationOptionsHaveIntentKey(): void {
    $disambiguator = new Disambiguator();

    foreach (self::$fixtures['fixtures'] as $fixture) {
      $id = $fixture['id'];

      foreach ($fixture['turns'] as $j => $turn) {
        $expected_intent = $turn['expected_intent'] ?? NULL;
        if ($expected_intent !== 'disambiguation') {
          continue;
        }

        $message = $turn['message'];
        $result = $disambiguator->check($message, []);
        $this->assertNotNull($result,
          "Fixture '$id' turn #$j: '$message' should trigger disambiguation");

        foreach ($result['options'] as $k => $option) {
          $this->assertArrayHasKey('intent', $option,
            "Fixture '$id' turn #$j option #$k must have 'intent' key");
          $this->assertNotEmpty($option['intent'],
            "Fixture '$id' turn #$j option #$k must have non-empty 'intent'");
          $this->assertArrayNotHasKey('value', $option,
            "Fixture '$id' turn #$j option #$k must not have deprecated 'value' key");
        }
      }
    }
  }

  /**
   * Validates chip presence for turns that expect chips.
   */
  public function testChipPresence(): void {
    foreach (self::$fixtures['fixtures'] as $fixture) {
      $id = $fixture['id'];

      foreach ($fixture['turns'] as $j => $turn) {
        $expected_chips = $turn['expected_chips_present'] ?? NULL;
        if ($expected_chips === NULL) {
          continue;
        }

        $message = $turn['message'];

        // Check if intent has chips in the pack.
        $synonym_match = $this->pack->matchSynonyms($message);
        if ($synonym_match && $expected_chips) {
          $chips = $this->pack->getChips($synonym_match);
          $this->assertNotEmpty($chips,
            "Fixture '$id' turn #$j ('$message' → '$synonym_match'): expected chips but pack has none");
        }

        // For INVENTORY turns, verify the inventory intent has chips.
        if ($turn['expected_turn_type'] === 'INVENTORY' && $expected_chips) {
          $inventory_type = TurnClassifier::resolveInventoryType($message);
          $chips = $this->pack->getChips($inventory_type);
          $this->assertNotEmpty($chips,
            "Fixture '$id' turn #$j: inventory '$inventory_type' should have chips");
        }
      }
    }
  }

  /**
   * Validates that history resolver finds context in multi-turn fixtures.
   */
  public function testHistoryResolutionInMultiTurn(): void {
    $now = 1000000;

    // Replay fixture A scenario (happy_path_custody).
    $history = [
      [
        'role' => 'user',
        'text' => 'I need help with custody',
        'intent' => 'topic_family_custody',
        'area' => 'family',
        'timestamp' => $now - 60,
        'safety_flags' => [],
      ],
    ];

    $resolved = HistoryIntentResolver::resolveFromHistory(
      $history, 'tell me more about that', $now
    );

    $this->assertNotNull($resolved, 'History resolver should find context for follow-up');
    $this->assertEquals('topic_family_custody', $resolved['intent']);
  }

}
