<?php

/**
 * @file
 * Retrieval evaluation harness - computes retrieval-specific metrics.
 *
 * This class evaluates FAQ and resource retrieval quality by computing:
 * - Recall@K (K=1, 3, 5)
 * - Mean Reciprocal Rank (MRR)
 * - Normalized Discounted Cumulative Gain (nDCG)
 */

namespace IlasChatbotEval;

/**
 * Retrieval evaluator for FAQ and resource search.
 */
class RetrievalEvaluator {

  /**
   * Configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * HTTP mode flag.
   *
   * @var bool
   */
  protected $httpMode = FALSE;

  /**
   * Base URL for HTTP mode.
   *
   * @var string
   */
  protected $baseUrl = '';

  /**
   * FAQ Index service (Drupal mode only).
   *
   * @var object|null
   */
  protected $faqIndex = NULL;

  /**
   * Resource Finder service (Drupal mode only).
   *
   * @var object|null
   */
  protected $resourceFinder = NULL;

  /**
   * Intent Router service (Drupal mode only).
   *
   * @var object|null
   */
  protected $intentRouter = NULL;

  /**
   * Results storage.
   *
   * @var array
   */
  protected $results = [];

  /**
   * Constructs a RetrievalEvaluator.
   *
   * @param array $config
   *   Configuration options.
   */
  public function __construct(array $config = []) {
    $this->config = array_merge([
      'http_mode' => FALSE,
      'base_url' => 'https://idaholegalaid.ddev.site',
      'timeout' => 10,
      'verbose' => FALSE,
      'max_results' => 10,
    ], $config);

    $this->httpMode = $this->config['http_mode'];
    $this->baseUrl = rtrim($this->config['base_url'], '/');
  }

  /**
   * Sets Drupal services for direct mode.
   *
   * @param object $faq_index
   *   The FAQ index service.
   * @param object $resource_finder
   *   The resource finder service.
   * @param object $intent_router
   *   The intent router service.
   */
  public function setServices($faq_index, $resource_finder, $intent_router) {
    $this->faqIndex = $faq_index;
    $this->resourceFinder = $resource_finder;
    $this->intentRouter = $intent_router;
  }

  /**
   * Loads test cases from JSON fixture.
   *
   * @param string $file_path
   *   Path to JSON fixture file.
   * @param array $options
   *   Options:
   *   - filter_category: Filter by category.
   *   - filter_type: Filter by expected_type.
   *   - limit: Maximum test cases.
   *
   * @return array
   *   Array of test cases.
   */
  public function loadFixture(string $file_path, array $options = []): array {
    if (!file_exists($file_path)) {
      throw new \Exception("Fixture file not found: $file_path");
    }

    $content = file_get_contents($file_path);
    $data = json_decode($content, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \Exception("Invalid JSON: " . json_last_error_msg());
    }

    $test_cases = $data['test_cases'] ?? [];

    // Apply filters.
    if (!empty($options['filter_category'])) {
      $test_cases = array_filter($test_cases, function ($tc) use ($options) {
        return ($tc['category'] ?? '') === $options['filter_category'];
      });
    }

    if (!empty($options['filter_type'])) {
      $test_cases = array_filter($test_cases, function ($tc) use ($options) {
        return ($tc['expected_type'] ?? '') === $options['filter_type'];
      });
    }

    $test_cases = array_values($test_cases);

    if (!empty($options['limit'])) {
      $test_cases = array_slice($test_cases, 0, (int) $options['limit']);
    }

    return $test_cases;
  }

