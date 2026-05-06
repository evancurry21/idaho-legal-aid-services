<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Builds and reuses an ephemeral structured conversation context summary.
 *
 * The summary intentionally stores only non-sensitive routing facts that help
 * multi-turn continuity. It must never persist freeform case facts or direct
 * identifiers such as names, addresses, phone numbers, emails, SSNs, or case
 * numbers.
 */
class ConversationContextSummary {

  /**
   * Summary fields allowed in cache.
   */
  private const ALLOWED_KEYS = [
    'current_topic',
    'service_area',
    'county',
    'deadline_or_notice',
    'household_context',
    'preferred_language',
    'last_offered_actions',
    'unresolved_clarifying_question',
    'safety_flags',
  ];

  /**
   * Intent sources that usually come from deterministic UI navigation.
   */
  private const DETERMINISTIC_SOURCES = [
    'quick_action',
    'selection',
    'selection_back',
    'selection_recovery',
    'typed_child_selection',
  ];

  /**
   * Generic intent types that are safe to reinterpret from prior context.
   */
  private const GENERIC_CONTINUATION_INTENTS = [
    'unknown',
    'resources',
    'faq',
    'meta_help',
    'meta_information',
    'meta_what_do_you_do',
    'intent_pack_meta_what_do_you_do',
    'intent_pack_meta_information',
    'services_overview',
    'forms_finder',
    'guides_finder',
    'feedback',
  ];

  /**
   * Returns a normalized stored summary with only approved fields.
   */
  public function normalizeStoredSummary($summary): array {
    if (!is_array($summary)) {
      return $this->emptySummary();
    }

    $normalized = $this->emptySummary();

    foreach (self::ALLOWED_KEYS as $key) {
      if (!array_key_exists($key, $summary)) {
        continue;
      }

      switch ($key) {
        case 'household_context':
          $value = is_array($summary[$key]) ? $summary[$key] : [];
          $normalized[$key] = [
            'children_present' => !empty($value['children_present']),
            'survivor_safety_mentioned' => !empty($value['survivor_safety_mentioned']),
            'disability_mentioned' => !empty($value['disability_mentioned']),
          ];
          break;

        case 'last_offered_actions':
        case 'safety_flags':
          $values = is_array($summary[$key]) ? $summary[$key] : [];
          $normalized[$key] = array_values(array_slice(array_unique(array_filter(array_map(
            static fn($item): string => is_scalar($item) ? trim((string) $item) : '',
            $values
          ))), 0, 6));
          break;

        default:
          $normalized[$key] = is_scalar($summary[$key])
            ? mb_substr(trim((string) $summary[$key]), 0, 160)
            : '';
      }
    }

    return $normalized;
  }

  /**
   * Builds a new summary from the current turn plus the prior summary.
   */
  public function summarizeTurn(
    string $user_message,
    array $intent,
    array $response,
    array $prior_summary = [],
    array $options = [],
  ): array {
    $summary = $this->normalizeStoredSummary($prior_summary);
    $facts = $this->extractMessageFacts($user_message);
    $intent_source = (string) ($intent['source'] ?? '');
    $deterministic_only_turn = in_array($intent_source, self::DETERMINISTIC_SOURCES, TRUE)
      && !$facts['has_contextual_facts']
      && !$facts['has_topic_signal'];

    if (!$deterministic_only_turn) {
      $service_area = $this->resolveServiceArea($intent, $summary, $facts);
      if ($service_area !== '') {
        $summary['service_area'] = $service_area;
      }

      $current_topic = $this->resolveCurrentTopic($intent, $summary, $facts);
      if ($current_topic !== '') {
        $summary['current_topic'] = $current_topic;
      }

      if ($facts['county'] !== '') {
        $summary['county'] = $facts['county'];
      }

      if ($facts['deadline_or_notice'] !== '') {
        $summary['deadline_or_notice'] = $facts['deadline_or_notice'];
      }

      foreach (['children_present', 'survivor_safety_mentioned', 'disability_mentioned'] as $key) {
        if (!empty($facts['household_context'][$key])) {
          $summary['household_context'][$key] = TRUE;
        }
      }

      if ($facts['preferred_language'] !== '') {
        $summary['preferred_language'] = $facts['preferred_language'];
      }
    }

    $summary['last_offered_actions'] = $this->extractLastOfferedActions($response, $summary['last_offered_actions']);
    $summary['unresolved_clarifying_question'] = $this->extractClarifyingQuestion($response);
    $summary['safety_flags'] = $this->mergeFlags(
      $summary['safety_flags'],
      is_array($options['safety_flags'] ?? NULL) ? $options['safety_flags'] : []
    );

    return $this->normalizeStoredSummary($summary);
  }

