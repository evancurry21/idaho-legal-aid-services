<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Drupal\ilas_site_assistant\Service\AcronymExpander;

/**
 * Tests the AcronymExpander service.
 */
#[Group('ilas_site_assistant')]
class AcronymExpanderTest extends TestCase {

  /**
   * The AcronymExpander instance under test.
   *
   * @var \Drupal\ilas_site_assistant\Service\AcronymExpander
   */
  protected $expander;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->expander = new AcronymExpander(NULL);
  }

  /**
   * Tests that the acronym map loaded correctly.
   */
  public function testAcronymMapLoaded(): void {
    $map = $this->expander->getAcronymMap();
    $this->assertNotEmpty($map, 'Acronym map should not be empty');
    $this->assertArrayHasKey('dv', $map);
    $this->assertArrayHasKey('poa', $map);
    $this->assertArrayHasKey('ssi', $map);
    $this->assertArrayHasKey('snap', $map);
  }

  /**
 *
 */
  #[DataProvider('acronymExpansionProvider')]
  public function testAcronymExpansion(string $input, string $expected_substring, string $description): void {
    $result = $this->expander->expand($input);
    $this->assertStringContainsString(
      $expected_substring,
      $result['text'],
      "Failed for: $description (input: '$input')"
    );
    $this->assertNotEmpty($result['expansions'], "Expected at least one expansion for '$input'");
  }

  /**
   *
   */
  public static function acronymExpansionProvider(): array {
    return [
      // === Domestic / Safety (5 cases) ===
      ['I need help with DV', 'domestic violence', 'DV -> domestic violence'],
      ['my DV situation is bad', 'domestic violence', 'DV in sentence context'],
      ['need a PO against my ex', 'protection order', 'PO -> protection order'],
      ['how do I get a CPO', 'civil protection order', 'CPO -> civil protection order'],
      ['file a TRO', 'temporary restraining order', 'TRO -> temporary restraining order'],

      // === Legal / Court (4 cases) ===
      ['I need a POA for my mom', 'power of attorney', 'POA -> power of attorney'],
      ['DPOA for elderly parent', 'durable power of attorney', 'DPOA -> durable power of attorney'],
      ['need MPOA forms', 'medical power of attorney', 'MPOA -> medical power of attorney'],
      ['what is a GAL', 'guardian ad litem', 'GAL -> guardian ad litem'],

      // === Benefits / Programs (10 cases) ===
      ['apply for SSI', 'supplemental security income', 'SSI -> supplemental security income'],
      ['denied SSDI benefits', 'social security disability insurance', 'SSDI -> social security disability insurance'],
      ['help with SNAP', 'nutrition assistance', 'SNAP -> food stamps/nutrition'],
      ['lost my EBT card', 'electronic benefits transfer', 'EBT -> electronic benefits transfer'],
      ['need WIC help', 'women infants children', 'WIC -> women infants children'],
      ['TANF application', 'temporary assistance', 'TANF -> temporary assistance'],
      ['apply for LIHEAP', 'energy assistance', 'LIHEAP -> energy assistance'],
      ['HUD housing', 'housing and urban development', 'HUD -> housing and urban development'],
      ['SS benefits denied', 'social security', 'SS -> social security'],
      ['help with SSD claim', 'social security disability', 'SSD -> social security disability'],

      // === Health (3 cases) ===
      ['ACA enrollment', 'affordable care act', 'ACA -> affordable care act'],
      ['CHIP for my kids', 'children health insurance', 'CHIP -> children health insurance'],
      ['VA benefits denied', 'veterans affairs', 'VA -> veterans affairs'],

      // === Consumer / Debt (5 cases) ===
      ['report to FTC', 'federal trade commission', 'FTC -> federal trade commission'],
      ['FDCPA violation', 'fair debt collection', 'FDCPA -> fair debt collection'],
      ['FCRA dispute', 'fair credit reporting', 'FCRA -> fair credit reporting'],
      ['file BK', 'bankruptcy', 'BK -> bankruptcy'],
      ['CH7 vs CH13', 'chapter 7', 'CH7 -> chapter 7'],

      // === Family Law (3 cases) ===
      ['CS modification', 'child support', 'CS -> child support'],
      ['CPS took my kids', 'child protective services', 'CPS -> child protective services'],
      ['ICPC transfer', 'interstate compact', 'ICPC -> interstate compact'],

      // === Employment / Civil Rights (5 cases) ===
      ['file EEOC complaint', 'equal employment opportunity', 'EEOC -> equal employment opportunity'],
      ['ADA accommodation', 'americans with disabilities', 'ADA -> americans with disabilities'],
      ['FMLA leave denied', 'family medical leave', 'FMLA -> family medical leave'],
      ['FLSA wage violation', 'fair labor standards', 'FLSA -> fair labor standards'],
      ['OSHA complaint', 'occupational safety', 'OSHA -> occupational safety'],

      // === Idaho-Specific (3 cases) ===
      ['what does ILAS do', 'idaho legal aid services', 'ILAS -> idaho legal aid services'],
      ['help from IDHW', 'idaho department of health', 'IDHW -> idaho department of health'],
      ['DHW benefits', 'idaho department of health', 'DHW -> idaho department of health'],

      // === General Legal (2 cases) ===
      ['going pro se', 'self represented', 'pro se -> self represented'],
      ['pro bono lawyer', 'free legal help', 'pro bono -> free legal help'],

      // === UI (unemployment) ===
      ['denied UI benefits', 'unemployment insurance', 'UI -> unemployment insurance'],

      // === IRS ===
      ['IRS sent a letter', 'internal revenue service', 'IRS -> internal revenue service'],
    ];
  }

  /**
 *
 */
  #[DataProvider('noExpansionProvider')]
  public function testNoFalseExpansion(string $input, string $description): void {
    $result = $this->expander->expand($input);
    $this->assertEmpty(
      $result['expansions'],
      "Should not expand anything in: '$input' ($description)"
    );
  }

  /**
   *
   */
  public static function noExpansionProvider(): array {
    return [
      ['I need help with divorce', 'No acronyms present'],
      ['eviction notice received', 'No acronyms present'],
      ['how to file bankruptcy', 'No acronyms present'],
      ['custody forms please', 'No acronyms present'],
      ['hello how are you', 'Greeting, no acronyms'],
    ];
  }

  /**
   * Tests isAcronym method.
   */
  public function testIsAcronym(): void {
    $this->assertTrue($this->expander->isAcronym('DV'));
    $this->assertTrue($this->expander->isAcronym('dv'));
    $this->assertTrue($this->expander->isAcronym('POA'));
    $this->assertTrue($this->expander->isAcronym('SSI'));
    $this->assertFalse($this->expander->isAcronym('divorce'));
    $this->assertFalse($this->expander->isAcronym('help'));
    $this->assertFalse($this->expander->isAcronym('XYZ'));
  }

  /**
   * Tests getExpansion method.
   */
  public function testGetExpansion(): void {
    $this->assertEquals('domestic violence', $this->expander->getExpansion('DV'));
    $this->assertEquals('power of attorney', $this->expander->getExpansion('POA'));
    $this->assertNull($this->expander->getExpansion('NOTREAL'));
  }

  /**
   * Tests getIntentHint method.
   */
  public function testGetIntentHint(): void {
    $this->assertEquals('high_risk_dv', $this->expander->getIntentHint('DV'));
    $this->assertEquals('forms_finder', $this->expander->getIntentHint('POA'));
    $this->assertEquals('topic_benefits', $this->expander->getIntentHint('SSI'));
    $this->assertNull($this->expander->getIntentHint('NOTREAL'));
  }

  /**
   * Tests case insensitivity.
   */
  public function testCaseInsensitive(): void {
    $result_upper = $this->expander->expand('DV situation');
    $result_lower = $this->expander->expand('dv situation');
    $this->assertEquals($result_upper['text'], $result_lower['text']);
    $this->assertStringContainsString('domestic violence', $result_upper['text']);
  }

  /**
   * Tests multiple acronyms in one message.
   */
  public function testMultipleAcronyms(): void {
    $result = $this->expander->expand('need SSI and SNAP help');
    $this->assertStringContainsString('supplemental security income', $result['text']);
    $this->assertStringContainsString('nutrition assistance', $result['text']);
    $this->assertCount(2, $result['expansions']);
  }

  /**
   * Tests that partial word matches are avoided.
   */
  public function testNoPartialWordMatch(): void {
    // "DIVA" should not match "DV".
    $result = $this->expander->expand('diva concert tickets');
    $this->assertEmpty($result['expansions'], 'Should not match DV inside DIVA');
  }

}