  /**
   * Runs retrieval evaluation on test cases.
   *
   * @param array $test_cases
   *   Array of test cases.
   *
   * @return array
   *   Evaluation results with metrics.
   */
  public function runEvaluation(array $test_cases): array {
    $this->results = [
      'summary' => [
        'total' => count($test_cases),
        'start_time' => date('c'),
        'end_time' => NULL,
      ],
      'metrics' => [
        'recall_at_1' => 0,
        'recall_at_3' => 0,
        'recall_at_5' => 0,
        'mrr' => 0,
        'ndcg_at_5' => 0,
        'avg_rank' => 0,
      ],
      'by_category' => [],
      'by_type' => [],
      'by_difficulty' => [],
      'test_results' => [],
      'failures' => [],
    ];

    $mrr_sum = 0;
    $ndcg_sum = 0;
    $recall_1_sum = 0;
    $recall_3_sum = 0;
    $recall_5_sum = 0;
    $rank_sum = 0;
    $ranked_count = 0;

    foreach ($test_cases as $index => $test_case) {
      $result = $this->evaluateSingleCase($test_case);
      $this->results['test_results'][] = $result;

      // Update category stats.
      $category = $test_case['category'] ?? 'unknown';
      if (!isset($this->results['by_category'][$category])) {
        $this->results['by_category'][$category] = $this->initCategoryStats();
      }
      $this->updateStats($this->results['by_category'][$category], $result);

      // Update type stats.
      $type = $test_case['expected_type'] ?? 'unknown';
      if (!isset($this->results['by_type'][$type])) {
        $this->results['by_type'][$type] = $this->initCategoryStats();
      }
      $this->updateStats($this->results['by_type'][$type], $result);

      // Update difficulty stats.
      $difficulty = $test_case['difficulty'] ?? 'medium';
      if (!isset($this->results['by_difficulty'][$difficulty])) {
        $this->results['by_difficulty'][$difficulty] = $this->initCategoryStats();
      }
      $this->updateStats($this->results['by_difficulty'][$difficulty], $result);

      // Accumulate metrics.
      if ($result['hit_at_1']) {
        $recall_1_sum++;
      }
      if ($result['hit_at_3']) {
        $recall_3_sum++;
      }
      if ($result['hit_at_5']) {
        $recall_5_sum++;
      }
      if ($result['reciprocal_rank'] > 0) {
        $mrr_sum += $result['reciprocal_rank'];
      }
      $ndcg_sum += $result['ndcg'];
      if ($result['rank'] > 0) {
        $rank_sum += $result['rank'];
        $ranked_count++;
      }

      // Track failures.
      if (!$result['hit_at_5']) {
        $this->results['failures'][] = [
          'id' => $test_case['id'],
          'query' => $test_case['query'],
          'expected' => $test_case['canonical_target'],
          'alternates' => $test_case['acceptable_alternates'] ?? [],
          'retrieved' => array_slice($result['retrieved_urls'], 0, 5),
          'category' => $category,
          'difficulty' => $difficulty,
        ];
      }

      // Verbose output.
      if ($this->config['verbose']) {
        $status = $result['hit_at_1'] ? 'HIT@1' : ($result['hit_at_3'] ? 'HIT@3' : ($result['hit_at_5'] ? 'HIT@5' : 'MISS'));
        echo sprintf("[%d/%d] %s: %s (rank: %s)\n",
          $index + 1,
          count($test_cases),
          $status,
          substr($test_case['query'], 0, 40),
          $result['rank'] > 0 ? $result['rank'] : 'N/A'
        );
      }
    }

    // Calculate final metrics.
    $total = count($test_cases);
    if ($total > 0) {
      $this->results['metrics']['recall_at_1'] = round($recall_1_sum / $total, 4);
      $this->results['metrics']['recall_at_3'] = round($recall_3_sum / $total, 4);
      $this->results['metrics']['recall_at_5'] = round($recall_5_sum / $total, 4);
      $this->results['metrics']['mrr'] = round($mrr_sum / $total, 4);
      $this->results['metrics']['ndcg_at_5'] = round($ndcg_sum / $total, 4);
      $this->results['metrics']['avg_rank'] = $ranked_count > 0 ? round($rank_sum / $ranked_count, 2) : 0;
    }

    // Calculate per-category metrics.
    foreach ($this->results['by_category'] as $cat => &$stats) {
      $this->finalizeStats($stats);
    }
    foreach ($this->results['by_type'] as $type => &$stats) {
      $this->finalizeStats($stats);
    }
    foreach ($this->results['by_difficulty'] as $diff => &$stats) {
      $this->finalizeStats($stats);
    }

    $this->results['summary']['end_time'] = date('c');
    $this->results['summary']['failures'] = count($this->results['failures']);

    return $this->results;
  }

