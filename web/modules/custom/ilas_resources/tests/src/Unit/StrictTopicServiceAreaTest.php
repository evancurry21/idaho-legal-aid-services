<?php

namespace Drupal\Tests\ilas_resources\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ilas_resources\Plugin\views\filter\StrictTopicServiceArea;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\views\ViewExecutable;

/**
 * Tests for StrictTopicServiceArea filter.
 *
 * @group ilas_resources
 */
class StrictTopicServiceAreaTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The filter under test.
   *
   * @var \Drupal\ilas_resources\Plugin\views\filter\StrictTopicServiceArea
   */
  protected $filter;

  /**
   * The mocked view.
   *
   * @var \Drupal\views\ViewExecutable|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $view;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock the entity type manager and term storage.
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $termStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager->expects($this->any())
      ->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($termStorage);

    // Create the filter.
    $configuration = [];
    $plugin_id = 'strict_topic_service_area';
    $plugin_definition = [];

    $this->filter = new StrictTopicServiceArea(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $this->entityTypeManager
    );

    // Mock the view.
    $this->view = $this->createMock(ViewExecutable::class);
    // Service area TID.
    $this->view->args = [123];
    $this->filter->view = $this->view;
  }

  /**
   * Tests postExecute filters resources correctly.
   */
  public function testPostExecuteFiltersCorrectly() {
    // Create mock nodes and terms.
    // Has service area 123.
    $topic1 = $this->createMockTerm(1, [123]);
    // Different service area.
    $topic2 = $this->createMockTerm(2, [456]);
    // Has both.
    $topic3 = $this->createMockTerm(3, [123, 456]);

    // Mock term storage to return our topics.
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $termStorage->expects($this->once())
      ->method('loadMultiple')
      ->with([1, 2, 3])
      ->willReturn([
        1 => $topic1,
        2 => $topic2,
        3 => $topic3,
      ]);

    // Create mock nodes.
    // Should pass (topic has service area 123)
    $node1 = $this->createMockNode([1]);
    // Should be filtered out.
    $node2 = $this->createMockNode([2]);
    // Should pass (topic has service area 123)
    $node3 = $this->createMockNode([3]);
    // Should pass (has topic 1)
    $node4 = $this->createMockNode([1, 2]);

    // Create result rows.
    $result = [
      (object) ['_entity' => $node1],
      (object) ['_entity' => $node2],
      (object) ['_entity' => $node3],
      (object) ['_entity' => $node4],
    ];

    // Run the filter.
    $this->filter->postExecute($result);

    // Check that only nodes with matching service areas remain.
    $this->assertCount(3, $result);
    $this->assertSame($node1, $result[0]->_entity);
    $this->assertSame($node3, $result[1]->_entity);
    $this->assertSame($node4, $result[2]->_entity);
  }

  /**
   * Tests postExecute with no service area argument.
   */
  public function testPostExecuteWithNoArgument() {
    $this->view->args = [];

    $result = [
      (object) ['_entity' => $this->createMockNode([1])],
      (object) ['_entity' => $this->createMockNode([2])],
    ];

    $original_count = count($result);
    $this->filter->postExecute($result);

    // Result should be unchanged.
    $this->assertCount($original_count, $result);
  }

  /**
   * Creates a mock node with topics.
   */
  protected function createMockNode(array $topic_tids) {
    $node = $this->createMock(NodeInterface::class);

    $field = $this->createMock(FieldItemListInterface::class);
    $field->expects($this->any())
      ->method('getValue')
      ->willReturn(array_map(function ($tid) {
        return ['target_id' => $tid];
      }, $topic_tids));

    $node->expects($this->any())
      ->method('hasField')
      ->with('field_topics')
      ->willReturn(TRUE);

    $node->expects($this->any())
      ->method('get')
      ->with('field_topics')
      ->willReturn($field);

    return $node;
  }

  /**
   * Creates a mock term with service areas.
   */
  protected function createMockTerm($tid, array $service_area_tids) {
    $term = $this->createMock(TermInterface::class);

    $field = $this->createMock(FieldItemListInterface::class);
    $field->expects($this->any())
      ->method('getValue')
      ->willReturn(array_map(function ($tid) {
        return ['target_id' => $tid];
      }, $service_area_tids));

    $term->expects($this->any())
      ->method('hasField')
      ->with('field_service_areas')
      ->willReturn(TRUE);

    $term->expects($this->any())
      ->method('get')
      ->with('field_service_areas')
      ->willReturn($field);

    return $term;
  }

}