  /**
   * Returns a continuity intent when the new turn should inherit context.
   */
  public function buildContinuationIntent(string $user_message, array $intent, array $summary): ?array {
    $summary = $this->normalizeStoredSummary($summary);
    if ($summary['service_area'] === '' || HistoryIntentResolver::detectResetSignal($user_message)) {
      return NULL;
    }

    $facts = $this->extractMessageFacts($user_message);
    $intent_type = (string) ($intent['type'] ?? 'unknown');

    if ($this->hasExplicitTopicShift($facts, $summary['service_area'])) {
      return NULL;
    }

    $should_continue = (
      in_array($intent_type, self::GENERIC_CONTINUATION_INTENTS, TRUE) ||
      str_starts_with($intent_type, 'intent_pack_meta_') ||
      $facts['has_contextual_facts'] ||
      $facts['asks_next_step']
    );

    if (!$should_continue) {
      return NULL;
    }

    $topic = $this->topicSuffix($summary['current_topic'], $summary['service_area']);

    return [
      'type' => 'service_area',
      'area' => $summary['service_area'],
      'topic' => $topic !== '' ? $topic : ($summary['deadline_or_notice'] !== '' ? $summary['deadline_or_notice'] : NULL),
      'topic_id' => NULL,
      'confidence' => max(0.72, (float) ($intent['confidence'] ?? 0.0)),
      'source' => 'conversation_context_summary',
      'extraction' => $intent['extraction'] ?? [],
    ];
  }

  /**
   * Extracts safe message facts without storing raw freeform text.
   */
  public function extractMessageFacts(string $message): array {
    $normalized = InputNormalizer::normalize($message);
    $lower = mb_strtolower($normalized);

    $county = $this->extractCounty($lower);
    $deadline_or_notice = $this->extractDeadlineOrNotice($lower);
    $household_context = [
      'children_present' => (bool) preg_match('/\b(kids?|children|child|minor|daughter|son)\b/u', $lower),
      'survivor_safety_mentioned' => (bool) preg_match('/\b(survivor|survival|domestic\s+violence|abuser|protect(?:ion|ive)\s+order|safe(?:ty)?|stalking)\b/u', $lower),
      'disability_mentioned' => (bool) preg_match('/\b(disability|disabled|ssi|ssdi|ada|medicaid)\b/u', $lower),
    ];

    $detected_area = $this->detectAreaFromMessage($lower);
    $preferred_language = $this->detectPreferredLanguage($lower);
    $asks_next_step = (bool) preg_match('/\b(what\s+should\s+i\s+do\s+next|what\s+do\s+i\s+do\s+next|next\s+steps?|what\s+now|now\s+what)\b/u', $lower);

    return [
      'county' => $county,
      'deadline_or_notice' => $deadline_or_notice,
      'household_context' => $household_context,
      'preferred_language' => $preferred_language,
      'detected_area' => $detected_area,
      'asks_next_step' => $asks_next_step,
      'has_contextual_facts' => $county !== ''
      || $deadline_or_notice !== ''
      || in_array(TRUE, $household_context, TRUE)
      || $asks_next_step,
      'has_topic_signal' => $detected_area !== '',
    ];
  }

