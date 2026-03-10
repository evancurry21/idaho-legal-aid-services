<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the real LlmEnhancer legal-advice detector defeats obfuscation.
 */
#[Group('ilas_site_assistant')]
final class LlmEnhancerLegalAdviceDetectorTest extends TestCase {

  /**
   * The testable enhancer instance.
   */
  private DetectorTestableLlmEnhancer $enhancer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn([]);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createStub(LoggerInterface::class);
    $loggerFactory = $this->createStub(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    $this->enhancer = new DetectorTestableLlmEnhancer(
      $configFactory,
      $this->createStub(ClientInterface::class),
      $loggerFactory,
      new PolicyFilter($configFactory),
    );
  }

  /**
   * The real post-generation detector should catch obfuscated advice text.
   */
  #[DataProvider('obfuscatedAdviceProvider')]
  public function testContainsLegalAdviceCatchesObfuscatedOutput(string $output): void {
    $this->assertTrue(
      $this->enhancer->detectLegalAdvice($output),
      "Expected the real LlmEnhancer detector to catch obfuscated legal advice: '$output'"
    );
  }

  /**
   * Safe informational text must remain unflagged.
   */
  #[DataProvider('safeOutputProvider')]
  public function testContainsLegalAdviceLeavesSafeOutputAlone(string $output): void {
    $this->assertFalse(
      $this->enhancer->detectLegalAdvice($output),
      "Safe output should not be flagged by the real LlmEnhancer detector: '$output'"
    );
  }

  /**
   * Provider for obfuscated post-generation advice text.
   */
  public static function obfuscatedAdviceProvider(): array {
    return [
      'slash-separated should' => ['You s/h/o/u/l/d file a motion to dismiss.'],
      'comma-separated should' => ['You s,h,o,u,l,d stop paying rent until repairs are made.'],
      'apostrophe-separated should' => ["You s'h'o'u'l'd appeal the decision immediately."],
      'zero-width should' => ["You s\u{200B}h\u{200B}o\u{200B}u\u{200B}l\u{200B}d file a motion to dismiss."],
    ];
  }

  /**
   * Provider for safe post-generation text.
   */
  public static function safeOutputProvider(): array {
    return [
      'general info' => ['This is general information only. Please contact our Legal Advice Line for advice about your situation.'],
      'resource pointer' => ['Here are some guides on the eviction process and how to contact ILAS for help.'],
    ];
  }

}

/**
 * Exposes the protected legal-advice detector for unit testing.
 */
final class DetectorTestableLlmEnhancer extends LlmEnhancer {

  /**
   * Public wrapper around the real detector.
   */
  public function detectLegalAdvice(string $text): bool {
    return $this->containsLegalAdvice($text);
  }

}
