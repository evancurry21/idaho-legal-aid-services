<?php

namespace Drupal\ilas_redirect_automation\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ilas_redirect_automation\Service\CsvExportService;
use Drupal\ilas_redirect_automation\Service\RedirectAnalyzerService;
use Drupal\ilas_redirect_automation\Service\RedirectApplierService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for ILAS Redirect Automation.
 */
class RedirectAutomationCommands extends DrushCommands {

  /**
   * The redirect analyzer service.
   *
   * @var \Drupal\ilas_redirect_automation\Service\RedirectAnalyzerService
   */
  protected $analyzer;

  /**
   * The redirect applier service.
   *
   * @var \Drupal\ilas_redirect_automation\Service\RedirectApplierService
   */
  protected $applier;

  /**
   * The CSV export service.
   *
   * @var \Drupal\ilas_redirect_automation\Service\CsvExportService
   */
  protected $csvExport;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $loggerService;

  /**
   * Constructs RedirectAutomationCommands.
   */
  public function __construct(
    RedirectAnalyzerService $analyzer,
    RedirectApplierService $applier,
    CsvExportService $csv_export,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct();
    $this->analyzer = $analyzer;
    $this->applier = $applier;
    $this->csvExport = $csv_export;
    $this->configFactory = $config_factory;
    $this->loggerService = $logger_factory->get('ilas_redirect_automation');
  }

  /**
   * Show 404 redirect statistics.
   *
   * @command redirect:analyze
   * @aliases ra,redirect-analyze
   * @option detailed Show detailed category breakdown
   * @usage redirect:analyze
   *   Show summary statistics
   * @usage redirect:analyze --detailed
   *   Show detailed category breakdown
   */
  public function analyze($options = ['detailed' => FALSE]) {
    $this->output()->writeln('<info>Analyzing 404 data...</info>');
    $this->output()->writeln('');

    $stats = $this->analyzer->getStatistics();

    $this->output()->writeln('<comment>Summary:</comment>');
    $this->output()->writeln(sprintf('  Total 404 entries:    %d', $stats['total']));
    $this->output()->writeln(sprintf('  Resolved:             %d (%.1f%%)', $stats['resolved'], ($stats['total'] > 0 ? $stats['resolved'] / $stats['total'] * 100 : 0)));
    $this->output()->writeln(sprintf('  Unresolved:           %d (%.1f%%)', $stats['unresolved'], ($stats['total'] > 0 ? $stats['unresolved'] / $stats['total'] * 100 : 0)));
    $this->output()->writeln(sprintf('  Would be ignored:     %d', $stats['would_be_ignored']));
    $this->output()->writeln('');

    if ($options['detailed']) {
      $this->output()->writeln('<comment>Category Breakdown:</comment>');
      $this->output()->writeln('');
      $this->output()->writeln(sprintf('  %-15s %10s %12s', 'Category', 'Paths', 'Total Hits'));
      $this->output()->writeln(sprintf('  %-15s %10s %12s', '--------', '-----', '----------'));

      foreach ($stats['categories'] as $category => $count) {
        $hits = $stats['category_hits'][$category] ?? 0;
        $this->output()->writeln(sprintf('  %-15s %10d %12d', $category, $count, $hits));
      }
    }

    $this->output()->writeln('');
    $this->output()->writeln('<info>Run "drush redirect:preview" to generate redirect proposals.</info>');

    return 0;
  }

