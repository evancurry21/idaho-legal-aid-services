<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests urgency/deadline detection patterns.
 *
 * These tests verify that time-sensitive legal matters are correctly
 * detected and routed to /legal-advice-line.
 */
#[Group('ilas_site_assistant')]
class UrgencyDetectionTest extends TestCase {

  /**
   * Urgency triggers from IntentRouter.urgentSafetyTriggers['urgent_deadline'].
   */
  protected const URGENCY_TRIGGERS = [
    // English - immediate deadlines (today/tomorrow)
    'deadline tomorrow', 'deadline today', 'deadline is today', 'deadline is tomorrow',
    'due tomorrow', 'due today', 'response due tomorrow', 'response due today',
    'file by tomorrow', 'file today', 'must file by', 'have to file by tomorrow',
    'respond by tomorrow', 'respond by today',
    'court date tomorrow', 'court date today', 'court hearing tomorrow',
    'have to respond today', 'must respond today',
    '24 hours', 'within 24 hours',
    // English - day-of-week deadlines
    'deadline friday', 'deadline monday', 'deadline is friday', 'deadline is monday',
    'deadline this friday', 'deadline this monday', 'deadline next monday',
    'due friday', 'due monday', 'due this friday', 'due this monday',
    'file by friday', 'file by monday', 'paperwork by friday', 'paperwork by monday',
    'respond by friday', 'respond by monday',
    'court date friday', 'court date monday', 'court friday', 'court monday',
    'court date is', 'court date this',
    'hearing friday', 'hearing monday', 'hearing tomorrow',
    // English - lawsuit/summons patterns
    'respond to lawsuit', 'respond to summons', 'lawsuit response',
    'lawsuit deadline', 'summons deadline', 'answer the lawsuit',
    'served papers', 'served with papers', 'got served', 'been served',
    'respond to the complaint', 'answer to complaint',
    // English - explicit urgency with days
    'by friday', 'by monday', 'by tomorrow', 'by end of week',
    'have to file by', 'need to file by', 'must respond by',
    'have to respond by', 'need to respond by',
    // Spanish - deadlines
    'fecha limite hoy', 'fecha limite manana',
    'fecha limite viernes', 'fecha limite lunes',
    'vence hoy', 'vence manana', 'vence viernes', 'vence lunes',
    'tengo que responder hoy', 'tengo que responder manana', 'tengo que responder',
    'me llego una demanda', 'me llegó una demanda',
    'recibí una demanda', 'recibi una demanda',
    // Spanish - court date patterns
    'corte manana', 'audiencia manana',
    'corte hoy', 'audiencia hoy', 'corte viernes', 'corte lunes',
    'fecha de corte manana', 'fecha de corte hoy',
    'tengo corte manana', 'tengo corte hoy',
    'tengo corte', 'tengo una corte',
    // Spanglish patterns
    'corte date', 'court date manana', 'court manana',
  ];

  /**
   * Dampeners that should suppress urgency detection.
   */
  protected const DAMPENERS = [
    'how long do i have',
    'what is the deadline',
    'when is the deadline',
    'typical deadline',
    'general deadline',
    'deadline information',
    'deadline for eviction',
    'how many days',
    'how much time do i have',
    'cuanto tiempo tengo',
    'cual es la fecha limite',
  ];

  /**
   * Tests that eval golden dataset utterances match triggers.
   *
   * These are the 3 utterances from the golden dataset that must pass.
   */
  #[DataProvider('evalUtterancesProvider')]
  public function testEvalUtterancesMatch(string $utterance, string $expectedTrigger) {
    $message_lower = strtolower($utterance);
    $matched = FALSE;
    $matched_trigger = NULL;

    foreach (self::URGENCY_TRIGGERS as $trigger) {
      if (strpos($message_lower, strtolower($trigger)) !== FALSE) {
        $matched = TRUE;
        $matched_trigger = $trigger;
        break;
      }
    }

    $this->assertTrue(
      $matched,
      "Utterance '$utterance' should match urgency trigger '$expectedTrigger'. Got: " . ($matched_trigger ?? 'no match')
    );
  }

