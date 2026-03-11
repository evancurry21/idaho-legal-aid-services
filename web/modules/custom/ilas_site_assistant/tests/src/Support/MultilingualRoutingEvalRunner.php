<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Support;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Service\Disambiguator;
use Drupal\ilas_site_assistant\Service\HistoryIntentResolver;
use Drupal\ilas_site_assistant\Service\InputNormalizer;
use Drupal\ilas_site_assistant\Service\IntentRouter;
use Drupal\ilas_site_assistant\Service\KeywordExtractor;
use Drupal\ilas_site_assistant\Service\NavigationIntent;
use Drupal\ilas_site_assistant\Service\OutOfScopeClassifier;
use Drupal\ilas_site_assistant\Service\PolicyFilter;
use Drupal\ilas_site_assistant\Service\PreRoutingDecisionEngine;
use Drupal\ilas_site_assistant\Service\ResponseBuilder;
use Drupal\ilas_site_assistant\Service\SafetyClassifier;
use Drupal\ilas_site_assistant\Service\TopicResolver;
use Drupal\ilas_site_assistant\Service\TopicRouter;
use Drupal\ilas_site_assistant\Service\TopIntentsPack;
use Drupal\ilas_site_assistant\Service\TurnClassifier;

/**
 * Shared offline evaluator for multilingual routing and actionability.
 */
final class MultilingualRoutingEvalRunner {

  private const DEFAULT_NOW = 1700000000;

  private IntentRouter $router;

  private ResponseBuilder $responseBuilder;

  private PreRoutingDecisionEngine $preRoutingDecisionEngine;

  /**
   * @var array<int, array<string, mixed>>
   */
  private array $fixtures;

  /**
   * @param array<int, array<string, mixed>>|null $fixtures
   *   Optional fixture array override.
   */
  public function __construct(?array $fixtures = NULL) {
    $translation = new MultilingualRoutingEvalTranslation();
    $configFactory = new MultilingualRoutingEvalConfigFactory();
    $policyFilter = new MultilingualRoutingEvalPolicyFilter($configFactory);
    $policyFilter->setStringTranslation($translation);

    $this->preRoutingDecisionEngine = new PreRoutingDecisionEngine(
      $policyFilter,
      new SafetyClassifier($configFactory),
      new OutOfScopeClassifier($configFactory),
    );

    $topicResolver = new MultilingualRoutingEvalTopicResolver();
    $keywordExtractor = new MultilingualRoutingEvalKeywordExtractor();
    $topIntentsPack = new TopIntentsPack(NULL);
    $navigationIntent = NavigationIntent::fromYaml(self::moduleRoot() . '/config/routing/navigation_pages.yml');

    $this->router = new IntentRouter(
      $configFactory,
      $topicResolver,
      $keywordExtractor,
      new TopicRouter(),
      $navigationIntent,
      new Disambiguator(),
      $topIntentsPack,
    );
    $this->router->setStringTranslation($translation);

    $this->responseBuilder = new ResponseBuilder([], $topIntentsPack);
    $this->fixtures = $fixtures ?? self::loadFixtures();
  }

  /**
   * Returns the fixture file path.
   */
  public static function fixturePath(): string {
    return dirname(__DIR__, 2) . '/fixtures/multilingual-routing-eval-cases.json';
  }

  /**
   * Loads multilingual eval fixtures from disk.
   *
   * @return array<int, array<string, mixed>>
   *   Parsed fixture cases.
   */
  public static function loadFixtures(): array {
    $path = self::fixturePath();
    if (!is_file($path)) {
      throw new \RuntimeException("Fixture file not found: {$path}");
    }

    $decoded = json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
      throw new \RuntimeException("Fixture file did not decode to an array: {$path}");
    }

