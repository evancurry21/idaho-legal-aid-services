<?php

namespace Drupal\ilas_payment\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ilas_payment\Service\SecureKeyManager;

/**
 * Stripe payment processing service.
 */
class StripePaymentService {

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
   * The payment processor.
   *
   * @var \Drupal\ilas_payment\Service\PaymentProcessor
   */
  protected $paymentProcessor;

  /**
   * The secure key manager.
   *
   * @var \Drupal\ilas_payment\Service\SecureKeyManager
   */
  protected $secureKeyManager;

  /**
   * Constructs a StripePaymentService.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    PaymentProcessor $payment_processor,
    SecureKeyManager $secure_key_manager
  ) {
    $this->logger = $logger_factory->get('ilas_payment.stripe');
    $this->configFactory = $config_factory;
    $this->paymentProcessor = $payment_processor;
    $this->secureKeyManager = $secure_key_manager;
  }

  /**
   * Initialize Stripe with API keys.
   */
  protected function initializeStripe() {
    $config = $this->configFactory->get('ilas_payment.settings');
    $test_mode = $config->get('test_mode');
    
    if ($test_mode) {
      $api_key = $this->secureKeyManager->getSecureKey('stripe_test_secret_key', $test_mode);
    }
    else {
      $api_key = $this->secureKeyManager->getSecureKey('stripe_live_secret_key', $test_mode);
    }
    
    if (empty($api_key)) {
      $this->logger->error('Stripe API key not configured for @mode mode', [
        '@mode' => $test_mode ? 'test' : 'live'
      ]);
      throw new \Exception('Stripe API key not configured');
    }
    
    // In a real implementation, we would use the Stripe PHP library
    // \Stripe\Stripe::setApiKey($api_key);
    
    return $api_key;
  }

