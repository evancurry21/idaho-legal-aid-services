<?php

namespace Drupal\ilas_payment\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;

/**
 * Secure key manager for handling sensitive API credentials.
 */
class SecureKeyManager {

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a SecureKeyManager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('ilas_payment.security');
  }

  /**
   * Gets a secure key value, preferring environment variables.
   *
   * @param string $key_name
   *   The key name (e.g., 'stripe_live_secret_key').
   * @param bool $test_mode
   *   Whether to use test mode keys.
   *
   * @return string|null
   *   The key value or NULL if not found.
   */
  public function getSecureKey($key_name, $test_mode = TRUE) {
    // Map internal key names to environment variable names
    $env_map = [
      'stripe_test_secret_key' => 'STRIPE_TEST_SECRET_KEY',
      'stripe_live_secret_key' => 'STRIPE_LIVE_SECRET_KEY',
      'stripe_test_public_key' => 'STRIPE_TEST_PUBLISHABLE_KEY',
      'stripe_live_public_key' => 'STRIPE_LIVE_PUBLISHABLE_KEY',
      'stripe_webhook_secret' => $test_mode ? 'STRIPE_TEST_WEBHOOK_SECRET' : 'STRIPE_LIVE_WEBHOOK_SECRET',
      'paypal_business_email' => 'PAYPAL_BUSINESS_EMAIL',
    ];

    $env_key = $env_map[$key_name] ?? null;
    
    // 1. First try environment variables (most secure)
    if ($env_key && !empty($_ENV[$env_key])) {
      $this->logger->info('Using environment variable for @key', ['@key' => $key_name]);
      return $_ENV[$env_key];
    }

    // 2. Try Drupal Settings (second preference)
    $settings_key = 'ilas_payment.' . $key_name;
    $settings_value = Settings::get($settings_key);
    if (!empty($settings_value)) {
      $this->logger->info('Using Drupal Settings for @key', ['@key' => $key_name]);
      return $settings_value;
    }

    // 3. Fall back to database config (legacy - log as insecure)
    $config = $this->configFactory->get('ilas_payment.settings');
    $config_value = $config->get($key_name);
    if (!empty($config_value)) {
      $this->logger->warning('SECURITY WARNING: Using database storage for sensitive key @key. Consider moving to environment variables.', [
        '@key' => $key_name,
      ]);
      return $config_value;
    }

    // 4. Key not found anywhere
    $this->logger->error('API key @key not found in environment, settings, or config.', [
      '@key' => $key_name,
    ]);
    return NULL;
  }

  /**
   * Validates that required keys are available.
   *
   * @param bool $test_mode
   *   Whether to validate test mode keys.
   * @param bool $stripe_enabled
   *   Whether Stripe is enabled.
   * @param bool $paypal_enabled
   *   Whether PayPal is enabled.
   *
   * @return array
   *   Array of missing keys.
   */
  public function validateRequiredKeys($test_mode = TRUE, $stripe_enabled = FALSE, $paypal_enabled = FALSE) {
    $missing_keys = [];

    if ($stripe_enabled) {
      $stripe_keys = $test_mode 
        ? ['stripe_test_secret_key', 'stripe_test_public_key']
        : ['stripe_live_secret_key', 'stripe_live_public_key'];
      
      // Always check webhook secret
      $stripe_keys[] = 'stripe_webhook_secret';

      foreach ($stripe_keys as $key) {
        if (empty($this->getSecureKey($key, $test_mode))) {
          $missing_keys[] = $key;
        }
      }
    }

    if ($paypal_enabled) {
      $paypal_keys = ['paypal_business_email'];
      foreach ($paypal_keys as $key) {
        if (empty($this->getSecureKey($key, $test_mode))) {
          $missing_keys[] = $key;
        }
      }
    }

    return $missing_keys;
  }

  /**
   * Checks if a key value is stored securely (not in database).
   *
   * @param string $key_name
   *   The key name to check.
   * @param bool $test_mode
   *   Whether to use test mode keys.
   *
   * @return bool
   *   TRUE if stored securely, FALSE if in database.
   */
  public function isKeySecurelyStored($key_name, $test_mode = TRUE) {
    $env_map = [
      'stripe_test_secret_key' => 'STRIPE_TEST_SECRET_KEY',
      'stripe_live_secret_key' => 'STRIPE_LIVE_SECRET_KEY',
      'stripe_test_public_key' => 'STRIPE_TEST_PUBLISHABLE_KEY',
      'stripe_live_public_key' => 'STRIPE_LIVE_PUBLISHABLE_KEY',
      'stripe_webhook_secret' => $test_mode ? 'STRIPE_TEST_WEBHOOK_SECRET' : 'STRIPE_LIVE_WEBHOOK_SECRET',
      'paypal_business_email' => 'PAYPAL_BUSINESS_EMAIL',
    ];

    $env_key = $env_map[$key_name] ?? null;
    
    // Check environment variables
    if ($env_key && !empty($_ENV[$env_key])) {
      return TRUE;
    }

    // Check Drupal Settings
    $settings_key = 'ilas_payment.' . $key_name;
    if (!empty(Settings::get($settings_key))) {
      return TRUE;
    }

    // Only in database config - not secure
    return FALSE;
  }

  /**
   * Gets environment variable mapping documentation.
   *
   * @return array
   *   Array of environment variable mappings for documentation.
   */
  public function getEnvironmentVariableMapping() {
    return [
      'stripe_test_secret_key' => [
        'env_var' => 'STRIPE_TEST_SECRET_KEY',
        'description' => 'Stripe test mode secret key',
        'required_for' => 'Stripe test payments',
      ],
      'stripe_live_secret_key' => [
        'env_var' => 'STRIPE_LIVE_SECRET_KEY',
        'description' => 'Stripe live mode secret key',
        'required_for' => 'Stripe production payments',
      ],
      'stripe_test_public_key' => [
        'env_var' => 'STRIPE_TEST_PUBLISHABLE_KEY',
        'description' => 'Stripe test mode publishable key',
        'required_for' => 'Stripe test payments (frontend)',
      ],
      'stripe_live_public_key' => [
        'env_var' => 'STRIPE_LIVE_PUBLISHABLE_KEY',
        'description' => 'Stripe live mode publishable key',
        'required_for' => 'Stripe production payments (frontend)',
      ],
      'stripe_webhook_secret' => [
        'env_var' => 'STRIPE_TEST_WEBHOOK_SECRET / STRIPE_LIVE_WEBHOOK_SECRET',
        'description' => 'Stripe webhook signing secret',
        'required_for' => 'Webhook signature verification',
      ],
      'paypal_business_email' => [
        'env_var' => 'PAYPAL_BUSINESS_EMAIL',
        'description' => 'PayPal business account email',
        'required_for' => 'PayPal IPN processing',
      ],
    ];
  }

}