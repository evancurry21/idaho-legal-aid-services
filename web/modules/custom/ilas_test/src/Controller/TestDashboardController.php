<?php

namespace Drupal\ilas_test\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ilas_test\TestRunner;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for test dashboard.
 */
class TestDashboardController extends ControllerBase {

  /**
   * The test runner.
   *
   * @var \Drupal\ilas_test\TestRunner
   */
  protected $testRunner;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a TestDashboardController.
   */
  public function __construct(TestRunner $test_runner, FileSystemInterface $file_system) {
    $this->testRunner = $test_runner;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ilas_test.runner'),
      $container->get('file_system')
    );
  }

  /**
   * Test dashboard page.
   */
  public function dashboard() {
    $build = [
      '#theme' => 'test_dashboard',
      '#attached' => [
        'library' => ['ilas_test/dashboard'],
      ],
    ];

    // Get latest test results
    $latest_results = $this->getLatestTestResults();
    if ($latest_results) {
      $build['#results'] = $latest_results;
    }

    // Add test execution form
    $build['execute_form'] = \Drupal::formBuilder()->getForm('Drupal\ilas_test\Form\ExecuteTestsForm');

    return $build;
  }

  /**
   * Run tests via AJAX.
   */
  public function runTests() {
    try {
      $report = $this->testRunner->runAllTests();

      return new JsonResponse([
        'success' => TRUE,
        'report' => $report,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('ilas_test')->error('Test run failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Test execution failed. Check the site logs for details.',
      ], 500);
    }
  }

  /**
   * Get test history.
   */
  public function history() {
    $reports = $this->getTestReports();

    $build = [
      '#theme' => 'table',
      '#header' => [
        $this->t('Date'),
        $this->t('Duration'),
        $this->t('Tests'),
        $this->t('Passed'),
        $this->t('Failed'),
        $this->t('Pass Rate'),
        $this->t('Actions'),
      ],
      '#rows' => [],
      '#empty' => $this->t('No test reports found.'),
    ];

    foreach ($reports as $report) {
      $build['#rows'][] = [
        date('Y-m-d H:i:s', $report['timestamp']),
        $report['duration'] . 's',
        $report['summary']['total_tests'],
        $report['summary']['passed'],
        $report['summary']['failed'],
        $report['summary']['pass_rate'] . '%',
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('View'),
            '#url' => \Drupal\Core\Url::fromRoute('ilas_test.report', [
              'report_id' => $report['id'],
            ]),
          ],
        ],
      ];
    }

    return $build;
  }

  /**
   * View specific test report.
   */
  public function viewReport($report_id) {
    $report = $this->loadTestReport($report_id);

    if (!$report) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $build = [
      '#theme' => 'test_report',
      '#report' => $report,
      '#attached' => [
        'library' => ['ilas_test/report'],
      ],
    ];

    return $build;
  }

  /**
   * Get latest test results.
   */
  protected function getLatestTestResults() {
    $reports = $this->getTestReports(1);
    return !empty($reports) ? reset($reports) : NULL;
  }

  /**
   * Get test reports.
   */
  protected function getTestReports($limit = 10) {
    $directory = 'private://test-reports';

    try {
      $files = $this->fileSystem->scanDirectory($directory, '/\.json$/');
    }
    catch (\Exception $e) {
      return [];
    }

    // Sort by timestamp descending
    usort($files, function($a, $b) {
      return filemtime($b->uri) - filemtime($a->uri);
    });

    $reports = [];
    $count = 0;

    foreach ($files as $file) {
      if ($count >= $limit) {
        break;
      }

      $content = file_get_contents($file->uri);
      $report = json_decode($content, TRUE);

      if ($report) {
        $report['id'] = basename($file->uri, '.json');
        $reports[] = $report;
        $count++;
      }
    }

    return $reports;
  }

  /**
   * Load specific test report.
   */
  protected function loadTestReport($report_id) {
    // Defense-in-depth: validate report_id even though route regex constrains it.
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $report_id)) {
      return NULL;
    }

    $filepath = 'private://test-reports/' . $report_id . '.json';

    if (!file_exists($filepath)) {
      return NULL;
    }

    $content = file_get_contents($filepath);
    $report = json_decode($content, TRUE);

    if ($report) {
      $report['id'] = $report_id;
    }

    return $report;
  }
}
