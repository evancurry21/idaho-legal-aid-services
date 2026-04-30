<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\ResponseGrounder;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the public citation contract that promptfoo asserts against.
 *
 * Tier B2/B3: every grounded source carries topic + source_class + a
 * supported:TRUE flag so the JS-side normalizer marks them as supported
 * citations and assertions like `quality-supported-citation-topic-support`
 * can match `expected_topic`.
 */
#[Group('ilas_site_assistant')]
final class CitationContractTest extends TestCase {

  private ResponseGrounder $grounder;

  protected function setUp(): void {
    parent::setUp();

    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);
    $state = $this->createStub(StateInterface::class);
    $logger = $this->createStub(LoggerInterface::class);

    $governance = new SourceGovernanceService($configFactory, $state, $logger);
    $this->grounder = new ResponseGrounder($governance);
  }

  public function testGroundedSourcesCarryTopicAndSourceClassAndSupportedFlag(): void {
    $response = ['message' => 'Eviction info follows.', 'type' => 'topic'];
    $results = [
      [
        'title' => 'Idaho eviction process',
        'url' => 'https://idaholegalaid.org/help/housing/eviction',
        'type' => 'guide',
        'source_class' => 'faq_lexical',
        'topic' => 'eviction',
      ],
      [
        'title' => 'Tenant rights overview',
        'url' => 'https://idaholegalaid.org/help/housing/tenant-rights',
        'type' => 'resource',
        'source_class' => 'resource_vector',
      ],
    ];

    $grounded = $this->grounder->groundResponse($response, $results);

    $this->assertArrayHasKey('sources', $grounded, 'sources[] must be present');
    $this->assertCount(2, $grounded['sources']);

    foreach ($grounded['sources'] as $source) {
      $this->assertArrayHasKey('supported', $source);
      $this->assertTrue($source['supported'], 'Each grounded source must carry supported:TRUE');
      $this->assertArrayHasKey('source_class', $source);
      $this->assertNotEmpty($source['source_class']);
    }

    // The first source had an explicit topic; it must round-trip.
    $this->assertSame('eviction', $grounded['sources'][0]['topic'] ?? NULL);
  }

  public function testEmptyResultsLeaveSourcesArrayUnset(): void {
    $response = ['message' => 'No results yet.', 'type' => 'topic'];
    $grounded = $this->grounder->groundResponse($response, []);

    $this->assertArrayNotHasKey('sources', $grounded);
  }

}