  /**
   * Preview proposed redirects without making changes.
   *
   * @command redirect:preview
   * @aliases rp,redirect-preview
   * @option category Filter by category (node, topics, taxonomy, files)
   * @option min-confidence Minimum confidence threshold (default: 0)
   * @option min-hits Minimum hit count (default: 1)
   * @option output Output file path (default: auto-generated in /tmp)
   * @option limit Maximum entries to process (default: unlimited)
   * @usage redirect:preview
   *   Generate preview CSV of all proposed redirects
   * @usage redirect:preview --category=node --min-confidence=70
   *   Preview only node paths with 70%+ confidence
   * @usage redirect:preview --output=/tmp/redirects.csv
   *   Save to specific file
   */
  public function preview($options = [
    'category' => NULL,
    'min-confidence' => 0,
    'min-hits' => 1,
    'output' => NULL,
    'limit' => 0,
  ]) {
    $this->output()->writeln('<info>Generating redirect preview...</info>');
    $this->output()->writeln('');

    $analyzeOptions = [
      'category' => $options['category'],
      'min_confidence' => (int) $options['min-confidence'],
      'min_hits' => (int) $options['min-hits'],
      'limit' => (int) $options['limit'],
    ];

    // Analyze 404 data
    $this->output()->writeln('Analyzing 404 entries...');
    $proposals = $this->analyzer->analyze($analyzeOptions);

    if (empty($proposals)) {
      $this->output()->writeln('<comment>No proposals generated.</comment>');
      return 0;
    }

    // Count by status
    $counts = [
      'pending' => 0,
      'low_confidence' => 0,
      'no_match' => 0,
    ];

    foreach ($proposals as $proposal) {
      $status = $proposal['status'] ?? 'unknown';
      if (isset($counts[$status])) {
        $counts[$status]++;
      }
    }

    $this->output()->writeln('');
    $this->output()->writeln('<comment>Results:</comment>');
    $this->output()->writeln(sprintf('  Total analyzed:     %d', count($proposals)));
    $this->output()->writeln(sprintf('  Ready to apply:     %d (change status to "approved" in CSV)', $counts['pending']));
    $this->output()->writeln(sprintf('  Low confidence:     %d (review manually)', $counts['low_confidence']));
    $this->output()->writeln(sprintf('  No match found:     %d', $counts['no_match']));
    $this->output()->writeln('');

    // Generate output filename
    $outputPath = $options['output'];
    if (empty($outputPath)) {
      $outputPath = '/tmp/' . $this->csvExport->generateFilename();
    }

    // Export to CSV
    $this->output()->writeln('Exporting to CSV...');
    $success = $this->csvExport->exportProposals($proposals, $outputPath);

    if ($success) {
      $this->output()->writeln('');
      $this->output()->writeln('<info>Preview saved to: ' . $outputPath . '</info>');
      $this->output()->writeln('');
      $this->output()->writeln('<comment>Next steps:</comment>');
      $this->output()->writeln('  1. Open the CSV file in a spreadsheet application');
      $this->output()->writeln('  2. Review each row and change "status" to "approved" for redirects you want to create');
      $this->output()->writeln('  3. Save the file');
      $this->output()->writeln('  4. Run: drush redirect:apply ' . $outputPath);
    }
    else {
      $this->output()->writeln('<error>Failed to export CSV</error>');
      return 1;
    }

    return 0;
  }

  /**
   * Apply approved redirects from CSV file.
   *
   * @command redirect:apply
   * @aliases rap,redirect-apply
   * @argument file Path to approved CSV file
   * @option dry-run Show what would be done without making changes
   * @option skip-validation Skip destination validation
   * @usage redirect:apply /path/to/approved.csv
   *   Apply redirects from approved CSV
   * @usage redirect:apply /path/to/approved.csv --dry-run
   *   Preview what would be applied without changes
   */
  public function apply($file, $options = [
    'dry-run' => FALSE,
    'skip-validation' => FALSE,
  ]) {
    if (empty($file)) {
      $this->output()->writeln('<error>Please specify a CSV file path.</error>');
      return 1;
    }

    if (!file_exists($file)) {
      $this->output()->writeln('<error>File not found: ' . $file . '</error>');
      return 1;
    }

    $dryRun = (bool) $options['dry-run'];
    $skipValidation = (bool) $options['skip-validation'];

    $this->output()->writeln('<info>Applying redirects from: ' . $file . '</info>');
    if ($dryRun) {
      $this->output()->writeln('<comment>(Dry run mode - no changes will be made)</comment>');
    }
    if ($skipValidation) {
      $this->output()->writeln('<comment>(Skipping destination validation)</comment>');
    }
    $this->output()->writeln('');

    // Validate CSV format
    $this->output()->writeln('Validating CSV format...');
    $validation = $this->csvExport->validateCsvFormat($file);

    if (!$validation['valid']) {
      $this->output()->writeln('<error>CSV validation failed:</error>');
      foreach ($validation['errors'] as $error) {
        $this->output()->writeln('  - ' . $error);
      }
      return 1;
    }

    $this->output()->writeln(sprintf('  Found %d approved entries', $validation['approved_count']));
    $this->output()->writeln('');

    if ($validation['approved_count'] === 0) {
      $this->output()->writeln('<comment>No approved entries found. Change "status" to "approved" in the CSV.</comment>');
      return 0;
    }

    // Parse approved entries
    $entries = $this->csvExport->parseApprovedCsv($file);

    // Get config for status code
    $config = $this->configFactory->get('ilas_redirect_automation.settings');
    $statusCode = $config->get('default_status_code') ?? 301;

    // Apply redirects
    $this->output()->writeln('Processing redirects...');
    $results = $this->applier->applyFromEntries($entries, $statusCode, $dryRun, $skipValidation);

    $this->output()->writeln('');
    $this->output()->writeln('<comment>Results:</comment>');
    $this->output()->writeln(sprintf('  Created:  %d', count($results['created'])));
    $this->output()->writeln(sprintf('  Skipped:  %d (already exist)', count($results['skipped'])));
    $this->output()->writeln(sprintf('  Errors:   %d', count($results['errors'])));

    if (!empty($results['errors']) && count($results['errors']) <= 10) {
      $this->output()->writeln('');
      $this->output()->writeln('<error>Errors:</error>');
      foreach ($results['errors'] as $error) {
        $this->output()->writeln(sprintf('  - %s: %s',
          $error['entry']['old_path'] ?? 'unknown',
          $error['reason'] ?? 'unknown error'
        ));
      }
    }

    if ($dryRun) {
      $this->output()->writeln('');
      $this->output()->writeln('<info>Dry run complete. Run without --dry-run to apply changes.</info>');
    }
    else {
      $this->output()->writeln('');
      $this->output()->writeln('<info>Redirects applied successfully!</info>');
    }

    return 0;
  }