  /**
   * Process a Stripe payment.
   */
  public function processPayment(array $payment_data) {
    try {
      $this->initializeStripe();
      
      // Create payment intent
      $intent_data = [
        'amount' => intval($payment_data['amount'] * 100), // Convert to cents
        'currency' => 'usd',
        'payment_method' => $payment_data['stripe_payment_method_id'] ?? NULL,
        'description' => 'Donation to Idaho Legal Aid Services',
        'metadata' => [
          'email' => $payment_data['email'],
          'campaign_id' => $payment_data['campaign_id'] ?? '',
        ],
      ];
      
      // Add customer if recurring
      if (!empty($payment_data['is_recurring'])) {
        $customer = $this->createOrUpdateCustomer($payment_data);
        $intent_data['customer'] = $customer['id'];
        $intent_data['setup_future_usage'] = 'off_session';
      }
      
      // In production, this would use Stripe SDK:
      // $intent = \Stripe\PaymentIntent::create($intent_data);
      
      // For now, simulate successful payment
      $transaction_id = 'pi_' . uniqid();
      
      $this->logger->info('Stripe payment processed: @id', [
        '@id' => $transaction_id,
      ]);
      
      return [
        'success' => TRUE,
        'transaction_id' => $transaction_id,
        'payment_intent_id' => $transaction_id,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Stripe payment failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return [
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Create or update Stripe customer.
   */
  protected function createOrUpdateCustomer($payment_data) {
    // Check if customer exists
    $existing_customer = $this->findCustomerByEmail($payment_data['email']);
    
    if ($existing_customer) {
      return $existing_customer;
    }
    
    // Create new customer
    $customer_data = [
      'email' => $payment_data['email'],
      'name' => trim(($payment_data['first_name'] ?? '') . ' ' . ($payment_data['last_name'] ?? '')),
      'metadata' => [
        'source' => 'donation_form',
      ],
    ];
    
    // In production: $customer = \Stripe\Customer::create($customer_data);
    
    return [
      'id' => 'cus_' . uniqid(),
      'email' => $payment_data['email'],
    ];
  }

  /**
   * Find Stripe customer by email.
   */
  protected function findCustomerByEmail($email) {
    // In production: 
    // $customers = \Stripe\Customer::all(['email' => $email, 'limit' => 1]);
    // return $customers->data[0] ?? NULL;
    
    return NULL;
  }

  /**
   * Create a Stripe subscription.
   */
  public function createSubscription($customer_id, $payment_data) {
    try {
      $subscription_data = [
        'customer' => $customer_id,
        'items' => [
          [
            'price_data' => [
              'unit_amount' => intval($payment_data['amount'] * 100),
              'currency' => 'usd',
              'recurring' => [
                'interval' => $payment_data['frequency_unit'] ?? 'month',
                'interval_count' => $payment_data['frequency_interval'] ?? 1,
              ],
              'product_data' => [
                'name' => 'Recurring Donation',
              ],
            ],
          ],
        ],
        'payment_settings' => [
          'payment_method_types' => ['card'],
        ],
      ];
      
      // In production: $subscription = \Stripe\Subscription::create($subscription_data);
      
      return [
        'id' => 'sub_' . uniqid(),
        'status' => 'active',
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create subscription: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      throw $e;
    }
  }

  /**
   * Handle Stripe webhook.
   */
  public function handleWebhook($payload, $signature) {
    try {
      $config = $this->configFactory->get('ilas_payment.settings');
      $webhook_secret = $config->get('stripe_webhook_secret');
      
      // Verify webhook signature
      // In production: $event = \Stripe\Webhook::constructEvent($payload, $signature, $webhook_secret);
      
      // For now, parse the payload
      $event = json_decode($payload, TRUE);
      
      switch ($event['type']) {
        case 'payment_intent.succeeded':
          $this->handlePaymentIntentSucceeded($event['data']['object']);
          break;
          
        case 'payment_intent.payment_failed':
          $this->handlePaymentIntentFailed($event['data']['object']);
          break;
          
        case 'customer.subscription.created':
        case 'customer.subscription.updated':
          $this->handleSubscriptionUpdate($event['data']['object']);
          break;
          
        case 'customer.subscription.deleted':
          $this->handleSubscriptionCanceled($event['data']['object']);
          break;
          
        case 'invoice.payment_succeeded':
          $this->handleInvoicePaymentSucceeded($event['data']['object']);
          break;
          
        case 'invoice.payment_failed':
          $this->handleInvoicePaymentFailed($event['data']['object']);
          break;
      }
      
      return ['success' => TRUE];
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook processing failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return ['success' => FALSE, 'error' => $e->getMessage()];
    }
  }

  /**
   * Handle successful payment intent.
   */
  protected function handlePaymentIntentSucceeded($payment_intent) {
    // Update contribution status if needed
    $this->logger->info('Payment intent succeeded: @id', [
      '@id' => $payment_intent['id'],
    ]);
  }

  /**
   * Handle failed payment intent.
   */
  protected function handlePaymentIntentFailed($payment_intent) {
    $this->logger->error('Payment intent failed: @id', [
      '@id' => $payment_intent['id'],
    ]);
    
    // Update contribution status to failed
  }

  /**
   * Handle subscription update.
   */
  protected function handleSubscriptionUpdate($subscription) {
    try {
      \Drupal::service('civicrm')->initialize();
      
      // Find recurring contribution by subscription ID
      $recur = civicrm_api3('ContributionRecur', 'get', [
        'processor_id' => $subscription['id'],
      ]);
      
      if ($recur['count'] > 0) {
        $recur_id = reset($recur['values'])['id'];
        
        // Update status
        $status_map = [
          'active' => 'In Progress',
          'past_due' => 'Overdue',
          'canceled' => 'Cancelled',
          'unpaid' => 'Failed',
        ];
        
        $update_params = [
          'id' => $recur_id,
          'contribution_status_id' => $status_map[$subscription['status']] ?? 'In Progress',
        ];
        
        if ($subscription['status'] == 'canceled') {
          $update_params['cancel_date'] = date('YmdHis');
        }
        
        civicrm_api3('ContributionRecur', 'create', $update_params);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update subscription: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Handle canceled subscription.
   */
  protected function handleSubscriptionCanceled($subscription) {
    $this->handleSubscriptionUpdate($subscription);
  }

  /**
   * Handle successful invoice payment.
   */
  protected function handleInvoicePaymentSucceeded($invoice) {
    try {
      \Drupal::service('civicrm')->initialize();
      
      // Find recurring contribution
      $recur = civicrm_api3('ContributionRecur', 'get', [
        'processor_id' => $invoice['subscription'],
      ]);
      
      if ($recur['count'] > 0) {
        $recur_data = reset($recur['values']);
        
        // Create contribution for this payment
        $contribution_params = [
          'contact_id' => $recur_data['contact_id'],
          'financial_type_id' => $recur_data['financial_type_id'],
          'total_amount' => $invoice['amount_paid'] / 100, // Convert from cents
          'currency' => strtoupper($invoice['currency']),
          'receive_date' => date('YmdHis', $invoice['created']),
          'contribution_status_id' => 'Completed',
          'contribution_recur_id' => $recur_data['id'],
          'trxn_id' => $invoice['payment_intent'],
          'invoice_id' => $invoice['id'],
          'source' => 'Stripe Recurring Payment',
        ];
        
        civicrm_api3('Contribution', 'create', $contribution_params);
        
        $this->logger->info('Recurring payment recorded for invoice: @id', [
          '@id' => $invoice['id'],
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to record invoice payment: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Handle failed invoice payment.
   */
  protected function handleInvoicePaymentFailed($invoice) {
    $this->logger->error('Invoice payment failed: @id', [
      '@id' => $invoice['id'],
    ]);
    
    // Record failed payment in CiviCRM
  }

  /**
   * Get Stripe public key for frontend.
   */
  public function getPublicKey() {
    $config = $this->configFactory->get('ilas_payment.settings');
    $test_mode = $config->get('test_mode');
    
    if ($test_mode) {
      return $config->get('stripe_test_public_key');
    }
    else {
      return $config->get('stripe_live_public_key');
    }
  }
}