  /**
   * Evaluates a single test case.
   *
   * @param array $test_case
   *   The test case.
   *
   * @return array
   *   Evaluation result.
   */
  protected function evaluateSingleCase(array $test_case): array {
    $query = $test_case['query'];
    $expected_type = $test_case['expected_type'] ?? 'any';
    $canonical = $test_case['canonical_target'];
    $alternates = $test_case['acceptable_alternates'] ?? [];

    // Get retrieval results.
    $retrieved = $this->performRetrieval($query, $expected_type);

    // Extract URLs from results.
    $retrieved_urls = array_map(function ($item) {
      return $item['url'] ?? $item['source_url'] ?? '';
    }, $retrieved);

    // Find rank of canonical/acceptable targets.
    $rank = $this->findRank($canonical, $alternates, $retrieved_urls);

    // Calculate metrics.
    $hit_at_1 = $rank === 1;
    $hit_at_3 = $rank > 0 && $rank <= 3;
    $hit_at_5 = $rank > 0 && $rank <= 5;
    $reciprocal_rank = $rank > 0 ? 1.0 / $rank : 0;

    // Calculate nDCG@5.
    $relevance = $this->buildRelevanceVector($canonical, $alternates, $retrieved_urls, 5);
    $ndcg = $this->calculateNdcg($relevance);

    return [
      'id' => $test_case['id'],
      'query' => $query,
      'expected_target' => $canonical,
      'rank' => $rank,
      'hit_at_1' => $hit_at_1,
      'hit_at_3' => $hit_at_3,
      'hit_at_5' => $hit_at_5,
      'reciprocal_rank' => $reciprocal_rank,
      'ndcg' => $ndcg,
      'retrieved_urls' => $retrieved_urls,
      'num_results' => count($retrieved),
    ];
  }

  /**
   * Performs retrieval for a query.
   *
   * @param string $query
   *   The search query.
   * @param string $expected_type
   *   Expected result type (faq, resource, navigation, any).
   *
   * @return array
   *   Retrieved results.
   */
  protected function performRetrieval(string $query, string $expected_type): array {
    if ($this->httpMode) {
      return $this->performHttpRetrieval($query, $expected_type);
    }

    return $this->performDirectRetrieval($query, $expected_type);
  }

  /**
   * Performs retrieval via HTTP API.
   *
   * @param string $query
   *   The search query.
   * @param string $expected_type
   *   Expected result type.
   *
   * @return array
   *   Retrieved results.
   */
  protected function performHttpRetrieval(string $query, string $expected_type): array {
    // Send message to chatbot API with debug mode.
    $url = $this->baseUrl . '/assistant/api/message';

    $payload = json_encode([
      'message' => $query,
      'debug' => TRUE,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_TIMEOUT => $this->config['timeout'],
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Debug-Mode: 1',
      ],
      CURLOPT_SSL_VERIFYPEER => FALSE,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || $response === FALSE) {
      return [];
    }

    $data = json_decode($response, TRUE);

    // Extract retrieval results from debug metadata.
    $results = [];

    // Check debug metadata for retrieval_results.
    if (!empty($data['_debug']['retrieval_results'])) {
      foreach ($data['_debug']['retrieval_results'] as $item) {
        $results[] = [
          'url' => $item['url'] ?? '',
          'title' => $item['title'] ?? '',
          'type' => $item['type'] ?? 'unknown',
          'score' => $item['score'] ?? 0,
        ];
      }
    }

    // Also check results array.
    if (!empty($data['results'])) {
      foreach ($data['results'] as $item) {
        $url = $item['url'] ?? $item['source_url'] ?? '';
        if ($url && !$this->urlExists($url, $results)) {
          $results[] = [
            'url' => $url,
            'title' => $item['title'] ?? $item['question'] ?? '',
            'type' => $item['type'] ?? 'unknown',
            'score' => $item['score'] ?? 0,
          ];
        }
      }
    }

    // Check for navigation URL.
    if (!empty($data['url'])) {
      array_unshift($results, [
        'url' => $data['url'],
        'title' => 'Navigation',
        'type' => 'navigation',
        'score' => 100,
      ]);
    }

    // Check links array (used by escalation responses).
    if (!empty($data['links'])) {
      foreach ($data['links'] as $link) {
        $url = $link['url'] ?? '';
        if ($url && !$this->urlExists($url, $results)) {
          $results[] = [
            'url' => $url,
            'title' => $link['label'] ?? '',
            'type' => 'link',
            'score' => 50,
          ];
        }
      }
    }

    // Check actions array (also used by escalation responses).
    if (!empty($data['actions'])) {
      foreach ($data['actions'] as $action) {
        $url = $action['url'] ?? '';
        if ($url && !$this->urlExists($url, $results)) {
          $results[] = [
            'url' => $url,
            'title' => $action['label'] ?? '',
            'type' => 'action',
            'score' => 40,
          ];
        }
      }
    }

    // Store response type for analysis.
    $response_type = $data['type'] ?? 'unknown';
    foreach ($results as &$r) {
      $r['_response_type'] = $response_type;
    }

    return array_slice($results, 0, $this->config['max_results']);
  }