  /**
   * Returns an empty normalized summary payload.
   */
  private function emptySummary(): array {
    return [
      'current_topic' => '',
      'service_area' => '',
      'county' => '',
      'deadline_or_notice' => '',
      'household_context' => [
        'children_present' => FALSE,
        'survivor_safety_mentioned' => FALSE,
        'disability_mentioned' => FALSE,
      ],
      'preferred_language' => '',
      'last_offered_actions' => [],
      'unresolved_clarifying_question' => '',
      'safety_flags' => [],
    ];
  }

  /**
   * Resolves the effective service area for the updated summary.
   */
  private function resolveServiceArea(array $intent, array $summary, array $facts): string {
    $area = trim((string) ($intent['area'] ?? $intent['service_area'] ?? ''));
    if ($area !== '') {
      return $area;
    }

    if ($facts['detected_area'] !== '') {
      return $facts['detected_area'];
    }

    if ($summary['service_area'] !== '' && ($facts['has_contextual_facts'] || $facts['asks_next_step'])) {
      return $summary['service_area'];
    }

    return '';
  }

  /**
   * Resolves the effective topic key for the updated summary.
   */
  private function resolveCurrentTopic(array $intent, array $summary, array $facts): string {
    $intent_type = (string) ($intent['type'] ?? '');
    $area = trim((string) ($intent['area'] ?? $intent['service_area'] ?? $summary['service_area']));
    $topic = trim((string) ($intent['topic'] ?? ''));

    if (str_starts_with($intent_type, 'topic_housing_eviction') || ($area === 'housing' && ($topic === 'eviction' || $facts['deadline_or_notice'] !== ''))) {
      return 'housing_eviction';
    }

    if (str_starts_with($intent_type, 'topic_family_custody') || ($area === 'family' && $topic === 'custody')) {
      return 'family_custody';
    }

    if ($intent_type === 'forms_finder' && $area !== '') {
      return 'forms_' . $area;
    }

    if ($intent_type === 'guides_finder' && $area !== '') {
      return 'guides_' . $area;
    }

    if ($intent_type === 'service_area' && $area !== '') {
      return $topic !== '' ? $area . '_' . preg_replace('/[^a-z0-9_]+/u', '_', mb_strtolower($topic)) : 'service_area_' . $area;
    }

    if (str_starts_with($intent_type, 'topic_')) {
      return preg_replace('/^topic_/', '', $intent_type);
    }

    if ($summary['current_topic'] !== '' && ($facts['has_contextual_facts'] || $facts['asks_next_step'])) {
      return $summary['current_topic'];
    }

    return '';
  }

  /**
   * Extracts a safe county token from the message.
   */
  private function extractCounty(string $message): string {
    foreach (array_keys(OfficeLocationResolver::COUNTY_MAP) as $county) {
      $pattern = '/\b' . preg_quote($county, '/') . '\s+county\b/u';
      if (preg_match($pattern, $message)) {
        return $county;
      }
    }

    return '';
  }

  /**
   * Extracts a notice/deadline token from the message.
   */
  private function extractDeadlineOrNotice(string $message): string {
    $map = [
      'lockout' => '/\b(lock(?:ed)?\s*out|changed\s+(?:the\s+)?locks|self[-\s]*help\s+eviction)\b/u',
      '3_day_notice' => '/\b(?:3|three)[-\s]*day\s+notice\b/u',
      '5_day_notice' => '/\b(?:5|five)[-\s]*day\s+notice\b/u',
      'hearing_date' => '/\b(court\s+date|hearing\s+(?:date|today|tomorrow)|trial\s+date)\b/u',
      'eviction_notice' => '/\b(eviction\s+notice|notice\s+to\s+vacate|pay\s+or\s+quit)\b/u',
    ];

    foreach ($map as $token => $pattern) {
      if (preg_match($pattern, $message)) {
        return $token;
      }
    }

    return '';
  }

  /**
   * Detects the broad legal area from the message itself.
   */
  private function detectAreaFromMessage(string $message): string {
    $areas = [
      'housing' => '/\b(eviction|landlord|tenant|rent|lease|lockout|housing|foreclosure)\b/u',
      'family' => '/\b(custody|divorce|visitation|child\s+support|family|protection\s+order)\b/u',
      'consumer' => '/\b(debt|collector|bankruptcy|garnishment|credit)\b/u',
      'health' => '/\b(benefits|medicaid|medicare|ssi|ssdi|disability)\b/u',
    ];

    foreach ($areas as $area => $pattern) {
      if (preg_match($pattern, $message)) {
        return $area;
      }
    }

    return '';
  }

