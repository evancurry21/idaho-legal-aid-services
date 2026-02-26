<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Classifies conversation turns as NEW, FOLLOW_UP, INVENTORY, or RESET.
 *
 * Pure-PHP static utility with no Drupal dependencies. Called before intent
 * routing to determine turn type, enabling proactive context propagation
 * and inventory routing without waiting for routing to fail.
 */
class TurnClassifier {

  /**
   * Turn type constants.
   */
  const TURN_NEW = 'NEW';
  const TURN_FOLLOW_UP = 'FOLLOW_UP';
  const TURN_INVENTORY = 'INVENTORY';
  const TURN_RESET = 'RESET';

  /**
   * Maximum seconds since last history entry for follow-up detection.
   */
  const FOLLOW_UP_TIME_WINDOW = 600;

  /**
   * Maximum word count for short-message follow-up heuristic.
   */
  const SHORT_MESSAGE_MAX_WORDS = 4;

  /**
   * Anaphoric reference patterns that signal a follow-up.
   *
   * These pronouns/phrases reference a prior response without naming
   * the topic explicitly.
   */
  const ANAPHORIC_PATTERNS = [
    'that',
    'those',
    'this',
    'these',
    'it',
    'them',
    'more about',
    'tell me more',
    'more info',
    'more information',
    'more details',
    'what about',
    'how about',
    'can you explain',
    'explain that',
    'go on',
    'continue',
    'and also',
    'what else',
    'anything else',
    // Spanish.
    'eso',
    'esto',
    'esos',
    'mas sobre',
    'dime mas',
    'mas informacion',
    'que mas',
  ];

  /**
   * Inventory patterns — catalog/browse requests (EN + ES).
   *
   * Matched case-insensitively against the full message.
   */
  const INVENTORY_PATTERNS = [
    // Forms inventory.
    '/\b(?:what|which)\s+forms?\s+(?:do\s+you\s+have|are\s+available|exist|are\s+there)\b/i',
    '/\b(?:show|list|give)\s+(?:me\s+)?(?:all|every|the)\s+forms?\b/i',
    '/\ball\s+(?:the\s+)?(?:available\s+)?forms?\b/i',
    '/\blist\s+(?:of\s+)?forms?\b/i',
    // Guides inventory.
    '/\b(?:what|which)\s+guides?\s+(?:do\s+you\s+have|are\s+available|exist|are\s+there)\b/i',
    '/\b(?:show|list|give)\s+(?:me\s+)?(?:all|every|the)\s+guides?\b/i',
    '/\ball\s+(?:the\s+)?(?:available\s+)?guides?\b/i',
    '/\blist\s+(?:of\s+)?guides?\b/i',
    // Services inventory.
    '/\b(?:what|which)\s+services?\s+(?:do\s+you\s+(?:have|offer|provide)|are\s+available|exist)\b/i',
    '/\b(?:what)\s+(?:can\s+you|do\s+you)\s+help\s+with\b/i',
    '/\b(?:show|list|give)\s+(?:me\s+)?(?:all|every|the)\s+services?\b/i',
    '/\blist\s+everything\b/i',
    '/\bshow\s+me\s+everything\b/i',
    // Spanish patterns.
    '/\b(?:que|cuales)\s+formularios?\s+(?:tienen|hay)\b/i',
    '/\b(?:que|cuales)\s+guias?\s+(?:tienen|hay)\b/i',
    '/\b(?:que|cuales)\s+servicios?\s+(?:tienen|ofrecen)\b/i',
    '/\b(?:mostrar|listar)\s+(?:todos?\s+(?:los?\s+)?)?(?:formularios?|guias?|servicios?)\b/i',
  ];

