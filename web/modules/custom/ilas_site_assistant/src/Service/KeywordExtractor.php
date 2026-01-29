<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Service for keyword extraction with phrase detection, synonym mapping, and negative filtering.
 *
 * Processing pipeline:
 * 1. Expand acronyms (DV -> domestic violence, POA -> power of attorney)
 * 2. Correct typos via Levenshtein distance against known vocabulary
 * 3. Detect and replace multi-word phrases with underscore-joined tokens
 * 4. Apply synonym mapping to normalize variations (including Spanish, typos)
 * 5. Extract keywords from normalized text
 * 6. Check negative keywords to prevent misroutes
 */
class KeywordExtractor {

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Phrase list (multi-word terms to detect as units).
   *
   * @var array
   */
  protected $phrases = [];

  /**
   * Synonym map per intent.
   *
   * @var array
   */
  protected $synonyms = [];

  /**
   * Negative keywords per intent.
   *
   * @var array
   */
  protected $negatives = [];

  /**
   * High-risk triggers.
   *
   * @var array
   */
  protected $highRiskTriggers = [];

  /**
   * High-risk info dampeners (prevent false positives on informational queries).
   *
   * @var array
   */
  protected $highRiskDampeners = [];

  /**
   * Out-of-scope triggers.
   *
   * @var array
   */
  protected $outOfScopeTriggers = [];

  /**
   * Module path.
   *
   * @var string
   */
  protected $modulePath;

  /**
   * The acronym expander service.
   *
   * @var \Drupal\ilas_site_assistant\Service\AcronymExpander|null
   */
  protected $acronymExpander;

  /**
   * The typo corrector service.
   *
   * @var \Drupal\ilas_site_assistant\Service\TypoCorrector|null
   */
  protected $typoCorrector;

  /**
   * Constructs a KeywordExtractor object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\ilas_site_assistant\Service\AcronymExpander|null $acronym_expander
   *   Optional acronym expander service.
   * @param \Drupal\ilas_site_assistant\Service\TypoCorrector|null $typo_corrector
   *   Optional typo corrector service.
   */
  public function __construct(CacheBackendInterface $cache, AcronymExpander $acronym_expander = NULL, TypoCorrector $typo_corrector = NULL) {
    $this->cache = $cache;
    $this->acronymExpander = $acronym_expander;
    $this->typoCorrector = $typo_corrector;
    $this->modulePath = \Drupal::service('extension.list.module')->getPath('ilas_site_assistant');
    $this->loadConfigurations();
  }

  /**
   * Loads configuration files.
   */
  protected function loadConfigurations() {
    $cache_id = 'ilas_site_assistant:keyword_extractor_config';

    if ($cached = $this->cache->get($cache_id)) {
      $config = $cached->data;
      $this->phrases = $config['phrases'];
      $this->synonyms = $config['synonyms'];
      $this->negatives = $config['negatives'];
      $this->highRiskTriggers = $config['high_risk_triggers'];
      $this->highRiskDampeners = $config['high_risk_dampeners'] ?? [];
      $this->outOfScopeTriggers = $config['out_of_scope_triggers'];
      return;
    }

    $config_path = $this->modulePath . '/config/routing';

    // Load phrases.
    $phrases_file = $config_path . '/phrases.yml';
    if (file_exists($phrases_file)) {
      $data = Yaml::parseFile($phrases_file);
      $this->phrases = $data['phrases'] ?? [];
      // Sort by length descending (longer phrases first).
      usort($this->phrases, function ($a, $b) {
        return strlen($b) - strlen($a);
      });
    }

    // Load synonyms.
    $synonyms_file = $config_path . '/synonyms.yml';
    if (file_exists($synonyms_file)) {
      $this->synonyms = Yaml::parseFile($synonyms_file);
    }

    // Load negatives.
    $negatives_file = $config_path . '/negatives.yml';
    if (file_exists($negatives_file)) {
      $data = Yaml::parseFile($negatives_file);
      foreach ($data as $intent => $config) {
        if ($intent === 'out_of_scope') {
          $this->outOfScopeTriggers = $config['triggers'] ?? [];
        }
        elseif (strpos($intent, 'high_risk_') === 0) {
          $this->highRiskTriggers[$intent] = $config['triggers'] ?? [];
          // Also load info_dampeners if present.
          if (isset($config['info_dampeners'])) {
            $this->highRiskDampeners[$intent] = $config['info_dampeners'];
          }
        }
        elseif (isset($config['negatives'])) {
          $this->negatives[$intent] = $config['negatives'];
        }
      }
    }

    // Cache for 1 hour.
    $this->cache->set($cache_id, [
      'phrases' => $this->phrases,
      'synonyms' => $this->synonyms,
      'negatives' => $this->negatives,
      'high_risk_triggers' => $this->highRiskTriggers,
      'high_risk_dampeners' => $this->highRiskDampeners,
      'out_of_scope_triggers' => $this->outOfScopeTriggers,
    ], time() + 3600);
  }

