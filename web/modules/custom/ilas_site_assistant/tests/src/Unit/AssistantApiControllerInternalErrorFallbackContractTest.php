<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\EnvironmentDetector;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\SelectionRegistry;
use Drupal\ilas_site_assistant\Service\SelectionStateStore;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Pins the durable response contract for the message-pipeline catch block.
 *
 * When the message pipeline throws an unhandled \Throwable, the controller
 * MUST return a graceful 200 with the following machine-readable markers,
 * because promptfoo evals, CI assertions, and monitoring dashboards rely on
 * these keys to distinguish a normal fallback from an exception fallback:
 *
 *   - top-level `degraded` === TRUE
 *   - top-level `escalation_type` === 'internal_error_fallback'
 *   - top-level `error_code` === 'internal_error'
 *   - meta.reason_code === 'internal_error'
 *   - meta.decision_reason === 'pipeline_exception_safe_fallback'
 *   - meta.fallback_used === TRUE
 *   - meta.degraded === TRUE
 *   - meta.schema_version === 'ilas_message_meta/v1'
 *   - actions array is non-empty (Legal Advice Line / Apply for Help)
 *
 * Removing or renaming any of these keys is a breaking change and must be
 * coordinated with the eval contracts and dashboards that consume them.
 *
 * Regression coverage for eval-FrE-2026-04-30T20:56:49 errors #7-#10.
 */
#[Group('ilas_site_assistant')]
final class AssistantApiControllerInternalErrorFallbackContractTest extends TestCase {

