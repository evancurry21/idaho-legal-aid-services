<?php

namespace Drupal\ilas_redirect_automation\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for matching old file paths to current file locations.
 */
class FileMatcherService {

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
   * Cache of available files.
   *
   * @var array|null
   */
  protected $fileCache = NULL;

  /**
   * Constructs a FileMatcherService object.
   */
  public function __construct(
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('ilas_redirect_automation');
  }

  /**
   * Match an old file path to a current file location.
   *
   * @param string $oldPath
   *   The old file path.
   * @param array $searchDirectories
   *   Directories to search in.
   *
   * @return array|null
   *   Match result with keys: destination, confidence, match_type, notes.
   */
  public function match(string $oldPath, array $searchDirectories): ?array {
    // Extract filename from old path
    $filename = $this->extractFilename($oldPath);

    if (empty($filename)) {
      return NULL;
    }

    // Build file cache if needed
    if ($this->fileCache === NULL) {
      $this->buildFileCache($searchDirectories);
    }

    // Try exact filename match
    $exactMatch = $this->findExactFileMatch($filename);
    if ($exactMatch) {
      return [
        'destination' => $this->buildFileUrl($exactMatch),
        'confidence' => 95,
        'match_type' => 'exact_file',
        'notes' => sprintf('Exact file match: %s', basename($exactMatch)),
      ];
    }

    // Try normalized filename match
    $normalizedMatch = $this->findNormalizedFileMatch($filename);
    if ($normalizedMatch) {
      return [
        'destination' => $this->buildFileUrl($normalizedMatch),
        'confidence' => 80,
        'match_type' => 'normalized_file',
        'notes' => sprintf('Normalized match: %s', basename($normalizedMatch)),
      ];
    }

    // Try fuzzy match
    $fuzzyMatch = $this->findFuzzyFileMatch($filename);
    if ($fuzzyMatch) {
      return [
        'destination' => $this->buildFileUrl($fuzzyMatch['path']),
        'confidence' => $fuzzyMatch['confidence'],
        'match_type' => 'fuzzy_file',
        'notes' => $fuzzyMatch['notes'],
      ];
    }

    return NULL;
  }

  /**
   * Extract filename from an old path.
   *
   * @param string $path
   *   The old path.
   *
   * @return string|null
   *   The filename or null.
   */
  protected function extractFilename(string $path): ?string {
    // Handle various old path formats
    // /files/filename.pdf
    // /sites/default/files/filename.pdf
    // /sites/default/files/subfolder/filename.pdf

    $path = ltrim($path, '/');

    // Remove common prefixes
    $prefixes = [
      'sites/default/files/',
      'files/',
    ];

    foreach ($prefixes as $prefix) {
      if (str_starts_with($path, $prefix)) {
        $path = substr($path, strlen($prefix));
        break;
      }
    }

    // Get just the filename (basename)
    $filename = basename($path);

    // Validate it looks like a file
    if (empty($filename) || strpos($filename, '.') === FALSE) {
      return NULL;
    }

    return $filename;
  }

  /**
   * Build a cache of available files in search directories.
   *
   * @param array $directories
   *   Directories to search.
   */
  protected function buildFileCache(array $directories): void {
    $this->fileCache = [];

    $basePath = DRUPAL_ROOT;

    foreach ($directories as $directory) {
      $fullPath = $basePath . '/' . ltrim($directory, '/');

      if (!is_dir($fullPath)) {
        continue;
      }

      $files = $this->scanDirectory($fullPath);
      foreach ($files as $file) {
        $relativePath = str_replace($basePath . '/', '', $file);
        $filename = basename($file);
        $normalizedName = $this->normalizeFilename($filename);

        $this->fileCache[] = [
          'path' => $file,
          'relative' => $relativePath,
          'filename' => $filename,
          'normalized' => $normalizedName,
        ];
      }
    }
  }

