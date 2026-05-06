<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\NullBackend;
use Drupal\ilas_site_assistant\Service\EnvironmentDetector;
use Drupal\ilas_site_assistant\Service\OfficeDirectory;
use Drupal\ilas_site_assistant\Service\OfficeLocationResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../../../src/Service/OfficeLocationResolver.php';
require_once __DIR__ . '/../../../src/Service/OfficeDirectory.php';

/**
 * Unit tests for OfficeLocationResolver.
 *
 * Backed by a fake {@see OfficeDirectory} so the resolver's lookup logic
 * (city/county/abbreviation) can be tested without a Drupal entity bootstrap.
 */
#[Group('ilas_site_assistant')]
class OfficeLocationResolverTest extends TestCase {

  /**
   * @var \Drupal\ilas_site_assistant\Service\OfficeLocationResolver
   */
  protected OfficeLocationResolver $resolver;

  /**
   * Fake directory backing the resolver.
   *
   * @var \Drupal\ilas_site_assistant\Service\OfficeDirectory
   */
  protected OfficeDirectory $directory;

  /**
   *
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = $this->makeFakeDirectory();
    $this->resolver = new OfficeLocationResolver($this->directory);
  }

  /**
   * Builds a deterministic fake OfficeDirectory matching the canonical 7
   * public offices.
   */
  private function makeFakeDirectory(): OfficeDirectory {
    return new class extends OfficeDirectory {

      public function __construct() {
        // Skip parent constructor — this fake bypasses entity loading.
      }

      /**
       *
       */
      public function all(): array {
        return [
          'boise' => $this->record('boise', 'Boise', '1447 S Tyrell Lane', 'Boise', '83706', '208-746-7541'),
          'coeur_dalene' => $this->record('coeur_dalene', 'Coeur d\'Alene', '610 W. Hubbard Avenue, Suite 219', "Coeur d'Alene", '83814', '208-215-3499'),
          'idaho_falls' => $this->record('idaho_falls', 'Idaho Falls', '482 Constitution Way, Suite 101', 'Idaho Falls', '83402', '208-279-0483'),
          'lewiston' => $this->record('lewiston', 'Lewiston', '2230 3rd Ave N', 'Lewiston', '83501', '208-413-9437'),
          'nampa' => $this->record('nampa', 'Nampa', '212 12th Ave Road', 'Nampa', '83686', '208-746-7541'),
          'pocatello' => $this->record('pocatello', 'Pocatello', '109 N Arthur Avenue, Suite 302', 'Pocatello', '83204', '208-904-0620'),
          'twin_falls' => $this->record('twin_falls', 'Twin Falls', '496 Shoup Ave West, Suite G', 'Twin Falls', '83301', '208-944-2897'),
        ];
      }

      /**
       *
       */
      public function get(string $slug): ?array {
        return $this->all()[$slug] ?? NULL;
      }

      /**
       *
       */
      public function isAvailable(): bool {
        return TRUE;
      }

      /**
       *
       */
      public function detectStaleTokens(string $message): array {
        $hits = [];
        foreach (parent::STALE_OFFICE_TOKENS as $token) {
          if (stripos($message, $token) !== FALSE) {
            $hits[] = $token;
          }
        }
        return $hits;
      }

      /**
       *
       */
      public function assertNoStaleTokens(string $message, string $context = 'response'): bool {
        return $this->detectStaleTokens($message) === [];
      }

      /**
       *
       */
      public function invalidate(): void {}

      /**
       *
       */
      private function record(string $slug, string $name, string $street, string $city, string $postal, string $phone): array {
        return [
          'slug' => $slug,
          'name' => $name,
          'street' => $street,
          'city' => $city,
          'postal_code' => $postal,
          'address' => $street . ', ' . $city . ' ' . $postal,
          'phone' => $phone,
          'phone_secondary' => '',
          'hours' => 'Monday through Friday, 8:30 a.m. to 4:30 p.m. (call to confirm current office hours).',
          'url' => '/contact/offices/' . str_replace('_', '-', $slug) . '-office',
          'counties' => '',
          'source_nid' => 0,
          'poisoned' => FALSE,
        ];
      }

    };
  }

