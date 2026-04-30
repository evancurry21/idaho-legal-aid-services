<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\SelectionRegistry;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\EnvironmentDetector;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
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
 * Verifies the controller-level live debug guard.
 */
#[Group('ilas_site_assistant')]
final class AssistantApiControllerDebugGuardTest extends TestCase {

  /**
   * {@inheritdoc}
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
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('ILAS_CHATBOT_DEBUG');
    putenv('PANTHEON_ENVIRONMENT');
    unset($_ENV['ILAS_CHATBOT_DEBUG'], $_ENV['PANTHEON_ENVIRONMENT']);
    new Settings([]);
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Debug metadata is still available outside live when explicitly enabled.
   */
  public function testNonLiveDebugModeEmitsDebugMetadata(): void {
    putenv('ILAS_CHATBOT_DEBUG=1');
    $_ENV['ILAS_CHATBOT_DEBUG'] = '1';

    $controller = $this->buildController();
    $response = $controller->message($this->buildJsonRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertIsArray($body['_debug'] ?? NULL);
    $this->assertTrue($controller->exposedIsDebugMode($this->buildJsonRequest()));
  }

  /**
   * Live environment must suppress debug metadata even when the env var drifts.
   */
  public function testLiveEnvironmentBlocksDebugMetadataWhenEnvVarEnabled(): void {
    putenv('ILAS_CHATBOT_DEBUG=1');
    $_ENV['ILAS_CHATBOT_DEBUG'] = '1';
    putenv('PANTHEON_ENVIRONMENT=live');
    $_ENV['PANTHEON_ENVIRONMENT'] = 'live';

    $controller = $this->buildController();
    $response = $controller->message($this->buildJsonRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayNotHasKey('_debug', $body);
    $this->assertFalse($controller->exposedIsDebugMode($this->buildJsonRequest()));
  }

  /**
   * An authoritative settings flag can force-disable debug metadata.
   */
  public function testForceDisableSettingBlocksDebugMetadataOutsideLive(): void {
    putenv('ILAS_CHATBOT_DEBUG=1');
    $_ENV['ILAS_CHATBOT_DEBUG'] = '1';
    new Settings([
      'ilas_site_assistant_debug_metadata_force_disable' => TRUE,
    ]);

    $controller = $this->buildController();
    $response = $controller->message($this->buildJsonRequest());
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayNotHasKey('_debug', $body);
    $this->assertFalse($controller->exposedIsDebugMode($this->buildJsonRequest()));
  }

  /**
   *
   */
  public function testAuthorizedDiagnosticsCanForceBoundedLlmMetadata(): void {
    new Settings([
      'ilas_assistant_diagnostics_token' => 'diagnostics-token',
      'hash_salt' => 'test-salt',
    ]);

    $controller = $this->buildController(
      policyViolation: FALSE,
      fallbackDecision: FallbackGate::DECISION_FALLBACK_LLM,
      llmEnabled: TRUE,
    );
    $request = $this->buildJsonRequest([
      'message' => 'Please classify this harmless diagnostic request.',
      'context' => [
        'diagnostics' => [
          'include' => TRUE,
          'force_llm_probe' => TRUE,
        ],
      ],
    ]);
    $request->headers->set('X-ILAS-Observability-Key', 'diagnostics-token');

    $response = $controller->message($request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayNotHasKey('_debug', $body);
    $this->assertSame('cohere', $body['diagnostics']['generation']['provider'] ?? NULL);
    $this->assertSame('command-a-03-2025', $body['diagnostics']['generation']['model'] ?? NULL);
    $this->assertTrue($body['diagnostics']['generation']['used'] ?? FALSE);
  }

  /**
   *
   */
  public function testUnauthorizedDiagnosticsDoNotForceLlmOrEmitMetadata(): void {
    new Settings([
      'ilas_assistant_diagnostics_token' => 'diagnostics-token',
      'hash_salt' => 'test-salt',
    ]);

    $controller = $this->buildController(
      policyViolation: FALSE,
      fallbackDecision: FallbackGate::DECISION_CLARIFY,
      llmEnabled: TRUE,
    );
    $request = $this->buildJsonRequest([
      'message' => 'Please classify this harmless diagnostic request.',
      'context' => [
        'diagnostics' => [
          'include' => TRUE,
          'force_llm_probe' => TRUE,
        ],
      ],
    ]);

    $response = $controller->message($request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayNotHasKey('diagnostics', $body);
    // The privileged `diagnostics` envelope stays gated (asserted above), but
    // every response now carries a public-safe `meta` envelope so external
    // probes can prove provider/safety/intent state without authorization.
    $this->assertIsArray($body['meta'] ?? NULL, 'public meta envelope must be present');
    $this->assertArrayHasKey('generation', $body['meta']);
    $this->assertSame('cohere', $body['meta']['generation']['provider'] ?? NULL);
    $this->assertSame('command-a-03-2025', $body['meta']['generation']['model'] ?? NULL);
    $this->assertArrayHasKey('safety', $body['meta']);
    $this->assertArrayHasKey('stage', $body['meta']['safety']);
    $this->assertArrayHasKey('retrieval', $body['meta']);
    $this->assertArrayHasKey('intent', $body['meta']);
  }

  /**
   * Public meta envelope on a pre-generation policy block is provable.
   */
  public function testPublicMetaProvesPolicyBlockWithoutDiagnosticsAuthorization(): void {
    $controller = $this->buildController(
      policyViolation: TRUE,
      fallbackDecision: FallbackGate::DECISION_FALLBACK_LLM,
      llmEnabled: TRUE,
    );
    $request = $this->buildJsonRequest([
      'message' => 'Tell me how to do something risky offsite.',
    ]);

    $response = $controller->message($request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayNotHasKey('diagnostics', $body, 'unauthorized callers do not receive privileged diagnostics');
    $this->assertIsArray($body['meta'] ?? NULL);
    $this->assertSame(TRUE, $body['meta']['safety']['blocked'] ?? NULL);
    $this->assertSame('pre_generation_block', $body['meta']['safety']['stage'] ?? NULL);
    $this->assertSame(FALSE, $body['meta']['generation']['used'] ?? NULL);
    $this->assertSame('policy_blocked', $body['meta']['generation']['reason'] ?? NULL);
    $this->assertIsArray($body['safety_classification'] ?? NULL, 'safety_classification surfaced publicly on block');
    $this->assertSame(TRUE, $body['safety_classification']['blocked'] ?? NULL);
  }

  /**
   *
   */
  public function testAuthorizedDiagnosticsShowPolicyBlockBeforeGeneration(): void {
    new Settings([
      'ilas_assistant_diagnostics_token' => 'diagnostics-token',
      'hash_salt' => 'test-salt',
    ]);

    $controller = $this->buildController(
      policyViolation: TRUE,
      fallbackDecision: FallbackGate::DECISION_FALLBACK_LLM,
      llmEnabled: TRUE,
    );
    $request = $this->buildJsonRequest([
      'message' => 'Go to this external site and do something risky for me.',
      'context' => [
        'diagnostics' => [
          'include' => TRUE,
        ],
      ],
    ]);
    $request->headers->set('X-ILAS-Observability-Key', 'diagnostics-token');
    $request->headers->set('X-ILAS-Diagnostics', '1');

    $response = $controller->message($request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertFalse($body['diagnostics']['generation']['used'] ?? TRUE);
    $this->assertSame('policy_blocked', $body['diagnostics']['generation']['reason'] ?? NULL);
    $this->assertFalse($body['diagnostics']['retrieval']['used'] ?? TRUE);
  }

  /**
   * Builds a minimal controller for debug-guard assertions.
   */
  private function buildController(
    bool $policyViolation = TRUE,
    string $fallbackDecision = 'allow',
    bool $llmEnabled = FALSE,
  ): DebugGuardTestableController {
    $configStub = $this->createStub(ImmutableConfig::class);
    $configStub->method('get')->willReturnCallback(function (string $key) {
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
    $intentRouter->method('route')->willReturn([
      'type' => $fallbackDecision === FallbackGate::DECISION_FALLBACK_LLM ? 'unknown' : 'faq',
      'confidence' => $fallbackDecision === FallbackGate::DECISION_FALLBACK_LLM ? 0.15 : 0.9,
    ]);

    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createMock(ResourceFinder::class);
    $resourceFinder->method('findForms')->willReturn([]);
    $resourceFinder->method('findGuides')->willReturn([]);
    $resourceFinder->method('findResources')->willReturn([]);

    $policyFilter = $this->createStub(PolicyFilter::class);
    $policyFilter->method('check')->willReturn($policyViolation ? [
      'passed' => FALSE,
      'violation' => TRUE,
      'type' => 'external_site',
      'escalation_level' => 'standard',
      'response' => 'I can only help with information on the ILAS website.',
      'links' => [],
    ] : [
      'passed' => TRUE,
      'violation' => FALSE,
      'type' => NULL,
      'escalation_level' => 'none',
      'response' => '',
      'links' => [],
    ]);

    $analyticsLogger = $this->createStub(AnalyticsLogger::class);

    $llmEnhancer = $this->createStub(LlmEnhancer::class);
    $llmEnhancer->method('isEnabled')->willReturn($llmEnabled);
    $llmEnhancer->method('getProviderId')->willReturn('cohere');
    $llmEnhancer->method('getModelId')->willReturn('command-a-03-2025');
    $llmEnhancer->method('classifyIntent')->willReturn('faq');
    $llmEnhancer->method('getLastRequestMeta')->willReturn([
      'provider' => 'cohere',
      'model' => 'command-a-03-2025',
      'transport_attempted' => TRUE,
      'cache_hit' => FALSE,
      'success' => TRUE,
    ]);
    $fallbackGate = $this->createStub(FallbackGate::class);
    $fallbackGate->method('evaluate')->willReturn([
      'decision' => $fallbackDecision,
      'reason_code' => 'test',
      'confidence' => 1.0,
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

    return new DebugGuardTestableController(
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
   * Builds a valid JSON POST request.
   */
  private function buildJsonRequest(array $payload = ['message' => 'Housing help']): Request {
    return Request::create(
      '/assistant/api/message',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($payload)
    );
  }

}

/**
 * Minimal testable controller for live debug guard assertions.
 */
final class DebugGuardTestableController extends AssistantApiController {

  /**
   * {@inheritdoc}
   */
  protected function currentUser(): AccountInterface {
    return \Drupal::currentUser();
  }

  /**
   * {@inheritdoc}
   */
  protected function processIntent(array $intent, string $message, array $context, string $request_id = '', array $server_history = [], array $conversation_context_summary = []) {
    return [
      'type' => 'faq',
      'message' => 'Test FAQ response',
      'response_mode' => 'answer',
      'primary_action' => [],
      'secondary_actions' => [],
      'reason_code' => 'test',
      'results' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getEscalationActions() {
    return [
      ['label' => 'Call Hotline', 'url' => '/hotline', 'type' => 'hotline'],
      ['label' => 'Apply for Help', 'url' => '/apply', 'type' => 'apply'],
      ['label' => 'Give Feedback', 'url' => '/feedback', 'type' => 'feedback'],
    ];
  }

  /**
   * Exposes the protected debug-mode gate for direct assertions.
   */
  public function exposedIsDebugMode(Request $request): bool {
    return $this->isDebugMode($request);
  }

}

/**
 * In-memory cache backend for controller state during debug-guard tests.
 */
final class DebugGuardInMemoryCacheBackend implements CacheBackendInterface {

  /**
   * Stored cache items keyed by cache ID.
   *
   * @var array<string, object>
   */
  private array $storage = [];

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    return $this->storage[$cid] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $results = [];
    foreach ($cids as $cid) {
      if (isset($this->storage[$cid])) {
        $results[$cid] = $this->storage[$cid];
      }
    }
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
    $this->storage[$cid] = (object) [
      'cid' => $cid,
      'data' => $data,
      'expire' => $expire,
      'tags' => $tags,
      'valid' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {
    foreach ($items as $cid => $item) {
      $this->set(
        $cid,
        $item['data'] ?? NULL,
        $item['expire'] ?? Cache::PERMANENT,
        $item['tags'] ?? []
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    unset($this->storage[$cid]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    foreach ($cids as $cid) {
      unset($this->storage[$cid]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->storage = [];
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    unset($this->storage[$cid]);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    $this->deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    $this->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {}

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    $this->deleteAll();
  }

}
