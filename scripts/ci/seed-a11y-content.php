<?php

/**
 * Drush PHP script: seeds the minimum content the a11y test suite needs.
 *
 * Creates one home_page node with four impact_card paragraphs attached and
 * points system.site.page.front at it. Idempotent — the seeded node is tagged
 * with a sentinel title so re-running the script replaces, not duplicates.
 *
 * Run via:
 *   ddev drush php:script scripts/ci/seed-a11y-content.php
 *
 * Used by the a11y-local-gate CI job (and reusable on a freshly-installed
 * local DDEV environment). Not intended to run against a populated production
 * database — the front-page override is global.
 */

use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

const SEED_NODE_TITLE = 'A11Y Seed Home Page';

$cards = [
  [
    'title'       => '2024 Legal Help',
    'description' => '<p>7,117 Clients</p><p>received legal representation.</p>',
    'color'       => 'blue',
    'back'        => 'In 2024, ILAS delivered vital legal support to Idaho residents.',
  ],
  [
    'title'       => 'Statewide Cases',
    'description' => '<p>3,661 Closed</p><p>in the 2024 calendar year.</p>',
    'color'       => 'blue',
    'back'        => 'ILAS successfully served each of Idaho\'s 44 counties in 2024.',
  ],
  [
    'title'       => 'Attorney Effort',
    'description' => '<p>22,000 Hours</p><p>devoted to clients in 2024.</p>',
    'color'       => 'blue',
    'back'        => 'In 2024, ILAS attorneys invested 22,446 hours.',
  ],
  [
    'title'       => 'Community Outreach',
    'description' => '<p>3,187 People</p><p>engaged in 2024.</p>',
    'color'       => 'blue',
    'back'        => 'ILAS empowered 3,187 community members through statewide outreach.',
  ],
];

$node_storage      = \Drupal::entityTypeManager()->getStorage('node');
$paragraph_storage = \Drupal::entityTypeManager()->getStorage('paragraph');

$existing = $node_storage->loadByProperties([
  'type'  => 'home_page',
  'title' => SEED_NODE_TITLE,
]);
foreach ($existing as $stale_node) {
  foreach ($stale_node->get('field_impact_cards')->referencedEntities() as $stale_paragraph) {
    $stale_paragraph->delete();
  }
  $stale_node->delete();
}

$paragraph_refs = [];
foreach ($cards as $card) {
  $paragraph = Paragraph::create([
    'type'                    => 'impact_card',
    'field_topic_title'       => $card['title'],
    'field_topic_description' => [
      'value'  => $card['description'],
      'format' => 'basic_html',
    ],
    'field_topic_color'       => $card['color'],
    'field_back_detail'       => [
      'value'  => $card['back'],
      'format' => 'basic_html',
    ],
  ]);
  $paragraph->save();
  $paragraph_refs[] = [
    'target_id'          => $paragraph->id(),
    'target_revision_id' => $paragraph->getRevisionId(),
  ];
}

$node = Node::create([
  'type'               => 'home_page',
  'title'              => SEED_NODE_TITLE,
  'status'             => 1,
  'uid'                => 1,
  'field_impact_cards' => $paragraph_refs,
]);
$node->save();

\Drupal::configFactory()
  ->getEditable('system.site')
  ->set('page.front', '/node/' . $node->id())
  ->save();

\Drupal::service('cache.render')->invalidateAll();
\Drupal::service('cache.dynamic_page_cache')->invalidateAll();

printf(
  "Seeded home_page node %d with %d impact_card paragraphs; system.site.page.front -> /node/%d\n",
  $node->id(),
  count($paragraph_refs),
  $node->id()
);