    return $decoded;
  }

  /**
   * Evaluates all multilingual routing fixtures.
   *
   * @return array<string, mixed>
   *   Structured report.
   */
  public function run(): array {
    $results = [];
    $passed = 0;

    foreach ($this->fixtures as $case) {
      $result = $this->evaluateCase($case);
      $results[] = $result;
      if ($result['passed']) {
        $passed++;
      }
    }

    return [
      'generated_at_utc' => gmdate('c'),
      'fixture_path' => self::fixturePath(),
      'total_cases' => count($results),
      'passed_cases' => $passed,
      'failed_cases' => count($results) - $passed,
      'pass_rate' => count($results) > 0 ? round(($passed / count($results)) * 100, 2) : 0.0,
      'results' => $results,
    ];
  }

  /**
   * Evaluates one multilingual fixture case.
   *
   * @param array<string, mixed> $case
   *   Fixture row.
   *
   * @return array<string, mixed>
   *   Case result.
   */
  public function evaluateCase(array $case): array {
    $message = (string) ($case['message'] ?? '');
    $history = is_array($case['history'] ?? NULL) ? $case['history'] : [];
    $expected = is_array($case['expected'] ?? NULL) ? $case['expected'] : [];
    $now = (int) ($case['now'] ?? self::DEFAULT_NOW);
    $normalized = InputNormalizer::normalize($message);
    $failures = [];

    $preRoutingDecision = $this->preRoutingDecisionEngine->evaluate($normalized);
    $turnType = TurnClassifier::classifyTurn($message, $history, $now);
    $intent = NULL;
    $response = NULL;

    if (($expected['decision_type'] ?? NULL) !== NULL) {
      $this->assertSameValue('decision_type', $expected['decision_type'], $preRoutingDecision['decision_type'], $failures);
    }
    if (($expected['turn_type'] ?? NULL) !== NULL) {
      $this->assertSameValue('turn_type', $expected['turn_type'], $turnType, $failures);
    }
    if (($expected['override_risk_category'] ?? NULL) !== NULL) {
      $actualRisk = $preRoutingDecision['routing_override_intent']['risk_category'] ?? NULL;
      $this->assertSameValue('override_risk_category', $expected['override_risk_category'], $actualRisk, $failures);
    }

    if (($preRoutingDecision['decision_type'] ?? NULL) === PreRoutingDecisionEngine::DECISION_CONTINUE) {
      $intent = $this->resolveIntent($message, $history, $turnType, $now);

      if (!empty($preRoutingDecision['routing_override_intent']) && ($expected['override_risk_category'] ?? NULL) !== NULL) {
        $intent = $preRoutingDecision['routing_override_intent'] + [
          'source' => 'pre_routing_override',
          'confidence' => (float) ($preRoutingDecision['routing_override_intent']['confidence'] ?? 1.0),
          'extraction' => [],
        ];
      }

      $response = $this->responseBuilder->buildFromIntent($intent, $message);
    }

    if (($expected['intent_type'] ?? NULL) !== NULL) {
      $this->assertSameValue('intent_type', $expected['intent_type'], $intent['type'] ?? NULL, $failures);
    }
    if (($expected['intent_source'] ?? NULL) !== NULL) {
      $this->assertSameValue('intent_source', $expected['intent_source'], $intent['source'] ?? NULL, $failures);
    }
    if (($expected['response_mode'] ?? NULL) !== NULL) {
      $this->assertSameValue('response_mode', $expected['response_mode'], $response['response_mode'] ?? NULL, $failures);
    }
    if (($expected['primary_action_url'] ?? NULL) !== NULL) {
      $this->assertSameValue('primary_action_url', $expected['primary_action_url'], $response['primary_action']['url'] ?? NULL, $failures);
    }
    if (($expected['primary_action_url_contains'] ?? NULL) !== NULL) {
      $actualUrl = (string) ($response['primary_action']['url'] ?? '');
      if (!str_contains($actualUrl, (string) $expected['primary_action_url_contains'])) {
        $failures[] = sprintf(
          "primary_action_url_contains expected fragment '%s', got '%s'",
          $expected['primary_action_url_contains'],
          $actualUrl
        );
      }
    }
    if (($expected['secondary_actions_min'] ?? NULL) !== NULL) {
      $actualCount = count($response['secondary_actions'] ?? []);
      if ($actualCount < (int) $expected['secondary_actions_min']) {
        $failures[] = sprintf(
          'secondary_actions_min expected >= %d, got %d',
          (int) $expected['secondary_actions_min'],
          $actualCount
        );
      }
    }
    if (!empty($expected['option_intents'])) {
      $actualOptionIntents = array_values(array_filter(array_map(
        static fn(array $option): string => (string) ($option['intent'] ?? ''),
        is_array($intent['options'] ?? NULL) ? $intent['options'] : []
      )));
      foreach ($expected['option_intents'] as $optionIntent) {
        if (!in_array($optionIntent, $actualOptionIntents, TRUE)) {
          $failures[] = sprintf(
            "option_intents missing '%s' in [%s]",
            $optionIntent,
            implode(', ', $actualOptionIntents)
          );
        }
      }
    }
    if (($expected['question_contains'] ?? NULL) !== NULL) {
      $actualQuestion = (string) ($intent['question'] ?? '');
      if (!str_contains($actualQuestion, (string) $expected['question_contains'])) {
        $failures[] = sprintf(
          "question_contains expected fragment '%s', got '%s'",
          $expected['question_contains'],
          $actualQuestion
        );
      }
    }

    return [
      'id' => (string) ($case['id'] ?? 'unknown'),
      'message' => $message,
      'passed' => $failures === [],
      'failures' => $failures,
      'turn_type' => $turnType,
      'decision_type' => $preRoutingDecision['decision_type'] ?? NULL,
      'override_risk_category' => $preRoutingDecision['routing_override_intent']['risk_category'] ?? NULL,
      'intent_type' => $intent['type'] ?? NULL,
      'intent_source' => $intent['source'] ?? NULL,
      'response_mode' => $response['response_mode'] ?? NULL,
      'reason_code' => $response['reason_code'] ?? NULL,
      'primary_action_url' => $response['primary_action']['url'] ?? NULL,
      'option_intents' => array_values(array_filter(array_map(
        static fn(array $option): string => (string) ($option['intent'] ?? ''),
        is_array($intent['options'] ?? NULL) ? $intent['options'] : []
      ))),
    ];
  }

  /**
   * Resolves an intent using the production-like pure-PHP routing stack.
   *
   * @param array<int, array<string, mixed>> $history
   *   Conversation history.
   *
   * @return array<string, mixed>
   *   Routed intent.
   */
  private function resolveIntent(string $message, array $history, string $turnType, int $now): array {
    if ($turnType === TurnClassifier::TURN_INVENTORY) {
      return [
        'type' => TurnClassifier::resolveInventoryType($message),
        'confidence' => 0.90,
        'source' => 'turn_classifier_inventory',
        'extraction' => [],
      ];
    }

    $intent = $this->router->route($message, []);
    $directIntentType = $intent['type'] ?? 'unknown';

    if ($turnType === TurnClassifier::TURN_FOLLOW_UP && $history !== []) {
      $topicContext = HistoryIntentResolver::extractTopicContext($history);
      if ($topicContext !== NULL && !empty($topicContext['area'])) {
        if ($directIntentType === 'unknown') {
          $historyResult = HistoryIntentResolver::resolveFromHistory($history, $message, $now, []);
          if ($historyResult !== NULL) {
            return [
              'type' => $historyResult['intent'],
              'confidence' => min(0.65, (float) $historyResult['confidence']),
              'source' => 'history_fallback',
              'extraction' => $intent['extraction'] ?? [],
              'history_meta' => $historyResult,
              'area' => $historyResult['topic_context']['area'] ?? NULL,
              'topic_id' => $historyResult['topic_context']['topic_id'] ?? NULL,
              'topic' => $historyResult['topic_context']['topic'] ?? NULL,
            ];
          }
        }
        elseif (empty($intent['area'])) {
          $intent['area'] = $topicContext['area'];
          $intent['topic_id'] = $topicContext['topic_id'] ?? NULL;
          $intent['topic'] = $topicContext['topic'] ?? NULL;
        }
      }
    }
    elseif ($directIntentType === 'unknown' && $history !== []) {
      $historyResult = HistoryIntentResolver::resolveFromHistory($history, $message, $now, []);
      if ($historyResult !== NULL) {
        return [
          'type' => $historyResult['intent'],
          'confidence' => min(0.65, (float) $historyResult['confidence']),
          'source' => 'history_fallback',
          'extraction' => $intent['extraction'] ?? [],
          'history_meta' => $historyResult,
          'area' => $historyResult['topic_context']['area'] ?? NULL,
          'topic_id' => $historyResult['topic_context']['topic_id'] ?? NULL,
          'topic' => $historyResult['topic_context']['topic'] ?? NULL,
        ];
      }
    }

    return $intent;
  }

  /**
   * Records an assertion failure when actual and expected values differ.
   *
   * @param array<int, string> $failures
   *   Failure list, passed by reference.
   */
  private function assertSameValue(string $field, mixed $expected, mixed $actual, array &$failures): void {
    if ($expected !== $actual) {
      $failures[] = sprintf(
        "%s expected '%s', got '%s'",
        $field,
        $this->stringifyValue($expected),
        $this->stringifyValue($actual)
      );
    }
  }

  /**
   * Normalizes values for human-readable failure messages.
   */
  private function stringifyValue(mixed $value): string {
    if ($value === NULL) {
      return 'NULL';
    }

    if (is_bool($value)) {
      return $value ? 'TRUE' : 'FALSE';
    }

    if (is_scalar($value)) {
      return (string) $value;
    }

    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[unencodable]';
  }

  /**
   * Returns the module root path.
   */
  private static function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

}

