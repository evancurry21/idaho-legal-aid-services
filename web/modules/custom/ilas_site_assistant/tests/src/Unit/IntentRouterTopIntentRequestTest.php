<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Service\Disambiguator;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\KeywordExtractor;
use Drupal\ilas_site_assistant\Service\NavigationIntent;
use Drupal\ilas_site_assistant\Service\TopicResolver;
use Drupal\ilas_site_assistant\Service\TopicRouter;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Covers explicit topic requests that should bypass bare-topic clarification.
 */
#[Group('ilas_site_assistant')]
final class IntentRouterTopIntentRequestTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $configStub = $this->createStub(ImmutableConfig::class);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $translationStub = $this->createStub(TranslationInterface::class);
    $translationStub->method('translateString')->willReturnCallback(
      static fn($markup) => $markup->getUntranslatedString()
    );

    $container = new ContainerBuilder();
    $container->set('string_translation', $translationStub);
    $container->set('config.factory', $configFactory);

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
   * Multi-word help/correction messages route to the explicit topic intent.
   */
  public function testExplicitTopicRequestsRouteToTopIntent(): void {
    $router = $this->buildRouter();

    $this->assertSame('topic_family_custody', $router->route('I need custody help.')['type'] ?? NULL);
    $this->assertSame('topic_family_divorce', $router->route('Actually divorce.')['type'] ?? NULL);
    $this->assertSame('topic_housing_eviction', $router->route('I got an eviction notice.')['type'] ?? NULL);
    $this->assertSame('topic_housing_eviction', $router->route('Necesito ayuda con desalojo.')['type'] ?? NULL);
  }

  /**
   * Bare topics still ask for the user's desired action.
   */
  public function testBareTopicStillDisambiguates(): void {
    $intent = $this->buildRouter()->route('divorce');

    $this->assertSame('disambiguation', $intent['type'] ?? NULL);
    $this->assertNotEmpty($intent['options'] ?? []);
  }

  /**
   * Resource-qualified topic requests keep resource routing precedence.
   */
  public function testResourceQualifiedTopicDoesNotShortCircuitToTopIntent(): void {
    $intent = $this->buildRouter()->route('custody forms');

    $this->assertNotSame('topic_family_custody', $intent['type'] ?? NULL);
  }

  /**
   * Builds a router with deterministic collaborators.
   */
  private function buildRouter(): IntentRouter {
    $configStub = $this->createStub(ImmutableConfig::class);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($configStub);

    $topicResolver = $this->createMock(TopicResolver::class);
    $topicResolver->method('resolveFromText')->willReturn(NULL);

    $keywordExtractor = $this->createMock(KeywordExtractor::class);
    $keywordExtractor->method('extract')->willReturnCallback(static function (string $message): array {
      return [
        'original' => $message,
        'normalized' => mb_strtolower(trim($message)),
        'keywords' => [],
        'phrases_found' => [],
        'synonyms_applied' => [],
      ];
    });
    $keywordExtractor->method('hasNegativeKeyword')->willReturn(FALSE);

    $navigationIntent = $this->createMock(NavigationIntent::class);
    $navigationIntent->method('detect')->willReturn(NULL);

    return new IntentRouter(
      $configFactory,
      $topicResolver,
      $keywordExtractor,
      new TopicRouter(NULL),
      $navigationIntent,
      new Disambiguator(),
      new TopIntentsPack(NULL),
    );
  }

}
