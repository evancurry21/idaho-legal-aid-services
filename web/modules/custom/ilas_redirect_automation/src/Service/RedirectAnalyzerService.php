<?php

namespace Drupal\ilas_redirect_automation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for analyzing 404 errors and generating redirect proposals.
 */
class RedirectAnalyzerService {

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
   * The path matcher service.
   *
   * @var \Drupal\ilas_redirect_automation\Service\PathMatcherService
   */
  protected $pathMatcher;

  /**
   * The file matcher service.
   *
   * @var \Drupal\ilas_redirect_automation\Service\FileMatcherService
   */
  protected $fileMatcher;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a RedirectAnalyzerService object.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    PathMatcherService $path_matcher,
    FileMatcherService $file_matcher,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('ilas_redirect_automation');
    $this->pathMatcher = $path_matcher;
    $this->fileMatcher = $file_matcher;
    $this->configFactory = $config_factory;
  }

  /**
   * Get configuration settings.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The configuration object.
   */
  protected function getConfig() {
    return $this->configFactory->get('ilas_redirect_automation.settings');
  }

  /**
   * Analyze 404 entries and generate proposals.
   *
   * @param array $options
   *   Options for analysis:
   *   - category: Filter by category (node, topic, file, taxonomy)
   *   - min_confidence: Minimum confidence threshold
   *   - min_hits: Minimum hit count
   *   - limit: Maximum entries to process.
   *
   * @return array
   *   Array of proposals with keys: old_path, hit_count, proposed_destination,
   *   confidence, match_type, status, notes.
   */
  public function analyze(array $options = []): array {
    $config = $this->getConfig();

    // Set defaults.
    $options += [
      'category' => NULL,
      'min_confidence' => $config->get('confidence_thresholds.low') ?? 50,
      'min_hits' => $config->get('min_hit_count') ?? 1,
      'limit' => 0,
    ];

    // Get 404 data.
    $entries = $this->get404Data($options);

    $proposals = [];
    $ignorePatterns = $config->get('ignore_patterns') ?? [];
    $fileDirectories = $config->get('file_directories') ?? [];

    foreach ($entries as $entry) {
      // Check if path should be ignored.
      if ($this->shouldIgnore($entry->path, $ignorePatterns)) {
        continue;
      }

      // Categorize the path.
      $category = $this->categorize($entry->path);

      // Filter by category if specified.
      if ($options['category'] && $category !== $options['category']) {
        continue;
      }

      // Try to match based on category.
      $match = $this->matchPath($entry->path, $category, $fileDirectories);

      // Build proposal.
      $proposal = [
        'old_path' => $entry->path,
        'hit_count' => (int) $entry->count,
        'category' => $category,
        'proposed_destination' => $match['destination'] ?? '',
        'confidence' => $match['confidence'] ?? 0,
        'match_type' => $match['match_type'] ?? 'no_match',
        'status' => 'pending',
        'notes' => $match['notes'] ?? 'No match found',
      ];

      // Skip if below confidence threshold.
      if ($proposal['confidence'] < $options['min_confidence'] && $proposal['confidence'] > 0) {
        $proposal['status'] = 'low_confidence';
      }
      elseif ($proposal['confidence'] === 0) {
        $proposal['status'] = 'no_match';
      }

      $proposals[] = $proposal;
    }

    // Sort by hit count descending.
    usort($proposals, function ($a, $b) {
      return $b['hit_count'] <=> $a['hit_count'];
    });

    return $proposals;
  }

  /**
   * Get 404 data from the database.
   *
   * @param array $options
   *   Filter options.
   *
   * @return array
   *   Array of 404 entries.
   */
  public function get404Data(array $options = []): array {
    $query = $this->database->select('redirect_404', 'r')
      ->fields('r', ['path', 'langcode', 'count', 'daily_count', 'timestamp', 'resolved'])
      ->condition('resolved', 0)
      ->orderBy('count', 'DESC');

    if (!empty($options['min_hits'])) {
      $query->condition('count', $options['min_hits'], '>=');
    }

    if (!empty($options['limit'])) {
      $query->range(0, $options['limit']);
    }

    return $query->execute()->fetchAll();
  }

  /**
   * Get statistics about 404 entries.
   *
   * @return array
   *   Statistics array.
   */
  public function getStatistics(): array {
    $config = $this->getConfig();
    $ignorePatterns = $config->get('ignore_patterns') ?? [];

    // Total counts.
    $totalQuery = $this->database->select('redirect_404', 'r')
      ->fields('r');

    $total = $totalQuery->countQuery()->execute()->fetchField();

    $resolvedQuery = $this->database->select('redirect_404', 'r')
      ->condition('resolved', 1);
    $resolved = $resolvedQuery->countQuery()->execute()->fetchField();

    $unresolved = $total - $resolved;

    // Category breakdown.
    $categories = [
      'node' => 0,
      'topics' => 0,
      'taxonomy' => 0,
      'files' => 0,
      'wordpress' => 0,
      'other' => 0,
    ];

    $categoryHits = [
      'node' => 0,
      'topics' => 0,
      'taxonomy' => 0,
      'files' => 0,
      'wordpress' => 0,
      'other' => 0,
    ];

    $entries = $this->get404Data(['min_hits' => 1, 'limit' => 0]);

    $ignoredCount = 0;

    foreach ($entries as $entry) {
      if ($this->shouldIgnore($entry->path, $ignorePatterns)) {
        $ignoredCount++;
        continue;
      }

      $category = $this->categorize($entry->path);
      if (isset($categories[$category])) {
        $categories[$category]++;
        $categoryHits[$category] += (int) $entry->count;
      }
    }

    return [
      'total' => (int) $total,
      'resolved' => (int) $resolved,
      'unresolved' => (int) $unresolved,
      'would_be_ignored' => $ignoredCount,
      'categories' => $categories,
      'category_hits' => $categoryHits,
    ];
  }

  /**
   * Check if a path should be ignored.
   *
   * @param string $path
   *   The path to check.
   * @param array $patterns
   *   Patterns to match against.
   *
   * @return bool
   *   TRUE if the path should be ignored.
   */
  public function shouldIgnore(string $path, array $patterns): bool {
    $path = strtolower($path);

    foreach ($patterns as $pattern) {
      $pattern = strtolower($pattern);

      // Simple pattern matching.
      if (str_contains($path, $pattern)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Categorize a 404 path.
   *
   * @param string $path
   *   The path to categorize.
   *
   * @return string
   *   The category name.
   */
  public function categorize(string $path): string {
    $patterns = [
      'node' => '#^/node/\d+#',
      'topics' => '#^/topics/\d+#',
      'taxonomy' => '#^/taxonomy/term/\d+#',
      'files' => '#^/(sites/default/)?files/#',
      'wordpress' => '#(wp-|xmlrpc|\.asp|phpmyadmin|administrator)#i',
    ];

    foreach ($patterns as $category => $pattern) {
      if (preg_match($pattern, $path)) {
        return $category;
      }
    }

    return 'other';
  }

  /**
   * Match a path to a destination based on category.
   *
   * @param string $path
   *   The path to match.
   * @param string $category
   *   The path category.
   * @param array $fileDirectories
   *   Directories to search for files.
   *
   * @return array
   *   Match result.
   */
  protected function matchPath(string $path, string $category, array $fileDirectories): array {
    switch ($category) {
      case 'node':
      case 'topics':
      case 'taxonomy':
        $match = $this->pathMatcher->match($path);
        return $match ?? [
          'destination' => NULL,
          'confidence' => 0,
          'match_type' => 'no_match',
          'notes' => 'No matching content found',
        ];

      case 'files':
        $match = $this->fileMatcher->match($path, $fileDirectories);
        return $match ?? [
          'destination' => NULL,
          'confidence' => 0,
          'match_type' => 'no_match',
          'notes' => 'File not found',
        ];

      case 'wordpress':
        return [
          'destination' => NULL,
          'confidence' => 0,
          'match_type' => 'ignore',
          'notes' => 'WordPress/attack probe - should be ignored',
        ];

      default:
        return [
          'destination' => NULL,
          'confidence' => 0,
          'match_type' => 'unknown',
          'notes' => 'Unknown path format',
        ];
    }
  }

}
