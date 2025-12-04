<?php

namespace Drupal\ilas_payment\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\ilas_payment\Service\SecureErrorHandler;

/**
 * Main payment processing service.
 */
class PaymentProcessor {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The secure error handler.
   *
   * @var \Drupal\ilas_payment\Service\SecureErrorHandler
   */
  protected $errorHandler;

  /**
   * Constructs a PaymentProcessor.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    MessengerInterface $messenger,
    SecureErrorHandler $error_handler
  ) {
    $this->logger = $logger_factory->get('ilas_payment');
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->messenger = $messenger;
    $this->errorHandler = $error_handler;
  }

  /**
   * Process a payment.
   *
   * @param array $payment_data
   *   Payment data including amount, payment method, etc.
   *
   * @return array
   *   Result of payment processing.
   */
  public function processPayment(array $payment_data) {
    try {
      \Drupal::service('civicrm')->initialize();
      
      // Validate payment data
      if (!$this->validatePaymentData($payment_data)) {
        throw new \Exception('Invalid payment data');
      }
      
      // Determine payment processor
      $processor_service = $this->getProcessorService($payment_data['payment_method']);
      
      // Process payment through appropriate service
      $payment_result = $processor_service->processPayment($payment_data);
      
      if ($payment_result['success']) {
        // Create CiviCRM contribution
        $contribution = $this->createContribution($payment_data, $payment_result);
        
        // Log successful payment
        $this->logger->info('Payment processed successfully. Contribution ID: @id', [
          '@id' => $contribution['id'],
        ]);
        
        return [
          'success' => TRUE,
          'contribution_id' => $contribution['id'],
          'transaction_id' => $payment_result['transaction_id'],
          'message' => 'Payment processed successfully.',
        ];
      }
      else {
        // Log failed payment
        $this->logger->error('Payment failed: @error', [
          '@error' => $payment_result['error'] ?? 'Unknown error',
        ]);
        
        return [
          'success' => FALSE,
          'error' => $payment_result['error'] ?? 'Payment processing failed.',
        ];
      }
    }
    catch (\Exception $e) {
      // Determine error type for appropriate user message
      $error_type = 'general';
      if (strpos($e->getMessage(), 'declined') !== FALSE) {
        $error_type = 'payment_declined';
      }
      elseif (strpos($e->getMessage(), 'network') !== FALSE || strpos($e->getMessage(), 'timeout') !== FALSE) {
        $error_type = 'network_error';
      }
      elseif (strpos($e->getMessage(), 'validation') !== FALSE || strpos($e->getMessage(), 'invalid') !== FALSE) {
        $error_type = 'validation_error';
      }
      
      return $this->errorHandler->createPaymentErrorResponse($e->getMessage(), $error_type);
    }
  }

  /**
   * Validate payment data.
   */
  protected function validatePaymentData(array $data) {
    // Required fields
    $required = ['amount', 'payment_method', 'email'];
    
    foreach ($required as $field) {
      if (empty($data[$field])) {
        $this->messenger->addError(t('Missing required field: @field', ['@field' => $field]));
        return FALSE;
      }
    }
    
    // Validate amount
    if (!is_numeric($data['amount']) || $data['amount'] <= 0) {
      $this->messenger->addError(t('Invalid payment amount.'));
      return FALSE;
    }
    
    // Validate email
    if (!\Drupal::service('email.validator')->isValid($data['email'])) {
      $this->messenger->addError(t('Invalid email address.'));
      return FALSE;
    }
    
    return TRUE;
  }

  /**
   * Get payment processor service.
   */
  protected function getProcessorService($payment_method) {
    switch ($payment_method) {
      case 'stripe':
        return \Drupal::service('ilas_payment.stripe');
        
      case 'paypal':
        return \Drupal::service('ilas_payment.paypal');
        
      default:
        throw new \Exception('Unsupported payment method: ' . $payment_method);
    }
  }

