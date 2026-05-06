<?php

namespace Drupal\ilas_redirect_automation\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for applying approved redirects.
 */
class RedirectApplierService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a RedirectApplierService object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->logger = $logger_factory->get('ilas_redirect_automation');
  }

  /**
   * Apply redirects from parsed CSV entries.
   *
   * @param array $entries
   *   Array of entries with old_path and proposed_destination.
   * @param int $statusCode
   *   The HTTP status code for redirects.
   * @param bool $dryRun
   *   If TRUE, only simulate without making changes.
   * @param bool $skipValidation
   *   If TRUE, skip destination validation.
   *
   * @return array
   *   Results with keys: created, skipped, errors.
   */
  public function applyFromEntries(array $entries, int $statusCode = 301, bool $dryRun = FALSE, bool $skipValidation = FALSE): array {
    $results = [
      'created' => [],
      'skipped' => [],
      'errors' => [],
    ];

    $redirectStorage = $this->entityTypeManager->getStorage('redirect');

    foreach ($entries as $entry) {
      $sourcePath = $entry['old_path'];
      $destination = $entry['proposed_destination'];

      // Validate source path.
      if (empty($sourcePath)) {
        $results['errors'][] = [
          'entry' => $entry,
          'reason' => 'Empty source path',
        ];
        continue;
      }

      // Validate destination.
      if (empty($destination)) {
        $results['errors'][] = [
          'entry' => $entry,
          'reason' => 'Empty destination',
        ];
        continue;
      }

      // Check if redirect already exists.
      if ($this->redirectExists($sourcePath)) {
        $results['skipped'][] = [
          'entry' => $entry,
          'reason' => 'Redirect already exists',
        ];
        continue;
      }

      // Validate destination exists (unless skipped)
      if (!$skipValidation && !$this->validateDestination($destination)) {
        $results['errors'][] = [
          'entry' => $entry,
          'reason' => 'Destination does not exist: ' . $destination,
        ];
        continue;
      }

      if ($dryRun) {
        $results['created'][] = [
          'entry' => $entry,
          'note' => 'Would be created (dry run)',
        ];
        continue;
      }

      // Create the redirect.
      try {
        $redirect = $this->createRedirect($sourcePath, $destination, $statusCode);

        if ($redirect) {
          $results['created'][] = [
            'entry' => $entry,
            'redirect_id' => $redirect->id(),
          ];

          // Mark as resolved in redirect_404 table.
          $this->markResolved($sourcePath);
        }
        else {
          $results['errors'][] = [
            'entry' => $entry,
            'reason' => 'Failed to create redirect entity',
          ];
        }
      }
      catch (\Exception $e) {
        $results['errors'][] = [
          'entry' => $entry,
          'reason' => $e->getMessage(),
        ];
      }
    }

    $this->logger->info('Apply results: @created created, @skipped skipped, @errors errors', [
      '@created' => count($results['created']),
      '@skipped' => count($results['skipped']),
      '@errors' => count($results['errors']),
    ]);

    return $results;
  }

  /**
   * Check if a redirect already exists for a source path.
   *
   * @param string $sourcePath
   *   The source path.
   *
   * @return bool
   *   TRUE if redirect exists.
   */
  protected function redirectExists(string $sourcePath): bool {
    // Normalize path (remove leading slash for storage)
    $path = ltrim($sourcePath, '/');

    $query = $this->database->select('redirect', 'r')
      ->fields('r', ['rid'])
      ->condition('redirect_source__path', $path)
      ->range(0, 1);

    return (bool) $query->execute()->fetchField();
  }

  /**
   * Validate that a destination path exists.
   *
   * @param string $destination
   *   The destination path.
   *
   * @return bool
   *   TRUE if destination exists.
   */
  public function validateDestination(string $destination): bool {
    // Normalize path.
    $path = '/' . ltrim($destination, '/');

    // Check if it's an internal path.
    if (str_starts_with($path, '/node/')) {
      // Extract node ID.
      if (preg_match('#^/node/(\d+)#', $path, $matches)) {
        $nid = $matches[1];
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
        return $node && $node->isPublished();
      }
    }

    // Check if it's a taxonomy term path.
    if (str_starts_with($path, '/taxonomy/term/')) {
      if (preg_match('#^/taxonomy/term/(\d+)#', $path, $matches)) {
        $tid = $matches[1];
        $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
        return $term && $term->isPublished();
      }
    }

    // Check if it's an alias.
    $aliasPath = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['path'])
      ->condition('alias', $path)
      ->condition('status', 1)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($aliasPath) {
      return TRUE;
    }

    // Check if it's a file path.
    if (preg_match('#\.(pdf|doc|docx|xls|xlsx|ppt|pptx|jpg|jpeg|png|gif|svg)$#i', $path)) {
      $filePath = DRUPAL_ROOT . $path;
      return file_exists($filePath);
    }

    return FALSE;
  }

  /**
   * Create a redirect entity.
   *
   * @param string $sourcePath
   *   The source path.
   * @param string $destination
   *   The destination path.
   * @param int $statusCode
   *   The HTTP status code.
   *
   * @return \Drupal\redirect\Entity\Redirect|null
   *   The created redirect or null on failure.
   */
  protected function createRedirect(string $sourcePath, string $destination, int $statusCode = 301) {
    // Normalize source path (remove leading slash)
    $source = ltrim($sourcePath, '/');

    // Determine destination format.
    if (str_starts_with($destination, '/node/')) {
      // Internal node path.
      if (preg_match('#^/node/(\d+)#', $destination, $matches)) {
        $destinationUri = 'entity:node/' . $matches[1];
      }
      else {
        $destinationUri = 'internal:' . $destination;
      }
    }
    elseif (str_starts_with($destination, '/taxonomy/term/')) {
      // Internal taxonomy path.
      if (preg_match('#^/taxonomy/term/(\d+)#', $destination, $matches)) {
        $destinationUri = 'entity:taxonomy_term/' . $matches[1];
      }
      else {
        $destinationUri = 'internal:' . $destination;
      }
    }
    else {
      // Use internal path.
      $destinationUri = 'internal:' . $destination;
    }

    try {
      $redirectStorage = $this->entityTypeManager->getStorage('redirect');

      $redirect = $redirectStorage->create([
        'redirect_source' => [
          'path' => $source,
          'query' => [],
        ],
        'redirect_redirect' => [
          'uri' => $destinationUri,
          'title' => '',
          'options' => [],
        ],
        'status_code' => $statusCode,
        'language' => 'und',
      ]);

      $redirect->save();

      $this->logger->info('Created redirect: @source -> @dest', [
        '@source' => $source,
        '@dest' => $destinationUri,
      ]);

      return $redirect;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create redirect for @source: @error', [
        '@source' => $source,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Mark a 404 entry as resolved.
   *
   * @param string $path
   *   The path to mark as resolved.
   */
  protected function markResolved(string $path): void {
    try {
      $this->database->update('redirect_404')
        ->fields(['resolved' => 1])
        ->condition('path', $path)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to mark path as resolved: @path - @error', [
        '@path' => $path,
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