  /**
   * Performs retrieval directly via Drupal services.
   *
   * @param string $query
   *   The search query.
   * @param string $expected_type
   *   Expected result type.
   *
   * @return array
   *   Retrieved results.
   */
  protected function performDirectRetrieval(string $query, string $expected_type): array {
    $results = [];

    // Get intent routing result.
    if ($this->intentRouter) {
      $intent = $this->intentRouter->route($query, []);
      $intent_type = $intent['type'] ?? 'unknown';

      // Add navigation URL if available.
      if (!empty($intent['url'])) {
        $results[] = [
          'url' => $intent['url'],
          'title' => 'Navigation: ' . $intent_type,
          'type' => 'navigation',
          'score' => 100,
        ];
      }
    }

    // Search FAQs.
    if ($this->faqIndex && in_array($expected_type, ['faq', 'any'])) {
      $faq_results = $this->faqIndex->search($query, 5);
      foreach ($faq_results as $faq) {
        $results[] = [
          'url' => $faq['url'] ?? $faq['source_url'] ?? '',
          'title' => $faq['question'] ?? $faq['title'] ?? '',
          'type' => 'faq',
          'score' => $faq['score'] ?? 0,
        ];
      }
    }

    // Search resources.
    if ($this->resourceFinder && in_array($expected_type, ['resource', 'any'])) {
      $resource_results = $this->resourceFinder->findResources($query, 5);
      foreach ($resource_results as $resource) {
        $url = $resource['url'] ?? '';
        if (!$this->urlExists($url, $results)) {
          $results[] = [
            'url' => $url,
            'title' => $resource['title'] ?? '',
            'type' => $resource['type'] ?? 'resource',
            'score' => $resource['score'] ?? 0,
          ];
        }
      }
    }

    return array_slice($results, 0, $this->config['max_results']);
  }

