<?php

namespace Drupal\ilas_redirect_automation\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for matching old paths to current path aliases.
 *
 * Priority order:
 * 1. Direct /resources/name match
 * 2. Resource that references the topic (via field_topics)
 * 3. Direct /topics/name match (fallback)
 */
class PathMatcherService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Cache of topic-to-resource mappings.
   *
   * @var array|null
   */
  protected $topicResourceCache = NULL;

  /**
   * Constructs a PathMatcherService object.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('ilas_redirect_automation');
  }

  /**
   * Match an old path to a current destination.
   *
   * @param string $oldPath
   *   The old path to match.
   *
   * @return array|null
   *   Match result with keys: destination, confidence, match_type, notes.
   */
  public function match(string $oldPath): ?array {
    // Pattern 1: /node/NID/alias.
    if (preg_match('#^/node/\d+/(.+)$#', $oldPath, $matches)) {
      return $this->matchAlias($matches[1], 'node');
    }

    // Pattern 2: /node/NID (no alias)
    if (preg_match('#^/node/(\d+)$#', $oldPath, $matches)) {
      return $this->matchByOldNodeId($matches[1]);
    }

    // Pattern 3: /topics/TID/alias.
    if (preg_match('#^/topics/\d+/(.+)$#', $oldPath, $matches)) {
      return $this->matchAlias($matches[1], 'topics');
    }

    // Pattern 4: /topics/TID (no alias)
    if (preg_match('#^/topics/(\d+)$#', $oldPath, $matches)) {
      return $this->matchByOldTopicId($matches[1]);
    }

    // Pattern 5: /taxonomy/term/TID.
    if (preg_match('#^/taxonomy/term/(\d+)$#', $oldPath, $matches)) {
      return $this->matchTaxonomyTerm($matches[1]);
    }

    return NULL;
  }

  /**
   * Match an alias to current path aliases with resource priority.
   *
   * @param string $alias
   *   The alias portion to match.
   * @param string $sourceType
   *   The source type (node, topics).
   *
   * @return array|null
   *   Match result.
   */
  protected function matchAlias(string $alias, string $sourceType): ?array {
    // Remove query string if present.
    $alias = strtok($alias, '?');

    // Normalize the alias for comparison.
    $normalizedAlias = $this->normalizeAlias($alias);

    // PRIORITY 1: Try direct /resources/name match first.
    $resourceMatch = $this->findResourceMatch($alias);
    if ($resourceMatch) {
      return $resourceMatch;
    }

    // PRIORITY 2: Check if this matches a topic that's connected to a resource.
    $topicResourceMatch = $this->findResourceByTopicName($alias);
    if ($topicResourceMatch) {
      return $topicResourceMatch;
    }

    // PRIORITY 3: Fall back to exact alias match (including /topics/)
    $exactMatch = $this->findExactAliasMatch($alias);
    if ($exactMatch) {
      return [
        'destination' => $exactMatch['alias'],
        'confidence' => 95,
        'match_type' => 'exact_alias',
        'notes' => 'Exact alias match found',
      ];
    }

    // PRIORITY 4: Try normalized match with resource preference.
    $normalizedMatch = $this->findNormalizedAliasMatch($normalizedAlias);
    if ($normalizedMatch) {
      return [
        'destination' => $normalizedMatch['alias'],
        'confidence' => 85,
        'match_type' => 'normalized_alias',
        'notes' => 'Normalized alias match',
      ];
    }

    // PRIORITY 5: Try fuzzy match with resource preference.
    $fuzzyMatch = $this->findFuzzyAliasMatch($normalizedAlias);
    if ($fuzzyMatch) {
      return [
        'destination' => $fuzzyMatch['alias'],
        'confidence' => $fuzzyMatch['confidence'],
        'match_type' => 'fuzzy_alias',
        'notes' => $fuzzyMatch['notes'],
      ];
    }

    // PRIORITY 6: Try resource title match.
    $titleMatch = $this->findResourceTitleMatch($alias);
    if ($titleMatch) {
      return $titleMatch;
    }

    return NULL;
  }

  /**
   * Find a direct /resources/name match.
   *
   * @param string $alias
   *   The alias to search for.
   *
   * @return array|null
   *   Match result or null.
   */
  protected function findResourceMatch(string $alias): ?array {
    $alias = ltrim($alias, '/');

    // Try exact resource path.
    $resourcePath = '/resources/' . $alias;

    $result = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['id', 'path', 'alias'])
      ->condition('alias', $resourcePath)
      ->condition('status', 1)
      ->execute()
      ->fetch();

    if ($result) {
      return [
        'destination' => $result->alias,
        'confidence' => 98,
        'match_type' => 'direct_resource',
        'notes' => 'Direct resource match',
      ];
    }

    // Try with normalized alias.
    $normalizedAlias = $this->normalizeAlias($alias);
    $resourcePath = '/resources/' . str_replace(' ', '-', $normalizedAlias);

    $result = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['id', 'path', 'alias'])
      ->condition('alias', $resourcePath)
      ->condition('status', 1)
      ->execute()
      ->fetch();

    if ($result) {
      return [
        'destination' => $result->alias,
        'confidence' => 95,
        'match_type' => 'normalized_resource',
        'notes' => 'Normalized resource match',
      ];
    }

    return NULL;
  }

  /**
   * Find a resource that references the given topic name.
   *
   * @param string $alias
   *   The topic alias/name to look up.
   *
   * @return array|null
   *   Match result or null.
   */
  protected function findResourceByTopicName(string $alias): ?array {
    // Build cache if needed.
    if ($this->topicResourceCache === NULL) {
      $this->buildTopicResourceCache();
    }

    // Normalize the alias for lookup.
    $normalizedAlias = $this->normalizeAlias($alias);

    // Try exact match in cache.
    if (isset($this->topicResourceCache[$normalizedAlias])) {
      $resource = $this->topicResourceCache[$normalizedAlias];
      return [
        'destination' => $resource['alias'],
        'confidence' => 92,
        'match_type' => 'topic_to_resource',
        'notes' => sprintf('Topic "%s" → Resource "%s"', $resource['topic_name'], $resource['resource_title']),
      ];
    }

    // Try partial match - check if any cached topic contains our search terms.
    $searchWords = $this->extractWords($normalizedAlias);

    foreach ($this->topicResourceCache as $topicNormalized => $resource) {
      $topicWords = $this->extractWords($topicNormalized);
      $matchingWords = array_intersect($searchWords, $topicWords);

      // If we match most words, consider it a match.
      if (count($matchingWords) >= 1 && count($matchingWords) >= count($searchWords) * 0.6) {
        return [
          'destination' => $resource['alias'],
          'confidence' => 85,
          'match_type' => 'topic_to_resource_fuzzy',
          'notes' => sprintf('Topic "%s" → Resource "%s" (fuzzy)', $resource['topic_name'], $resource['resource_title']),
        ];
      }
    }

    return NULL;
  }

  /**
   * Build cache of topic names to their parent resources.
   */
  protected function buildTopicResourceCache(): void {
    $this->topicResourceCache = [];

    // Query all topics connected to resources.
    $query = $this->database->select('node__field_topics', 'ft');
    $query->join('node_field_data', 'n', 'ft.entity_id = n.nid');
    $query->join('taxonomy_term_field_data', 't', 'ft.field_topics_target_id = t.tid');
    $query->leftJoin('path_alias', 'pa', "pa.path = CONCAT('/node/', n.nid) AND pa.status = 1");

    $query->fields('n', ['nid', 'title']);
    $query->fields('t', ['tid', 'name']);
    $query->fields('pa', ['alias']);
    $query->condition('n.type', 'resource');
    $query->condition('n.status', 1);

    $results = $query->execute()->fetchAll();

    foreach ($results as $row) {
      $topicNormalized = $this->normalizeAlias($row->name);
      $resourceAlias = $row->alias ?: '/node/' . $row->nid;

      // Only store if it's a /resources/ path (preferred)
      if (str_starts_with($resourceAlias, '/resources/')) {
        $this->topicResourceCache[$topicNormalized] = [
          'topic_name' => $row->name,
          'resource_title' => $row->title,
          'alias' => $resourceAlias,
        ];
      }
    }
  }

  /**
   * Find an exact alias match, preferring /resources/ over /topics/.
   *
   * @param string $alias
   *   The alias to search for.
   *
   * @return array|null
   *   The matching alias record or null.
   */
  protected function findExactAliasMatch(string $alias): ?array {
    $aliasWithSlash = '/' . ltrim($alias, '/');

    $query = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['id', 'path', 'alias'])
      ->condition('status', 1)
      ->condition('alias', '%' . $this->database->escapeLike($aliasWithSlash), 'LIKE');

    $results = $query->execute()->fetchAll();

    // Sort results to prefer /resources/ over /topics/.
    $resourceMatches = [];
    $topicMatches = [];
    $otherMatches = [];

    foreach ($results as $result) {
      if (str_ends_with($result->alias, $aliasWithSlash) || str_ends_with($result->alias, $alias)) {
        if (str_starts_with($result->alias, '/resources/')) {
          $resourceMatches[] = $result;
        }
        elseif (str_starts_with($result->alias, '/topics/')) {
          $topicMatches[] = $result;
        }
        else {
          $otherMatches[] = $result;
        }
      }
    }

    // Return best match in priority order.
    $bestResult = $resourceMatches[0] ?? $otherMatches[0] ?? $topicMatches[0] ?? NULL;

    if ($bestResult) {
      return [
        'id' => $bestResult->id,
        'path' => $bestResult->path,
        'alias' => $bestResult->alias,
      ];
    }

    return NULL;
  }

  /**
   * Find a normalized alias match, preferring /resources/.
   *
   * @param string $normalizedAlias
   *   The normalized alias to search for.
   *
   * @return array|null
   *   The matching alias record or null.
   */
  protected function findNormalizedAliasMatch(string $normalizedAlias): ?array {
    $query = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['id', 'path', 'alias'])
      ->condition('status', 1);

    $results = $query->execute()->fetchAll();

    $resourceMatch = NULL;
    $topicMatch = NULL;
    $otherMatch = NULL;

    foreach ($results as $result) {
      $currentNormalized = $this->normalizeAlias($result->alias);
      if ($currentNormalized === $normalizedAlias) {
        if (str_starts_with($result->alias, '/resources/')) {
          $resourceMatch = $result;
          // Best possible match.
          break;
        }
        elseif (str_starts_with($result->alias, '/topics/') && !$topicMatch) {
          $topicMatch = $result;
        }
        elseif (!$otherMatch) {
          $otherMatch = $result;
        }
      }
    }

    $bestResult = $resourceMatch ?? $otherMatch ?? $topicMatch;

    if ($bestResult) {
      return [
        'id' => $bestResult->id,
        'path' => $bestResult->path,
        'alias' => $bestResult->alias,
      ];
    }

    return NULL;
  }

  /**
   * Find a fuzzy alias match, preferring /resources/.
   *
   * @param string $normalizedAlias
   *   The normalized alias to search for.
   *
   * @return array|null
   *   The matching alias record with confidence and notes.
   */
  protected function findFuzzyAliasMatch(string $normalizedAlias): ?array {
    $query = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['id', 'path', 'alias'])
      ->condition('status', 1);

    $results = $query->execute()->fetchAll();

    $searchWords = $this->extractWords($normalizedAlias);

    $resourceMatches = [];
    $otherMatches = [];

    foreach ($results as $result) {
      $currentWords = $this->extractWords($result->alias);
      $matchingWords = array_intersect($searchWords, $currentWords);
      $matchCount = count($matchingWords);
      $totalWords = max(count($searchWords), count($currentWords));

      if ($totalWords > 0 && $matchCount >= 2) {
        $score = ($matchCount / $totalWords) * 100;
        $confidence = min(85, 60 + ($matchCount * 5));

        $matchData = [
          'id' => $result->id,
          'path' => $result->path,
          'alias' => $result->alias,
          'confidence' => $confidence,
          'score' => $score,
          'notes' => sprintf('Matched %d/%d words: %s', $matchCount, count($searchWords), implode(', ', $matchingWords)),
        ];

        if (str_starts_with($result->alias, '/resources/')) {
          $resourceMatches[] = $matchData;
        }
        else {
          $otherMatches[] = $matchData;
        }
      }
    }

    // Sort by score descending.
    usort($resourceMatches, fn($a, $b) => $b['score'] <=> $a['score']);
    usort($otherMatches, fn($a, $b) => $b['score'] <=> $a['score']);

    // Prefer resource matches.
    $bestMatch = $resourceMatches[0] ?? $otherMatches[0] ?? NULL;

    return $bestMatch;
  }

  /**
   * Find a resource by title match.
   *
   * @param string $alias
   *   The alias to convert to search terms.
   *
   * @return array|null
   *   The matching resource with alias info.
   */
  protected function findResourceTitleMatch(string $alias): ?array {
    $searchTerms = str_replace('-', ' ', $alias);
    $searchTerms = preg_replace('/\s+/', ' ', $searchTerms);
    $searchTerms = trim($searchTerms);

    if (empty($searchTerms)) {
      return NULL;
    }

    // Search resource node titles only.
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $query = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', 'resource')
      ->condition('title', '%' . $searchTerms . '%', 'LIKE');

    $nids = $query->execute();

    if (empty($nids)) {
      // Try individual significant words.
      $words = explode(' ', $searchTerms);
      $significantWords = array_filter($words, fn($w) => strlen($w) >= 4);

      foreach ($significantWords as $word) {
        $query = $nodeStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('status', 1)
          ->condition('type', 'resource')
          ->condition('title', '%' . $word . '%', 'LIKE');
        $nids = $query->execute();
        if (!empty($nids)) {
          break;
        }
      }
    }

    if (empty($nids)) {
      return NULL;
    }

    $nid = reset($nids);
    $node = $nodeStorage->load($nid);

    if (!$node) {
      return NULL;
    }

    // Get the node's alias.
    $aliasQuery = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['alias'])
      ->condition('path', '/node/' . $nid)
      ->condition('status', 1)
      ->execute()
      ->fetchField();

    $destination = $aliasQuery ?: '/node/' . $nid;

    return [
      'destination' => $destination,
      'confidence' => 75,
      'match_type' => 'resource_title_match',
      'notes' => sprintf('Resource title match: "%s"', $node->getTitle()),
    ];
  }

  /**
   * Match by old node ID (search by title similarity).
   *
   * @param string $oldNid
   *   The old node ID.
   *
   * @return array|null
   *   Match result.
   */
  protected function matchByOldNodeId(string $oldNid): ?array {
    return [
      'destination' => NULL,
      'confidence' => 0,
      'match_type' => 'no_match',
      'notes' => sprintf('Old node ID %s - no alias to match', $oldNid),
    ];
  }

  /**
   * Match by old topic ID.
   *
   * @param string $oldTid
   *   The old topic ID.
   *
   * @return array|null
   *   Match result.
   */
  protected function matchByOldTopicId(string $oldTid): ?array {
    return [
      'destination' => NULL,
      'confidence' => 0,
      'match_type' => 'no_match',
      'notes' => sprintf('Old topic ID %s - no alias to match', $oldTid),
    ];
  }

  /**
   * Match a taxonomy term by ID - route to resource if connected.
   *
   * @param string $tid
   *   The taxonomy term ID.
   *
   * @return array|null
   *   Match result.
   */
  protected function matchTaxonomyTerm(string $tid): ?array {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term = $termStorage->load($tid);

    if (!$term) {
      return [
        'destination' => NULL,
        'confidence' => 0,
        'match_type' => 'no_match',
        'notes' => sprintf('Old taxonomy term ID %s does not exist', $tid),
      ];
    }

    // First, try to find a resource that references this term.
    $resourceMatch = $this->findResourceByTopicName($term->getName());
    if ($resourceMatch) {
      return $resourceMatch;
    }

    // Fall back to the term's alias.
    $aliasQuery = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['alias'])
      ->condition('path', '/taxonomy/term/' . $tid)
      ->condition('status', 1)
      ->execute()
      ->fetchField();

    $destination = $aliasQuery ?: '/taxonomy/term/' . $tid;

    return [
      'destination' => $destination,
      'confidence' => 85,
      'match_type' => 'taxonomy_direct',
      'notes' => sprintf('Taxonomy term: %s (no resource reference found)', $term->getName()),
    ];
  }

  /**
   * Normalize an alias for comparison.
   *
   * @param string $alias
   *   The alias to normalize.
   *
   * @return string
   *   The normalized alias.
   */
  protected function normalizeAlias(string $alias): string {
    $alias = trim($alias, '/');
    $alias = preg_replace('#^(resources|topics|legal-help|page)/+#', '', $alias);
    $alias = strtolower($alias);
    $alias = str_replace(['-', '_'], ' ', $alias);
    $alias = preg_replace('/\s+/', ' ', $alias);
    return trim($alias);
  }

  /**
   * Extract significant words from a path.
   *
   * @param string $path
   *   The path to extract words from.
   *
   * @return array
   *   Array of significant words.
   */
  protected function extractWords(string $path): array {
    $normalized = $this->normalizeAlias($path);
    $words = explode(' ', $normalized);
    $stopWords = ['and', 'the', 'for', 'with', 'your', 'how', 'what', 'are', 'is', 'in', 'to', 'of', 'a', 'an', 'or'];

    return array_filter($words, function ($word) use ($stopWords) {
      return strlen($word) >= 3 && !in_array($word, $stopWords);
    });
  }

  /**
   * Clear the topic-resource cache.
   */
  public function clearCache(): void {
    $this->topicResourceCache = NULL;
  }

}
