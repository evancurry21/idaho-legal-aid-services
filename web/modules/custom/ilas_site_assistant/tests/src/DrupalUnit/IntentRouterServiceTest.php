<?php

namespace Drupal\Tests\ilas_site_assistant\DrupalUnit;

use Drupal\Tests\UnitTestCase;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\TopicResolver;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;

/**
 * Unit tests for IntentRouter service.
 *
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\IntentRouter
 * @group ilas_site_assistant
 */
class IntentRouterServiceTest extends UnitTestCase {

  /**
   * The intent router service.
   *
   * @var \Drupal\ilas_site_assistant\Service\IntentRouter
   */
  protected $intentRouter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create mock config.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    // Create mock topic resolver.
    $topicResolver = $this->createMock(TopicResolver::class);
    $topicResolver->method('resolveFromText')->willReturn(NULL);
    $topicResolver->method('searchTopics')->willReturn([]);

    $this->intentRouter = new IntentRouter($configFactory, $topicResolver);
  }

  /**
   * Tests greeting detection.
   *
   * @covers ::route
   * @dataProvider greetingProvider
   */
  public function testGreetingDetection(string $message, bool $expectGreeting): void {
    $result = $this->intentRouter->route($message);

    if ($expectGreeting) {
      $this->assertEquals('greeting', $result['type']);
    }
    else {
      $this->assertNotEquals('greeting', $result['type']);
    }
  }

  /**
   * Data provider for greeting tests.
   */
  public static function greetingProvider(): array {
    return [
      'hi' => ['hi', TRUE],
      'hello' => ['hello', TRUE],
      'hey' => ['hey!', TRUE],
      'good morning' => ['good morning', TRUE],
      'hi with question' => ['hi how do i apply for help', FALSE],
      'greeting in sentence' => ['i said hello to the landlord', FALSE],
    ];
  }

  /**
   * Tests apply intent detection.
   *
   * @covers ::route
   * @dataProvider applyProvider
   */
  public function testApplyDetection(string $message, string $expectedType): void {
    $result = $this->intentRouter->route($message);
    $this->assertEquals($expectedType, $result['type']);
  }

  /**
   * Data provider for apply tests.
   */
  public static function applyProvider(): array {
    return [
      'apply for help' => ['how do i apply for help', 'apply'],
      'need a lawyer' => ['i need a lawyer', 'apply'],
      'need legal help' => ['i need legal help', 'apply'],
      'get started' => ['how do i get started', 'apply'],
      'find attorney' => ['how do i find an attorney', 'apply'],
    ];
  }

  /**
   * Tests eligibility intent detection.
   *
   * @covers ::route
   */
  public function testEligibilityDetection(): void {
    $messages = [
      'do i qualify for help',
      'am i eligible',
      'who can get help',
      'eligibility requirements',
    ];

    foreach ($messages as $message) {
      $result = $this->intentRouter->route($message);
      $this->assertEquals('eligibility', $result['type'], "Failed for: $message");
    }
  }

  /**
   * Tests hotline intent detection.
   *
   * @covers ::route
   */
  public function testHotlineDetection(): void {
    $messages = [
      'what is the hotline number',
      'can i call someone',
      'phone number',
      'talk to someone',
    ];

    foreach ($messages as $message) {
      $result = $this->intentRouter->route($message);
      $this->assertEquals('hotline', $result['type'], "Failed for: $message");
    }
  }

  /**
   * Tests offices intent detection.
   *
   * @covers ::route
   */
  public function testOfficesDetection(): void {
    $messages = [
      'where is your office',
      'office locations',
      'what are your hours',
      'boise office address',
    ];

    foreach ($messages as $message) {
      $result = $this->intentRouter->route($message);
      $this->assertEquals('offices', $result['type'], "Failed for: $message");
    }
  }

  /**
   * Tests forms intent detection.
   *
   * @covers ::route
   */
  public function testFormsDetection(): void {
    $messages = [
      'where can i find forms',
      'divorce forms',
      'eviction response form',
      'download documents',
    ];

    foreach ($messages as $message) {
      $result = $this->intentRouter->route($message);
      $this->assertEquals('forms', $result['type'], "Failed for: $message");
    }
  }

  /**
   * Tests guides intent detection.
   *
   * @covers ::route
   */
  public function testGuidesDetection(): void {
    $messages = [
      'tenant rights guide',
      'step by step instructions',
      'how to guide',
    ];

    foreach ($messages as $message) {
      $result = $this->intentRouter->route($message);
      $this->assertEquals('guides', $result['type'], "Failed for: $message");
    }
  }

  /**
   * Tests FAQ intent detection.
   *
   * @covers ::route
   */
  public function testFaqDetection(): void {
    $messages = [
      'frequently asked questions',
      'FAQ',
      'what is the difference between',
    ];

    foreach ($messages as $message) {
      $result = $this->intentRouter->route($message);
      $this->assertEquals('faq', $result['type'], "Failed for: $message");
    }
  }

  /**
   * Tests service area detection.
   *
   * @covers ::route
   * @dataProvider serviceAreaProvider
   */
  public function testServiceAreaDetection(string $message, string $expectedArea): void {
    $result = $this->intentRouter->route($message);

    $this->assertEquals('service_area', $result['type']);
    $this->assertEquals($expectedArea, $result['area']);
  }

  /**
   * Data provider for service area tests.
   */
  public static function serviceAreaProvider(): array {
    return [
      'housing eviction' => ['i got an eviction notice', 'housing'],
      'landlord issues' => ['my landlord is not fixing things', 'housing'],
      'divorce' => ['i want a divorce', 'family'],
      'custody' => ['child custody questions', 'family'],
      'debt collection' => ['debt collectors are calling me', 'consumer'],
      'medicaid' => ['i was denied medicaid', 'health'],
      'senior help' => ['senior legal help', 'seniors'],
      'discrimination' => ['workplace discrimination', 'civil_rights'],
    ];
  }

  /**
   * Tests donate intent detection.
   *
   * @covers ::route
   */
  public function testDonateDetection(): void {
    $messages = [
      'i want to donate',
      'how can i give money',
      'make a donation',
    ];

    foreach ($messages as $message) {
      $result = $this->intentRouter->route($message);
      $this->assertEquals('donate', $result['type'], "Failed for: $message");
    }
  }

  /**
   * Tests feedback intent detection.
   *
   * @covers ::route
   */
  public function testFeedbackDetection(): void {
    $messages = [
      'i want to complain',
      'feedback form',
      'file a complaint',
    ];

    foreach ($messages as $message) {
      $result = $this->intentRouter->route($message);
      $this->assertEquals('feedback', $result['type'], "Failed for: $message");
    }
  }

  /**
   * Tests services overview intent detection.
   *
   * @covers ::route
   */
  public function testServicesDetection(): void {
    $messages = [
      'what do you do',
      'what services do you provide',
      'types of help you offer',
    ];

    foreach ($messages as $message) {
      $result = $this->intentRouter->route($message);
      $this->assertEquals('services', $result['type'], "Failed for: $message");
    }
  }

  /**
   * Tests unknown intent fallback.
   *
   * @covers ::route
   */
  public function testUnknownFallback(): void {
    $messages = [
      'asdfghjkl',
      'lorem ipsum dolor sit amet',
      '123456789',
    ];

    foreach ($messages as $message) {
      $result = $this->intentRouter->route($message);
      // Should either be unknown or fall through to resources.
      $this->assertContains($result['type'], ['unknown', 'resources'], "Unexpected type for: $message");
    }
  }

  /**
   * Tests priority of intent detection.
   *
   * Eligibility should take priority over apply when both match.
   *
   * @covers ::route
   */
  public function testIntentPriority(): void {
    // This message matches both eligibility and apply patterns.
    $result = $this->intentRouter->route('do i qualify to apply for help');

    // Eligibility should win due to priority order.
    $this->assertEquals('eligibility', $result['type']);
  }

  /**
   * Tests risk detector intent detection.
   *
   * @covers ::route
   */
  public function testRiskDetectorDetection(): void {
    $messages = [
      'senior legal risk assessment',
      'risk detector',
      'legal checkup',
    ];

    foreach ($messages as $message) {
      $result = $this->intentRouter->route($message);
      $this->assertEquals('risk_detector', $result['type'], "Failed for: $message");
    }
  }

  /**
   * Tests consumer debt intent detection.
   *
   * Ensures consumer debt-related queries route to the consumer service area.
   *
   * @covers ::route
   * @dataProvider consumerDebtProvider
   */
  public function testConsumerDebtDetection(string $message, string $expectedArea): void {
    $result = $this->intentRouter->route($message);
    $this->assertEquals('service_area', $result['type'], "Expected service_area type for: $message");
    $this->assertEquals($expectedArea, $result['area'], "Expected $expectedArea area for: $message");
  }

  /**
   * Data provider for consumer debt tests.
   */
  public static function consumerDebtProvider(): array {
    return [
      // English - debt collection
      'debt collector calling' => ['a debt collector keeps calling me at work', 'consumer'],
      'bill collector' => ['a bill collector is harassing me', 'consumer'],
      'collection calls' => ['collection agency calling me constantly', 'consumer'],
      // English - garnishment
      'wage garnishment' => ['how do I stop wage garnishment', 'consumer'],
      'wages garnished' => ['my wages are being garnished', 'consumer'],
      // English - medical debt (CRITICAL: should NOT route to health)
      'medical bills' => ['I have a lot of medical bills I can not pay', 'consumer'],
      'hospital debt' => ['hospital debt is overwhelming', 'consumer'],
      // English - debt lawsuits
      'sued for debt' => ['I was sued for a debt', 'consumer'],
      'court papers debt' => ['got court papers for a debt', 'consumer'],
      // English - repossession
      'car repossessed' => ['my car was repossessed', 'consumer'],
      // English - bankruptcy
      'file bankruptcy' => ['can I file bankruptcy', 'consumer'],
      // English - credit report
      'credit report error' => ['there is an error on my credit report', 'consumer'],
      // English - old debt
      'old debt' => ['can they still collect on a debt from 10 years ago', 'consumer'],
      // English - bank levy
      'bank account frozen' => ['creditor froze my bank account', 'consumer'],
    ];
  }

  /**
   * Tests Spanish consumer debt intent detection.
   *
   * @covers ::route
   * @dataProvider consumerDebtSpanishProvider
   */
  public function testConsumerDebtSpanishDetection(string $message, string $expectedArea): void {
    $result = $this->intentRouter->route($message);
    $this->assertEquals('service_area', $result['type'], "Expected service_area type for Spanish: $message");
    $this->assertEquals($expectedArea, $result['area'], "Expected $expectedArea area for Spanish: $message");
  }

  /**
   * Data provider for Spanish consumer debt tests.
   */
  public static function consumerDebtSpanishProvider(): array {
    return [
      // Spanish - debt collector
      'cobrador llama' => ['un cobrador me llama todos los dias', 'consumer'],
      'cobrador de deudas' => ['el cobrador de deudas no me deja en paz', 'consumer'],
      // Spanish - garnishment
      'embargando sueldo' => ['me estan embargando el sueldo', 'consumer'],
      // Spanish - medical debt (CRITICAL: should NOT route to health)
      'deudas medicas' => ['tengo muchas deudas medicas', 'consumer'],
      'facturas medicas' => ['tengo muchas facturas medicas que no puedo pagar', 'consumer'],
      // Spanish - repossession
      'quitaron carro' => ['me quitaron el carro', 'consumer'],
    ];
  }

  /**
   * Tests that medical debt does NOT route to health topic.
   *
   * This is a critical guardrail test - medical bills/debt are CONSUMER issues
   * (debt collection), not healthcare access issues.
   *
   * @covers ::route
   * @dataProvider medicalDebtNotHealthProvider
   */
  public function testMedicalDebtRoutesToConsumerNotHealth(string $message): void {
    $result = $this->intentRouter->route($message);

    $this->assertEquals('service_area', $result['type'],
      "Medical debt '$message' should be a service_area");
    $this->assertEquals('consumer', $result['area'],
      "Medical debt '$message' should route to CONSUMER, not health");
    $this->assertNotEquals('health', $result['area'],
      "Medical debt '$message' should NOT route to health");
  }

  /**
   * Data provider for medical debt guardrail tests.
   */
  public static function medicalDebtNotHealthProvider(): array {
    return [
      ['I have medical bills I can not pay'],
      ['hospital bills are in collections'],
      ['medical debt is overwhelming me'],
      ['I owe money for medical bills'],
      ['tengo muchas deudas medicas'],
      ['facturas medicas que no puedo pagar'],
      ['deuda del hospital'],
    ];
  }

  /**
   * Tests that debt collector calling does NOT route to hotline.
   *
   * "Debt collector keeps calling me" should route to consumer,
   * NOT to the legal advice hotline (despite containing "calling").
   *
   * @covers ::route
   */
  public function testDebtCollectorCallingDoesNotRouteToHotline(): void {
    $result = $this->intentRouter->route('a debt collector keeps calling me at work');

    $this->assertNotEquals('hotline', $result['type'],
      "Debt collector calling should NOT route to hotline");
    $this->assertEquals('service_area', $result['type']);
    $this->assertEquals('consumer', $result['area']);
  }

}