  /**
   * Processes a message through the extraction pipeline.
   *
   * @param string $message
   *   The raw user message.
   *
   * @return array
   *   Extraction result with keys:
   *   - 'original': Original message
   *   - 'normalized': Message after phrase and synonym processing
   *   - 'phrases_found': Array of detected phrases
   *   - 'synonyms_applied': Array of synonym substitutions made
   *   - 'keywords': Array of extracted keywords
   *   - 'high_risk': High-risk category if detected, or NULL
   *   - 'out_of_scope': TRUE if out-of-scope triggers detected
   */
  public function extract(string $message): array {
    $result = [
      'original' => $message,
      'normalized' => '',
      'phrases_found' => [],
      'synonyms_applied' => [],
      'acronyms_expanded' => [],
      'typos_corrected' => [],
      'keywords' => [],
      'high_risk' => NULL,
      'out_of_scope' => FALSE,
    ];

    $text = strtolower(trim($message));

    // Step 1: Check for high-risk triggers FIRST (before any normalization).
    $result['high_risk'] = $this->detectHighRisk($text);

    // Step 2: Check for out-of-scope triggers.
    $result['out_of_scope'] = $this->detectOutOfScope($text);

    // Step 3: Expand acronyms (DV -> domestic violence, etc.).
    if ($this->acronymExpander) {
      $acronym_result = $this->acronymExpander->expand($text);
      $text = $acronym_result['text'];
      $result['acronyms_expanded'] = $acronym_result['expansions'];

      // Re-check high-risk after acronym expansion (e.g., "DV" -> "domestic violence").
      if (!$result['high_risk']) {
        $result['high_risk'] = $this->detectHighRisk($text);
      }
    }

    // Step 4: Correct typos (custdy -> custody, divorse -> divorce, etc.).
    if ($this->typoCorrector) {
      $typo_result = $this->typoCorrector->correct($text);
      $text = $typo_result['text'];
      $result['typos_corrected'] = $typo_result['corrections'];
    }

    // Step 5: Detect and replace phrases.
    [$text, $phrases_found] = $this->detectPhrases($text);
    $result['phrases_found'] = $phrases_found;

    // Step 6: Apply synonym mapping.
    [$text, $synonyms_applied] = $this->applySynonyms($text);
    $result['synonyms_applied'] = $synonyms_applied;

    $result['normalized'] = $text;

    // Step 7: Extract keywords (simple tokenization).
    $result['keywords'] = $this->extractKeywords($text);

    return $result;
  }

  /**
   * Detects and replaces multi-word phrases with underscore-joined tokens.
   *
   * @param string $text
   *   The input text (lowercase).
   *
   * @return array
   *   [processed_text, array_of_found_phrases]
   */
  protected function detectPhrases(string $text): array {
    $found = [];

    foreach ($this->phrases as $phrase) {
      $phrase_lower = strtolower($phrase);
      if (strpos($text, $phrase_lower) !== FALSE) {
        $found[] = $phrase;
        // Replace with underscore-joined version.
        $replacement = str_replace(' ', '_', $phrase_lower);
        $text = str_replace($phrase_lower, $replacement, $text);
      }
    }

    return [$text, $found];
  }

  /**
   * Applies synonym mapping to normalize text.
   *
   * @param string $text
   *   The input text.
   *
   * @return array
   *   [processed_text, array_of_applied_synonyms]
   */
  protected function applySynonyms(string $text): array {
    $applied = [];

    foreach ($this->synonyms as $intent => $mappings) {
      foreach ($mappings as $canonical => $variations) {
        foreach ($variations as $variation) {
          $variation_lower = strtolower($variation);
          // Use word boundary matching for whole words.
          $pattern = '/\b' . preg_quote($variation_lower, '/') . '\b/i';
          if (preg_match($pattern, $text)) {
            $applied[] = [
              'from' => $variation,
              'to' => $canonical,
              'intent' => $intent,
            ];
            $text = preg_replace($pattern, $canonical, $text);
          }
        }
      }
    }

    return [$text, $applied];
  }

