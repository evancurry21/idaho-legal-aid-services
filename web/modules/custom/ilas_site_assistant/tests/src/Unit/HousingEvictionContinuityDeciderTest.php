<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\HousingEvictionContinuityDecider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/Service/HistoryIntentResolver.php';
require_once __DIR__ . '/../../../src/Service/HousingEvictionContinuityDecider.php';

/**
 * Tests the canonical HousingEvictionContinuityDecider service directly.
 *
 * The service is the single source of truth shared by AssistantApiController
 * and AssistantFlowRunner. This file covers the predicates that fire for the
 * reported bug: a bare-city follow-up like "This is in Boise." inside an
 * active eviction thread must NOT be treated as an office search.
 */
#[Group('ilas_site_assistant')]
final class HousingEvictionContinuityDeciderTest extends TestCase {

  private const GENERIC_FOLLOWUP_INTENTS = [
    'unknown',
    'faq',
    'resources',
    'meta_help',
    'meta_information',
    'services_overview',
    'forms_finder',
    'guides_finder',
  ];

  /**
   *
   */
  public function testBareCityFollowupOverridesOfficesContactWhenEvictionActive(): void {
    $decider = new HousingEvictionContinuityDecider();
    $intent = ['type' => 'offices_contact', 'confidence' => 0.85];
    $summary = ['current_topic' => 'housing_eviction', 'deadline_or_notice' => 'eviction_notice'];
    $history = [
      [
        'role' => 'user',
        'text' => 'I got a 3-day eviction notice.',
        'intent' => 'topic_housing_eviction',
        'topic' => 'eviction',
      ],
    ];

    $this->assertTrue($decider->shouldOverrideOfficesContact(
      $intent,
      self::GENERIC_FOLLOWUP_INTENTS,
      $history,
      $summary,
      'This is in Boise.',
      TRUE,
      FALSE
    ));
  }

  /**
   *
   */
  public function testNextStepFollowupOverridesEvenWithoutCity(): void {
    $decider = new HousingEvictionContinuityDecider();
    $intent = ['type' => 'unknown', 'confidence' => 0.0];
    $summary = ['current_topic' => 'housing_eviction'];
    $history = [['role' => 'user', 'text' => 'I got an eviction notice.', 'intent' => 'topic_housing_eviction']];

    $this->assertTrue($decider->shouldOverrideOfficesContact(
      $intent,
      self::GENERIC_FOLLOWUP_INTENTS,
      $history,
      $summary,
      'What are my next steps?',
      FALSE,
      TRUE
    ));
  }

  /**
   *
   */
  public function testTopicSwitchToCustodyDoesNotOverride(): void {
    $decider = new HousingEvictionContinuityDecider();
    $intent = ['type' => 'topic_family', 'confidence' => 0.9];
    $summary = ['current_topic' => 'housing_eviction'];
    $history = [['role' => 'user', 'text' => 'I got an eviction notice.', 'intent' => 'topic_housing_eviction']];

    $this->assertFalse($decider->shouldOverrideOfficesContact(
      $intent,
      self::GENERIC_FOLLOWUP_INTENTS,
      $history,
      $summary,
      "What should I do about custody, I'm in Ada County?",
      TRUE,
      TRUE
    ));
  }

  /**
   *
   */
  public function testTopicSwitchToConsumerDoesNotOverride(): void {
    $decider = new HousingEvictionContinuityDecider();
    $intent = ['type' => 'topic_consumer', 'confidence' => 0.9];
    $summary = ['current_topic' => 'housing_eviction'];
    $history = [['role' => 'user', 'text' => 'I got an eviction notice.']];

    $this->assertFalse($decider->shouldOverrideOfficesContact(
      $intent,
      self::GENERIC_FOLLOWUP_INTENTS,
      $history,
      $summary,
      'I have a debt collector calling.',
      FALSE,
      FALSE
    ));
  }

  /**
   *
   */
  public function testEmptyHistoryDoesNotOverride(): void {
    $decider = new HousingEvictionContinuityDecider();
    $this->assertFalse($decider->shouldOverrideOfficesContact(
      ['type' => 'offices_contact'],
      self::GENERIC_FOLLOWUP_INTENTS,
      [],
      [],
      'This is in Boise.',
      TRUE,
      FALSE
    ));
  }

  /**
   *
   */
  public function testResetSignalSuppressesGuard(): void {
    $decider = new HousingEvictionContinuityDecider();
    $summary = ['current_topic' => 'housing_eviction'];
    $history = [['role' => 'user', 'text' => 'I got an eviction notice.']];

    $this->assertFalse($decider->shouldOverrideOfficesContact(
      ['type' => 'offices_contact'],
      self::GENERIC_FOLLOWUP_INTENTS,
      $history,
      $summary,
      'Actually, switching topics — where is your Boise office?',
      TRUE,
      FALSE
    ));
  }

  /**
   *
   */
  public function testNonHousingHistoryDoesNotFire(): void {
    $decider = new HousingEvictionContinuityDecider();
    $intent = ['type' => 'offices_contact'];
    $summary = ['current_topic' => 'family'];
    $history = [['role' => 'user', 'text' => 'I want to file for custody.']];

    $this->assertFalse($decider->shouldOverrideOfficesContact(
      $intent,
      self::GENERIC_FOLLOWUP_INTENTS,
      $history,
      $summary,
      'This is in Boise.',
      TRUE,
      FALSE
    ));
  }

  /**
   *
   */
  public function testIsHousingEvictionFollowupViaContextSummary(): void {
    $decider = new HousingEvictionContinuityDecider();
    $this->assertTrue($decider->isHousingEvictionFollowup(
      ['current_topic' => 'housing_eviction'],
      [],
      'where is my office'
    ));
  }

  /**
   *
   */
  public function testIsHousingEvictionFollowupViaDeadlineMarker(): void {
    $decider = new HousingEvictionContinuityDecider();
    $this->assertTrue($decider->isHousingEvictionFollowup(
      ['deadline_or_notice' => '3_day_notice'],
      [],
      'this is in boise'
    ));
  }

  /**
   *
   */
  public function testIsHousingEvictionFollowupViaCurrentMessage(): void {
    $decider = new HousingEvictionContinuityDecider();
    $this->assertTrue($decider->isHousingEvictionFollowup(
      [],
      [],
      'I just got a notice to vacate.'
    ));
  }

  /**
   *
   */
  public function testIsHousingEvictionFollowupViaHistoryText(): void {
    $decider = new HousingEvictionContinuityDecider();
    $history = [
      ['role' => 'user', 'text' => 'My landlord gave me a notice to quit.'],
    ];
    $this->assertTrue($decider->isHousingEvictionFollowup(
      [],
      $history,
      'this is in nampa'
    ));
  }

  /**
   *
   */
  public function testIsHousingEvictionFollowupReturnsFalseForUnrelatedThread(): void {
    $decider = new HousingEvictionContinuityDecider();
    $history = [
      ['role' => 'user', 'text' => 'How do I file for divorce?'],
    ];
    $this->assertFalse($decider->isHousingEvictionFollowup(
      [],
      $history,
      'this is in boise'
    ));
  }

}