  /**
   * Checks if URL already exists in results.
   *
   * @param string $url
   *   The URL to check.
   * @param array $results
   *   Existing results.
   *
   * @return bool
   *   TRUE if URL exists.
   */
  protected function urlExists(string $url, array $results): bool {
    foreach ($results as $result) {
      if ($result['url'] === $url) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Finds the rank of target URL in results.
   *
   * @param string|null $canonical
   *   Canonical target URL.
   * @param array $alternates
   *   Acceptable alternate URLs.
   * @param array $retrieved_urls
   *   Retrieved URLs.
   *
   * @return int
   *   Rank (1-indexed) or 0 if not found.
   */
  protected function findRank(?string $canonical, array $alternates, array $retrieved_urls): int {
    if ($canonical === NULL) {
      // Out of scope queries - expect no relevant results.
      return 0;
    }

    $targets = array_merge([$canonical], $alternates);

    foreach ($retrieved_urls as $rank => $url) {
      foreach ($targets as $target) {
        if ($this->urlMatches($url, $target)) {
          return $rank + 1; // 1-indexed rank.
        }
      }
    }

    return 0; // Not found.
  }

  /**
   * Checks if a URL matches a target.
   *
   * @param string $url
   *   The retrieved URL.
   * @param string $target
   *   The target URL pattern.
   *
   * @return bool
   *   TRUE if matches.
   */
  protected function urlMatches(string $url, string $target): bool {
    // Normalize URLs.
    $url = strtolower(parse_url($url, PHP_URL_PATH) ?? $url);
    $target = strtolower($target);

    // Remove trailing slashes.
    $url = rtrim($url, '/');
    $target = rtrim($target, '/');

    // Exact match.
    if ($url === $target) {
      return TRUE;
    }

    // Target is prefix (e.g., /forms matches /forms/divorce).
    if (strpos($url, $target) === 0) {
      return TRUE;
    }

    // URL contains anchor, target matches base or anchor.
    if (strpos($url, '#') !== FALSE) {
      // Check if base URL matches.
      $base_url = explode('#', $url)[0];
      if ($base_url === $target || strpos($base_url, $target) === 0) {
        return TRUE;
      }
      // Check if full URL with anchor matches.
      if ($url === $target) {
        return TRUE;
      }
    }

    // Target contains anchor, URL matches base.
    if (strpos($target, '#') !== FALSE) {
      $base_target = explode('#', $target)[0];
      if ($url === $base_target) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Builds relevance vector for nDCG calculation.
   *
   * @param string|null $canonical
   *   Canonical target.
   * @param array $alternates
   *   Acceptable alternates.
   * @param array $retrieved_urls
   *   Retrieved URLs.
   * @param int $k
   *   Length of vector.
   *
   * @return array
   *   Relevance scores (3=canonical, 2=alternate, 0=irrelevant).
   */
  protected function buildRelevanceVector(?string $canonical, array $alternates, array $retrieved_urls, int $k): array {
    $relevance = array_fill(0, $k, 0);

    if ($canonical === NULL) {
      return $relevance;
    }

    for ($i = 0; $i < min($k, count($retrieved_urls)); $i++) {
      $url = $retrieved_urls[$i];

      if ($this->urlMatches($url, $canonical)) {
        $relevance[$i] = 3; // Canonical match = highest relevance.
      }
      else {
        foreach ($alternates as $alt) {
          if ($this->urlMatches($url, $alt)) {
            $relevance[$i] = 2; // Alternate match = medium relevance.
            break;
          }
        }
      }
    }

    return $relevance;
  }

  /**
   * Calculates nDCG from relevance vector.
   *
   * @param array $relevance
   *   Relevance scores.
   *
   * @return float
   *   nDCG score.
   */
  protected function calculateNdcg(array $relevance): float {
    $dcg = $this->calculateDcg($relevance);

    // Ideal DCG: sort relevance descending.
    $ideal_relevance = $relevance;
    rsort($ideal_relevance);
    $idcg = $this->calculateDcg($ideal_relevance);

    if ($idcg == 0) {
      return 0;
    }

    return $dcg / $idcg;
  }

  /**
   * Calculates DCG from relevance vector.
   *
   * @param array $relevance
   *   Relevance scores.
   *
   * @return float
   *   DCG score.
   */
  protected function calculateDcg(array $relevance): float {
    $dcg = 0;
    foreach ($relevance as $i => $rel) {
      // DCG formula: rel / log2(i + 2).
      $dcg += $rel / log($i + 2, 2);
    }
    return $dcg;
  }

  /**
   * Initializes category stats structure.
   *
   * @return array
   *   Stats structure.
   */
  protected function initCategoryStats(): array {
    return [
      'total' => 0,
      'hit_at_1' => 0,
      'hit_at_3' => 0,
      'hit_at_5' => 0,
      'mrr_sum' => 0,
      'ndcg_sum' => 0,
      'recall_at_1' => 0,
      'recall_at_3' => 0,
      'recall_at_5' => 0,
      'mrr' => 0,
      'ndcg' => 0,
    ];
  }

  /**
   * Updates category stats with a result.
   *
   * @param array &$stats
   *   Stats to update.
   * @param array $result
   *   Evaluation result.
   */
  protected function updateStats(array &$stats, array $result): void {
    $stats['total']++;
    if ($result['hit_at_1']) {
      $stats['hit_at_1']++;
    }
    if ($result['hit_at_3']) {
      $stats['hit_at_3']++;
    }
    if ($result['hit_at_5']) {
      $stats['hit_at_5']++;
    }
    $stats['mrr_sum'] += $result['reciprocal_rank'];
    $stats['ndcg_sum'] += $result['ndcg'];
  }

  /**
   * Finalizes category stats (calculates averages).
   *
   * @param array &$stats
   *   Stats to finalize.
   */
  protected function finalizeStats(array &$stats): void {
    if ($stats['total'] > 0) {
      $stats['recall_at_1'] = round($stats['hit_at_1'] / $stats['total'], 4);
      $stats['recall_at_3'] = round($stats['hit_at_3'] / $stats['total'], 4);
      $stats['recall_at_5'] = round($stats['hit_at_5'] / $stats['total'], 4);
      $stats['mrr'] = round($stats['mrr_sum'] / $stats['total'], 4);
      $stats['ndcg'] = round($stats['ndcg_sum'] / $stats['total'], 4);
    }
    // Clean up intermediate values.
    unset($stats['mrr_sum'], $stats['ndcg_sum']);
  }

  /**
   * Generates a markdown report.
   *
   * @param array $results
   *   Evaluation results.
   * @param string $title
   *   Report title.
   *
   * @return string
   *   Markdown report.
   */
  public static function generateReport(array $results, string $title = 'Retrieval Evaluation Report'): string {
    $md = [];
    $md[] = "# $title";
    $md[] = '';
    $md[] = "**Generated:** " . date('Y-m-d H:i:s');
    $md[] = "**Total Test Cases:** " . $results['summary']['total'];
    $md[] = "**Failed Retrievals:** " . $results['summary']['failures'];
    $md[] = '';

    // Overall metrics.
    $md[] = '## Overall Metrics';
    $md[] = '';
    $md[] = '| Metric | Score |';
    $md[] = '|--------|-------|';
    $md[] = '| Recall@1 | ' . self::formatPercent($results['metrics']['recall_at_1']) . ' |';
    $md[] = '| Recall@3 | ' . self::formatPercent($results['metrics']['recall_at_3']) . ' |';
    $md[] = '| Recall@5 | ' . self::formatPercent($results['metrics']['recall_at_5']) . ' |';
    $md[] = '| MRR | ' . round($results['metrics']['mrr'], 4) . ' |';
    $md[] = '| nDCG@5 | ' . round($results['metrics']['ndcg_at_5'], 4) . ' |';
    $md[] = '| Avg Rank | ' . $results['metrics']['avg_rank'] . ' |';
    $md[] = '';

    // Metrics by category.
    if (!empty($results['by_category'])) {
      $md[] = '## Metrics by Category';
      $md[] = '';
      $md[] = '| Category | Total | R@1 | R@3 | R@5 | MRR |';
      $md[] = '|----------|-------|-----|-----|-----|-----|';

      foreach ($results['by_category'] as $cat => $stats) {
        $md[] = sprintf('| %s | %d | %s | %s | %s | %.3f |',
          $cat,
          $stats['total'],
          self::formatPercent($stats['recall_at_1']),
          self::formatPercent($stats['recall_at_3']),
          self::formatPercent($stats['recall_at_5']),
          $stats['mrr']
        );
      }
      $md[] = '';
    }

    // Metrics by type.
    if (!empty($results['by_type'])) {
      $md[] = '## Metrics by Expected Type';
      $md[] = '';
      $md[] = '| Type | Total | R@1 | R@3 | R@5 | MRR |';
      $md[] = '|------|-------|-----|-----|-----|-----|';

      foreach ($results['by_type'] as $type => $stats) {
        $md[] = sprintf('| %s | %d | %s | %s | %s | %.3f |',
          $type,
          $stats['total'],
          self::formatPercent($stats['recall_at_1']),
          self::formatPercent($stats['recall_at_3']),
          self::formatPercent($stats['recall_at_5']),
          $stats['mrr']
        );
      }
      $md[] = '';
    }

    // Metrics by difficulty.
    if (!empty($results['by_difficulty'])) {
      $md[] = '## Metrics by Difficulty';
      $md[] = '';
      $md[] = '| Difficulty | Total | R@1 | R@3 | R@5 | MRR |';
      $md[] = '|------------|-------|-----|-----|-----|-----|';

      foreach ($results['by_difficulty'] as $diff => $stats) {
        $md[] = sprintf('| %s | %d | %s | %s | %s | %.3f |',
          $diff,
          $stats['total'],
          self::formatPercent($stats['recall_at_1']),
          self::formatPercent($stats['recall_at_3']),
          self::formatPercent($stats['recall_at_5']),
          $stats['mrr']
        );
      }
      $md[] = '';
    }

    // Hardest failures.
    if (!empty($results['failures'])) {
      $md[] = '## Hardest Failures';
      $md[] = '';
      $md[] = 'Top ' . min(20, count($results['failures'])) . ' failed retrievals:';
      $md[] = '';

      foreach (array_slice($results['failures'], 0, 20) as $failure) {
        $md[] = "### `{$failure['id']}`";
        $md[] = '';
        $md[] = "- **Query:** \"{$failure['query']}\"";
        $md[] = "- **Expected:** `{$failure['expected']}`";
        if (!empty($failure['alternates'])) {
          $md[] = "- **Alternates:** " . implode(', ', array_map(function ($a) {
            return "`$a`";
          }, $failure['alternates']));
        }
        $md[] = "- **Retrieved:**";
        foreach (array_slice($failure['retrieved'], 0, 5) as $i => $url) {
          $md[] = "  " . ($i + 1) . ". `$url`";
        }
        $md[] = "- **Category:** {$failure['category']}";
        $md[] = "- **Difficulty:** {$failure['difficulty']}";
        $md[] = '';
      }
    }

    $md[] = '---';
    $md[] = '*Report generated by ILAS Retrieval Evaluation Harness*';

    return implode("\n", $md);
  }

  /**
   * Formats a percentage value.
   *
   * @param float $value
   *   Value between 0 and 1.
   *
   * @return string
   *   Formatted percentage.
   */
  protected static function formatPercent(float $value): string {
    return round($value * 100, 1) . '%';
  }

  /**
   * Saves results to files.
   *
   * @param array $results
   *   Evaluation results.
   * @param string $output_dir
   *   Output directory.
   * @param string $prefix
   *   Filename prefix.
   *
   * @return array
   *   Created file paths.
   */
  public static function saveResults(array $results, string $output_dir, string $prefix = 'retrieval'): array {
    if (!is_dir($output_dir)) {
      mkdir($output_dir, 0755, TRUE);
    }

    $timestamp = date('Y-m-d_His');
    $files = [];

    // Markdown report.
    $md_path = "{$output_dir}/{$prefix}-report-{$timestamp}.md";
    file_put_contents($md_path, self::generateReport($results));
    $files['markdown'] = $md_path;

    // JSON results.
    $json_path = "{$output_dir}/{$prefix}-results-{$timestamp}.json";
    file_put_contents($json_path, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $files['json'] = $json_path;

    // Latest symlinks.
    $latest_md = "{$output_dir}/{$prefix}-report-latest.md";
    $latest_json = "{$output_dir}/{$prefix}-results-latest.json";

    @unlink($latest_md);
    @unlink($latest_json);

    symlink(basename($md_path), $latest_md);
    symlink(basename($json_path), $latest_json);

    $files['latest_markdown'] = $latest_md;
    $files['latest_json'] = $latest_json;

    return $files;
  }

  /**
   * Prints summary to stdout.
   *
   * @param array $results
   *   Evaluation results.
   */
  public static function printSummary(array $results): void {
    echo "\n";
    echo "=== Retrieval Evaluation Results ===\n";
    echo "\n";

    $m = $results['metrics'];
    echo "Recall@1: " . self::formatPercent($m['recall_at_1']) . "\n";
    echo "Recall@3: " . self::formatPercent($m['recall_at_3']) . "\n";
    echo "Recall@5: " . self::formatPercent($m['recall_at_5']) . "\n";
    echo "MRR:      " . round($m['mrr'], 4) . "\n";
    echo "nDCG@5:   " . round($m['ndcg_at_5'], 4) . "\n";
    echo "Avg Rank: " . $m['avg_rank'] . "\n";
    echo "\n";

    echo "Failures: " . $results['summary']['failures'] . " / " . $results['summary']['total'] . "\n";
    echo "\n";
  }

  /**
   * Gets the results.
   *
   * @return array
   *   The results.
   */
  public function getResults(): array {
    return $this->results;
  }

}