  /**
   * Detects a lightweight language preference from the message.
   */
  private function detectPreferredLanguage(string $message): string {
    if (preg_match('/\b(hola|ayuda|desalojo|custodia|beneficios|abogado|quiero|necesito)\b/u', $message)) {
      return 'es';
    }

    return '';
  }

  /**
   * Returns TRUE when the message shifts to a different topic area.
   */
  private function hasExplicitTopicShift(array $facts, string $current_area): bool {
    return $facts['detected_area'] !== '' && $facts['detected_area'] !== $current_area;
  }

  /**
   * Returns the most specific topic suffix for continuity reuse.
   */
  private function topicSuffix(string $current_topic, string $service_area): string {
    $current_topic = trim($current_topic);
    if ($current_topic === '') {
      return '';
    }

    if (str_contains($current_topic, '_')) {
      $prefix = $service_area !== '' ? $service_area . '_' : '';
      if ($prefix !== '' && str_starts_with($current_topic, $prefix)) {
        return substr($current_topic, strlen($prefix));
      }
    }

    return $current_topic;
  }

  /**
   * Extracts compact action tokens from a response.
   */
  private function extractLastOfferedActions(array $response, array $prior_actions): array {
    $tokens = [];

    $collections = [];
    if (!empty($response['primary_action']) && is_array($response['primary_action'])) {
      $collections[] = [$response['primary_action']];
    }
    foreach (['secondary_actions', 'actions', 'links'] as $key) {
      if (!empty($response[$key]) && is_array($response[$key])) {
        $collections[] = $response[$key];
      }
    }

    foreach ($collections as $items) {
      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }
        $token = trim((string) ($item['type'] ?? ''));
        if ($token === '') {
          $label = mb_strtolower(trim((string) ($item['label'] ?? '')));
          $url = mb_strtolower(trim((string) ($item['url'] ?? '')));
          if (str_contains($url, 'apply') || str_contains($label, 'apply')) {
            $token = 'apply';
          }
          elseif (str_contains($url, 'legal-advice-line') || str_contains($label, 'advice line') || str_contains($label, 'hotline')) {
            $token = 'hotline';
          }
          elseif (str_contains($url, '/forms') || str_contains($label, 'form')) {
            $token = 'forms';
          }
          elseif (str_contains($url, '/guides') || str_contains($label, 'guide')) {
            $token = 'guides';
          }
          elseif (str_contains($url, '/resources') || str_contains($label, 'resource')) {
            $token = 'resources';
          }
          elseif (str_contains($url, '/contact/offices') || str_contains($label, 'office')) {
            $token = 'office';
          }
          elseif (str_contains($url, '/services')) {
            $token = 'services';
          }
        }

        if ($token !== '') {
          $tokens[] = $token;
        }
      }
    }

    $tokens = array_values(array_slice(array_unique($tokens), 0, 6));
    return $tokens !== [] ? $tokens : $prior_actions;
  }

  /**
   * Extracts the active assistant clarifying question, if any.
   */
  private function extractClarifyingQuestion(array $response): string {
    $response_mode = (string) ($response['response_mode'] ?? '');
    $type = (string) ($response['type'] ?? '');

    if ($response_mode === 'clarify' || str_contains($type, 'clarify') || $type === 'disambiguation') {
      return mb_substr(trim((string) ($response['message'] ?? '')), 0, 160);
    }

    return '';
  }

  /**
   * Merges and de-duplicates safety flags.
   */
  private function mergeFlags(array $prior_flags, array $current_flags): array {
    return array_values(array_slice(array_unique(array_filter(array_map(
      static fn($flag): string => is_scalar($flag) ? trim((string) $flag) : '',
      array_merge($prior_flags, $current_flags)
    ))), 0, 6));
  }

}
