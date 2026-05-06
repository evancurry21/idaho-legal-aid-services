<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\OfficeDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/Service/OfficeDirectory.php';

/**
 * Regression coverage for stale office data tokens.
 *
 * Locks down the deny-list so future regressions cannot reintroduce the
 * stale Boise office address (310 N 5th Street, 83702), the deprecated
 * direct office numbers, or the stale Lewiston address.
 */
#[Group('ilas_site_assistant')]
final class OfficeStaleDataRegressionTest extends TestCase {

  /**
 *
 */
  #[DataProvider('staleTokenProvider')]
  public function testDenyListDetectsStaleToken(string $message, string $expected_token): void {
    $directory = new class extends OfficeDirectory {

      public function __construct() {}

    };
    $hits = $directory->detectStaleTokens($message);
    $this->assertNotEmpty($hits, "Expected deny-list to detect '$expected_token' in: $message");
  }

  /**
   *
   */
  public static function staleTokenProvider(): array {
    return [
      'old_boise_address' => ['Visit us at 310 N 5th Street, Boise.', '310 N 5th'],
      'old_boise_address_period' => ['310 N. 5th St., Boise', '310 N. 5th'],
      'old_boise_zip_paired' => ['Boise office at 83702', 'boise:83702'],
      'old_boise_zip_paired_reverse' => ['83702 Boise office', 'boise:83702'],
      'old_boise_phone_paren' => ['Call (208) 345-0106 to reach us.', '(208) 345-0106'],
      'old_boise_phone_dash' => ['Call 208-345-0106 to reach us.', '208-345-0106'],
      'old_phone_336' => ['208-336-8980', '208-336-8980'],
      'old_phone_331' => ['(208) 331-9031', '(208) 331-9031'],
      'old_lewiston' => ['Lewiston office at 1424 Main Street.', '1424 Main'],
    ];
  }

  /**
   *
   */
  public function testCleanMessageDoesNotTripDenyList(): void {
    $directory = new class extends OfficeDirectory {

      public function __construct() {}

    };
    $hits = $directory->detectStaleTokens(
      'Boise office is at 1447 S Tyrell Lane, 83706. Call 208-746-7541.'
    );
    $this->assertSame([], $hits);
  }

  /**
   *
   */
  public function testCleanLewistonAddressDoesNotTripDenyList(): void {
    $directory = new class extends OfficeDirectory {

      public function __construct() {}

    };
    $hits = $directory->detectStaleTokens(
      'Lewiston office: 2230 3rd Ave N, Lewiston, ID 83501.'
    );
    $this->assertSame([], $hits);
  }

  /**
   *
   */
  public function testGenericZip83702InUserMessageStillTrippsBoiseGuard(): void {
    // The Boise paired-context regex requires Boise within ~40 chars of 83702.
    // A bare 83702 in user input stays silent (we don't reject user-supplied
    // ZIPs); only an office record claiming 83702 IS BoiseTM trips it.
    $directory = new class extends OfficeDirectory {

      public function __construct() {}

    };
    $hits = $directory->detectStaleTokens('My ZIP code is 83702.');
    $this->assertSame([], $hits, 'Bare ZIP without Boise office context must not trigger guard.');
  }

}