  /**
   * Create CiviCRM contribution.
   */
  protected function createContribution($payment_data, $payment_result) {
    // Find or create contact
    $contact_id = $this->findOrCreateContact($payment_data);
    
    // Prepare contribution data
    $contribution_params = [
      'contact_id' => $contact_id,
      'financial_type_id' => $payment_data['financial_type_id'] ?? 'Donation',
      'total_amount' => $payment_data['amount'],
      'currency' => 'USD',
      'receive_date' => date('YmdHis'),
      'contribution_status_id' => 'Completed',
      'payment_instrument_id' => $this->getPaymentInstrumentId($payment_data['payment_method']),
      'trxn_id' => $payment_result['transaction_id'],
      'source' => 'Online Donation - ' . ucfirst($payment_data['payment_method']),
    ];
    
    // Add campaign if specified
    if (!empty($payment_data['campaign_id'])) {
      $contribution_params['campaign_id'] = $payment_data['campaign_id'];
    }
    
    // Add soft credit if specified
    if (!empty($payment_data['honor_contact_id'])) {
      $contribution_params['soft_credit'] = [
        [
          'contact_id' => $payment_data['honor_contact_id'],
          'amount' => $payment_data['amount'],
          'soft_credit_type_id' => $payment_data['honor_type_id'] ?? 1,
        ],
      ];
    }
    
    // Handle recurring contributions
    if (!empty($payment_data['is_recurring'])) {
      $recur_params = $this->createRecurringContribution($contact_id, $payment_data);
      $contribution_params['contribution_recur_id'] = $recur_params['id'];
    }
    
    // Create contribution
    $contribution = civicrm_api3('Contribution', 'create', $contribution_params);
    
    // Add note if provided
    if (!empty($payment_data['note'])) {
      civicrm_api3('Note', 'create', [
        'entity_table' => 'civicrm_contribution',
        'entity_id' => $contribution['id'],
        'note' => $payment_data['note'],
        'contact_id' => $contact_id,
      ]);
    }
    
    return $contribution;
  }

  /**
   * Find or create contact.
   */
  protected function findOrCreateContact($data) {
    // Check if contact exists by email
    $existing = civicrm_api3('Contact', 'get', [
      'email' => $data['email'],
      'contact_type' => 'Individual',
    ]);
    
    if ($existing['count'] > 0) {
      return reset($existing['values'])['id'];
    }
    
    // Create new contact
    $params = [
      'contact_type' => 'Individual',
      'email' => $data['email'],
    ];
    
    if (!empty($data['first_name'])) {
      $params['first_name'] = $data['first_name'];
    }
    if (!empty($data['last_name'])) {
      $params['last_name'] = $data['last_name'];
    }
    
    // Add phone if provided
    if (!empty($data['phone'])) {
      $params['api.Phone.create'] = [
        'phone' => $data['phone'],
        'location_type_id' => 'Home',
        'phone_type_id' => 'Phone',
      ];
    }
    
    // Add address if provided
    if (!empty($data['street_address'])) {
      $params['api.Address.create'] = [
        'street_address' => $data['street_address'],
        'city' => $data['city'] ?? '',
        'postal_code' => $data['postal_code'] ?? '',
        'state_province_id' => $this->getStateProvinceId($data['state'] ?? ''),
        'location_type_id' => 'Home',
      ];
    }
    
    $contact = civicrm_api3('Contact', 'create', $params);
    
    // Add donor tag
    $this->applyDonorTag($contact['id']);
    
    return $contact['id'];
  }

  /**
   * Get payment instrument ID.
   */
  protected function getPaymentInstrumentId($method) {
    $mapping = [
      'stripe' => 'Credit Card',
      'paypal' => 'PayPal',
      'check' => 'Check',
      'ach' => 'EFT',
    ];
    
    return $mapping[$method] ?? 'Credit Card';
  }

  /**
   * Get state/province ID.
   */
  protected function getStateProvinceId($state) {
    if (empty($state)) {
      return NULL;
    }
    
    $result = civicrm_api3('StateProvince', 'get', [
      'abbreviation' => $state,
      'country_id' => 1228, // United States
    ]);
    
    if ($result['count'] > 0) {
      return reset($result['values'])['id'];
    }
    
    return NULL;
  }

  /**
   * Apply donor tag to contact.
   */
  protected function applyDonorTag($contact_id) {
    // Find or create donor tag
    $tag_result = civicrm_api3('Tag', 'get', [
      'name' => 'donor',
    ]);
    
    if ($tag_result['count'] == 0) {
      $tag_result = civicrm_api3('Tag', 'create', [
        'name' => 'donor',
        'label' => 'Donor',
        'used_for' => 'civicrm_contact',
      ]);
    }
    
    $tag_id = reset($tag_result['values'])['id'];
    
    // Apply tag
    civicrm_api3('EntityTag', 'create', [
      'entity_table' => 'civicrm_contact',
      'entity_id' => $contact_id,
      'tag_id' => $tag_id,
    ]);
  }