  /**
 *
 */
  #[DataProvider('cityResolutionProvider')]
  public function testCityResolution(string $input, string $expected_office): void {
    $result = $this->resolver->resolve($input);
    $this->assertNotNull($result, "Input '$input' should resolve to an office");
    $this->assertEquals($expected_office, $result['name'], "Input '$input' should resolve to '$expected_office'");
  }

  /**
   *
   */
  public static function cityResolutionProvider(): array {
    return [
      'boise' => ['boise', 'Boise'],
      'boise_uppercase' => ['BOISE', 'Boise'],
      'boise_whitespace' => ['  boise  ', 'Boise'],
      'meridian' => ['meridian', 'Boise'],
      'eagle' => ['eagle', 'Boise'],
      'mountain_home' => ['mountain home', 'Boise'],
      'nampa' => ['nampa', 'Nampa'],
      'caldwell' => ['caldwell', 'Nampa'],
      'pocatello' => ['pocatello', 'Pocatello'],
      'blackfoot' => ['blackfoot', 'Pocatello'],
      'american_falls' => ['american falls', 'Pocatello'],
      'twin_falls' => ['twin falls', 'Twin Falls'],
      'jerome' => ['jerome', 'Twin Falls'],
      'burley' => ['burley', 'Twin Falls'],
      'hailey' => ['hailey', 'Twin Falls'],
      'ketchum' => ['ketchum', 'Twin Falls'],
      'lewiston' => ['lewiston', 'Lewiston'],
      'moscow' => ['moscow', 'Lewiston'],
      'coeur_dalene' => ["coeur d'alene", "Coeur d'Alene"],
      'sandpoint' => ['sandpoint', "Coeur d'Alene"],
      'post_falls' => ['post falls', "Coeur d'Alene"],
      'idaho_falls' => ['idaho falls', 'Idaho Falls'],
      'rexburg' => ['rexburg', 'Idaho Falls'],
      'driggs' => ['driggs', 'Idaho Falls'],
      'salmon' => ['salmon', 'Idaho Falls'],
      'rigby' => ['rigby', 'Idaho Falls'],
      'sentence_where_boise' => ['where is the boise office', 'Boise'],
      'sentence_what_office_twin_falls' => ['what office helps me in twin falls', 'Twin Falls'],
      'sentence_directions_lewiston' => ['directions to the lewiston office', 'Lewiston'],
      'sentence_visit_pocatello' => ['can i visit the pocatello office', 'Pocatello'],
      'sentence_near_idaho_falls' => ['nearest office to idaho falls', 'Idaho Falls'],
    ];
  }

  /**
 *
 */
  #[DataProvider('abbreviationProvider')]
  public function testAbbreviationResolution(string $input, string $expected_office): void {
    $result = $this->resolver->resolve($input);
    $this->assertNotNull($result, "Abbreviation '$input' should resolve to an office");
    $this->assertEquals($expected_office, $result['name']);
  }

  /**
   *
   */
  public static function abbreviationProvider(): array {
    return [
      'cda' => ['CDA', "Coeur d'Alene"],
      'cda_lowercase' => ['cda', "Coeur d'Alene"],
      'if' => ['IF', 'Idaho Falls'],
      'tf' => ['TF', 'Twin Falls'],
      'mtn_home' => ['mtn home', 'Boise'],
    ];
  }

  /**
 *
 */
  #[DataProvider('countyResolutionProvider')]
  public function testCountyResolution(string $input, string $expected_office): void {
    $result = $this->resolver->resolve($input);
    $this->assertNotNull($result, "County '$input' should resolve");
    $this->assertEquals($expected_office, $result['name']);
  }

