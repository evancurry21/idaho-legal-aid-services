<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\TurnClassifier;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for TurnClassifier — turn type classification.
 */
#[Group('ilas_site_assistant')]
class TurnClassifierTest extends TestCase {

  /**
   * Helper to build a history entry.
   */
  private function entry(string $intent, int $timestamp, string $area = ''): array {
    $entry = [
      'role' => 'user',
      'text' => "test message for $intent",
      'intent' => $intent,
      'safety_flags' => [],
      'timestamp' => $timestamp,
    ];
    if ($area) {
      $entry['area'] = $area;
    }
    return $entry;
  }

  /**
   * Default turn with no history is NEW.
   */
  public function testDefaultIsNew(): void {
    $result = TurnClassifier::classifyTurn('I need help with housing', [], time());
    $this->assertEquals(TurnClassifier::TURN_NEW, $result);
  }

  /**
   * Explicit reset signals return RESET.
   */
  public function testResetSignalDetected(): void {
    $history = [$this->entry('topic_housing', time() - 60)];
    $result = TurnClassifier::classifyTurn('new question about custody', $history, time());
    $this->assertEquals(TurnClassifier::TURN_RESET, $result);
  }

  /**
   * Spanish reset signal detected.
   */
  public function testResetSignalSpanish(): void {
    $history = [$this->entry('topic_housing', time() - 60)];
    $result = TurnClassifier::classifyTurn('otra pregunta por favor', $history, time());
    $this->assertEquals(TurnClassifier::TURN_RESET, $result);
  }

  /**
   * "what forms do you have" is INVENTORY.
   */
  public function testFormsInventoryDetected(): void {
    $result = TurnClassifier::classifyTurn('what forms do you have', [], time());
    $this->assertEquals(TurnClassifier::TURN_INVENTORY, $result);
  }

  /**
   * "show me all the guides" is INVENTORY.
   */
  public function testGuidesInventoryDetected(): void {
    $result = TurnClassifier::classifyTurn('show me all the guides', [], time());
    $this->assertEquals(TurnClassifier::TURN_INVENTORY, $result);
  }

  /**
   * "what services do you offer" is INVENTORY.
   */
  public function testServicesInventoryDetected(): void {
    $result = TurnClassifier::classifyTurn('what services do you offer', [], time());
    $this->assertEquals(TurnClassifier::TURN_INVENTORY, $result);
  }

  /**
   * "list everything" is INVENTORY.
   */
  public function testListEverythingIsInventory(): void {
    $result = TurnClassifier::classifyTurn('list everything', [], time());
    $this->assertEquals(TurnClassifier::TURN_INVENTORY, $result);
  }

  /**
   * Spanish inventory pattern detected.
   */
  public function testInventorySpanish(): void {
    $result = TurnClassifier::classifyTurn('que formularios tienen', [], time());
    $this->assertEquals(TurnClassifier::TURN_INVENTORY, $result);
  }

  /**
   * Anaphoric "does that" with history is FOLLOW_UP.
   */
  public function testAnaphoricFollowUp(): void {
    $now = time();
    $history = [$this->entry('topic_family', $now - 60, 'family')];
    $result = TurnClassifier::classifyTurn('does that give me custody information', $history, $now);
    $this->assertEquals(TurnClassifier::TURN_FOLLOW_UP, $result);
  }

  /**
   * "tell me more" with history is FOLLOW_UP.
   */
  public function testTellMeMoreFollowUp(): void {
    $now = time();
    $history = [$this->entry('topic_housing', $now - 60)];
    $result = TurnClassifier::classifyTurn('tell me more about that', $history, $now);
    $this->assertEquals(TurnClassifier::TURN_FOLLOW_UP, $result);
  }

  /**
   * Short message (<=4 words) with recent history is FOLLOW_UP.
   */
  public function testShortMessageFollowUp(): void {
    $now = time();
    $history = [$this->entry('topic_family', $now - 60)];
    $result = TurnClassifier::classifyTurn('and custody?', $history, $now);
    $this->assertEquals(TurnClassifier::TURN_FOLLOW_UP, $result);
  }

  /**
   * Anaphoric reference without history is NEW (not FOLLOW_UP).
   */
  public function testAnaphoricWithoutHistoryIsNew(): void {
    $result = TurnClassifier::classifyTurn('tell me more about that', [], time());
    $this->assertEquals(TurnClassifier::TURN_NEW, $result);
  }

  /**
   * Old history (>10 min) prevents FOLLOW_UP detection.
   */
  public function testStaleHistoryPreventsFollowUp(): void {
    $now = time();
    $history = [$this->entry('topic_housing', $now - 700)];  // >600s ago
    $result = TurnClassifier::classifyTurn('and what about that?', $history, $now);
    $this->assertEquals(TurnClassifier::TURN_NEW, $result);
  }

  /**
   * resolveInventoryType correctly identifies guides.
   */
  public function testResolveInventoryTypeGuides(): void {
    $result = TurnClassifier::resolveInventoryType('what guides do you have');
    $this->assertEquals('guides_inventory', $result);
  }

  /**
   * resolveInventoryType correctly identifies services.
   */
  public function testResolveInventoryTypeServices(): void {
    $result = TurnClassifier::resolveInventoryType('what services do you offer');
    $this->assertEquals('services_inventory', $result);
  }

  /**
   * resolveInventoryType defaults to forms.
   */
  public function testResolveInventoryTypeDefaultForms(): void {
    $result = TurnClassifier::resolveInventoryType('list everything');
    $this->assertEquals('forms_inventory', $result);
  }

}
