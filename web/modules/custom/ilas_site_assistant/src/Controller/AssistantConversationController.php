<?php

namespace Drupal\ilas_site_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for viewing metadata-only conversation logs.
 *
 * All data displayed is minimized at write time by ConversationLogger.
 * This controller never accesses raw or redacted message bodies.
 */
class AssistantConversationController extends ControllerBase {

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
   * Constructs an AssistantConversationController object.
   */
  public function __construct(Connection $database, DateFormatterInterface $date_formatter) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter')
    );
  }

  /**
   * Lists conversations with filters and pagination.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   A render array.
   */
  public function list(Request $request) {
    $build = [];

    // Check if the table exists.
    if (!$this->database->schema()->tableExists('ilas_site_assistant_conversations')) {
      $build['empty'] = [
        '#markup' => $this->t('Conversation logging has not been initialized. Run database updates and enable conversation logging in the <a href=":url">Site Assistant settings</a>.', [
          ':url' => Url::fromRoute('ilas_site_assistant.admin.settings')->toString(),
        ]),
      ];
      return $build;
    }

    // Parse filter parameters.
    $filter_intent = $request->query->get('intent', '');
    $filter_date_from = $request->query->get('date_from', '');
    $filter_date_to = $request->query->get('date_to', '');

    // Build the filter form.
    $build['filters'] = $this->buildFilterForm($filter_intent, $filter_date_from, $filter_date_to);

    // Query for distinct conversations.
    $query = $this->database->select('ilas_site_assistant_conversations', 'c');
    $query->addField('c', 'conversation_id');
    $query->addExpression('MIN(c.created)', 'started');
    $query->addExpression('MAX(c.created)', 'last_msg');
    $query->addExpression('COUNT(c.id)', 'message_count');
    $query->groupBy('c.conversation_id');
    $query->orderBy('last_msg', 'DESC');

    // Apply filters.
    if (!empty($filter_intent)) {
      $query->condition('c.intent', $filter_intent);
    }
    if (!empty($filter_date_from)) {
      $from_ts = strtotime($filter_date_from);
      if ($from_ts !== FALSE) {
        $query->condition('c.created', $from_ts, '>=');
      }
    }
    if (!empty($filter_date_to)) {
      $to_ts = strtotime($filter_date_to . ' 23:59:59');
      if ($to_ts !== FALSE) {
        $query->condition('c.created', $to_ts, '<=');
      }
    }

    // Use a pager.
    $query = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->limit(25);

    $results = $query->execute();

    // Build the conversations table.
    $header = [
      $this->t('Conversation ID'),
      $this->t('Started'),
      $this->t('Last Message'),
      $this->t('Messages'),
      $this->t('Intents'),
      $this->t('Actions'),
    ];

    $rows = [];
    foreach ($results as $row) {
      // Get distinct intents for this conversation.
      $intents = $this->getConversationIntents($row->conversation_id);

      $rows[] = [
        substr($row->conversation_id, 0, 8) . '...',
        $this->dateFormatter->format($row->started, 'short'),
        $this->dateFormatter->format($row->last_msg, 'short'),
        // message_count includes both user + assistant rows, so divide by 2
        // for exchange count display.
        (int) ceil($row->message_count / 2) . ' exchanges',
        implode(', ', $intents),
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('View'),
            '#url' => Url::fromRoute('ilas_site_assistant.admin.conversation_detail', [
              'conversation_id' => $row->conversation_id,
            ]),
          ],
        ],
      ];
    }

    if (empty($rows)) {
      $build['empty_results'] = [
        '#markup' => '<p>' . $this->t('No conversations found matching the current filters.') . '</p>',
      ];
    }
    else {
      $build['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
      ];

      $build['pager'] = [
        '#type' => 'pager',
      ];
    }

    // Show total count.
    $total_query = $this->database->select('ilas_site_assistant_conversations', 'c');
    $total_query->addExpression('COUNT(DISTINCT c.conversation_id)', 'total');
    $total = (int) $total_query->execute()->fetchField();

    $build['summary'] = [
      '#markup' => '<p>' . $this->t('Total conversations logged: @count', ['@count' => $total]) . '</p>',
      '#weight' => -10,
    ];

    return $build;
  }

  /**
   * Shows the detail of a single conversation.
   *
   * @param string $conversation_id
   *   The conversation UUID.
   *
   * @return array
   *   A render array.
   */
  public function detail(string $conversation_id) {
    $build = [];

    if (!$this->database->schema()->tableExists('ilas_site_assistant_conversations')) {
      $build['empty'] = [
        '#markup' => $this->t('Conversation log table does not exist.'),
      ];
      return $build;
    }

    // Fetch all messages for this conversation.
    $query = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['direction', 'message_hash', 'message_length_bucket', 'redaction_profile', 'intent', 'response_type', 'created', 'request_id'])
      ->condition('c.conversation_id', $conversation_id)
      ->orderBy('c.created', 'ASC')
      ->orderBy('c.id', 'ASC');

    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      $build['empty'] = [
        '#markup' => $this->t('Conversation not found or has been cleaned up.'),
      ];
      return $build;
    }

    $build['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to conversation list'),
      '#url' => Url::fromRoute('ilas_site_assistant.admin.conversations'),
      '#attributes' => ['class' => ['button']],
    ];

    $build['info'] = [
      '#markup' => '<h3>' . $this->t('Conversation: @id', ['@id' => $conversation_id]) . '</h3><p>' . $this->t('Message bodies are intentionally not stored. This view shows only per-turn fingerprints and low-cardinality metadata.') . '</p>',
    ];

    // Build the message table.
    $header = [
      $this->t('Time'),
      $this->t('Direction'),
      $this->t('Message Fingerprint'),
      $this->t('Length'),
      $this->t('Redaction Profile'),
      $this->t('Intent'),
      $this->t('Response Type'),
      $this->t('Request ID'),
    ];

    $rows = [];
    foreach ($results as $msg) {
      $rows[] = [
        $this->dateFormatter->format($msg->created, 'medium'),
        $msg->direction === 'user' ? $this->t('User') : $this->t('Assistant'),
        ObservabilityPayloadMinimizer::hashPrefix((string) $msg->message_hash) . '...',
        $msg->message_length_bucket,
        $msg->redaction_profile,
        $msg->intent ?? '-',
        $msg->response_type ?? '-',
        $msg->request_id ? substr($msg->request_id, 0, 8) . '...' : '-',
      ];
    }

    $build['messages'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#attributes' => ['class' => ['conversation-detail-table']],
    ];

    return $build;
  }

  /**
   * Gets distinct intent types for a conversation.
   *
   * @param string $conversation_id
   *   The conversation UUID.
   *
   * @return array
   *   Array of distinct intent strings.
   */
  protected function getConversationIntents(string $conversation_id): array {
    $query = $this->database->select('ilas_site_assistant_conversations', 'c')
      ->fields('c', ['intent'])
      ->condition('c.conversation_id', $conversation_id)
      ->condition('c.direction', 'user')
      ->groupBy('c.intent');
    $query->isNotNull('c.intent');

    $intents = [];
    foreach ($query->execute() as $row) {
      if (!empty($row->intent)) {
        $intents[] = $row->intent;
      }
    }
    return $intents;
  }

  /**
   * Builds the filter form as a render array.
   *
   * @param string $filter_intent
   *   Current intent filter value.
   * @param string $filter_date_from
   *   Current date-from filter value.
   * @param string $filter_date_to
   *   Current date-to filter value.
   *
   * @return array
   *   Render array for the filter form.
   */
  protected function buildFilterForm(string $filter_intent, string $filter_date_from, string $filter_date_to): array {
    // Get available intent types from the data.
    $intent_options = ['' => $this->t('- All intents -')];
    if ($this->database->schema()->tableExists('ilas_site_assistant_conversations')) {
      $intent_query = $this->database->select('ilas_site_assistant_conversations', 'c')
        ->fields('c', ['intent'])
        ->condition('c.direction', 'user')
        ->groupBy('c.intent')
        ->orderBy('c.intent');
      $intent_query->isNotNull('c.intent');

      foreach ($intent_query->execute() as $row) {
        if (!empty($row->intent)) {
          $intent_options[$row->intent] = $row->intent;
        }
      }
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Filters'),
      '#open' => !empty($filter_intent) || !empty($filter_date_from) || !empty($filter_date_to),
      'form' => [
        '#type' => 'html_tag',
        '#tag' => 'form',
        '#attributes' => [
          'method' => 'get',
          'action' => Url::fromRoute('ilas_site_assistant.admin.conversations')->toString(),
        ],
        'intent' => [
          '#type' => 'select',
          '#title' => $this->t('Intent'),
          '#options' => $intent_options,
          '#default_value' => $filter_intent,
          '#attributes' => ['name' => 'intent'],
        ],
        'date_from' => [
          '#type' => 'date',
          '#title' => $this->t('From date'),
          '#default_value' => $filter_date_from,
          '#attributes' => ['name' => 'date_from'],
        ],
        'date_to' => [
          '#type' => 'date',
          '#title' => $this->t('To date'),
          '#default_value' => $filter_date_to,
          '#attributes' => ['name' => 'date_to'],
        ],
        'submit' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Filter'),
          '#attributes' => [
            'type' => 'submit',
            'class' => ['button', 'button--primary'],
          ],
        ],
        'reset' => [
          '#type' => 'link',
          '#title' => $this->t('Reset'),
          '#url' => Url::fromRoute('ilas_site_assistant.admin.conversations'),
          '#attributes' => ['class' => ['button']],
        ],
      ],
    ];
  }

}