  /**
   * Classifies a conversation turn.
   *
   * Priority order:
   * 1. RESET — explicit topic-shift signal (reuses HistoryIntentResolver)
   * 2. INVENTORY — catalog/browse request pattern match
   * 3. FOLLOW_UP — anaphoric reference or short message with recent history
   * 4. NEW — default
   *
   * @param string $message
   *   The current user message.
   * @param array $server_history
   *   Array of history entries with 'timestamp' keys.
   * @param int $now
   *   Current timestamp (injectable for testing).
   *
   * @return string
   *   One of TURN_NEW, TURN_FOLLOW_UP, TURN_INVENTORY, TURN_RESET.
   */
  public static function classifyTurn(string $message, array $server_history, int $now): string {
    // 1. RESET: check for explicit topic-shift signals.
    if (HistoryIntentResolver::detectResetSignal($message)) {
      return self::TURN_RESET;
    }

    // 2. INVENTORY: check for catalog/browse request patterns.
    if (self::detectInventory($message)) {
      return self::TURN_INVENTORY;
    }

    // 3. FOLLOW_UP: anaphoric references or short messages with recent history.
    if (self::detectFollowUp($message, $server_history, $now)) {
      return self::TURN_FOLLOW_UP;
    }

    // 4. Default: NEW turn.
    return self::TURN_NEW;
  }

  /**
   * Detects if the message is an inventory/catalog request.
   *
   * @param string $message
   *   The user message.
   *
   * @return bool
   *   TRUE if message matches an inventory pattern.
   */
  public static function detectInventory(string $message): bool {
    foreach (self::INVENTORY_PATTERNS as $pattern) {
      if (preg_match($pattern, $message)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Resolves the inventory subtype from the message.
   *
   * @param string $message
   *   The user message.
   *
   * @return string
   *   One of 'forms_inventory', 'guides_inventory', 'services_inventory'.
   */
  public static function resolveInventoryType(string $message): string {
    $lower = mb_strtolower(trim($message));

    if (preg_match('/\b(?:guide|guides|guia|guias)\b/', $lower)) {
      return 'guides_inventory';
    }
    if (preg_match('/\b(?:service|services|servicio|servicios|help\s+with)\b/', $lower)) {
      return 'services_inventory';
    }
    // Default to forms inventory (most common catalog request).
    return 'forms_inventory';
  }

  /**
   * Detects if the message is a follow-up to a prior turn.
   *
   * Two heuristics:
   * 1. Message contains anaphoric reference patterns.
   * 2. Message is short (<= 4 words) AND there is recent history.
   *
   * @param string $message
   *   The user message.
   * @param array $server_history
   *   Array of history entries.
   * @param int $now
   *   Current timestamp.
   *
   * @return bool
   *   TRUE if likely a follow-up.
   */
  public static function detectFollowUp(string $message, array $server_history, int $now): bool {
    // Must have history to follow up on.
    if (empty($server_history)) {
      return FALSE;
    }

    // Check recency: most recent entry must be within time window.
    $last_entry = end($server_history);
    $last_timestamp = $last_entry['timestamp'] ?? 0;
    if (($now - $last_timestamp) > self::FOLLOW_UP_TIME_WINDOW) {
      return FALSE;
    }

    // Heuristic 1: anaphoric reference detected.
    if (self::hasAnaphoricReference($message)) {
      return TRUE;
    }

    // Heuristic 2: short message with recent history.
    $word_count = count(preg_split('/\s+/', trim($message)));
    if ($word_count <= self::SHORT_MESSAGE_MAX_WORDS) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks if the message contains an anaphoric reference.
   *
   * @param string $message
   *   The user message.
   *
   * @return bool
   *   TRUE if an anaphoric pattern is found.
   */
  public static function hasAnaphoricReference(string $message): bool {
    $lower = mb_strtolower(trim($message));

    foreach (self::ANAPHORIC_PATTERNS as $pattern) {
      // Use word boundary for single words, substring match for multi-word.
      if (str_contains($pattern, ' ')) {
        if (str_contains($lower, $pattern)) {
          return TRUE;
        }
      }
      else {
        // Word boundary match to avoid false positives
        // (e.g., "that" in "bathroom").
        if (preg_match('/\b' . preg_quote($pattern, '/') . '\b/', $lower)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}
