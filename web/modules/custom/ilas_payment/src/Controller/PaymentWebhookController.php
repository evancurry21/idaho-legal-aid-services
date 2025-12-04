<?php

namespace Drupal\ilas_payment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\ilas_payment\Service\SecureErrorHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ilas_payment\Service\StripePaymentService;
use Drupal\ilas_payment\Service\PayPalPaymentService;

/**
 * Controller for payment webhook endpoints.
 */
class PaymentWebhookController extends ControllerBase {

  /**
   * The Stripe payment service.
   *
   * @var \Drupal\ilas_payment\Service\StripePaymentService
   */
  protected $stripeService;

  /**
   * The PayPal payment service.
   *
   * @var \Drupal\ilas_payment\Service\PayPalPaymentService
   */
  protected $paypalService;

  /**
   * The secure error handler.
   *
   * @var \Drupal\ilas_payment\Service\SecureErrorHandler
   */
  protected $errorHandler;

  /**
   * Constructs a PaymentWebhookController.
   */
  public function __construct(StripePaymentService $stripe_service, PayPalPaymentService $paypal_service, SecureErrorHandler $error_handler) {
    $this->stripeService = $stripe_service;
    $this->paypalService = $paypal_service;
    $this->errorHandler = $error_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ilas_payment.stripe'),
      $container->get('ilas_payment.paypal'),
      $container->get('ilas_payment.secure_error_handler')
    );
  }

  /**
   * Handle Stripe webhook.
   */
  public function stripeWebhook(Request $request) {
    $payload = $request->getContent();
    $signature = $request->headers->get('Stripe-Signature');
    
    $this->getLogger('ilas_payment')->info('Stripe webhook received');
    
    $result = $this->stripeService->handleWebhook($payload, $signature);
    
    if ($result['success']) {
      return new Response('OK', 200);
    }
    else {
      return $this->errorHandler->createWebhookErrorResponse(
        $result['error'] ?? 'Unknown webhook processing error',
        'stripe'
      );
    }
  }

  /**
   * Handle PayPal IPN.
   */
  public function paypalIpn(Request $request) {
    $post_data = $request->request->all();
    
    $this->getLogger('ilas_payment')->info('PayPal IPN received');
    
    try {
      $result = $this->paypalService->processIpn($post_data);
      if ($result) {
        return new Response('OK', 200);
      }
      else {
        return $this->errorHandler->createWebhookErrorResponse(
          'PayPal IPN processing failed',
          'paypal'
        );
      }
    }
    catch (\Exception $e) {
      return $this->errorHandler->createWebhookErrorResponse(
        $e->getMessage(),
        'paypal'
      );
    }
  }
}