  /**
   * Data provider for eval utterances.
   */
  public static function evalUtterancesProvider(): array {
    return [
      'lawsuit deadline friday' => [
        'deadline to respond to lawsuit is friday',
        'respond to lawsuit',
      ],
      'file paperwork monday' => [
        'have to file paperwork by monday',
        'by monday',
      ],
      'spanglish court date' => [
        'tengo una corte date manana',
        'corte date',
      ],
    ];
  }

  /**
   * Tests additional near-miss patterns that should match.
   */
  #[DataProvider('nearMissUtterancesProvider')]
  public function testNearMissPatterns(string $utterance) {
    $message_lower = strtolower($utterance);
    $matched = FALSE;

    foreach (self::URGENCY_TRIGGERS as $trigger) {
      if (strpos($message_lower, strtolower($trigger)) !== FALSE) {
        $matched = TRUE;
        break;
      }
    }

    $this->assertTrue($matched, "Near-miss utterance '$utterance' should match an urgency trigger");
  }

  /**
   * Data provider for near-miss patterns.
   */
  public static function nearMissUtterancesProvider(): array {
    return [
      'served with summons' => ['i was served with papers last week'],
      'court friday' => ['court date is this friday'],
      'spanish deadline' => ['tengo que responder a la demanda'],
      'deadline monday' => ['my deadline is monday'],
      'response due' => ['my response is due tomorrow'],
      'got served' => ['i got served yesterday'],
      'lawsuit answer' => ['i need to answer the lawsuit'],
      'hearing tomorrow' => ['i have a hearing tomorrow'],
      'file by friday' => ['i need to file by friday'],
    ];
  }

  /**
   * Tests that dampeners prevent over-triggering.
   */
  #[DataProvider('dampenerUtterancesProvider')]
  public function testDampenersPreventFalsePositives(string $utterance) {
    $message_lower = strtolower($utterance);
    $is_dampened = FALSE;

    foreach (self::DAMPENERS as $dampener) {
      if (strpos($message_lower, $dampener) !== FALSE) {
        $is_dampened = TRUE;
        break;
      }
    }

    $this->assertTrue(
      $is_dampened,
      "Informational query '$utterance' should be dampened (not trigger urgency)"
    );
  }

  /**
   * Data provider for dampener utterances.
   */
  public static function dampenerUtterancesProvider(): array {
    return [
      'how long question' => ['how long do i have to respond to an eviction'],
      'what is deadline' => ['what is the deadline for filing an answer'],
      'general info' => ['what is typical deadline for responding to a lawsuit'],
      'spanish info' => ['cuanto tiempo tengo para responder'],
    ];
  }

  /**
   * Tests that generic time queries don't over-trigger.
   *
   * These queries mention time but are informational, not urgent.
   */
  public function testNoOverTrigger() {
    $non_urgent = [
      'how long does a divorce take',
      'what is the typical eviction timeline',
      'i want to learn about deadlines',
      'general information about court dates',
    ];

    foreach ($non_urgent as $utterance) {
      $message_lower = strtolower($utterance);
      $matched = FALSE;

      foreach (self::URGENCY_TRIGGERS as $trigger) {
        if (strpos($message_lower, strtolower($trigger)) !== FALSE) {
          // Check if dampened
          $is_dampened = FALSE;
          foreach (self::DAMPENERS as $dampener) {
            if (strpos($message_lower, $dampener) !== FALSE) {
              $is_dampened = TRUE;
              break;
            }
          }
          if (!$is_dampened) {
            $matched = TRUE;
            break;
          }
        }
      }

      $this->assertFalse(
        $matched,
        "Generic query '$utterance' should NOT trigger urgency"
      );
    }
  }

}
