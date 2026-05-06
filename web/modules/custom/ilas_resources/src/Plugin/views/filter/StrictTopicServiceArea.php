<?php

namespace Drupal\ilas_resources\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters resources to those that have at least one topic mapped to the service area.
 *
 * @ViewsFilter("strict_topic_service_area")
 */
class StrictTopicServiceArea extends FilterPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a StrictTopicServiceArea object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary() {
    return $this->t('Strict Topic/Service Area Filter');
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // We'll do all filtering in the postExecute handler.
    // So, no SQL filtering here.
  }

  /**
   * Implements postExecute filter.
   */
  public function postExecute(&$result) {
    if (empty($this->view->args[0])) {
      return;
    }
    // Expects Service Area TID as first argument.
    $service_tid = $this->view->args[0];

    // Get term storage.
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Collect all unique topic TIDs to load them in bulk.
    $all_topic_tids = [];
    foreach ($result as $row) {
      $node = $row->_entity ?? NULL;
      if ($node && $node->hasField('field_topics')) {
        $topic_tids = array_column($node->get('field_topics')->getValue(), 'target_id');
        $all_topic_tids = array_merge($all_topic_tids, $topic_tids);
      }
    }
    $all_topic_tids = array_unique($all_topic_tids);

    // Load all topics at once to avoid N+1 queries.
    $topics = $term_storage->loadMultiple($all_topic_tids);

    // Build a map of topic TID to service area TIDs for quick lookup.
    $topic_service_map = [];
    foreach ($topics as $tid => $topic) {
      if ($topic->hasField('field_service_areas')) {
        $topic_service_map[$tid] = array_column($topic->get('field_service_areas')->getValue(), 'target_id');
      }
    }

    // Now filter the results using the pre-loaded data.
    foreach ($result as $key => $row) {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $row->_entity ?? NULL;
      if (!$node || !$node->hasField('field_topics')) {
        unset($result[$key]);
        continue;
      }

      $topic_tids = array_column($node->get('field_topics')->getValue(), 'target_id');
      $match = FALSE;

      foreach ($topic_tids as $tid) {
        if (isset($topic_service_map[$tid]) && in_array($service_tid, $topic_service_map[$tid])) {
          $match = TRUE;
          break;
        }
      }

      if (!$match) {
        unset($result[$key]);
      }
    }

    // Reindex the result array.
    $result = array_values($result);
  }

  /**
   *
   */
  public function buildExposeForm(&$form, FormStateInterface $form_state) {}

  /**
   *
   */
  public function acceptExposedInput($input) {
    return TRUE;
  }

}
