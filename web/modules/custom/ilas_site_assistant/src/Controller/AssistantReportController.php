<?php

namespace Drupal\ilas_site_assistant\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Drupal\ilas_site_assistant\Service\TopicResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Site Assistant admin reports.
 */
class AssistantReportController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The topic resolver.
   *
   * @var \Drupal\ilas_site_assistant\Service\TopicResolver
   */
  protected $topicResolver;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an AssistantReportController object.
   */
  public function __construct(Connection $database, DateFormatterInterface $date_formatter, TopicResolver $topic_resolver, ConfigFactoryInterface $config_factory) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->topicResolver = $topic_resolver;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('ilas_site_assistant.topic_resolver'),
      $container->get('config.factory')
    );
  }

  /**
   * Renders the admin report page.
   *
   * @return array
   *   A render array.
   */
  public function report() {
    $build = [];

    // Summary stats.
    $build['summary'] = [
      '#type' => 'details',
      '#title' => $this->t('Summary Statistics'),
      '#open' => TRUE,
    ];

    $build['summary']['table'] = $this->buildSummaryTable();

    // Top topics.
    $build['topics'] = [
      '#type' => 'details',
      '#title' => $this->t('Top Topics Selected'),
      '#open' => TRUE,
    ];

    $build['topics']['table'] = $this->buildTopTopicsTable();

    // Top destinations.
    $build['destinations'] = [
      '#type' => 'details',
      '#title' => $this->t('Top Clicked Destinations'),
      '#open' => TRUE,
    ];

    $build['destinations']['table'] = $this->buildTopDestinationsTable();

    // No-answer queries.
    $build['no_answer'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Gaps (No-Answer Queries)'),
      '#open' => TRUE,
    ];

    $build['no_answer']['description'] = [
      '#markup' => '<p>' . $this->t('Queries that did not find matching content. Raw query text is intentionally not stored; this report uses hashes and low-cardinality metadata so content gaps can be analyzed without persisting user text.') . '</p>',
    ];

    $build['no_answer']['table'] = $this->buildNoAnswerTable();

    // Quality signals.
    $build['quality'] = [
      '#type' => 'details',
      '#title' => $this->t('Quality Signals'),
      '#open' => TRUE,
    ];

    $build['quality']['description'] = [
      '#markup' => '<p>' . $this->t('Failure-mode event counts. Rising trends indicate retrieval, safety, or grounding degradation.') . '</p>',
    ];

    $build['quality']['table'] = $this->buildQualitySignalsTable();

    // User feedback.
    $build['feedback'] = [
      '#type' => 'details',
      '#title' => $this->t('User Feedback'),
      '#open' => TRUE,
    ];

    $build['feedback']['summary'] = $this->buildFeedbackSummaryTable();
    $build['feedback']['breakdown'] = $this->buildFeedbackBreakdownTable();

    // Review loop.
    $build['review_loop'] = [
      '#type' => 'details',
      '#title' => $this->t('Review Loop'),
      '#open' => TRUE,
    ];

    $build['review_loop']['content'] = $this->buildReviewLoopSection();

    return $build;
  }

  /**
   * Builds the summary statistics table.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildSummaryTable() {
    $header = [
      $this->t('Metric'),
      $this->t('Last 7 Days'),
      $this->t('Last 30 Days'),
      $this->t('All Time'),
    ];

    $rows = [];

    // Chat opens.
    $rows[] = [
      $this->t('Chats Opened'),
      $this->getEventCount('chat_open', 7),
      $this->getEventCount('chat_open', 30),
      $this->getEventCount('chat_open', NULL),
    ];

    // Topics selected.
    $rows[] = [
      $this->t('Topics Selected'),
      $this->getEventCount('topic_selected', 7),
      $this->getEventCount('topic_selected', 30),
      $this->getEventCount('topic_selected', NULL),
    ];

    // Resource clicks.
    $rows[] = [
      $this->t('Resource Clicks'),
      $this->getEventCount('resource_click', 7),
      $this->getEventCount('resource_click', 30),
      $this->getEventCount('resource_click', NULL),
    ];

    // Apply clicks.
    $rows[] = [
      $this->t('Apply Clicks'),
      $this->getEventCount('apply_click', 7),
      $this->getEventCount('apply_click', 30),
      $this->getEventCount('apply_click', NULL),
    ];

    // Hotline clicks.
    $rows[] = [
      $this->t('Hotline Clicks'),
      $this->getEventCount('hotline_click', 7),
      $this->getEventCount('hotline_click', 30),
      $this->getEventCount('hotline_click', NULL),
    ];

    // No-answer queries.
    $rows[] = [
      $this->t('No-Answer Queries'),
      $this->getEventCount('no_answer', 7),
      $this->getEventCount('no_answer', 30),
      $this->getEventCount('no_answer', NULL),
    ];

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No data available yet.'),
    ];
  }

  /**
   * Builds the top topics table.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildTopTopicsTable() {
    $header = [
      $this->t('Topic'),
      $this->t('Count'),
    ];

    $query = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'topic_selected')
      ->groupBy('event_value')
      ->orderBy('total', 'DESC')
      ->range(0, 10);
    $query->addExpression('SUM(count)', 'total');

    $results = $query->execute()->fetchAll();
    $topics = $this->topicResolver->getAllTopics();

    $rows = [];
    foreach ($results as $row) {
      $label = $this->t('(unknown)');
      if ($row->event_value !== '' && isset($topics[(int) $row->event_value]['name'])) {
        $label = $topics[(int) $row->event_value]['name'] . ' (' . $row->event_value . ')';
      }
      elseif ($row->event_value !== '') {
        $label = $row->event_value;
      }

      $rows[] = [
        $label,
        $row->total,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No topic data available yet.'),
    ];
  }

  /**
   * Builds the top destinations table.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildTopDestinationsTable() {
    $header = [
      $this->t('URL Path'),
      $this->t('Count'),
    ];

    $query = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'resource_click')
      ->groupBy('event_value')
      ->orderBy('total', 'DESC')
      ->range(0, 10);
    $query->addExpression('SUM(count)', 'total');

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $rows[] = [
        $row->event_value ?: $this->t('(unknown)'),
        $row->total,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No destination data available yet.'),
    ];
  }

  /**
   * Builds the no-answer queries table.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildNoAnswerTable() {
    $header = [
      $this->t('Query Fingerprint'),
      $this->t('Language'),
      $this->t('Length'),
      $this->t('Redaction Profile'),
      $this->t('Count'),
      $this->t('Last Seen'),
    ];

    $query = $this->database->select('ilas_site_assistant_no_answer', 'n')
      ->fields('n', ['query_hash', 'language_hint', 'length_bucket', 'redaction_profile', 'count', 'last_seen'])
      ->orderBy('count', 'DESC')
      ->range(0, 20);

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $rows[] = [
        ObservabilityPayloadMinimizer::hashPrefix($row->query_hash) . '...',
        $row->language_hint,
        $row->length_bucket,
        $row->redaction_profile,
        $row->count,
        $this->dateFormatter->format($row->last_seen, 'short'),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No unmatched queries recorded yet.'),
    ];
  }

  /**
   * Gets the count for an event type within a date range.
   *
   * @param string $event_type
   *   The event type.
   * @param int|null $days
   *   Number of days to look back, or NULL for all time.
   *
   * @return int
   *   The count.
   */
  protected function getEventCount(string $event_type, ?int $days) {
    $query = $this->database->select('ilas_site_assistant_stats', 's')
      ->condition('event_type', $event_type);
    $query->addExpression('SUM(count)', 'total');

    if ($days !== NULL) {
      $start_date = date('Y-m-d', strtotime("-{$days} days"));
      $query->condition('date', $start_date, '>=');
    }

    $result = $query->execute()->fetchField();
    return (int) $result;
  }

  /**
   * Builds the quality signals table.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildQualitySignalsTable() {
    $header = [
      $this->t('Signal'),
      $this->t('Last 7 Days'),
      $this->t('Last 30 Days'),
      $this->t('All Time'),
    ];

    $signals = [
      'no_answer' => $this->t('No-Answer Queries'),
      'generic_answer' => $this->t('Generic Answers'),
      'grounding_refusal' => $this->t('Grounding Refusals'),
      'safety_violation' => $this->t('Safety Violations'),
      'out_of_scope' => $this->t('Out-of-Scope'),
      'policy_violation' => $this->t('Policy Violations'),
      'post_gen_safety_legal_advice' => $this->t('Post-Gen: Legal Advice'),
      'post_gen_safety_weak_grounding' => $this->t('Post-Gen: Weak Grounding'),
      'post_gen_stale_citations' => $this->t('Post-Gen: Stale Citations'),
    ];

    $rows = [];
    foreach ($signals as $event_type => $label) {
      $rows[] = [
        $label,
        $this->getEventCount($event_type, 7),
        $this->getEventCount($event_type, 30),
        $this->getEventCount($event_type, NULL),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No quality signal data available yet.'),
    ];
  }

  /**
   * Builds the feedback summary table.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildFeedbackSummaryTable() {
    $header = [
      $this->t('Metric'),
      $this->t('Last 7 Days'),
      $this->t('Last 30 Days'),
      $this->t('All Time'),
    ];

    $rows = [];

    $rows[] = [
      $this->t('Helpful'),
      $this->getEventCount('feedback_helpful', 7),
      $this->getEventCount('feedback_helpful', 30),
      $this->getEventCount('feedback_helpful', NULL),
    ];

    $rows[] = [
      $this->t('Not Helpful'),
      $this->getEventCount('feedback_not_helpful', 7),
      $this->getEventCount('feedback_not_helpful', 30),
      $this->getEventCount('feedback_not_helpful', NULL),
    ];

    // Satisfaction rate.
    $helpful_all = $this->getEventCount('feedback_helpful', NULL);
    $not_helpful_all = $this->getEventCount('feedback_not_helpful', NULL);
    $total_all = $helpful_all + $not_helpful_all;

    $helpful_7 = $this->getEventCount('feedback_helpful', 7);
    $not_helpful_7 = $this->getEventCount('feedback_not_helpful', 7);
    $total_7 = $helpful_7 + $not_helpful_7;

    $helpful_30 = $this->getEventCount('feedback_helpful', 30);
    $not_helpful_30 = $this->getEventCount('feedback_not_helpful', 30);
    $total_30 = $helpful_30 + $not_helpful_30;

    $rows[] = [
      $this->t('Satisfaction Rate'),
      $total_7 > 0 ? round($helpful_7 / $total_7 * 100) . '%' : $this->t('N/A'),
      $total_30 > 0 ? round($helpful_30 / $total_30 * 100) . '%' : $this->t('N/A'),
      $total_all > 0 ? round($helpful_all / $total_all * 100) . '%' : $this->t('N/A'),
    ];

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No feedback data available yet.'),
    ];
  }

  /**
   * Builds a feedback breakdown table by response type.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildFeedbackBreakdownTable() {
    $header = [
      $this->t('Response Type'),
      $this->t('Helpful'),
      $this->t('Not Helpful'),
      $this->t('Satisfaction'),
    ];

    // Gather helpful counts by response type.
    $helpful_query = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'feedback_helpful')
      ->groupBy('event_value');
    $helpful_query->addExpression('SUM(count)', 'total');
    $helpful_results = $helpful_query->execute()->fetchAllKeyed();

    // Gather not-helpful counts by response type.
    $not_helpful_query = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'feedback_not_helpful')
      ->groupBy('event_value');
    $not_helpful_query->addExpression('SUM(count)', 'total');
    $not_helpful_results = $not_helpful_query->execute()->fetchAllKeyed();

    // Merge and sort by total feedback volume.
    $response_types = array_unique(array_merge(array_keys($helpful_results), array_keys($not_helpful_results)));
    $merged = [];
    foreach ($response_types as $type) {
      $h = (int) ($helpful_results[$type] ?? 0);
      $nh = (int) ($not_helpful_results[$type] ?? 0);
      $merged[$type] = ['helpful' => $h, 'not_helpful' => $nh, 'total' => $h + $nh];
    }
    uasort($merged, function ($a, $b) {
      return $b['total'] - $a['total'];
    });

    $rows = [];
    $count = 0;
    foreach ($merged as $type => $data) {
      if ($count >= 10) {
        break;
      }
      $satisfaction = $data['total'] > 0 ? round($data['helpful'] / $data['total'] * 100) . '%' : $this->t('N/A');
      $rows[] = [
        $type ?: $this->t('(unknown)'),
        $data['helpful'],
        $data['not_helpful'],
        $satisfaction,
      ];
      $count++;
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No per-type feedback data available yet.'),
    ];
  }

  /**
   * Builds the review loop ownership section.
   *
   * @return array
   *   Render array with review loop info.
   */
  protected function buildReviewLoopSection() {
    $config = $this->configFactory->get('ilas_site_assistant.settings');
    $review = $config->get('review_loop') ?? [];

    $owner = $review['owner_role'] ?? $this->t('Not assigned');
    $cadence = $review['cadence'] ?? $this->t('Not defined');
    $scope = $review['scope'] ?? [];
    $escalation = $review['escalation_path'] ?? $this->t('Not defined');
    $artifact = $review['artifact_location'] ?? $this->t('Not defined');

    $scope_html = '';
    if (!empty($scope)) {
      $scope_html = '<ul>';
      foreach ($scope as $item) {
        $scope_html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
      }
      $scope_html .= '</ul>';
    }

    $html = '<dl>';
    $html .= '<dt><strong>' . $this->t('Owner Role') . '</strong></dt>';
    $html .= '<dd>' . htmlspecialchars((string) $owner, ENT_QUOTES, 'UTF-8') . '</dd>';
    $html .= '<dt><strong>' . $this->t('Review Cadence') . '</strong></dt>';
    $html .= '<dd>' . htmlspecialchars((string) $cadence, ENT_QUOTES, 'UTF-8') . '</dd>';
    $html .= '<dt><strong>' . $this->t('Review Scope') . '</strong></dt>';
    $html .= '<dd>' . ($scope_html ?: $this->t('Not defined')) . '</dd>';
    $html .= '<dt><strong>' . $this->t('Escalation Path') . '</strong></dt>';
    $html .= '<dd>' . htmlspecialchars((string) $escalation, ENT_QUOTES, 'UTF-8') . '</dd>';
    $html .= '<dt><strong>' . $this->t('Follow-Up Artifacts') . '</strong></dt>';
    $html .= '<dd><code>' . htmlspecialchars((string) $artifact, ENT_QUOTES, 'UTF-8') . '</code></dd>';
    $html .= '</dl>';

    return [
      '#markup' => $html,
    ];
  }

}
