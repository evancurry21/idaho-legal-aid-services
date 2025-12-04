<?php

namespace Drupal\ilas_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ilas_reports\Service\MetricsService;
use Drupal\ilas_reports\Service\DashboardService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * API controller for metrics.
 */
class MetricsApiController extends ControllerBase {

  /**
   * The metrics service.
   *
   * @var \Drupal\ilas_reports\Service\MetricsService
   */
  protected $metricsService;

  /**
   * The dashboard service.
   *
   * @var \Drupal\ilas_reports\Service\DashboardService
   */
  protected $dashboardService;

  /**
   * Constructs a MetricsApiController.
   */
  public function __construct(
    MetricsService $metrics_service,
    DashboardService $dashboard_service
  ) {
    $this->metricsService = $metrics_service;
    $this->dashboardService = $dashboard_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ilas_reports.metrics'),
      $container->get('ilas_reports.dashboard')
    );
  }

  /**
   * Get specific metric.
   */
  public function getMetric($metric, Request $request) {
    try {
      $parameters = $request->query->all();
      $data = $this->metricsService->getMetric($metric, $parameters);
      
      return new JsonResponse([
        'success' => TRUE,
        'metric' => $metric,
        'data' => $data,
        'timestamp' => time(),
      ]);
    }
    catch (\Exception $e) {
      // Log the detailed error securely
      \Drupal::logger('ilas_reports.api')->error('Metrics API error: @error', [
        '@error' => $e->getMessage(),
        'ip' => \Drupal::request()->getClientIp(),
        'uri' => \Drupal::request()->getRequestUri(),
      ]);

      // Return generic error to client
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'An error occurred while fetching metrics data. Please try again.',
        'error_code' => 'ERR_' . strtoupper(substr(md5(microtime()), 0, 8)),
      ], 500);
    }
  }

  /**
   * Get dashboard data.
   */
  public function getDashboard() {
    try {
      $dashboard = $this->dashboardService->getDashboard();
      
      return new JsonResponse([
        'success' => TRUE,
        'dashboard' => $dashboard,
        'timestamp' => time(),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
      ], 500);
    }
  }
}