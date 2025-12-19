<?php

namespace Drupal\ilas_redirect_automation\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for CSV export and import operations.
 */
class CsvExportService {

  /**
   * CSV columns.
   */
  const CSV_COLUMNS = [
    'old_path',
    'hit_count',
    'category',
    'proposed_destination',
    'confidence',
    'match_type',
    'status',
    'notes',
  ];

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a CsvExportService object.
   */
  public function __construct(
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('ilas_redirect_automation');
  }

  /**
   * Export proposals to a CSV file.
   *
   * @param array $proposals
   *   Array of proposals to export.
   * @param string $filepath
   *   The file path to write to.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function exportProposals(array $proposals, string $filepath): bool {
    try {
      // Ensure directory exists
      $directory = dirname($filepath);
      if (!is_dir($directory)) {
        $this->fileSystem->mkdir($directory, NULL, TRUE);
      }

      $handle = fopen($filepath, 'w');
      if ($handle === FALSE) {
        $this->logger->error('Failed to open file for writing: @file', ['@file' => $filepath]);
        return FALSE;
      }

      // Write header
      fputcsv($handle, self::CSV_COLUMNS);

      // Write data
      foreach ($proposals as $proposal) {
        $row = [];
        foreach (self::CSV_COLUMNS as $column) {
          $row[] = $proposal[$column] ?? '';
        }
        fputcsv($handle, $row);
      }

      fclose($handle);

      $this->logger->info('Exported @count proposals to @file', [
        '@count' => count($proposals),
        '@file' => $filepath,
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Export failed: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Parse a CSV file of approved redirects.
   *
   * @param string $filepath
   *   The file path to read.
   *
   * @return array
   *   Array of parsed entries.
   */
  public function parseApprovedCsv(string $filepath): array {
    if (!file_exists($filepath)) {
      $this->logger->error('File not found: @file', ['@file' => $filepath]);
      return [];
    }

    $entries = [];
    $handle = fopen($filepath, 'r');

    if ($handle === FALSE) {
      $this->logger->error('Failed to open file: @file', ['@file' => $filepath]);
      return [];
    }

    // Read header
    $header = fgetcsv($handle);
    if ($header === FALSE) {
      fclose($handle);
      return [];
    }

    // Map header to column indices
    $columnMap = array_flip($header);

    // Read data rows
    $lineNumber = 1;
    while (($row = fgetcsv($handle)) !== FALSE) {
      $lineNumber++;

      // Map row to associative array
      $entry = [];
      foreach (self::CSV_COLUMNS as $column) {
        $index = $columnMap[$column] ?? NULL;
        $entry[$column] = ($index !== NULL && isset($row[$index])) ? $row[$index] : '';
      }

      // Only include approved entries with destinations
      if ($entry['status'] === 'approved' && !empty($entry['proposed_destination'])) {
        $entry['line_number'] = $lineNumber;
        $entries[] = $entry;
      }
    }

    fclose($handle);

    $this->logger->info('Parsed @count approved entries from @file', [
      '@count' => count($entries),
      '@file' => $filepath,
    ]);

    return $entries;
  }

  /**
   * Validate a CSV file format.
   *
   * @param string $filepath
   *   The file path to validate.
   *
   * @return array
   *   Array with 'valid' boolean and 'errors' array.
   */
  public function validateCsvFormat(string $filepath): array {
    $result = [
      'valid' => TRUE,
      'errors' => [],
    ];

    if (!file_exists($filepath)) {
      $result['valid'] = FALSE;
      $result['errors'][] = 'File not found: ' . $filepath;
      return $result;
    }

    $handle = fopen($filepath, 'r');
    if ($handle === FALSE) {
      $result['valid'] = FALSE;
      $result['errors'][] = 'Cannot open file: ' . $filepath;
      return $result;
    }

    // Check header
    $header = fgetcsv($handle);
    if ($header === FALSE) {
      $result['valid'] = FALSE;
      $result['errors'][] = 'Cannot read header row';
      fclose($handle);
      return $result;
    }

    // Verify required columns exist
    $requiredColumns = ['old_path', 'proposed_destination', 'status'];
    $missingColumns = array_diff($requiredColumns, $header);

    if (!empty($missingColumns)) {
      $result['valid'] = FALSE;
      $result['errors'][] = 'Missing required columns: ' . implode(', ', $missingColumns);
    }

    // Validate some data rows
    $lineNumber = 1;
    $rowCount = 0;
    $approvedCount = 0;

    while (($row = fgetcsv($handle)) !== FALSE) {
      $lineNumber++;
      $rowCount++;

      // Check row has correct number of columns
      if (count($row) !== count($header)) {
        $result['errors'][] = sprintf('Line %d: Column count mismatch (expected %d, got %d)',
          $lineNumber, count($header), count($row));
      }

      // Count approved entries
      $statusIndex = array_search('status', $header);
      if ($statusIndex !== FALSE && isset($row[$statusIndex]) && $row[$statusIndex] === 'approved') {
        $approvedCount++;
      }
    }

    fclose($handle);

    // Add info
    $result['row_count'] = $rowCount;
    $result['approved_count'] = $approvedCount;

    return $result;
  }

  /**
   * Generate a default output filename.
   *
   * @param string $prefix
   *   Filename prefix.
   *
   * @return string
   *   The generated filename.
   */
  public function generateFilename(string $prefix = 'redirect-proposals'): string {
    return sprintf('%s-%s.csv', $prefix, date('Y-m-d-His'));
  }

}