  /**
   * Create recurring contribution.
   */
  protected function createRecurringContribution($contact_id, $data) {
    $recur_params = [
      'contact_id' => $contact_id,
      'amount' => $data['amount'],
      'currency' => 'USD',
      'frequency_unit' => $data['frequency_unit'] ?? 'month',
      'frequency_interval' => $data['frequency_interval'] ?? 1,
      'start_date' => date('YmdHis'),
      'create_date' => date('YmdHis'),
      'contribution_status_id' => 'In Progress',
      'payment_processor_id' => $this->getPaymentProcessorId($data['payment_method']),
      'financial_type_id' => $data['financial_type_id'] ?? 'Donation',
    ];
    
    if (!empty($data['installments'])) {
      $recur_params['installments'] = $data['installments'];
    }
    
    $recur = civicrm_api3('ContributionRecur', 'create', $recur_params);
    
    return $recur;
  }

  /**
   * Get payment processor ID.
   */
  protected function getPaymentProcessorId($method) {
    $config = $this->configFactory->get('ilas_payment.settings');
    
    switch ($method) {
      case 'stripe':
        return $config->get('stripe_processor_id');
        
      case 'paypal':
        return $config->get('paypal_processor_id');
        
      default:
        return NULL;
    }
  }

  /**
   * Process recurring donations.
   */
  public function processRecurringDonations() {
    try {
      \Drupal::service('civicrm')->initialize();
      
      // Get recurring contributions due for processing
      $today = date('Y-m-d');
      $recurring = civicrm_api3('ContributionRecur', 'get', [
        'contribution_status_id' => 'In Progress',
        'next_sched_contribution_date' => ['<=' => $today],
        'options' => ['limit' => 50],
      ]);
      
      foreach ($recurring['values'] as $recur) {
        $this->processRecurringContribution($recur);
      }
      
      $this->logger->info('Processed @count recurring donations.', [
        '@count' => $recurring['count'],
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process recurring donations: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Process a single recurring contribution.
   */
  protected function processRecurringContribution($recur) {
    try {
      // Get payment method details
      $payment_method = $this->getRecurringPaymentMethod($recur);
      
      // Process payment
      $payment_data = [
        'amount' => $recur['amount'],
        'payment_method' => $payment_method['type'],
        'token' => $payment_method['token'],
        'contact_id' => $recur['contact_id'],
        'financial_type_id' => $recur['financial_type_id'],
        'contribution_recur_id' => $recur['id'],
      ];
      
      $result = $this->processPayment($payment_data);
      
      if ($result['success']) {
        // Update next scheduled date
        $next_date = $this->calculateNextPaymentDate($recur);
        
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $recur['id'],
          'next_sched_contribution_date' => $next_date,
        ]);
      }
      else {
        // Handle failed payment
        $this->handleFailedRecurringPayment($recur, $result['error']);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process recurring contribution @id: @error', [
        '@id' => $recur['id'],
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Get recurring payment method.
   */
  protected function getRecurringPaymentMethod($recur) {
    // This would retrieve stored payment token/method
    // Implementation depends on payment processor
    return [
      'type' => 'stripe',
      'token' => $recur['processor_id'] ?? '',
    ];
  }

  /**
   * Calculate next payment date.
   */
  protected function calculateNextPaymentDate($recur) {
    $interval = $recur['frequency_interval'];
    $unit = $recur['frequency_unit'];
    
    $next_date = new \DateTime($recur['next_sched_contribution_date']);
    $next_date->modify("+{$interval} {$unit}");
    
    return $next_date->format('Y-m-d');
  }

  /**
   * Handle failed recurring payment.
   */
  protected function handleFailedRecurringPayment($recur, $error) {
    // Log the failure
    $this->logger->error('Recurring payment failed for contribution @id: @error', [
      '@id' => $recur['id'],
      '@error' => $error,
    ]);
    
    // Create failed contribution record
    civicrm_api3('Contribution', 'create', [
      'contact_id' => $recur['contact_id'],
      'financial_type_id' => $recur['financial_type_id'],
      'total_amount' => $recur['amount'],
      'currency' => $recur['currency'],
      'receive_date' => date('YmdHis'),
      'contribution_status_id' => 'Failed',
      'contribution_recur_id' => $recur['id'],
      'source' => 'Recurring contribution - Failed',
    ]);
    
    // Update failure count
    $failure_count = ($recur['failure_count'] ?? 0) + 1;
    
    $update_params = [
      'id' => $recur['id'],
      'failure_count' => $failure_count,
    ];
    
    // Cancel if too many failures
    if ($failure_count >= 3) {
      $update_params['contribution_status_id'] = 'Failed';
      $update_params['cancel_date'] = date('YmdHis');
      $update_params['cancel_reason'] = 'Multiple payment failures';
    }
    
    civicrm_api3('ContributionRecur', 'create', $update_params);
  }
}