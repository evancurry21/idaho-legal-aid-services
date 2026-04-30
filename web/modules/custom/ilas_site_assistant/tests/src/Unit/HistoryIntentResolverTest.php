<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\HistoryIntentResolver;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for HistoryIntentResolver — history-based intent fallback.
 */
#[Group('ilas_site_assistant')]
class HistoryIntentResolverTest extends TestCase {

  /**
   * Helper to build a history entry.
   */
  private function entry(string $intent, int $timestamp, string $text = ''): array {
    return [
      'role' => 'user',
      'text' => $text ?: "test message for $intent",
      'intent' => $intent,
      'safety_flags' => [],
      'timestamp' => $timestamp,
    ];
  }

  /**
   * 3 turns of housing, then an ambiguous follow-up.
   */
  public function testTopicEstablishedFollowUp(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 120, 'I am being evicted'),
      $this->entry('topic_housing', $now - 90, 'what are my rights as a tenant'),
      $this->entry('topic_housing', $now - 60, 'can they change the locks'),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about those mediation programs?', $now
    );

    $this->assertNotNull($result);
    $this->assertEquals('topic_housing', $result['intent']);
    $this->assertEquals(3, $result['turns_analyzed']);
    $this->assertGreaterThanOrEqual(0.5, $result['confidence']);
  }

  /**
   * Direct routing handles topic shifts — this tests that the resolver
   * itself would still return housing if called (controller won't call it
   * because direct routing won't return unknown for "bankruptcy").
   */
  public function testTopicShiftHandledByDirectRouting(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 120),
      $this->entry('topic_housing', $now - 90),
      $this->entry('topic_housing', $now - 60),
    ];

    // "Switching gears" triggers reset signal — resolver returns NULL.
    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'Switching gears: tell me about bankruptcy', $now
    );

    $this->assertNull($result);
  }

  /**
   * Explicit reset signal suppresses fallback.
   */
  public function testExplicitResetSignal(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 120),
      $this->entry('topic_housing', $now - 90),
      $this->entry('topic_housing', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'New question: where is your office?', $now
    );

    $this->assertNull($result);
  }

  /**
   * Short correction words suppress sticky history fallback.
   */
  public function testCorrectionResetSignals(): void {
    $this->assertTrue(HistoryIntentResolver::detectResetSignal('Actually divorce.'));
    $this->assertTrue(HistoryIntentResolver::detectResetSignal('Instead custody.'));
  }

  /**
   * History entries older than the time window are ignored.
   */
  public function testStaleHistoryIgnored(): void {
    $now = 1000000;
    $history = [
    // >600s ago
      $this->entry('topic_housing', $now - 700),
    // >600s ago
      $this->entry('topic_housing', $now - 650),
    // >600s ago
      $this->entry('topic_housing', $now - 620),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about mediation?', $now
    );

    $this->assertNull($result);
  }

  /**
   * Direct match (not unknown) means controller won't call resolver at all.
   * This test confirms the resolver's behavior is independent.
   */
  public function testDirectMatchPreserved(): void {
    // This is really a controller-level concern, but we verify the resolver
    // returns a result if called — the controller is responsible for NOT
    // calling it when direct routing succeeds.
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'I want to apply for help', $now
    );

    // Resolver would return housing, but controller won't call it.
    $this->assertNotNull($result);
    $this->assertEquals('topic_housing', $result['intent']);
  }

  /**
   * History of only excluded intents returns NULL.
   */
  public function testExcludedIntentsNotPropagated(): void {
    $now = 1000000;
    $history = [
      $this->entry('greeting', $now - 120),
      $this->entry('unknown', $now - 90),
      $this->entry('disambiguation', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'tell me more', $now
    );

    $this->assertNull($result);
  }

  /**
   * Tied intents produce no fallback.
   */
  public function testTiedIntentsNoFallback(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 120),
      $this->entry('topic_housing', $now - 100),
      $this->entry('topic_family', $now - 80),
      $this->entry('topic_family', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about that?', $now
    );

    $this->assertNull($result);
  }

  /**
   * Single turn of housing is enough (1/1 = 100% dominance).
   */
  public function testSingleTurnDominance(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about mediation?', $now
    );

    $this->assertNotNull($result);
    $this->assertEquals('topic_housing', $result['intent']);
    $this->assertEquals(1.0, $result['confidence']);
    $this->assertEquals(1, $result['turns_analyzed']);
  }

  /**
   * Older history without area metadata still exposes topic context.
   */
  public function testExtractTopicContextInfersFromHistoryText(): void {
    $context = HistoryIntentResolver::extractTopicContext([
      $this->entry('unknown', 1000000, 'I got an eviction notice.'),
    ]);

    $this->assertSame('housing', $context['area'] ?? NULL);
    $this->assertSame('eviction', $context['topic'] ?? NULL);
  }

  /**
   * Dominance threshold enforced: 2/4 = 50% meets threshold for family.
   */
  public function testDominanceThresholdEnforced(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 120),
      $this->entry('topic_family', $now - 100),
      $this->entry('topic_family', $now - 80),
      $this->entry('topic_consumer', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'tell me more about that', $now
    );

    $this->assertNotNull($result);
    $this->assertEquals('topic_family', $result['intent']);
    $this->assertEquals(0.5, $result['confidence']);
  }

  /**
   * Spanish reset signal detected.
   */
  public function testResetSignalSpanish(): void {
    $this->assertTrue(HistoryIntentResolver::detectResetSignal('otra pregunta por favor'));
    $this->assertTrue(HistoryIntentResolver::detectResetSignal('Quiero cambiar de tema'));
    $this->assertTrue(HistoryIntentResolver::detectResetSignal('otra cosa necesito'));
  }

  /**
   * Max turns config respected — only last 6 analyzed by default.
   */
  public function testMaxTurnsRespected(): void {
    $now = 1000000;
    $history = [];

    // 7 turns of consumer (oldest), then 3 turns of housing (newest).
    for ($i = 0; $i < 7; $i++) {
      $history[] = $this->entry('topic_consumer', $now - (300 - $i * 10));
    }
    for ($i = 0; $i < 3; $i++) {
      $history[] = $this->entry('topic_housing', $now - (30 - $i * 10));
    }

    // With max_turns=6, only the last 6 entries are analyzed.
    // That's 3 consumer + 3 housing = tied → NULL.
    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about that?', $now, ['history_max_turns' => 6]
    );

    $this->assertNull($result, 'Tied intents within max_turns window should return NULL');
  }

  /**
   * Empty history returns NULL.
   */
  public function testEmptyHistoryReturnsNull(): void {
    $result = HistoryIntentResolver::resolveFromHistory(
      [], 'hello there', time()
    );

    $this->assertNull($result);
  }

  /**
   * ExtractTopicContext returns area from enriched history entry.
   */
  public function testExtractTopicContextReturnsArea(): void {
    $history = [
      [
        'role' => 'user',
        'intent' => 'greeting',
        'timestamp' => time() - 120,
      ],
      [
        'role' => 'user',
        'intent' => 'service_area',
        'area' => 'housing',
        'topic_id' => NULL,
        'topic' => NULL,
        'timestamp' => time() - 60,
      ],
    ];

    $context = HistoryIntentResolver::extractTopicContext($history);

    $this->assertNotNull($context);
    $this->assertEquals('housing', $context['area']);
    $this->assertNull($context['topic_id']);
  }

  /**
   * ExtractTopicContext returns NULL when no area in history.
   */
  public function testExtractTopicContextReturnsNullWithoutArea(): void {
    $history = [
      [
        'role' => 'user',
        'intent' => 'greeting',
        'timestamp' => time() - 60,
      ],
    ];

    $context = HistoryIntentResolver::extractTopicContext($history);

    $this->assertNull($context);
  }

  /**
   * ResolveFromHistory includes topic_context in return.
   */
  public function testResolveFromHistoryIncludesTopicContext(): void {
    $now = 1000000;
    $history = [
      [
        'role' => 'user',
        'text' => 'eviction help',
        'intent' => 'topic_housing',
        'area' => 'housing',
        'safety_flags' => [],
        'timestamp' => $now - 60,
      ],
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about that?', $now
    );

    $this->assertNotNull($result);
    $this->assertArrayHasKey('topic_context', $result);
    $this->assertNotNull($result['topic_context']);
    $this->assertEquals('housing', $result['topic_context']['area']);
  }

  /**
   * ResolveFromHistory infers topic_context for old entries without area.
   */
  public function testResolveFromHistoryTopicContextInferredWithoutArea(): void {
    $now = 1000000;
    $history = [
      $this->entry('topic_housing', $now - 60),
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what about mediation?', $now
    );

    $this->assertNotNull($result);
    $this->assertArrayHasKey('topic_context', $result);
    $this->assertSame('housing', $result['topic_context']['area'] ?? NULL);
  }

  /**
   * Transcript A: 3 turns of family, then "does that give me custody information?"
   *
   * The resolver must return topic_family (not NULL), confirming that
   * the follow-up message can inherit family context from history.
   */
  public function testTranscriptACustodyFollowUp(): void {
    $now = 1000000;
    $history = [
      [
        'role' => 'user',
        'text' => 'I need some custody advice',
        'intent' => 'topic_family',
        'area' => 'family',
        'safety_flags' => [],
        'timestamp' => $now - 180,
      ],
      [
        'role' => 'user',
        'text' => 'what about child support',
        'intent' => 'topic_family',
        'area' => 'family',
        'safety_flags' => [],
        'timestamp' => $now - 120,
      ],
      [
        'role' => 'user',
        'text' => 'and visitation rights',
        'intent' => 'topic_family',
        'area' => 'family',
        'safety_flags' => [],
        'timestamp' => $now - 60,
      ],
    ];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'does that give me custody information?', $now
    );

    $this->assertNotNull($result, 'Follow-up after 3 family turns must resolve');
    $this->assertEquals('topic_family', $result['intent']);
    $this->assertGreaterThanOrEqual(0.5, $result['confidence']);
    // Topic context should include family area.
    $this->assertNotNull($result['topic_context']);
    $this->assertEquals('family', $result['topic_context']['area']);
  }

  /**
   * Transcript B: 3 turns of housing, then "what forms do you have"
   *
   * The resolver returns housing if called (because history is 100% housing),
   * but the controller must handle inventory routing BEFORE calling the
   * resolver. This test confirms the resolver's behavior is consistent.
   */
  public function testTranscriptBInventoryAfterHousing(): void {
    $now = 1000000;
    $history = [
      [
        'role' => 'user',
        'text' => 'I am being evicted',
        'intent' => 'topic_housing',
        'area' => 'housing',
        'safety_flags' => [],
        'timestamp' => $now - 180,
      ],
      [
        'role' => 'user',
        'text' => 'tenant rights',
        'intent' => 'topic_housing',
        'area' => 'housing',
        'safety_flags' => [],
        'timestamp' => $now - 120,
      ],
      [
        'role' => 'user',
        'text' => 'can they change the locks',
        'intent' => 'topic_housing',
        'area' => 'housing',
        'safety_flags' => [],
        'timestamp' => $now - 60,
      ],
    ];

    // The resolver returns housing because it doesn't know about inventory.
    // The controller must intercept inventory before calling the resolver.
    $result = HistoryIntentResolver::resolveFromHistory(
      $history, 'what forms do you have', $now
    );

    $this->assertNotNull($result);
    $this->assertEquals('topic_housing', $result['intent']);
    $this->assertNotNull($result['topic_context']);
    $this->assertEquals('housing', $result['topic_context']['area']);
  }

}
