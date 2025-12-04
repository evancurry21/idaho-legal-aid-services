<?php

namespace Drupal\ilas_payment\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Secure error handling service that prevents information disclosure.
 */
class SecureErrorHandler {

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
   * Whether we're in debug mode.
   *
   * @var bool
   */
  protected $debugMode;

  /**
   * Constructs a SecureErrorHandler.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->logger = $logger_factory->get('ilas_payment.security');
    $this->configFactory = $config_factory;
    
    // Only show detailed errors in development
    $this->debugMode = \Drupal::service('kernel')->getEnvironment() === 'dev' || 
                      defined('MAINTAINER_MODE');
  }

  /**
   * Creates a secure HTTP error response.
   *
   * @param string $internal_error
   *   The actual error message (logged securely).
   * @param string $public_message
   *   Generic message shown to users.
   * @param int $status_code
   *   HTTP status code.
   * @param array $context
   *   Additional logging context.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Secure HTTP response.
   */
  public function createErrorResponse($internal_error, $public_message = 'An error occurred', $status_code = 500, array $context = []) {
    // Log detailed error information securely
    $this->logger->error('Error: @error', array_merge([
      '@error' => $internal_error,
      'ip' => \Drupal::request()->getClientIp(),
      'user_agent' => \Drupal::request()->headers->get('user-agent'),
      'uri' => \Drupal::request()->getRequestUri(),
    ], $context));

    // Return generic message to user (detailed error only in dev mode)
    $message = $this->debugMode ? $internal_error : $public_message;
    
    return new Response($message, $status_code, [
      'Content-Type' => 'text/plain',
      'Cache-Control' => 'no-cache, no-store, must-revalidate',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

  /**
   * Creates a secure JSON error response.
   *
   * @param string $internal_error
   *   The actual error message (logged securely).
   * @param string $public_message
   *   Generic message shown to users.
   * @param int $status_code
   *   HTTP status code.
   * @param array $context
   *   Additional logging context.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Secure JSON response.
   */
  public function createJsonErrorResponse($internal_error, $public_message = 'An error occurred', $status_code = 500, array $context = []) {
    // Log detailed error information securely
    $this->logger->error('API Error: @error', array_merge([
      '@error' => $internal_error,
      'ip' => \Drupal::request()->getClientIp(),
      'user_agent' => \Drupal::request()->headers->get('user-agent'),
      'uri' => \Drupal::request()->getRequestUri(),
    ], $context));

    // Return generic JSON response (detailed error only in dev mode)
    $response_data = [
      'success' => FALSE,
      'error' => $this->debugMode ? $internal_error : $public_message,
      'error_code' => $this->generateErrorCode(),
    ];

    return new JsonResponse($response_data, $status_code, [
      'Cache-Control' => 'no-cache, no-store, must-revalidate',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

  /**
   * Creates a secure payment error response.
   *
   * @param string $internal_error
   *   The actual payment error (logged securely).
   * @param string $error_type
   *   Type of payment error for specific messaging.
   *
   * @return array
   *   Secure payment result array.
   */
  public function createPaymentErrorResponse($internal_error, $error_type = 'general') {
    // Log payment error with extra context
    $this->logger->error('Payment Error: @error', [
      '@error' => $internal_error,
      'error_type' => $error_type,
      'ip' => \Drupal::request()->getClientIp(),
      'session_id' => \Drupal::service('session')->getId(),
    ]);

    // Return user-friendly error messages based on error type
    $public_messages = [
      'payment_declined' => 'Your payment was declined. Please check your card details and try again.',
      'network_error' => 'We\'re experiencing connectivity issues. Please try again in a moment.',
      'validation_error' => 'Please check your payment information and try again.',
      'system_error' => 'We\'re experiencing technical difficulties. Please try again later.',
      'general' => 'We were unable to process your payment. Please try again or contact support.',
    ];

    $public_message = $public_messages[$error_type] ?? $public_messages['general'];

    return [
      'success' => FALSE,
      'error' => $this->debugMode ? $internal_error : $public_message,
      'error_code' => $this->generateErrorCode(),
      'retry_allowed' => in_array($error_type, ['network_error', 'system_error']),
    ];
  }

  /**
   * Creates a secure webhook error response.
   *
   * @param string $internal_error
   *   The actual error (logged securely).
   * @param string $webhook_type
   *   Type of webhook (stripe, paypal, etc.).
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Secure webhook response.
   */
  public function createWebhookErrorResponse($internal_error, $webhook_type = 'unknown') {
    // Log webhook error with specific context
    $this->logger->error('Webhook Error (@type): @error', [
      '@error' => $internal_error,
      '@type' => $webhook_type,
      'ip' => \Drupal::request()->getClientIp(),
      'user_agent' => \Drupal::request()->headers->get('user-agent'),
      'payload_size' => strlen(\Drupal::request()->getContent()),
    ]);

    // Webhook responses should be minimal and generic
    return new Response('Webhook processing failed', 400, [
      'Content-Type' => 'text/plain',
      'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
  }

  /**
   * Generates a unique error code for tracking.
   *
   * @return string
   *   Short error tracking code.
   */
  protected function generateErrorCode() {
    return 'ERR_' . strtoupper(substr(md5(microtime() . mt_rand()), 0, 8));
  }

  /**
   * Logs authentication/authorization failures securely.
   *
   * @param string $attempt_type
   *   Type of auth attempt (webhook, api, form).
   * @param string $reason
   *   Internal reason for failure.
   * @param array $context
   *   Additional context.
   */
  public function logAuthFailure($attempt_type, $reason, array $context = []) {
    $this->logger->warning('Authentication failure (@type): @reason', array_merge([
      '@type' => $attempt_type,
      '@reason' => $reason,
      'ip' => \Drupal::request()->getClientIp(),
      'user_agent' => \Drupal::request()->headers->get('user-agent'),
      'uri' => \Drupal::request()->getRequestUri(),
    ], $context));
  }

  /**
   * Sanitizes error messages by removing sensitive information.
   *
   * @param string $error_message
   *   Raw error message.
   *
   * @return string
   *   Sanitized error message.
   */
  public function sanitizeErrorMessage($error_message) {
    if ($this->debugMode) {
      return $error_message;
    }

    // Remove sensitive patterns
    $sensitive_patterns = [
      '/\/[a-zA-Z0-9_\-\/\.]*\.php/', // File paths
      '/\/var\/www\/[^s]*/', // Web paths
      '/\/home\/[^s]*/', // Home directories  
      '/password[s]?[:\s=]/i', // Password references
      '/api[_\s]key[s]?[:\s=]/i', // API key references
      '/secret[s]?[:\s=]/i', // Secret references
      '/database[:\s]/i', // Database references
      '/mysql[:\s]/i', // MySQL references
      '/drupal[:\s]/i', // Drupal internals
    ];

    $sanitized = preg_replace($sensitive_patterns, '[REDACTED]', $error_message);
    
    return $sanitized ?: 'An error occurred';
  }

}