  /**
   * Extracts keywords from processed text.
   *
   * @param string $text
   *   The normalized text.
   *
   * @return array
   *   Array of keywords.
   */
  protected function extractKeywords(string $text): array {
    // Remove punctuation except underscores (from phrases).
    $text = preg_replace('/[^\w\s_]/', ' ', $text);

    // Split on whitespace.
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

    // Filter out common stop words.
    $stop_words = [
      'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
      'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
      'should', 'may', 'might', 'must', 'shall', 'can', 'need', 'dare',
      'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by', 'from', 'as',
      'into', 'through', 'during', 'before', 'after', 'above', 'below',
      'between', 'under', 'again', 'further', 'then', 'once', 'here',
      'there', 'when', 'where', 'why', 'how', 'all', 'each', 'few',
      'more', 'most', 'other', 'some', 'such', 'no', 'nor', 'not',
      'only', 'own', 'same', 'so', 'than', 'too', 'very', 'just',
      'and', 'but', 'if', 'or', 'because', 'until', 'while',
      'i', 'me', 'my', 'myself', 'we', 'our', 'ours', 'ourselves',
      'you', 'your', 'yours', 'yourself', 'yourselves', 'he', 'him',
      'his', 'himself', 'she', 'her', 'hers', 'herself', 'it', 'its',
      'itself', 'they', 'them', 'their', 'theirs', 'themselves',
      'what', 'which', 'who', 'whom', 'this', 'that', 'these', 'those',
      'am', 'about', 'any', 'both', 'down', 'up', 'out', 'off', 'over',
      'u', 'r', 'ur', 'im', 'ive', 'dont', 'cant', 'wont', 'didnt',
      // Spanish stop words
      'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'de', 'del',
      'en', 'con', 'para', 'por', 'que', 'es', 'son', 'como', 'yo', 'mi',
      'tu', 'su', 'nos', 'se', 'le', 'les', 'al', 'lo', 'mas', 'pero',
    ];

    return array_values(array_filter($words, function ($word) use ($stop_words) {
      return strlen($word) > 1 && !in_array($word, $stop_words);
    }));
  }

  /**
   * Checks if an intent should be blocked by negative keywords.
   *
   * @param string $intent
   *   The intent to check.
   * @param string $text
   *   The message text (lowercase).
   *
   * @return bool
   *   TRUE if a negative keyword is present (intent should be blocked).
   */
  public function hasNegativeKeyword(string $intent, string $text): bool {
    $text = strtolower($text);

    if (!isset($this->negatives[$intent])) {
      return FALSE;
    }

    foreach ($this->negatives[$intent] as $negative) {
      if (strpos($text, strtolower($negative)) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Detects high-risk situations from text.
   *
   * @param string $text
   *   The message text (lowercase).
   *
   * @return string|null
   *   High-risk category ('high_risk_dv', 'high_risk_eviction', etc.) or NULL.
   */
  protected function detectHighRisk(string $text): ?string {
    foreach ($this->highRiskTriggers as $category => $triggers) {
      foreach ($triggers as $trigger) {
        if (strpos($text, strtolower($trigger)) !== FALSE) {
          // Check if info dampeners should suppress this match.
          // Dampeners prevent false positives on purely informational queries.
          if ($this->isDampened($text, $category)) {
            continue;
          }
          return $category;
        }
      }
    }
    return NULL;
  }

  /**
   * Checks if a high-risk match should be dampened (suppressed).
   *
   * Info dampeners prevent triggering on informational queries like
   * "how long do I have to respond" which are asking about deadlines
   * but not reporting an actual urgent deadline.
   *
   * @param string $text
   *   The message text (lowercase).
   * @param string $category
   *   The high-risk category.
   *
   * @return bool
   *   TRUE if the match should be suppressed.
   */
  protected function isDampened(string $text, string $category): bool {
    if (!isset($this->highRiskDampeners[$category])) {
      return FALSE;
    }

    foreach ($this->highRiskDampeners[$category] as $dampener) {
      if (strpos($text, strtolower($dampener)) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Detects out-of-scope requests.
   *
   * @param string $text
   *   The message text (lowercase).
   *
   * @return bool
   *   TRUE if out-of-scope trigger detected.
   */
  protected function detectOutOfScope(string $text): bool {
    foreach ($this->outOfScopeTriggers as $trigger) {
      if (strpos($text, strtolower($trigger)) !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gets the raw phrases list.
   *
   * @return array
   *   The phrases.
   */
  public function getPhrases(): array {
    return $this->phrases;
  }

  /**
   * Gets the raw synonyms map.
   *
   * @return array
   *   The synonyms.
   */
  public function getSynonyms(): array {
    return $this->synonyms;
  }

  /**
   * Gets the raw negatives map.
   *
   * @return array
   *   The negatives.
   */
  public function getNegatives(): array {
    return $this->negatives;
  }

  /**
   * Clears the configuration cache.
   */
  public function clearCache(): void {
    $this->cache->delete('ilas_site_assistant:keyword_extractor_config');
    $this->loadConfigurations();
  }

}
