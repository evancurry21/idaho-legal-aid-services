<?php

/**
 * @file
 * Views preprocess query helpers for the B5 Subtheme.
 *
 * Provides the document-topic SQL lookup used by the Forms/Guides category
 * views (forms_categories, guides_categories) and the URL-slug utility used
 * to build the resulting option keys. b5subtheme_preprocess_views_view()
 * stays in b5subtheme.theme and calls _b5subtheme_get_document_topics() from
 * here.
 */

use Drupal\Core\Cache\Cache;

/**
 * Gets topics that have documents of a specific type.
 *
 * @param string $document_type
 *   The document type ('form' or 'guide').
 * @param string $show_field
 *   The field name that controls visibility (e.g., 'field_show_on_forms_page').
 *
 * @return array
 *   Array of topic options with 'tid', 'name', and 'url_slug' keys.
 */
function _b5subtheme_get_document_topics(string $document_type, string $show_field): array {
  $options = [];

  // Normalize the document type for use in the cache id.
  $key = preg_replace('/[^a-z0-9_]/', '', strtolower($document_type));
  $cid = 'b5subtheme:document_topics:' . $key;
  $cache_bin = \Drupal::cache();

  if ($cached = $cache_bin->get($cid)) {
    return $cached->data;
  }

  try {
    $database = \Drupal::database();

    // Query topics that:
    // 1. Are published
    // 2. Have the show_on_*_page field set to TRUE
    // 3. Have at least one published document media item of the correct type.
    $query = $database->select('taxonomy_term_field_data', 't');
    $query->fields('t', ['tid', 'name']);
    $query->condition('t.vid', 'topics');
    $query->condition('t.status', 1);

    // Join the show_on field.
    $query->innerJoin(
      'taxonomy_term__' . $show_field,
      'show_field',
      't.tid = show_field.entity_id AND show_field.' . $show_field . '_value = 1'
    );

    // Join to media via document_topics field.
    $query->innerJoin(
      'media__field_document_topics',
      'dt',
      't.tid = dt.field_document_topics_target_id'
    );

    // Join to media field data to check status.
    $query->innerJoin(
      'media_field_data',
      'm',
      'dt.entity_id = m.mid AND m.status = 1'
    );

    // Join to document type field to filter by form/guide.
    $query->innerJoin(
      'media__field_document_type',
      'doc_type',
      'dt.entity_id = doc_type.entity_id AND doc_type.field_document_type_value = :doc_type',
      [':doc_type' => $document_type]
    );

    // Group by term and order alphabetically.
    $query->groupBy('t.tid');
    $query->groupBy('t.name');
    $query->orderBy('t.name', 'ASC');

    $results = $query->execute()->fetchAll();

    foreach ($results as $row) {
      // Create URL-friendly slug from topic name.
      $slug = _b5subtheme_create_url_slug($row->name);
      $options[] = [
        'tid' => $row->tid,
        'name' => $row->name,
        'url_slug' => $slug,
      ];
    }

    $cache_bin->set($cid, $options, Cache::PERMANENT, [
      'taxonomy_term_list:topics',
      'media_list:document',
    ]);
  }
  catch (\Exception $e) {
    // Log error but don't break the page. Skip cache write so a transient
    // failure does not poison the topic list.
    \Drupal::logger('b5subtheme')->error('Error fetching document topics: @message', ['@message' => $e->getMessage()]);
  }

  return $options;
}

/**
 * Creates a URL-friendly slug from a string.
 *
 * @param string $string
 *   The string to convert.
 *
 * @return string
 *   URL-friendly slug.
 */
function _b5subtheme_create_url_slug(string $string): string {
  // Convert to lowercase.
  $slug = strtolower($string);
  // Replace spaces and underscores with hyphens.
  $slug = preg_replace('/[\s_]+/', '-', $slug);
  // Remove special characters except hyphens.
  $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
  // Remove multiple consecutive hyphens.
  $slug = preg_replace('/-+/', '-', $slug);
  // Trim hyphens from ends.
  $slug = trim($slug, '-');

  return $slug;
}