  /**
   * Manage ignore patterns for 404 paths.
   *
   * @command redirect:ignore
   * @aliases ri,redirect-ignore
   * @option add Add a pattern to ignore list
   * @option remove Remove a pattern from ignore list
   * @option list List all current ignore patterns
   * @usage redirect:ignore --list
   *   Show all current ignore patterns
   * @usage redirect:ignore --add="/wp-*"
   *   Add pattern to ignore WordPress probes
   * @usage redirect:ignore --remove="/wp-*"
   *   Remove a pattern from the ignore list
   */
  public function ignore($options = [
    'add' => NULL,
    'remove' => NULL,
    'list' => FALSE,
  ]) {
    $config = $this->configFactory->getEditable('ilas_redirect_automation.settings');
    $patterns = $config->get('ignore_patterns') ?? [];

    if ($options['list']) {
      $this->output()->writeln('<comment>Current ignore patterns:</comment>');
      if (empty($patterns)) {
        $this->output()->writeln('  (none)');
      }
      else {
        foreach ($patterns as $pattern) {
          $this->output()->writeln('  - ' . $pattern);
        }
      }
      return 0;
    }

    if (!empty($options['add'])) {
      $pattern = $options['add'];
      if (!in_array($pattern, $patterns)) {
        $patterns[] = $pattern;
        $config->set('ignore_patterns', $patterns)->save();
        $this->output()->writeln('<info>Added pattern: ' . $pattern . '</info>');
      }
      else {
        $this->output()->writeln('<comment>Pattern already exists: ' . $pattern . '</comment>');
      }
      return 0;
    }

    if (!empty($options['remove'])) {
      $pattern = $options['remove'];
      $index = array_search($pattern, $patterns);
      if ($index !== FALSE) {
        unset($patterns[$index]);
        $patterns = array_values($patterns);
        $config->set('ignore_patterns', $patterns)->save();
        $this->output()->writeln('<info>Removed pattern: ' . $pattern . '</info>');
      }
      else {
        $this->output()->writeln('<comment>Pattern not found: ' . $pattern . '</comment>');
      }
      return 0;
    }

    $this->output()->writeln('Usage:');
    $this->output()->writeln('  drush redirect:ignore --list');
    $this->output()->writeln('  drush redirect:ignore --add="pattern"');
    $this->output()->writeln('  drush redirect:ignore --remove="pattern"');

    return 0;
  }

  /**
   * Validate a single destination path.
   *
   * @command redirect:validate
   * @aliases rv,redirect-validate
   * @argument path The destination path to validate
   * @usage redirect:validate /resources/evictions
   *   Check if the path exists
   */
  public function validate($path) {
    if (empty($path)) {
      $this->output()->writeln('<error>Please specify a path to validate.</error>');
      return 1;
    }

    $this->output()->writeln('Validating path: ' . $path);

    $isValid = $this->applier->validateDestination($path);

    if ($isValid) {
      $this->output()->writeln('<info>Path is valid and accessible.</info>');
      return 0;
    }
    else {
      $this->output()->writeln('<error>Path does not exist or is not accessible.</error>');
      return 1;
    }
  }

}
