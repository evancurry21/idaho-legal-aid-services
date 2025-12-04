<?php

namespace Drupal\ilas_payment\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ilas_payment\Service\SecureKeyManager;

/**
 * Access checker for webhook endpoints with signature verification.
 */
class WebhookAccessChecker implements AccessInterface, ContainerInjectionInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The secure key manager.
   *
   * @var \Drupal\ilas_payment\Service\SecureKeyManager
   */
  protected $secureKeyManager;

  /**
   * Constructs a WebhookAccessChecker object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\ilas_payment\Service\SecureKeyManager $secure_key_manager
   *   The secure key manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, SecureKeyManager $secure_key_manager) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('ilas_payment');
    $this->secureKeyManager = $secure_key_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('ilas_payment.secure_key_manager')
    );
  }

  /**
   * Checks access for Stripe webhook endpoint.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function stripeSignatureAccess(Request $request) {
    $config = $this->configFactory->get('ilas_payment.settings');
    $test_mode = $config->get('test_mode') ?? TRUE;
    $webhook_secret = $this->secureKeyManager->getSecureKey('stripe_webhook_secret', $test_mode);
    
    if (empty($webhook_secret)) {
      $this->logger->error('Stripe webhook secret not configured for @mode mode.', [
        '@mode' => $test_mode ? 'test' : 'live'
      ]);
      return AccessResult::forbidden('Webhook secret not configured.');
    }

    $payload = $request->getContent();
    $signature = $request->headers->get('stripe-signature');
    
    if (empty($signature) || empty($payload)) {
      $this->logger->warning('Stripe webhook missing signature or payload.', [
        'has_signature' => !empty($signature),
        'has_payload' => !empty($payload),
        'remote_ip' => $request->getClientIp(),
      ]);
      return AccessResult::forbidden('Invalid webhook request.');
    }

    if ($this->verifyStripeSignature($payload, $signature, $webhook_secret)) {
      return AccessResult::allowed();
    }

    $this->logger->warning('Stripe webhook signature verification failed.', [
      'remote_ip' => $request->getClientIp(),
      'user_agent' => $request->headers->get('user-agent'),
    ]);
    
    return AccessResult::forbidden('Invalid webhook signature.');
  }

  /**
   * Checks access for PayPal IPN endpoint.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function paypalIpnAccess(Request $request) {
    // PayPal IPN verification is handled in the controller
    // Here we just validate the request comes from PayPal IP ranges
    $client_ip = $request->getClientIp();
    
    if ($this->isPayPalIp($client_ip)) {
      return AccessResult::allowed();
    }
    
    $this->logger->warning('PayPal IPN request from non-PayPal IP.', [
      'remote_ip' => $client_ip,
      'user_agent' => $request->headers->get('user-agent'),
    ]);
    
    return AccessResult::forbidden('Invalid PayPal IPN source.');
  }

  /**
   * Verifies Stripe webhook signature.
   *
   * @param string $payload
   *   The request payload.
   * @param string $signature
   *   The Stripe signature header.
   * @param string $webhook_secret
   *   The webhook signing secret.
   *
   * @return bool
   *   TRUE if signature is valid, FALSE otherwise.
   */
  protected function verifyStripeSignature($payload, $signature, $webhook_secret) {
    $elements = explode(',', $signature);
    $signature_data = [];
    
    foreach ($elements as $element) {
      list($key, $value) = explode('=', $element, 2);
      $signature_data[$key] = $value;
    }
    
    if (!isset($signature_data['t']) || !isset($signature_data['v1'])) {
      return FALSE;
    }
    
    $timestamp = $signature_data['t'];
    $signature_hash = $signature_data['v1'];
    
    // Check timestamp tolerance (5 minutes)
    if (abs(time() - $timestamp) > 300) {
      $this->logger->warning('Stripe webhook timestamp outside tolerance.', [
        'timestamp' => $timestamp,
        'current_time' => time(),
        'difference' => abs(time() - $timestamp),
      ]);
      return FALSE;
    }
    
    $signed_payload = $timestamp . '.' . $payload;
    $expected_signature = hash_hmac('sha256', $signed_payload, $webhook_secret);
    
    return hash_equals($expected_signature, $signature_hash);
  }

  /**
   * Checks if IP address is from PayPal.
   *
   * @param string $ip
   *   The IP address to check.
   *
   * @return bool
   *   TRUE if IP is from PayPal, FALSE otherwise.
   */
  protected function isPayPalIp($ip) {
    // PayPal IPN IP ranges (as of 2024)
    $paypal_ip_ranges = [
      '173.0.80.0/20',
      '173.0.81.0/24',
      '173.0.82.0/23',
      '173.0.84.0/22',
      '173.0.88.0/21',
      '64.4.240.0/21',
      '64.4.248.0/22',
      '66.211.168.0/22',
      '66.211.172.0/23',
      '66.211.174.0/24',
      '66.211.175.0/24',
      '173.0.80.0/20',
      '64.4.240.0/21',
      '66.211.168.0/22',
    ];
    
    $ip_long = ip2long($ip);
    
    foreach ($paypal_ip_ranges as $range) {
      if (strpos($range, '/') === FALSE) {
        if ($ip === $range) {
          return TRUE;
        }
      } else {
        list($network, $mask) = explode('/', $range);
        $network_long = ip2long($network);
        $mask_long = ((1 << (32 - $mask)) - 1) ^ 0xffffffff;
        
        if (($ip_long & $mask_long) === ($network_long & $mask_long)) {
          return TRUE;
        }
      }
    }
    
    return FALSE;
  }

}