/**
 * Minimal config object for pure-PHP eval wiring.
 */
final class MultilingualRoutingEvalConfig {

  /**
   * @param array<string, mixed> $values
   *   Config values.
   */
  public function __construct(private array $values) {}

  /**
   * Returns a configured value or NULL.
   */
  public function get(string $key): mixed {
    return $this->values[$key] ?? NULL;
  }

}

/**
 * Minimal config factory for pure-PHP eval wiring.
 */
final class MultilingualRoutingEvalConfigFactory implements ConfigFactoryInterface {

  /**
   * @var array<string, mixed>
   */
  private array $values = [
    'history_fallback' => ['enabled' => TRUE],
  ];

  /**
   * {@inheritdoc}
   */
  public function get($name) {
    return new MultilingualRoutingEvalConfig($this->values);
  }

  /**
   * {@inheritdoc}
   */
  public function getEditable($name) {
    return $this->get($name);
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $names) {
    $configs = [];
    foreach ($names as $name) {
      $configs[$name] = $this->get($name);
    }
    return $configs;
  }

  /**
   * {@inheritdoc}
   */
  public function reset($name = NULL) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function rename($old_name, $new_name) {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheKeys() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function clearStaticCache() {}

  /**
   * {@inheritdoc}
   */
  public function listAll($prefix = '') {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function addOverride($service) {
    return $this;
  }

}

/**
 * Identity translator for routing services that use StringTranslationTrait.
 */
final class MultilingualRoutingEvalTranslation implements TranslationInterface {

  /**
   * {@inheritdoc}
   */
  public function translate($string, array $args = [], array $options = []) {
    return strtr((string) $string, $this->normalizeArgs($args));
  }

  /**
   * {@inheritdoc}
   */
  public function translateString(TranslatableMarkup $translated_string) {
    return $this->translate($translated_string->getUntranslatedString(), $translated_string->getArguments());
  }

  /**
   * {@inheritdoc}
   */
  public function formatPlural($count, $singular, $plural, array $args = [], array $options = []) {
    $args['@count'] = $count;
    $template = ((int) $count === 1) ? $singular : $plural;
    return strtr((string) $template, $this->normalizeArgs($args));
  }

  /**
   * Normalizes placeholder values to strings.
   *
   * @param array<string, mixed> $args
   *   Placeholder values.
   *
   * @return array<string, string>
   *   String placeholder map.
   */
  private function normalizeArgs(array $args): array {
    $normalized = [];
    foreach ($args as $key => $value) {
      $normalized[$key] = (string) $value;
    }
    return $normalized;
  }

}

/**
 * Minimal topic resolver for pure-PHP routing evals.
 */
final class MultilingualRoutingEvalTopicResolver extends TopicResolver {

  /**
   * Constructs the eval topic resolver without Drupal dependencies.
   */
  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public function resolveFromText(string $text) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function searchTopics(string $query, int $limit = 5) {
    return [];
  }

}

/**
 * Minimal keyword extractor for pure-PHP routing evals.
 */
final class MultilingualRoutingEvalKeywordExtractor extends KeywordExtractor {

  /**
   * Constructs the eval keyword extractor without Drupal dependencies.
   */
  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public function extract(string $message): array {
    $normalized = mb_strtolower(trim($message));
    $keywords = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

    return [
      'original' => $message,
      'normalized' => $normalized,
      'phrases_found' => [],
      'synonyms_applied' => [],
      'acronyms_expanded' => [],
      'typos_corrected' => [],
      'keywords' => is_array($keywords) ? array_values($keywords) : [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function hasNegativeKeyword(string $intent, string $text): bool {
    return FALSE;
  }

}

/**
 * Policy filter variant that stays pure-PHP inside the offline evaluator.
 */
final class MultilingualRoutingEvalPolicyFilter extends PolicyFilter {

  /**
   * {@inheritdoc}
   */
  protected function getCanonicalUrls() {
    return ResponseBuilder::getDefaultCanonicalUrls();
  }

}
