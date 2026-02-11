<?php

namespace Drupal\ilas_test\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ilas_test\TestRunner;

/**
 * Form to execute tests.
 */
class ExecuteTestsForm extends FormBase {

  /**
   * The test runner.
   *
   * @var \Drupal\ilas_test\TestRunner
   */
  protected $testRunner;

  /**
   * Constructs an ExecuteTestsForm.
   */
  public function __construct(TestRunner $test_runner) {
    $this->testRunner = $test_runner;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ilas_test.runner')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ilas_test_execute_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['test_categories'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Test Categories'),
      '#options' => [
        'unit' => $this->t('Unit Tests'),
        'integration' => $this->t('Integration Tests'),
        'functional' => $this->t('Functional Tests'),
        'performance' => $this->t('Performance Tests'),
        'security' => $this->t('Security Tests'),
        'accessibility' => $this->t('Accessibility Tests'),
      ],
      '#default_value' => [
        'unit',
        'integration',
        'functional',
        'performance',
        'security',
        'accessibility',
      ],
    ];
    
    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Options'),
      '#open' => FALSE,
    ];
    
    $form['options']['verbose'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verbose output'),
      '#description' => $this->t('Show detailed test output.'),
    ];
    
    $form['options']['stop_on_failure'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stop on failure'),
      '#description' => $this->t('Stop execution when a test fails.'),
    ];
    
    $form['options']['email_results'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email results'),
      '#description' => $this->t('Send test results via email when complete.'),
    ];
    
    $form['actions'] = [
      '#type' => 'actions',
    ];
    
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run Tests'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::runTestsAjax',
        'wrapper' => 'test-results',
        'progress' => [
          'type' => 'bar',
          'message' => $this->t('Running tests...'),
        ],
      ],
    ];
    
    $form['results'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'test-results'],
    ];
    
    return $form;
  }

  /**
   * AJAX callback to run tests.
   */
  public function runTestsAjax(array &$form, FormStateInterface $form_state) {
    $categories = array_filter($form_state->getValue('test_categories'));
    
    try {
      $report = $this->testRunner->runAllTests();
      
      $form['results']['summary'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Test Results'),
        '#items' => [
          $this->t('Total Tests: @count', ['@count' => $report['summary']['total_tests']]),
          $this->t('Passed: @count', ['@count' => $report['summary']['passed']]),
          $this->t('Failed: @count', ['@count' => $report['summary']['failed']]),
          $this->t('Pass Rate: @rate%', ['@rate' => $report['summary']['pass_rate']]),
        ],
        '#attributes' => ['class' => ['test-summary']],
      ];
      
      if ($report['summary']['failed'] > 0) {
        $form['results']['failures'] = [
          '#type' => 'details',
          '#title' => $this->t('Failed Tests'),
          '#open' => TRUE,
          '#attributes' => ['class' => ['test-failures']],
        ];
        
        foreach ($report['results'] as $category => $results) {
          if (!empty($results['errors'])) {
            $form['results']['failures'][$category] = [
              '#theme' => 'item_list',
              '#title' => $this->t('@category errors', ['@category' => ucfirst($category)]),
              '#items' => array_map(function($error) {
                return $error['test'] . ': ' . $error['error'];
              }, $results['errors']),
            ];
          }
        }
      }
      
      $form['results']['report_link'] = [
        '#type' => 'link',
        '#title' => $this->t('View Full Report'),
        '#url' => \Drupal\Core\Url::fromRoute('ilas_test.report', [
          'report_id' => $report['id'],
        ]),
        '#attributes' => ['class' => ['button']],
      ];
    }
    catch (\Exception $e) {
      $this->getLogger('ilas_test')->error('Test run failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $form['results']['error'] = [
        '#markup' => '<div class="messages messages--error">' .
                    $this->t('Test execution failed. Check the site logs for details.') .
                    '</div>',
      ];
    }
    
    return $form['results'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Handled by AJAX
  }
}