  /**
   * Scan a directory recursively for files.
   *
   * @param string $directory
   *   The directory to scan.
   *
   * @return array
   *   Array of file paths.
   */
  protected function scanDirectory(string $directory): array {
    $files = [];

    if (!is_dir($directory) || !is_readable($directory)) {
      return $files;
    }

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $files[] = $file->getPathname();
      }
    }

    return $files;
  }

  /**
   * Find an exact filename match.
   *
   * @param string $filename
   *   The filename to search for.
   *
   * @return string|null
   *   The matching file path or null.
   */
  protected function findExactFileMatch(string $filename): ?string {
    foreach ($this->fileCache as $file) {
      if ($file['filename'] === $filename) {
        return $file['path'];
      }
    }

    return NULL;
  }

  /**
   * Find a normalized filename match.
   *
   * @param string $filename
   *   The filename to search for.
   *
   * @return string|null
   *   The matching file path or null.
   */
  protected function findNormalizedFileMatch(string $filename): ?string {
    $normalizedSearch = $this->normalizeFilename($filename);

    foreach ($this->fileCache as $file) {
      if ($file['normalized'] === $normalizedSearch) {
        return $file['path'];
      }
    }

    return NULL;
  }

  /**
   * Find a fuzzy filename match.
   *
   * @param string $filename
   *   The filename to search for.
   *
   * @return array|null
   *   The matching result with path, confidence, notes.
   */
  protected function findFuzzyFileMatch(string $filename): ?array {
    $searchWords = $this->extractFileWords($filename);
    $searchExtension = pathinfo($filename, PATHINFO_EXTENSION);

    $bestMatch = NULL;
    $bestScore = 0;

    foreach ($this->fileCache as $file) {
      $fileWords = $this->extractFileWords($file['filename']);
      $fileExtension = pathinfo($file['filename'], PATHINFO_EXTENSION);

      // Extension must match
      if (strtolower($searchExtension) !== strtolower($fileExtension)) {
        continue;
      }

      // Calculate word overlap
      $matchingWords = array_intersect($searchWords, $fileWords);
      $matchCount = count($matchingWords);

      if ($matchCount >= 2) {
        $score = ($matchCount / max(count($searchWords), count($fileWords))) * 100;

        if ($score > $bestScore) {
          $bestScore = $score;
          $bestMatch = [
            'path' => $file['path'],
            'confidence' => min(75, 50 + ($matchCount * 8)),
            'notes' => sprintf('Matched words: %s', implode(', ', $matchingWords)),
          ];
        }
      }
    }

    return $bestMatch;
  }

  /**
   * Normalize a filename for comparison.
   *
   * @param string $filename
   *   The filename to normalize.
   *
   * @return string
   *   The normalized filename.
   */
  protected function normalizeFilename(string $filename): string {
    // Get extension
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // Get name without extension
    $name = pathinfo($filename, PATHINFO_FILENAME);

    // Lowercase
    $name = strtolower($name);

    // Replace separators with spaces
    $name = str_replace(['-', '_', '.', ' '], ' ', $name);

    // Remove extra whitespace
    $name = preg_replace('/\s+/', ' ', $name);

    // Trim
    $name = trim($name);

    return $name . '.' . $extension;
  }

  /**
   * Extract significant words from a filename.
   *
   * @param string $filename
   *   The filename.
   *
   * @return array
   *   Array of significant words.
   */
  protected function extractFileWords(string $filename): array {
    // Get name without extension
    $name = pathinfo($filename, PATHINFO_FILENAME);

    // Lowercase and split
    $name = strtolower($name);
    $name = str_replace(['-', '_', '.'], ' ', $name);

    $words = explode(' ', $name);

    // Filter short words
    return array_filter($words, function ($word) {
      return strlen($word) >= 3;
    });
  }

  /**
   * Build a public URL for a file path.
   *
   * @param string $filepath
   *   The full file path.
   *
   * @return string
   *   The public URL.
   */
  protected function buildFileUrl(string $filepath): string {
    $basePath = DRUPAL_ROOT;

    // Make path relative to web root
    if (str_starts_with($filepath, $basePath)) {
      $relativePath = substr($filepath, strlen($basePath));
    }
    else {
      $relativePath = $filepath;
    }

    // Ensure leading slash
    return '/' . ltrim($relativePath, '/');
  }

  /**
   * Clear the file cache.
   */
  public function clearCache(): void {
    $this->fileCache = NULL;
  }

}
