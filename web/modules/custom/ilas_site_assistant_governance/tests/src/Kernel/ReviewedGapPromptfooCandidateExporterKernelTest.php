<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant_governance\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ilas_site_assistant\Service\PiiRedactor;
use Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel coverage for exporting reviewed gaps into Promptfoo candidates.
 */
#[Group('ilas_site_assistant_governance')]
#[RunTestsInSeparateProcesses]
final class ReviewedGapPromptfooCandidateExporterKernelTest extends KernelTestBase {

  /**
   * Runtime workflow coverage should not be blocked by legacy config drift.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'options',
    'search_api',
    'search_api_db',
    'entity_reference_revisions',
    'paragraphs',
    'ilas_site_assistant',
    'ilas_site_assistant_governance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('search_api_task');
    $this->installEntitySchema('assistant_gap_item');
    $this->installConfig(['search_api', 'search_api_db', 'ilas_site_assistant', 'ilas_site_assistant_governance']);
    $this->installSchema('ilas_site_assistant_governance', [
      'ilas_site_assistant_conversation_session',
      'ilas_site_assistant_conversation_turn',
      'ilas_site_assistant_gap_hit',
      'ilas_site_assistant_legal_hold',
    ]);
  }

  /**
   * Reviewed gaps export as redacted candidate cases, not trusted tests.
   */
  public function testExporterBuildsPrivacySafeCandidateCases(): void {
    $safe = $this->createGapItem('safe', [
      'exemplar_redacted_query' => 'How do I contest a three-day eviction notice?',
      'review_state' => AssistantGapItem::STATE_RESOLVED,
      'resolution_code' => AssistantGapItem::RESOLUTION_CONTENT_UPDATED,
      'secondary_flags' => [AssistantGapItem::FLAG_POTENTIAL_CONTENT_GAP],
      'identity_source' => 'router',
      'identity_intent' => 'housing_help',
    ]);
    $this->insertAssistantTurn((int) $safe->id(), 'I could not confidently answer that request.', 'fallback');
    $this->insertGapHit((int) $safe->id(), 'housing_help');

    $private = $this->createGapItem('private', [
      'exemplar_redacted_query' => 'My name is Jane Doe and my phone is 208-555-1212. Can I fight an eviction?',
      'review_state' => AssistantGapItem::STATE_RESOLVED,
      'resolution_code' => AssistantGapItem::RESOLUTION_SEARCH_TUNED,
      'secondary_flags' => [AssistantGapItem::FLAG_POTENTIAL_SEARCH_TUNING],
      'identity_source' => 'selection',
      'identity_selection_key' => 'housing_eviction',
      'identity_intent' => 'housing_help',
    ]);
    $this->insertAssistantTurn((int) $private->id(), 'Call 208-555-1212 for help with that.', 'fallback');
    $this->insertGapHit((int) $private->id(), 'housing_help', 'housing_eviction');

    $false_positive = $this->createGapItem('false-positive', [
      'exemplar_redacted_query' => 'This should not become a regression test.',
      'review_state' => AssistantGapItem::STATE_RESOLVED,
      'resolution_code' => AssistantGapItem::RESOLUTION_FALSE_POSITIVE,
    ]);
    $this->insertAssistantTurn((int) $false_positive->id(), 'False positive.', 'fallback');

    $held = $this->createGapItem('held', [
      'exemplar_redacted_query' => 'Held legal matter should stay out of exports.',
      'review_state' => AssistantGapItem::STATE_RESOLVED,
      'resolution_code' => AssistantGapItem::RESOLUTION_CONTENT_UPDATED,
      'is_held' => 1,
    ]);
    $this->insertAssistantTurn((int) $held->id(), 'Held.', 'fallback');

    $export = $this->container
      ->get('ilas_site_assistant_governance.reviewed_gap_promptfoo_candidate_exporter')
      ->buildExport([
        'days' => 0,
        'limit' => 10,
        'states' => 'resolved',
      ]);

    $this->assertSame(2, $export['stats']['exported']);
    $this->assertSame(1, $export['stats']['skipped']['excluded_resolution_code']);
    $this->assertSame(2, count($export['candidates']));

    $serialized = json_encode($export['candidates'], JSON_UNESCAPED_SLASHES);
    $this->assertIsString($serialized);
    $this->assertStringNotContainsString('Jane Doe', $serialized);
    $this->assertStringNotContainsString('208-555-1212', $serialized);
    $this->assertStringNotContainsString('conversation-', $serialized);
    $this->assertStringNotContainsString('request-', $serialized);

    $safe_case = $this->findCandidateByQuestion($export['candidates'], 'How do I contest a three-day eviction notice?');
    $this->assertTrue($safe_case['metadata']['safe_for_ci_candidate']);
    $this->assertContains('content_gap', $safe_case['metadata']['failure_modes']);
    $this->assertSame('possible_missing_or_stale_content', $safe_case['metadata']['source_citation_issue']);

    $private_case = $this->findCandidateBySourceId($export['candidates'], (int) $private->id());
    $this->assertSame('My name is [REDACTED-NAME] and my phone is [REDACTED-PHONE]. Can I fight an eviction?', $private_case['vars']['question']);
    $this->assertFalse($private_case['metadata']['safe_for_ci_candidate']);
    $this->assertTrue($private_case['metadata']['contains_redaction_token']);
    $this->assertContains('retrieval', $private_case['metadata']['failure_modes']);
    $this->assertContains('conversation', $private_case['metadata']['failure_modes']);
    $this->assertSame('Call [REDACTED-PHONE] for help with that.', $private_case['metadata']['observed_assistant_response_excerpt']);
  }

