<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the static eviction-followup detector used by the
 * service_area follow-up branch in AssistantApiController.
 */
#[Group('ilas_site_assistant')]
final class HousingEvictionFollowupTest extends TestCase {

  public function testCurrentTopicHousingEvictionTriggersFollowup(): void {
    $summary = ['current_topic' => 'housing_eviction'];
    $this->assertTrue(
      AssistantApiController::isHousingEvictionFollowup($summary, [], 'I am in Ada County. What are my next steps?')
    );
  }

  public function testDeadlineNoticeTriggersFollowup(): void {
    foreach (['3_day_notice', 'lockout', 'eviction_notice'] as $deadline) {
      $summary = ['deadline_or_notice' => $deadline];
      $this->assertTrue(
        AssistantApiController::isHousingEvictionFollowup($summary, [], 'next steps please'),
        "Deadline '$deadline' should trigger eviction follow-up"
      );
    }
  }

  public function testEvictionKeywordInCurrentMessageTriggers(): void {
    $this->assertTrue(
      AssistantApiController::isHousingEvictionFollowup([], [], 'My landlord just gave me a notice to vacate.')
    );
    $this->assertTrue(
      AssistantApiController::isHousingEvictionFollowup([], [], 'I got a 3-day notice')
    );
  }

  public function testEvictionKeywordInRecentHistoryTriggers(): void {
    $history = [
      ['role' => 'user', 'text' => 'I got an eviction notice'],
      ['role' => 'assistant', 'text' => 'Here is some info'],
    ];
    $this->assertTrue(
      AssistantApiController::isHousingEvictionFollowup([], $history, 'I am in Ada County. What are my next steps?')
    );
  }

  public function testHistoryTopicEvictionTriggers(): void {
    $history = [
      [
        'role' => 'user',
        'text' => '[redacted]',
        'topic' => 'eviction',
        'intent' => 'topic_housing_eviction',
      ],
    ];
    $this->assertTrue(
      AssistantApiController::isHousingEvictionFollowup([], $history, 'Ada County. What now?')
    );
  }

  public function testNonEvictionHousingTurnDoesNotTrigger(): void {
    // Plain housing follow-up without any eviction signal — must not trigger.
    $history = [
      ['role' => 'user', 'text' => 'My landlord refuses to fix the heater'],
    ];
    $this->assertFalse(
      AssistantApiController::isHousingEvictionFollowup([], $history, 'What are my next steps?')
    );
  }

  public function testEmptyInputsReturnFalse(): void {
    $this->assertFalse(
      AssistantApiController::isHousingEvictionFollowup([], [], 'next steps?')
    );
  }

}
