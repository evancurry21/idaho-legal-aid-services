<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\AssistantFlowRunner;
use Drupal\ilas_site_assistant\Service\FallbackGate;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\LlmEnhancer;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\SelectionRegistry;
use Drupal\ilas_site_assistant\Service\SelectionStateStore;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once __DIR__ . '/controller_test_bootstrap.php';

/**
 * Verifies the FAQ-miss → grounded resource fallthrough behaviour.
 *
 * When `faq` intent is high-confidence but the curated FAQ index returns no
 * match, the controller must (a) attempt grounded retrieval via ResourceFinder,
 * (b) promote the response type to `resources` so the citation-required gate
 * still applies, and (c) keep the conservative legal-info caveat. When both
 * indexes return empty the controller must fall back to the original empty-FAQ
 * shape so the grounding-refusal gate at AssistantApiController.php:3517–3525
 * still flips the response to `clarify_no_grounding`.
 */
#[Group('ilas_site_assistant')]
final class FaqResourceFallthroughTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $translation = $this->createStub(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static fn($markup) => $markup->getUntranslatedString()
    );

    $container = new ContainerBuilder();
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * FAQ miss + non-empty resource results → grounded resources response.
   */
  public function testFaqMissPromotesToGroundedResourcesResponse(): void {
    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createStub(ResourceFinder::class);
    $resourceFinder->method('findResources')->willReturn([
      [
        'id' => 'node:42',
        'title' => 'Idaho Tenant Rights',
        'url' => '/legal-help/housing/tenant-rights',
        'score' => 17.4,
        'source' => 'lexical',
        'source_class' => 'resource_lexical',
      ],
    ]);

    $controller = $this->buildController($faqIndex, $resourceFinder);

    $response = $this->invokeProcessIntent(
      $controller,
      ['type' => 'faq', 'confidence' => 0.9],
      'what are idaho tenant rights for eviction notices'
    );

    $this->assertSame('resources', $response['type'],
      'FAQ miss with groundable resource hit must promote response type to `resources` so the citation gate enforces grounding.');
    $this->assertNotEmpty($response['results'] ?? [],
      'Promoted response must carry the retrieved resource items as `results`.');

    $first = $response['results'][0] ?? [];
    $this->assertSame('Idaho Tenant Rights', $first['title'] ?? NULL);
    $this->assertSame('resource_lexical', $first['source_class'] ?? NULL,
      'Promoted result must carry an approved retrieval contract source_class — this is what the downstream grounder requires to emit citations.');
    $this->assertNotEmpty(trim((string) ($first['url'] ?? '')),
      'Promoted result must carry a non-empty URL — this is the citation source the grounder will sanitize and attach.');

    $this->assertSame('faq_miss_resource_fallthrough', $response['decision_reason'] ?? NULL);
    $this->assertSame('faq_miss_resource_fallthrough', $response['reason_code'] ?? NULL);
    $this->assertNotEmpty($response['caveat'] ?? '', 'Conservative legal-info caveat must be set.');

    $secondaryActions = $response['secondary_actions'] ?? [];
    $applyAction = NULL;
    foreach ($secondaryActions as $action) {
      if (($action['type'] ?? '') === 'apply') {
        $applyAction = $action;
        break;
      }
    }
    $this->assertNotNull($applyAction, 'Apply-for-help CTA must be attached on fallthrough.');
    $this->assertSame('/apply-for-help', $applyAction['url'] ?? NULL);
  }

  /**
   * FAQ miss + resource result without an approved source_class → no promotion.
   *
   * Defends against a future ResourceFinder regression that returns items with
   * unknown provenance: the controller must NOT promote to `resources`,
   * because the downstream grounder cannot emit a citation from an unapproved
   * source class. The empty-FAQ shape is preserved so the grounding-refusal
   * gate fires cleanly.
   */
  public function testFaqMissWithUnapprovedSourceClassDoesNotPromote(): void {
    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createStub(ResourceFinder::class);
    $resourceFinder->method('findResources')->willReturn([
      [
        'id' => 'node:99',
        'title' => 'Unapproved Source',
        'url' => '/some/path',
        'source_class' => 'unknown_legacy',
      ],
    ]);

    $controller = $this->buildController($faqIndex, $resourceFinder);

    $response = $this->invokeProcessIntent(
      $controller,
      ['type' => 'faq', 'confidence' => 0.9],
      'whatever'
    );

    $this->assertSame('faq', $response['type'],
      'Result with unapproved source_class must not be promoted — citation gate would reject it anyway.');
    $this->assertSame([], $response['results'] ?? NULL);
    $this->assertNotSame('faq_miss_resource_fallthrough', $response['decision_reason'] ?? '');
  }

  /**
   * FAQ miss + resource result with empty URL → no promotion.
   */
  public function testFaqMissWithEmptyUrlDoesNotPromote(): void {
    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createStub(ResourceFinder::class);
    $resourceFinder->method('findResources')->willReturn([
      [
        'id' => 'node:7',
        'title' => 'No URL',
        'url' => '',
        'source_class' => 'resource_lexical',
      ],
    ]);

    $controller = $this->buildController($faqIndex, $resourceFinder);

    $response = $this->invokeProcessIntent(
      $controller,
      ['type' => 'faq', 'confidence' => 0.9],
      'whatever'
    );

    $this->assertSame('faq', $response['type'],
      'Result with empty URL must not be promoted — there would be nothing for the grounder to cite.');
    $this->assertSame([], $response['results'] ?? NULL);
  }

  /**
   * FAQ miss + empty resource results → preserves empty FAQ shape.
   *
   * The grounding-refusal gate downstream (CITATION_REQUIRED_TYPES + no
   * citations) is what flips this to `clarify_no_grounding`. We assert the
   * controller did NOT promote to `resources` and did NOT invent a citation,
   * which leaves the safety net intact.
   */
  public function testFaqMissWithEmptyResourcesKeepsFaqShape(): void {
    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createStub(ResourceFinder::class);
    $resourceFinder->method('findResources')->willReturn([]);

    $controller = $this->buildController($faqIndex, $resourceFinder);

    $response = $this->invokeProcessIntent(
      $controller,
      ['type' => 'faq', 'confidence' => 0.9],
      'something exotic with zero matches'
    );

    $this->assertSame('faq', $response['type'],
      'When no grounded resource exists, the response must remain `faq` so the downstream citation gate can refuse it.');
    $this->assertSame([], $response['results'] ?? NULL);
    $this->assertArrayNotHasKey('caveat', $response,
      'No fabricated caveat: only the grounded-resource path adds it.');
    $this->assertNotSame('faq_miss_resource_fallthrough', $response['decision_reason'] ?? '');
  }

  /**
   * Fallthrough disabled by config → preserves empty FAQ shape.
   */
  public function testFaqMissDoesNotFallThroughWhenDisabled(): void {
    $faqIndex = $this->createStub(FaqIndex::class);
    $faqIndex->method('search')->willReturn([]);

    $resourceFinder = $this->createStub(ResourceFinder::class);
    $resourceFinder->method('findResources')->willReturn([
      [
        'id' => 'node:1',
        'title' => 'Should Not Be Used',
        'url' => '/x',
      ],
    ]);

    $controller = $this->buildController($faqIndex, $resourceFinder, [
      'enable_faq_resource_fallthrough' => FALSE,
    ]);

    $response = $this->invokeProcessIntent(
      $controller,
      ['type' => 'faq', 'confidence' => 0.9],
      'whatever'
    );

    $this->assertSame('faq', $response['type'],
      'With the kill-switch off, the controller must not call ResourceFinder fallthrough.');
    $this->assertSame([], $response['results'] ?? NULL);
  }

  /**
   * Builds an AssistantApiController with stubs sufficient for processIntent.
   *
   * @param array<string, mixed> $configOverrides
   *   Overrides for the per-test config values.
   */
  private function buildController(
    FaqIndex $faqIndex,
    ResourceFinder $resourceFinder,
    array $configOverrides = [],
  ): AssistantApiController {
    $defaults = [
      'enable_faq' => TRUE,
      'enable_resources' => TRUE,
      'enable_faq_resource_fallthrough' => TRUE,
      'enable_logging' => FALSE,
    ];
    $values = $configOverrides + $defaults;

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) use ($values) {
      return $values[$key] ?? NULL;
    });

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $cache = $this->createStub(CacheBackendInterface::class);

    return new AssistantApiController(
      $configFactory,
      $this->createStub(IntentRouter::class),
      $faqIndex,
      $resourceFinder,
      $this->createStub(PolicyFilter::class),
      $this->createStub(AnalyticsLogger::class),
      $this->createStub(LlmEnhancer::class),
      $this->createStub(FallbackGate::class),
      $this->createStub(FloodInterface::class),
      $cache,
      new NullLogger(),
      assistant_flow_runner: $this->createStub(AssistantFlowRunner::class),
      selection_registry: new SelectionRegistry(new TopIntentsPack()),
      selection_state_store: new SelectionStateStore($cache),
    );
  }

  /**
   * Invokes the protected processIntent() method via reflection.
   *
   * @param array<string, mixed> $intent
   *   Intent record passed to processIntent().
   *
   * @return array<string, mixed>
   *   The response array.
   */
  private function invokeProcessIntent(
    AssistantApiController $controller,
    array $intent,
    string $message,
  ): array {
    $method = (new \ReflectionClass(AssistantApiController::class))
      ->getMethod('processIntent');
    $method->setAccessible(TRUE);
    /** @var array<string, mixed> $response */
    $response = $method->invoke($controller, $intent, $message, [], 'test-req-id', [], []);
    return $response;
  }

}
