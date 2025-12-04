<?php

namespace Drupal\ilas_payment\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ilas_payment\Service\SecureKeyManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure payment settings.
 */
class PaymentSettingsForm extends ConfigFormBase {

  /**
   * The secure key manager service.
   *
   * @var \Drupal\ilas_payment\Service\SecureKeyManager
   */
  protected $secureKeyManager;

  /**
   * Constructs a PaymentSettingsForm object.
   *
   * @param \Drupal\ilas_payment\Service\SecureKeyManager $secure_key_manager
   *   The secure key manager service.
   */
  public function __construct(SecureKeyManager $secure_key_manager) {
    $this->secureKeyManager = $secure_key_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ilas_payment.secure_key_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ilas_payment_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ilas_payment.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ilas_payment.settings');
    
    // General settings
    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Settings'),
      '#open' => TRUE,
    ];
    
    $form['general']['test_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Test Mode'),
      '#description' => $this->t('Enable test mode for payment processing.'),
      '#default_value' => $config->get('test_mode') ?? TRUE,
    ];
    
    $form['general']['suggested_amounts'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Suggested Donation Amounts'),
      '#description' => $this->t('Comma-separated list of suggested amounts (e.g., 25,50,100,250,500).'),
      '#default_value' => implode(',', $config->get('suggested_amounts') ?? [25, 50, 100, 250, 500]),
    ];
    
    $form['general']['minimum_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Donation Amount'),
      '#min' => 1,
      '#default_value' => $config->get('minimum_amount') ?? 5,
    ];
    
    $form['general']['receipt_email_from'] = [
      '#type' => 'email',
      '#title' => $this->t('Receipt Email From Address'),
      '#default_value' => $config->get('receipt_email_from') ?? \Drupal::config('system.site')->get('mail'),
      '#required' => TRUE,
    ];
    
    // Stripe settings
    $form['stripe'] = [
      '#type' => 'details',
      '#title' => $this->t('Stripe Settings'),
      '#open' => TRUE,
    ];
    
    $form['stripe']['stripe_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Stripe'),
      '#default_value' => $config->get('stripe_enabled') ?? FALSE,
    ];
    
    $form['stripe']['stripe_test_public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Publishable Key'),
      '#default_value' => $config->get('stripe_test_public_key'),
      '#states' => [
        'visible' => [
          ':input[name="stripe_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];
    
    $test_secret_secure = $this->secureKeyManager->isKeySecurelyStored('stripe_test_secret_key', TRUE);
    $form['stripe']['stripe_test_secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Secret Key'),
      '#default_value' => $test_secret_secure ? '••••••••••••' : $config->get('stripe_test_secret_key'),
      '#description' => $test_secret_secure 
        ? $this->t('<strong>✓ SECURE:</strong> Key loaded from environment variable or Drupal settings.')
        : $this->t('<strong>⚠ WARNING:</strong> For security, set STRIPE_TEST_SECRET_KEY environment variable instead of storing in database.'),
      '#disabled' => $test_secret_secure,
      '#states' => [
        'visible' => [
          ':input[name="stripe_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];
    
    $form['stripe']['stripe_live_public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Publishable Key'),
      '#default_value' => $config->get('stripe_live_public_key'),
      '#states' => [
        'visible' => [
          ':input[name="stripe_enabled"]' => ['checked' => TRUE],
          ':input[name="test_mode"]' => ['checked' => FALSE],
        ],
      ],
    ];
    
    $live_secret_secure = $this->secureKeyManager->isKeySecurelyStored('stripe_live_secret_key', FALSE);
    $form['stripe']['stripe_live_secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Secret Key'),
      '#default_value' => $live_secret_secure ? '••••••••••••' : $config->get('stripe_live_secret_key'),
      '#description' => $live_secret_secure 
        ? $this->t('<strong>✓ SECURE:</strong> Key loaded from environment variable or Drupal settings.')
        : $this->t('<strong>⚠ WARNING:</strong> For security, set STRIPE_LIVE_SECRET_KEY environment variable instead of storing in database.'),
      '#disabled' => $live_secret_secure,
      '#states' => [
        'visible' => [
          ':input[name="stripe_enabled"]' => ['checked' => TRUE],
          ':input[name="test_mode"]' => ['checked' => FALSE],
        ],
      ],
    ];
    
    $test_mode = $config->get('test_mode') ?? TRUE;
    $webhook_secret_secure = $this->secureKeyManager->isKeySecurelyStored('stripe_webhook_secret', $test_mode);
    $form['stripe']['stripe_webhook_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook Signing Secret'),
      '#description' => $webhook_secret_secure 
        ? $this->t('<strong>✓ SECURE:</strong> Key loaded from environment variable or Drupal settings.<br>Stripe webhook endpoint: @url', [
          '@url' => \Drupal::request()->getSchemeAndHttpHost() . '/payment/stripe/webhook',
        ])
        : $this->t('<strong>⚠ WARNING:</strong> For security, set STRIPE_TEST_WEBHOOK_SECRET or STRIPE_LIVE_WEBHOOK_SECRET environment variable.<br>Stripe webhook endpoint: @url', [
          '@url' => \Drupal::request()->getSchemeAndHttpHost() . '/payment/stripe/webhook',
        ]),
      '#default_value' => $webhook_secret_secure ? '••••••••••••' : $config->get('stripe_webhook_secret'),
      '#disabled' => $webhook_secret_secure,
      '#states' => [
        'visible' => [
          ':input[name="stripe_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];
    
    // Security Information
    $env_mapping = $this->secureKeyManager->getEnvironmentVariableMapping();
    $form['security_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Security Information'),
      '#open' => FALSE,
    ];
    
    $form['security_info']['env_vars'] = [
      '#type' => 'markup',
      '#markup' => $this->buildEnvironmentVariableTable($env_mapping),
    ];

    // PayPal settings
    $form['paypal'] = [
      '#type' => 'details',
      '#title' => $this->t('PayPal Settings'),
      '#open' => FALSE,
    ];
    
    $form['paypal']['paypal_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable PayPal'),
      '#default_value' => $config->get('paypal_enabled') ?? FALSE,
    ];
    
    $form['paypal']['paypal_business_email'] = [
      '#type' => 'email',
      '#title' => $this->t('PayPal Business Email'),
      '#default_value' => $config->get('paypal_business_email'),
      '#states' => [
        'visible' => [
          ':input[name="paypal_enabled"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="paypal_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];
    
    $form['paypal']['paypal_ipn_url'] = [
      '#type' => 'item',
      '#title' => $this->t('PayPal IPN URL'),
      '#markup' => \Drupal::request()->getSchemeAndHttpHost() . '/payment/paypal/ipn',
      '#description' => $this->t('Configure this URL in your PayPal account settings.'),
      '#states' => [
        'visible' => [
          ':input[name="paypal_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];
    
    // Financial types
    $form['financial'] = [
      '#type' => 'details',
      '#title' => $this->t('Financial Configuration'),
      '#open' => FALSE,
    ];
    
    $form['financial']['default_financial_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Financial Type'),
      '#options' => ilas_payment_get_financial_types(),
      '#default_value' => $config->get('default_financial_type') ?? 'Donation',
    ];
    
    $form['financial']['create_financial_types'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create default financial types'),
      '#description' => $this->t('Create standard financial types in CiviCRM if they do not exist.'),
      '#default_value' => FALSE,
    ];
    
    // Email templates
    $form['emails'] = [
      '#type' => 'details',
      '#title' => $this->t('Email Templates'),
      '#open' => FALSE,
    ];
    
    $form['emails']['send_receipts'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send automatic receipts'),
      '#default_value' => $config->get('send_receipts') ?? TRUE,
    ];
    
    $form['emails']['receipt_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Receipt Email Subject'),
      '#default_value' => $config->get('receipt_subject') ?? 'Thank you for your donation to Idaho Legal Aid Services',
      '#states' => [
        'visible' => [
          ':input[name="send_receipts"]' => ['checked' => TRUE],
        ],
      ],
    ];
    
    $form['emails']['receipt_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Receipt Email Template'),
      '#description' => $this->t('Available tokens: [donor_name], [amount], [date], [transaction_id]'),
      '#default_value' => $config->get('receipt_template') ?? $this->getDefaultReceiptTemplate(),
      '#rows' => 10,
      '#states' => [
        'visible' => [
          ':input[name="send_receipts"]' => ['checked' => TRUE],
        ],
      ],
    ];
    
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate suggested amounts
    $amounts = $form_state->getValue('suggested_amounts');
    $amounts_array = array_map('trim', explode(',', $amounts));
    
    foreach ($amounts_array as $amount) {
      if (!is_numeric($amount) || $amount <= 0) {
        $form_state->setErrorByName('suggested_amounts', $this->t('All suggested amounts must be positive numbers.'));
        break;
      }
    }
    
    // Validate Stripe keys if enabled
    if ($form_state->getValue('stripe_enabled')) {
      if ($form_state->getValue('test_mode')) {
        if (empty($form_state->getValue('stripe_test_public_key')) || 
            empty($form_state->getValue('stripe_test_secret_key'))) {
          $form_state->setErrorByName('stripe_test_public_key', $this->t('Test keys are required when Stripe is enabled in test mode.'));
        }
      }
      else {
        if (empty($form_state->getValue('stripe_live_public_key')) || 
            empty($form_state->getValue('stripe_live_secret_key'))) {
          $form_state->setErrorByName('stripe_live_public_key', $this->t('Live keys are required when Stripe is enabled in live mode.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ilas_payment.settings');
    
    // Process suggested amounts
    $amounts = $form_state->getValue('suggested_amounts');
    $amounts_array = array_map('intval', array_map('trim', explode(',', $amounts)));
    
    // Save configuration (excluding sensitive keys that are stored securely)
    $test_mode = $form_state->getValue('test_mode');
    $config
      ->set('test_mode', $test_mode)
      ->set('suggested_amounts', $amounts_array)
      ->set('minimum_amount', $form_state->getValue('minimum_amount'))
      ->set('receipt_email_from', $form_state->getValue('receipt_email_from'))
      ->set('stripe_enabled', $form_state->getValue('stripe_enabled'))
      ->set('stripe_test_public_key', $form_state->getValue('stripe_test_public_key'))
      ->set('stripe_live_public_key', $form_state->getValue('stripe_live_public_key'))
      ->set('paypal_enabled', $form_state->getValue('paypal_enabled'))
      ->set('default_financial_type', $form_state->getValue('default_financial_type'))
      ->set('send_receipts', $form_state->getValue('send_receipts'))
      ->set('receipt_subject', $form_state->getValue('receipt_subject'))
      ->set('receipt_template', $form_state->getValue('receipt_template'));
    
    // Only save sensitive keys to database if not stored securely elsewhere
    if (!$this->secureKeyManager->isKeySecurelyStored('stripe_test_secret_key', TRUE)) {
      $config->set('stripe_test_secret_key', $form_state->getValue('stripe_test_secret_key'));
      $this->messenger()->addWarning($this->t('Stripe test secret key saved to database. For security, consider using environment variable STRIPE_TEST_SECRET_KEY.'));
    }
    
    if (!$this->secureKeyManager->isKeySecurelyStored('stripe_live_secret_key', FALSE)) {
      $config->set('stripe_live_secret_key', $form_state->getValue('stripe_live_secret_key'));
      $this->messenger()->addWarning($this->t('Stripe live secret key saved to database. For security, consider using environment variable STRIPE_LIVE_SECRET_KEY.'));
    }
    
    if (!$this->secureKeyManager->isKeySecurelyStored('stripe_webhook_secret', $test_mode)) {
      $config->set('stripe_webhook_secret', $form_state->getValue('stripe_webhook_secret'));
      $env_var = $test_mode ? 'STRIPE_TEST_WEBHOOK_SECRET' : 'STRIPE_LIVE_WEBHOOK_SECRET';
      $this->messenger()->addWarning($this->t('Stripe webhook secret saved to database. For security, consider using environment variable @env_var.', ['@env_var' => $env_var]));
    }
    
    if (!$this->secureKeyManager->isKeySecurelyStored('paypal_business_email', $test_mode)) {
      $config->set('paypal_business_email', $form_state->getValue('paypal_business_email'));
      $this->messenger()->addWarning($this->t('PayPal business email saved to database. For security, consider using environment variable PAYPAL_BUSINESS_EMAIL.'));
    }
    
    $config->save();
    
    // Create financial types if requested
    if ($form_state->getValue('create_financial_types')) {
      $this->createDefaultFinancialTypes();
    }
    
    // Create payment processors in CiviCRM if enabled
    if ($form_state->getValue('stripe_enabled')) {
      $this->createStripeProcessor($form_state);
    }
    
    if ($form_state->getValue('paypal_enabled')) {
      $this->createPayPalProcessor($form_state);
    }
    
    parent::submitForm($form, $form_state);
  }

  /**
   * Builds environment variable table for security information.
   *
   * @param array $env_mapping
   *   The environment variable mapping from SecureKeyManager.
   *
   * @return string
   *   HTML table markup.
   */
  protected function buildEnvironmentVariableTable(array $env_mapping) {
    $header = [
      'Configuration Key',
      'Environment Variable',
      'Description',
      'Required For',
      'Status'
    ];
    
    $rows = [];
    foreach ($env_mapping as $key => $info) {
      $test_mode = $this->configFactory->get('ilas_payment.settings')->get('test_mode') ?? TRUE;
      $is_secure = $this->secureKeyManager->isKeySecurelyStored($key, $test_mode);
      
      $rows[] = [
        $key,
        $info['env_var'],
        $info['description'],
        $info['required_for'],
        $is_secure ? '✓ Secure' : '⚠ Database',
      ];
    }
    
    $table = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#attributes' => ['class' => ['payment-security-table']],
      '#prefix' => '<div class="security-info"><h4>Environment Variables for Secure Key Storage</h4><p>For maximum security, store sensitive API keys as environment variables or in Drupal settings.php instead of the database.</p>',
      '#suffix' => '</div>',
    ];
    
    return \Drupal::service('renderer')->render($table);
  }

  /**
   * Get default receipt template.
   */
  protected function getDefaultReceiptTemplate() {
    return "Dear [donor_name],

Thank you for your generous donation of $[amount] to Idaho Legal Aid Services.

Your donation helps us provide critical legal services to low-income individuals and families throughout Idaho. With your support, we can continue to ensure equal access to justice for all.

Donation Details:
Amount: $[amount]
Date: [date]
Transaction ID: [transaction_id]

This letter serves as your official tax receipt. Idaho Legal Aid Services is a 501(c)(3) nonprofit organization. Our tax ID number is XX-XXXXXXX. No goods or services were provided in exchange for this donation.

If you have any questions about your donation, please contact us at donate@idaholegalaid.org.

With gratitude,
Idaho Legal Aid Services";
  }

  /**
   * Create default financial types.
   */
  protected function createDefaultFinancialTypes() {
    try {
      \Drupal::service('civicrm')->initialize();
      
      $types = [
        'Donation' => 'General donations',
        'Event Fee' => 'Event registration fees',
        'Member Dues' => 'Membership dues',
        'Grant' => 'Grant funding',
        'Restricted Donation' => 'Donations restricted for specific purposes',
      ];
      
      foreach ($types as $name => $description) {
        // Check if exists
        $existing = civicrm_api3('FinancialType', 'get', [
          'name' => $name,
        ]);
        
        if ($existing['count'] == 0) {
          civicrm_api3('FinancialType', 'create', [
            'name' => $name,
            'description' => $description,
            'is_deductible' => 1,
            'is_active' => 1,
          ]);
          
          $this->messenger()->addStatus($this->t('Created financial type: @type', ['@type' => $name]));
        }
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to create financial types: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Create Stripe processor in CiviCRM.
   */
  protected function createStripeProcessor(FormStateInterface $form_state) {
    try {
      \Drupal::service('civicrm')->initialize();
      
      // Check if Stripe processor type exists
      $processor_types = civicrm_api3('PaymentProcessorType', 'get', [
        'name' => 'Stripe',
      ]);
      
      if ($processor_types['count'] == 0) {
        // Create Stripe processor type
        $type_result = civicrm_api3('PaymentProcessorType', 'create', [
          'name' => 'Stripe',
          'title' => 'Stripe',
          'class_name' => 'Payment_Stripe',
          'billing_mode' => 1,
          'is_recur' => 1,
          'payment_type' => 1,
        ]);
        
        $processor_type_id = $type_result['id'];
      }
      else {
        $processor_type_id = reset($processor_types['values'])['id'];
      }
      
      // Create processor instance
      $test_mode = $form_state->getValue('test_mode');
      
      $processor_params = [
        'payment_processor_type_id' => $processor_type_id,
        'is_test' => $test_mode ? 1 : 0,
        'is_active' => 1,
        'is_default' => 1,
        'name' => 'Stripe ' . ($test_mode ? '(Test)' : '(Live)'),
        'user_name' => $test_mode ? 
          $form_state->getValue('stripe_test_public_key') : 
          $form_state->getValue('stripe_live_public_key'),
        'password' => $test_mode ? 
          $form_state->getValue('stripe_test_secret_key') : 
          $form_state->getValue('stripe_live_secret_key'),
        'signature' => $form_state->getValue('stripe_webhook_secret'),
      ];
      
      $processor = civicrm_api3('PaymentProcessor', 'create', $processor_params);
      
      // Save processor ID
      $config = \Drupal::configFactory()->getEditable('ilas_payment.settings');
      $config->set('stripe_processor_id', $processor['id'])->save();
      
      $this->messenger()->addStatus($this->t('Stripe payment processor configured in CiviCRM.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to create Stripe processor: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Create PayPal processor in CiviCRM.
   */
  protected function createPayPalProcessor(FormStateInterface $form_state) {
    try {
      \Drupal::service('civicrm')->initialize();
      
      $test_mode = $form_state->getValue('test_mode');
      
      $processor_params = [
        'payment_processor_type_id' => 1, // PayPal Standard
        'is_test' => $test_mode ? 1 : 0,
        'is_active' => 1,
        'name' => 'PayPal ' . ($test_mode ? '(Test)' : '(Live)'),
        'user_name' => $form_state->getValue('paypal_business_email'),
      ];
      
      $processor = civicrm_api3('PaymentProcessor', 'create', $processor_params);
      
      // Save processor ID
      $config = \Drupal::configFactory()->getEditable('ilas_payment.settings');
      $config->set('paypal_processor_id', $processor['id'])->save();
      
      $this->messenger()->addStatus($this->t('PayPal payment processor configured in CiviCRM.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to create PayPal processor: @error', ['@error' => $e->getMessage()]));
    }
  }
}