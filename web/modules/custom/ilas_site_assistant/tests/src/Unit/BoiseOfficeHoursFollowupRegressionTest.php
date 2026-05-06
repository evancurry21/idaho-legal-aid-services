<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\HistoryIntentResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pinned regression for the Boise office-hours multi-turn follow-up.
 *
 * End-to-end proof lives in
 * AssistantMessageRuntimeBehaviorFunctionalTest::testMultiTurnBoiseOfficeHoursThroughApi
 * which spins up a full BrowserTestBase site and takes ~10 minutes. This file
 * pins the same behavior at the lowest practical layer (HistoryIntentResolver
 * static methods) so the bug can be diagnosed and fixed in a sub-second loop.
 *
 * What is locked here:
 *  - A prior turn with explicit office context (intent + area) survives as
 *    topic_context for the next turn. (testHistoryResolverPreservesExplicitBoiseAreaContext)
 *  - extractTopicContext infers an office area when the prior entry only
 *    encodes office context in intent + text, not in the area field. This is
 *    the gap that causes the production failure: the controller stores an
 *    'office_location' intent without an area string, so the resolver loses
 *    the context. (testExtractTopicContextInfersOfficeAreaFromOfficeIntent)
 *  - "what about hours?" / "what are their hours?" do not trigger a topic
 *    reset signal — they must be allowed to inherit prior office context.
 *    (testFollowupHoursPhrasesAreNotResetSignals)
 *  - resolveFromHistory returns a non-NULL result for the multi-turn Boise
 *    office-hours follow-up and the result preserves an office area in
 *    topic_context. (testResolveFromHistoryPreservesOfficeContextOnHoursFollowup)
 *
 * Some cases here are expected to be RED until the underlying bug is fixed.
 * That is intentional: this is the development feedback loop.
 *
 * Phase membership: Phase 1 (VC-UNIT). No Drupal kernel, no DB, no HTTP. The
 * #[Group('ilas_site_assistant')] tag picks it up under the run-quality-gate
 * Phase 1 invocation
 *   vendor/bin/phpunit -c phpunit.xml --group ilas_site_assistant <module>/tests/src/Unit
 */
#[Group('ilas_site_assistant')]
final class BoiseOfficeHoursFollowupRegressionTest extends TestCase {

  /**
   * Builds a "Where is your Boise office?" first-turn history entry with the
   * area field already populated (the post-fix shape we want).
   */
  private function priorBoiseOfficeTurnWithArea(int $timestamp): array {
    return [
      'role' => 'user',
      'text' => 'Where is your Boise office?',
      'intent' => 'office_location',
      'area' => 'office_location',
      'safety_flags' => [],
      'timestamp' => $timestamp,
    ];
  }

  /**
   * Builds the same first-turn entry as it appears in production today: the
   * intent is 'office_location' but no area field is set, because
   * AssistantApiController::inferAreaFromIntentType() has no office branch.
   */
  private function priorBoiseOfficeTurnWithoutArea(int $timestamp): array {
    return [
      'role' => 'user',
      'text' => 'Where is your Boise office?',
      'intent' => 'office_location',
      'safety_flags' => [],
      'timestamp' => $timestamp,
    ];
  }

  /**
   * If the controller has correctly attached area='office_location' to the
   * prior turn, the resolver must round-trip it through topic_context.
   *
   * Locks: explicit office context preservation across turns.
   */
  public function testHistoryResolverPreservesExplicitBoiseAreaContext(): void {
    $now = 1000000;
    $history = [$this->priorBoiseOfficeTurnWithArea($now - 60)];

    $context = HistoryIntentResolver::extractTopicContext($history);

    $this->assertNotNull(
      $context,
      'extractTopicContext must return office context when the prior turn has area set.'
    );
    $this->assertNotEmpty(
      $context['area'] ?? '',
      'Prior turn area must be propagated as topic_context.area.'
    );
    $this->assertSame(
      'office_location',
      $context['area'],
      'Office context must be preserved with the office_location area.'
    );
  }