  /**
   *
   */
  public static function countyResolutionProvider(): array {
    return [
      'ada_county' => ['ada county', 'Boise'],
      'Ada_County_capitalized' => ['Ada County', 'Boise'],
      'canyon_county' => ['canyon county', 'Nampa'],
      'bannock_county' => ['bannock county', 'Pocatello'],
      'bingham_county' => ['bingham county', 'Idaho Falls'],
      'twin_falls_county' => ['twin falls county', 'Twin Falls'],
      'blaine_county' => ['blaine county', 'Twin Falls'],
      'nez_perce_county' => ['nez perce county', 'Lewiston'],
      'kootenai_county' => ['kootenai county', "Coeur d'Alene"],
      'latah_county' => ['latah county', 'Lewiston'],
      'bonneville_county' => ['bonneville county', 'Idaho Falls'],
      'madison_county' => ['madison county', 'Idaho Falls'],
      'fremont_county' => ['fremont county', 'Idaho Falls'],
      'idaho_county' => ['idaho county', 'Lewiston'],
      'bonneville_bare' => ['bonneville', 'Idaho Falls'],
      'bannock_bare' => ['bannock', 'Pocatello'],
      'latah_bare' => ['latah', 'Lewiston'],
      'blaine_bare' => ['blaine', 'Twin Falls'],
      'elmore_bare' => ['elmore', 'Boise'],
    ];
  }

  /**
 *
 */
  #[DataProvider('unknownLocationProvider')]
  public function testUnknownLocationReturnsNull(string $input): void {
    $result = $this->resolver->resolve($input);
    $this->assertNull($result, "Input '$input' should return NULL");
  }

  /**
   *
   */
  public static function unknownLocationProvider(): array {
    return [
      'empty' => [''],
      'whitespace_only' => ['   '],
      'mars' => ['mars'],
      'new_york' => ['new york'],
      'portland' => ['portland'],
      'gibberish' => ['asdfghjkl'],
      'help' => ['i need help'],
      'spanish_idaho_not_county' => ['que derechos tengo como inquilino en idaho'],
    ];
  }

  /**
   *
   */
  public function testResolvedOfficeHasRequiredKeys(): void {
    $result = $this->resolver->resolve('boise');
    $this->assertNotNull($result);
    foreach (['name', 'address', 'phone', 'hours', 'url', 'slug'] as $key) {
      $this->assertArrayHasKey($key, $result);
      $this->assertNotEmpty($result[$key], "Key '$key' must be non-empty");
    }
    $this->assertSame('1447 S Tyrell Lane, Boise 83706', $result['address']);
    $this->assertSame('208-746-7541', $result['phone']);
  }

  /**
   *
   */
  public function testGetAllOfficesReturnsSeven(): void {
    $offices = $this->resolver->getAllOffices();
    $this->assertCount(7, $offices, 'Resolver must surface all 7 current public offices.');
    $expected = ['boise', 'coeur_dalene', 'idaho_falls', 'lewiston', 'nampa', 'pocatello', 'twin_falls'];
    $this->assertSame($expected, array_keys($offices));
    foreach ($offices as $slug => $office) {
      $this->assertArrayHasKey('name', $office, "Office '$slug' must have 'name'");
      $this->assertArrayHasKey('address', $office);
      $this->assertArrayHasKey('phone', $office);
      $this->assertArrayHasKey('url', $office);
    }
  }

  /**
   *
   */
  public function testNoStaleTokensAcrossDirectory(): void {
    $stale = [
      '310 N 5th',
      '83702',
      '208-345-0106',
      '208-336-8980',
      '208-331-9031',
      '1424 Main',
    ];
    foreach ($this->resolver->getAllOffices() as $slug => $office) {
      $haystack = strtolower(implode(' | ', [
        (string) ($office['address'] ?? ''),
        (string) ($office['street'] ?? ''),
        (string) ($office['phone'] ?? ''),
        (string) ($office['phone_secondary'] ?? ''),
      ]));
      foreach ($stale as $needle) {
        $this->assertStringNotContainsString(
          strtolower($needle),
          $haystack,
          "Office '$slug' must not contain stale token '$needle'"
        );
      }
    }
  }

}
