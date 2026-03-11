<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Tests\ilas_site_assistant\Support\MultilingualRoutingEvalRunner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Locks multilingual routing/actionability coverage to the shared fixtures.
 */
#[Group('ilas_site_assistant')]
final class MultilingualRoutingEvalTest extends TestCase {

  /**
   * Tests the fixture-driven multilingual routing evaluator.
   */
  public function testCuratedMultilingualRoutingFixturesPass(): void {
    $runner = new MultilingualRoutingEvalRunner();
    $report = $runner->run();

    $failedCases = array_values(array_filter(
      $report['results'],
      static fn(array $result): bool => !$result['passed']
    ));

    $this->assertSame(
      0,
      $report['failed_cases'],
      "Expected zero multilingual routing failures.\n" .
      json_encode($failedCases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    $this->assertGreaterThanOrEqual(10, $report['passed_cases']);
  }

}