  /**
   * Older history entries written before the area field is set must still
   * yield office topic context — the resolver should infer "office" from
   * intent='office_location' or text mentioning a known office.
   *
   * Expected RED until inferTopicContextFromEntry adds an office branch.
   */
  public function testExtractTopicContextInfersOfficeAreaFromOfficeIntent(): void {
    $history = [$this->priorBoiseOfficeTurnWithoutArea(1000000 - 60)];

    $context = HistoryIntentResolver::extractTopicContext($history);

    $this->assertNotNull(
      $context,
      'extractTopicContext must infer office context from intent=office_location even when the area field is missing.'
    );
    $area = (string) ($context['area'] ?? '');
    $this->assertNotSame('', $area, 'Inferred topic_context must include an area.');
    $this->assertMatchesRegularExpression(
      '/office|boise/i',
      $area,
      'Inferred area must reference an office concept (got: ' . $area . ').'
    );
  }

  /**
   * "What about hours?" and "what are their hours?" must not register as
   * reset signals — otherwise the resolver short-circuits and the controller
   * falls back to top intents / meta_what_do_you_do, which is the failure
   * we see in production.
   */
  public function testFollowupHoursPhrasesAreNotResetSignals(): void {
    $this->assertFalse(
      HistoryIntentResolver::detectResetSignal('What about hours?'),
      '"What about hours?" must not be treated as a topic reset.'
    );
    $this->assertFalse(
      HistoryIntentResolver::detectResetSignal('what are their hours?'),
      '"what are their hours?" must not be treated as a topic reset.'
    );
    $this->assertFalse(
      HistoryIntentResolver::detectResetSignal('and the hours?'),
      'Anaphoric hours questions must not be treated as a topic reset.'
    );
  }

  /**
   * Full Boise office-hours follow-up contract using the post-fix history
   * shape (area populated). The resolver must return a non-NULL result,
   * topic_context must carry the office area, and the dominant intent must
   * not be a generic fallback that would suppress office handling downstream.
   */
  public function testResolveFromHistoryPreservesOfficeContextOnHoursFollowup(): void {
    $now = 1000000;
    $history = [$this->priorBoiseOfficeTurnWithArea($now - 60)];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history,
      'What about hours?',
      $now
    );

    $this->assertNotNull(
      $result,
      'resolveFromHistory must yield a fallback when the prior turn was an office-location request.'
    );
    $this->assertSame(
      'office_location',
      $result['intent'] ?? NULL,
      'The dominant intent from a single office_location prior turn must be office_location.'
    );

    // Office handling must outrank generic / meta intents.
    $this->assertNotSame('meta_what_do_you_do', $result['intent'] ?? NULL);
    $this->assertNotSame('top_intents_pack', $result['intent'] ?? NULL);
    $this->assertNotSame('apply_for_help', $result['intent'] ?? NULL);
    $this->assertNotSame('unknown', $result['intent'] ?? NULL);

    // topic_context must propagate office area for the controller to consume.
    $this->assertArrayHasKey('topic_context', $result);
    $this->assertNotNull($result['topic_context']);
    $area = (string) ($result['topic_context']['area'] ?? '');
    $this->assertMatchesRegularExpression(
      '/office|boise/i',
      $area,
      'topic_context.area must reference an office concept (got: ' . $area . ').'
    );
  }

  /**
   * Realistic production history: prior turn is an office_location request
   * but the area field was never populated by the controller. The resolver
   * must still preserve office context for the follow-up.
   *
   * Expected RED until either:
   *   (a) AssistantApiController::inferAreaFromIntentType() returns 'office_location'
   *       (or similar) for office_location intents, OR
   *   (b) HistoryIntentResolver::inferTopicContextFromEntry() learns office.
   *
   * Either fix preserves the multi-turn contract.
   */
  public function testResolveFromHistoryRecoversOfficeContextWhenAreaMissing(): void {
    $now = 1000000;
    $history = [$this->priorBoiseOfficeTurnWithoutArea($now - 60)];

    $result = HistoryIntentResolver::resolveFromHistory(
      $history,
      'What about hours?',
      $now
    );

    $this->assertNotNull(
      $result,
      'resolveFromHistory must still resolve when the prior turn lacks an area but has intent=office_location.'
    );
    $this->assertNotNull(
      $result['topic_context'] ?? NULL,
      'topic_context must be inferred from intent/text when area is missing on the prior turn.'
    );
    $area = (string) ($result['topic_context']['area'] ?? '');
    $this->assertMatchesRegularExpression(
      '/office|boise/i',
      $area,
      'Inferred topic_context.area must reference an office concept (got: ' . $area . ').'
    );
  }

}
