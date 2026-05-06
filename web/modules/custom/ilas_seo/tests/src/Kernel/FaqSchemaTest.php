<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_seo\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies that FAQPage JSON-LD is aggregated to a single block per page.
 *
 * Regression coverage for the audit finding in .planning/codebase/CONCERNS.md
 * — "FAQPage schema duplication per page". Pages with multiple
 * faq_smart_section paragraphs must emit exactly one FAQPage script whose
 * mainEntity merges every valid Q/A item across all sections.
 *
 * Aggregation is owned by ilas_seo's GraphBuilder service. The inline JSON-LD
 * that used to live in paragraph--faq-smart-section.html.twig has been removed
 * — the template assertion guards against regression.
 */
#[Group('ilas_seo')]
final class FaqSchemaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'file',
    'text',
    'node',
    'entity_reference_revisions',
    'paragraphs',
    'ilas_seo',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node', 'paragraphs']);

    $this->createContentModel();
  }

  /**
   * Creates the FAQ paragraph + node bundles and fields used by the test.
   */
  private function createContentModel(): void {
    NodeType::create([
      'type' => 'standard_page',
      'name' => 'Standard Page',
    ])->save();

    foreach (['faq_item' => 'FAQ Item', 'faq_smart_section' => 'FAQ Smart Section'] as $id => $label) {
      ParagraphsType::create(['id' => $id, 'label' => $label])->save();
    }

    $this->createFieldStorage('paragraph', 'field_faq_question', 'string');
    $this->createFieldStorage('paragraph', 'field_faq_answer', 'string_long');
    $this->createFieldStorage('paragraph', 'field_faq_items', 'entity_reference_revisions', [
      'target_type' => 'paragraph',
    ], -1);
    $this->createFieldStorage('node', 'field_faq_section', 'entity_reference_revisions', [
      'target_type' => 'paragraph',
    ], -1);

    $this->createField('paragraph', 'faq_item', 'field_faq_question', 'FAQ Question');
    $this->createField('paragraph', 'faq_item', 'field_faq_answer', 'FAQ Answer');
    $this->createField('paragraph', 'faq_smart_section', 'field_faq_items', 'FAQ Items', [
      'handler' => 'default:paragraph',
      'handler_settings' => ['target_bundles' => ['faq_item' => 'faq_item']],
    ]);
    $this->createField('node', 'standard_page', 'field_faq_section', 'FAQ Section', [
      'handler' => 'default:paragraph',
      'handler_settings' => ['target_bundles' => ['faq_smart_section' => 'faq_smart_section']],
    ]);
  }

  /**
   * Builds a faq_item paragraph with the given Q/A.
   */
  private function createFaqItem(string $question, string $answer): Paragraph {
    $paragraph = Paragraph::create([
      'type' => 'faq_item',
      'field_faq_question' => $question,
      'field_faq_answer' => $answer,
    ]);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Builds a faq_smart_section paragraph that references the given items.
   */
  private function createFaqSection(array $items): Paragraph {
    $references = [];
    foreach ($items as $item) {
      $references[] = [
        'target_id' => $item->id(),
        'target_revision_id' => $item->getRevisionId(),
      ];
    }
    $section = Paragraph::create([
      'type' => 'faq_smart_section',
      'field_faq_items' => $references,
    ]);
    $section->save();
    return $section;
  }

  /**
   * Forces the current_route_match service to resolve $node from getParameter.
   */
  private function setCurrentRouteNode(Node $node): void {
    $route_match = $this->createMock(CurrentRouteMatch::class);
    $route_match->method('getParameter')->willReturnMap([
      ['node', $node],
    ]);
    $this->container->set('current_route_match', $route_match);
  }

  /**
   * Two FAQ sections must collapse into a single FAQPage with merged items.
   */
  public function testGraphBuilderAggregatesAllSectionsIntoSingleFaqPage(): void {
    $section_a_items = [
      $this->createFaqItem('How do I apply?', 'Visit the apply page.'),
      $this->createFaqItem('What documents do I need?', 'Bring a photo ID.'),
      // Empty question — must be skipped from mainEntity.
      $this->createFaqItem('', 'This answer should not appear.'),
    ];
    $section_b_items = [
      $this->createFaqItem('Do you charge fees?', 'No, services are free.'),
      $this->createFaqItem('What languages?', 'English and Spanish.'),
    ];

    $section_a = $this->createFaqSection($section_a_items);
    $section_b = $this->createFaqSection($section_b_items);

    $node = Node::create([
      'type' => 'standard_page',
      'title' => 'FAQ Test Page',
      'status' => Node::PUBLISHED,
      'field_faq_section' => [
        [
          'target_id' => $section_a->id(),
          'target_revision_id' => $section_a->getRevisionId(),
        ],
        [
          'target_id' => $section_b->id(),
          'target_revision_id' => $section_b->getRevisionId(),
        ],
      ],
    ]);
    $node->save();

    $this->setCurrentRouteNode($node);

    $builder = $this->container->get('ilas_seo.graph_builder');
    $result = $builder->build();

    $faq_blocks = array_values(array_filter(
      $result['blocks'],
      static fn (array $block) => ($block[1] ?? NULL) === 'faq_page_schema',
    ));
    $this->assertCount(1, $faq_blocks, 'Exactly one faq_page_schema block is emitted across both sections.');

    $script = $faq_blocks[0][0];
    $this->assertSame('script', $script['#tag']);
    $this->assertSame('application/ld+json', $script['#attributes']['type']);

    $decoded = Json::decode((string) $script['#value']);
    $this->assertIsArray($decoded);
    $this->assertSame('FAQPage', $decoded['@type']);
    $this->assertArrayHasKey('mainEntity', $decoded);

    $this->assertCount(4, $decoded['mainEntity'], 'Empty question is skipped; remaining four items are merged from both sections.');

    $names = array_map(static fn ($entry) => $entry['name'], $decoded['mainEntity']);
    $this->assertEqualsCanonicalizing(
      [
        'How do I apply?',
        'What documents do I need?',
        'Do you charge fees?',
        'What languages?',
      ],
      $names,
    );

    foreach ($decoded['mainEntity'] as $entry) {
      $this->assertSame('Question', $entry['@type']);
      $this->assertSame('Answer', $entry['acceptedAnswer']['@type']);
      $this->assertNotSame('', trim((string) $entry['acceptedAnswer']['text']));
    }

    // Confirm no second FAQPage block sneaks in via any other emitter.
    $faq_pages = 0;
    foreach ($result['blocks'] as $block) {
      $payload = Json::decode((string) ($block[0]['#value'] ?? ''));
      if (is_array($payload) && (($payload['@type'] ?? '') === 'FAQPage')) {
        $faq_pages++;
      }
    }
    $this->assertSame(1, $faq_pages, 'Exactly one FAQPage block is present in the assembled graph.');
  }

  /**
   * Pages without FAQ paragraphs must not emit any FAQPage block.
   */
  public function testNoFaqSectionsEmitsNoFaqPageBlock(): void {
    $node = Node::create([
      'type' => 'standard_page',
      'title' => 'Plain Page',
      'status' => Node::PUBLISHED,
    ]);
    $node->save();

    $this->setCurrentRouteNode($node);

    $builder = $this->container->get('ilas_seo.graph_builder');
    $result = $builder->build();

    foreach ($result['blocks'] as $block) {
      $payload = Json::decode((string) ($block[0]['#value'] ?? ''));
      $this->assertNotSame('FAQPage', $payload['@type'] ?? NULL, 'No FAQPage block should be emitted on a node without FAQ paragraphs.');
    }
  }

  /**
   * The paragraph template must no longer emit an inline FAQPage script.
   *
   * Guards against regressions that re-introduce paragraph-level emission and
   * recreate the duplicate-FAQPage problem documented in the audit.
   */
  public function testParagraphTemplateHasNoInlineJsonLd(): void {
    $template = $this->root . '/themes/custom/b5subtheme/templates/paragraph/paragraph--faq-smart-section.html.twig';
    $this->assertFileExists($template);
    $contents = (string) file_get_contents($template);

    $this->assertStringNotContainsString('application/ld+json', $contents);
    $this->assertStringNotContainsString('"@type": "FAQPage"', $contents);
    $this->assertStringNotContainsString('"mainEntity"', $contents);
  }

  /**
   * Field storage helper.
   */
  private function createFieldStorage(
    string $entity_type,
    string $field_name,
    string $type,
    array $settings = [],
    int $cardinality = 1,
  ): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
    ])->save();
  }

  /**
   * Bundle field helper.
   */
  private function createField(
    string $entity_type,
    string $bundle,
    string $field_name,
    string $label,
    array $settings = [],
  ): void {
    FieldConfig::create([
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'field_name' => $field_name,
      'label' => $label,
      'settings' => $settings,
    ])->save();
  }

}
