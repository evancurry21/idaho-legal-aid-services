<?php

/**
 * @file
 * Provides views data for the ILAS Resources module.
 */

/**
 * Implements hook_views_data().
 */
function ilas_resources_views_data() {
  $data = [];

  $data['node']['strict_topic_service_area'] = [
    'title' => t('Topic to Service Area Mapping'),
    'filter' => [
      'id' => 'strict_topic_service_area',
      'title' => t('Topic to Service Area Mapping'),
      'help' => t('Filter content based on topic to service area mapping.'),
    ],
  ];

  return $data;
}

/**
 * Implements hook_views_data_alter().
 */
function ilas_resources_views_data_alter(array &$data) {
  $data['node_field_data']['strict_topic_service_area'] = [
    'title' => t('Topic to Service Area Mapping'),
    'filter' => [
      'id' => 'strict_topic_service_area',
      'title' => t('Topic to Service Area Mapping'),
      'help' => t('Filter content based on topic to service area mapping.'),
    ],
  ];
}
