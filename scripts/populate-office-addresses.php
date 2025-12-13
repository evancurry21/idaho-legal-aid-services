<?php

/**
 * @file
 * Populates office address fields for schema.org structured data.
 *
 * Run with: drush php:script scripts/populate-office-addresses.php
 */

$offices = [
  62 => [
    'street' => '1447 S Tyrell Lane',
    'city' => 'Boise',
    'postal_code' => '83706',
  ],
  39 => [
    'street' => '1447 S Tyrell Lane',
    'city' => 'Boise',
    'postal_code' => '83706',
  ],
  63 => [
    'street' => '610 W. Hubbard Avenue, Suite 219',
    'city' => "Coeur d'Alene",
    'postal_code' => '83814',
  ],
  64 => [
    'street' => '482 Constitution Way, Suite 101',
    'city' => 'Idaho Falls',
    'postal_code' => '83402',
  ],
  65 => [
    'street' => '2230 3rd Ave N',
    'city' => 'Lewiston',
    'postal_code' => '83501',
  ],
  66 => [
    'street' => '212 12th Ave Road',
    'city' => 'Nampa',
    'postal_code' => '83686',
  ],
  67 => [
    'street' => '109 N Arthur Avenue, Suite 302',
    'city' => 'Pocatello',
    'postal_code' => '83204',
  ],
  68 => [
    'street' => '496 Shoup Ave West, Suite G',
    'city' => 'Twin Falls',
    'postal_code' => '83301',
  ],
];

$node_storage = \Drupal::entityTypeManager()->getStorage('node');

foreach ($offices as $nid => $data) {
  $node = $node_storage->load($nid);

  if (!$node) {
    echo "Node $nid not found, skipping.\n";
    continue;
  }

  $node->set('field_street_address', $data['street']);
  $node->set('field_address_city', $data['city']);
  $node->set('field_postal_code', $data['postal_code']);
  $node->save();

  echo "Updated: {$node->getTitle()} (nid: $nid)\n";
}

echo "\nDone! All office addresses have been populated.\n";