  /**
   *
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/controller_test_bootstrap.php';

    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(function (string $key) {
      $values = [
        'rate_limit_per_minute' => 15,
        'rate_limit_per_hour' => 120,
        'enable_faq' => TRUE,
        'enable_resources' => TRUE,
        'enable_logging' => FALSE,
        'langfuse.environment' => 'test',
        'langfuse.enabled' => FALSE,
      ];
      return $values[$key] ?? NULL;
    });
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $translationStub = $this->createStub(TranslationInterface::class);
    $translationStub->method('translateString')->willReturnCallback(
      static fn($markup) => $markup->getUntranslatedString()
    );

    $container = new ContainerBuilder();
    $container->set('logger.factory', new class {

      /**
       *
       */
      public function get(string $channel): NullLogger {
        return new NullLogger();
      }

    });
    $container->set('string_translation', $translationStub);
    $container->set('config.factory', $configFactory);
    $account = $this->createStub(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $container->set('current_user', $account);
    \Drupal::setContainer($container);
  }

  /**
   *
   */
  protected function tearDown(): void {
    new Settings([]);
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Forces classifyIntent() to throw and asserts the durable contract.
   */
  public function testCatchBlockEmitsDurableInternalErrorFallbackMarkers(): void {
    $controller = $this->buildController(throwClass: \RuntimeException::class);

    $response = $controller->message($this->buildJsonRequest());
    $body = json_decode($response->getContent(), TRUE);

    // No 5xx ever — the entire point of the hardened catch block.
    $this->assertSame(200, $response->getStatusCode(), 'Pipeline exception must yield HTTP 200, not 5xx.');

    // Top-level markers — these are the easiest assertion targets for evals.
    $this->assertTrue(
      $body['degraded'] ?? FALSE,
      'Top-level degraded=true is the primary marker for evals/CI.'
    );
    $this->assertSame(
      'internal_error_fallback',
      $body['escalation_type'] ?? NULL,
      'escalation_type discriminates exception-fallback from normal escalation.'
    );
    $this->assertSame(
      'internal_error',
      $body['error_code'] ?? NULL,
      'error_code preserves back-compat with monitors that read the old 500 envelope.'
    );

    // Meta-level markers — redundant on purpose so dashboards can pick
    // either path.
    $this->assertIsArray($body['meta'] ?? NULL);
    $meta = $body['meta'];
    $this->assertSame('ilas_message_meta/v1', $meta['schema_version'] ?? NULL);
    $this->assertSame('internal_error', $meta['reason_code'] ?? NULL);
    $this->assertSame('pipeline_exception_safe_fallback', $meta['decision_reason'] ?? NULL);
    $this->assertTrue($meta['fallback_used'] ?? FALSE);
    $this->assertTrue($meta['degraded'] ?? FALSE);

    // Actionable next steps must be present so high-urgency users can
    // still reach the Legal Advice Line / Apply for Help paths.
    $this->assertNotEmpty($body['actions'] ?? [], 'Fallback must include escalation actions.');

    // Visible message must be non-empty and not the old "Something went
    // wrong" stub from the prior 500 envelope.
    $this->assertNotEmpty($body['message'] ?? '');
    $this->assertStringNotContainsStringIgnoringCase('Something went wrong', (string) $body['message']);
  }

  /**
   * Different Throwable kinds must produce the same contract.
   *
   * The catch is `\Throwable`, so Errors and Exceptions both qualify.
   * Locking both shapes prevents future regressions where one type leaks
   * past the catch.
   *
   * @dataProvider throwableClasses
   */
  public function testCatchBlockHandlesBothErrorAndExceptionAlike(string $throwClass): void {
    $controller = $this->buildController(throwClass: $throwClass);

    $response = $controller->message($this->buildJsonRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($body['degraded'] ?? FALSE);
    $this->assertSame('internal_error_fallback', $body['escalation_type'] ?? NULL);
  }

  /**
   *
   */
  public static function throwableClasses(): array {
    return [
      'RuntimeException' => [\RuntimeException::class],
      'LogicException' => [\LogicException::class],
      'TypeError' => [\TypeError::class],
      'Error' => [\Error::class],
    ];
  }

  /**
   * Spanish input goes through the language-aware fallback copy.
   */
  public function testCatchBlockUsesSpanishCopyForSpanishInputs(): void {
    $controller = $this->buildController(throwClass: \RuntimeException::class);

    $response = $controller->message($this->buildJsonRequest([
      'message' => 'Necesito ayuda urgente con un desalojo',
    ]));
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('es', $body['meta']['language_hint'] ?? NULL);
    // Content must be Spanish, not the English default.
    $this->assertStringContainsStringIgnoringCase('línea de asesoría', (string) $body['message']);
  }

  /**
   * Builds a controller whose llmEnhancer->classifyIntent throws on demand.
   */
  private function buildController(string $throwClass): InternalErrorFallbackTestableController {
    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(static function (string $key) {
      $values = [
        'rate_limit_per_minute' => 15,
        'rate_limit_per_hour' => 120,
        'enable_faq' => TRUE,
        'enable_logging' => FALSE,
        'langfuse.environment' => 'test',
        'langfuse.enabled' => FALSE,
      ];
      return $values[$key] ?? NULL;
    });
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $intentRouter = $this->createStub(IntentRouter::class);
    // Force the controller into the LLM fallback gate: low-confidence unknown.
    $intentRouter->method('route')->willReturn(['type' => 'unknown', 'confidence' => 0.15]);

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createMock(ResourceFinder::class);
    $resourceFinder->method('findForms')->willReturn([]);
    $resourceFinder->method('findGuides')->willReturn([]);
    $resourceFinder->method('findResources')->willReturn([]);

    $policyFilter = $this->createStub(PolicyFilter::class);
    $policyFilter->method('check')->willReturn([
      'passed' => TRUE,
      'violation' => FALSE,
      'type' => NULL,
      'escalation_level' => 'none',
      'response' => '',
      'links' => [],
    ]);

    $analyticsLogger = $this->createStub(AnalyticsLogger::class);

    // Poisoned LlmEnhancer: classifyIntent throws the requested Throwable.
    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $llmEnhancer->method('isEnabled')->willReturn(TRUE);
    $llmEnhancer->method('getProviderId')->willReturn('cohere');
    $llmEnhancer->method('getModelId')->willReturn('command-a-03-2025');
    $llmEnhancer->method('classifyIntent')
      ->willThrowException(new $throwClass('Simulated pipeline failure for contract test.'));

    $fallbackGate = $this->createStub(FallbackGate::class);
    $fallbackGate->method('evaluate')->willReturn([
      'decision' => FallbackGate::DECISION_FALLBACK_LLM,
      'reason_code' => 'test_force_llm',
      'confidence' => 0.15,
    ]);

    $flood = $this->createStub(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

    $cache = new DebugGuardInMemoryCacheBackend();
    $logger = $this->createStub(LoggerInterface::class);
    $topIntentsPack = new TopIntentsPack();
    $selectionStateStore = new SelectionStateStore($cache);
    $state = $this->createStub(StateInterface::class);
    $sourceGovernance = new SourceGovernanceService($configFactory, $state, new NullLogger());
    $assistantFlowRunner = $this->createStub(AssistantFlowRunner::class);
    $assistantFlowRunner->method('evaluatePending')->willReturn(['status' => 'continue']);
    $assistantFlowRunner->method('evaluatePostResponse')->willReturn(['status' => 'continue']);

    return new InternalErrorFallbackTestableController(
      $configFactory,
      $intentRouter,
      $faqIndex,
      $resourceFinder,
      $policyFilter,
      $analyticsLogger,
      $llmEnhancer,
      $fallbackGate,
      $flood,
      $cache,
      $logger,
      assistant_flow_runner: $assistantFlowRunner,
      selection_registry: new SelectionRegistry($topIntentsPack),
      selection_state_store: $selectionStateStore,
      environment_detector: new EnvironmentDetector(),
      pre_routing_decision_engine: new PreRoutingDecisionEngine($policyFilter),
      top_intents_pack: $topIntentsPack,
      source_governance: $sourceGovernance,
    );
  }

  /**
   *
   */
  private function buildJsonRequest(array $payload = ['message' => 'eviction help']): Request {
    return Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($payload, JSON_THROW_ON_ERROR),
    );
  }

}

/**
 * Testable controller for the internal-error-fallback contract test.
 *
 * Mirrors the DebugGuardTestableController shape so processIntent and the
 * escalation actions are deterministic during the test. Note: the throw
 * happens BEFORE processIntent in classifyIntent(), so the override of
 * processIntent is defensive only.
 */
final class InternalErrorFallbackTestableController extends AssistantApiController {

  /**
   * Test-only AccountInterface set by the test harness.
   */
  public ?AccountInterface $testCurrentUser = NULL;

  /**
   * {@inheritdoc}
   *
   * Avoids \Drupal::currentUser() so the test fixture remains DI-clean.
   * The parent class consults the injected current_user service in
   * production; tests inject a stub via $testCurrentUser.
   */
  protected function currentUser(): AccountInterface {
    if ($this->testCurrentUser !== NULL) {
      return $this->testCurrentUser;
    }
    return parent::currentUser();
  }

  /**
   *
   */
  protected function processIntent(array $intent, string $message, array $context, string $request_id = '', array $server_history = [], array $conversation_context_summary = []) {
    return [
      'type' => 'fallback',
      'message' => 'Test fallback (should not be reached when classifyIntent throws).',
      'response_mode' => 'clarify',
      'primary_action' => [],
      'secondary_actions' => [],
      'reason_code' => 'test',
      'results' => [],
    ];
  }

  /**
   *
   */
  protected function getEscalationActions() {
    return [
      ['label' => 'Call Hotline', 'url' => '/Legal-Advice-Line', 'type' => 'hotline'],
      ['label' => 'Apply for Help', 'url' => '/apply-for-help', 'type' => 'apply'],
    ];
  }

}