  /**
   * Creates a gap item with required base fields.
   */
  private function createGapItem(string $seed, array $overrides = []): AssistantGapItem {
    $storage = $this->container->get('entity_type.manager')->getStorage('assistant_gap_item');
    $timestamp = 1710000000;
    $values = $overrides + [
      'cluster_hash' => hash('sha256', $seed . ':cluster'),
      'query_hash' => hash('sha256', $seed . ':query'),
      'exemplar_redacted_query' => 'How can I get legal help?',
      'language_hint' => 'en',
      'query_length_bucket' => '25-99',
      'redaction_profile' => 'none',
      'identity_context_key' => 'test:' . $seed,
      'identity_source' => 'router',
      'identity_intent' => 'unknown',
      'review_state' => AssistantGapItem::STATE_RESOLVED,
      'first_seen' => $timestamp,
      'last_seen' => $timestamp,
      'occurrence_count_total' => 1,
      'occurrence_count_unresolved' => 0,
      'changed' => $timestamp,
    ];

    /** @var \Drupal\ilas_site_assistant_governance\Entity\AssistantGapItem $entity */
    $entity = $storage->create($values);
    $entity->save();
    return $entity;
  }

  /**
   * Inserts a redacted assistant turn for a gap item.
   */
  private function insertAssistantTurn(int $gap_item_id, string $message, string $response_type): void {
    $redacted = PiiRedactor::redactForStorage($message, 4000);
    $this->container->get('database')->insert('ilas_site_assistant_conversation_turn')
      ->fields([
        'conversation_id' => 'conversation-' . $gap_item_id,
        'turn_sequence' => 2,
        'request_id' => 'request-' . $gap_item_id,
        'direction' => 'assistant',
        'message_redacted' => $redacted,
        'message_hash' => hash('sha256', $redacted),
        'message_length_bucket' => '25-99',
        'redaction_profile' => 'kernel-test',
        'redaction_version' => 'v1',
        'language_hint' => 'en',
        'intent' => 'housing_help',
        'response_type' => $response_type,
        'is_no_answer' => 1,
        'gap_item_id' => $gap_item_id,
        'created' => 1710000060 + $gap_item_id,
      ])
      ->execute();
  }

  /**
   * Inserts safe gap-hit metadata for a gap item.
   */
  private function insertGapHit(int $gap_item_id, string $intent, string $active_selection_key = ''): void {
    $this->container->get('database')->insert('ilas_site_assistant_gap_hit')
      ->fields([
        'gap_item_id' => $gap_item_id,
        'conversation_id' => 'conversation-' . $gap_item_id,
        'request_id' => 'request-' . $gap_item_id,
        'occurred_at' => 1710000060 + $gap_item_id,
        'is_unresolved' => 0,
        'query_hash' => hash('sha256', 'query-' . $gap_item_id),
        'language_hint' => 'en',
        'assignment_source' => 'router',
        'intent' => $intent,
        'active_selection_key' => $active_selection_key !== '' ? $active_selection_key : NULL,
      ])
      ->execute();
  }

  /**
   * Finds a candidate by exact exported question.
   */
  private function findCandidateByQuestion(array $candidates, string $question): array {
    foreach ($candidates as $candidate) {
      if (($candidate['vars']['question'] ?? '') === $question) {
        return $candidate;
      }
    }
    $this->fail('Expected candidate question not found.');
  }

  /**
   * Finds a candidate by source gap item ID.
   */
  private function findCandidateBySourceId(array $candidates, int $id): array {
    foreach ($candidates as $candidate) {
      if (($candidate['metadata']['source_gap_item_id'] ?? 0) === $id) {
        return $candidate;
      }
    }
    $this->fail('Expected candidate source ID not found.');
  }

}
