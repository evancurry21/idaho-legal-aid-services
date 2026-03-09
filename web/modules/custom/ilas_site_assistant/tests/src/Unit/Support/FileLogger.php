<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit\Support;

use Psr\Log\LoggerInterface;

/**
 * File-backed PSR logger for forked-process assertions.
 */
class FileLogger implements LoggerInterface {

  private string $path;

  public function __construct(string $path) {
    $this->path = $path;
    $directory = dirname($path);
    if (!is_dir($directory)) {
      mkdir($directory, 0777, TRUE);
    }
    if (!is_file($path)) {
      touch($path);
    }
  }

  /**
   * Returns parsed log entries.
   *
   * @return array<int, array<string, mixed>>
   *   Parsed log entries.
   */
  public function readEntries(): array {
    if (!is_file($this->path)) {
      return [];
    }

    $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
      return [];
    }

    $entries = [];
    foreach ($lines as $line) {
      $decoded = json_decode($line, TRUE);
      if (is_array($decoded)) {
        $entries[] = $decoded;
      }
    }
    return $entries;
  }

  /**
   * {@inheritdoc}
   */
  public function emergency($message, array $context = []): void {
    $this->log('emergency', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function alert($message, array $context = []): void {
    $this->log('alert', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function critical($message, array $context = []): void {
    $this->log('critical', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function error($message, array $context = []): void {
    $this->log('error', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function warning($message, array $context = []): void {
    $this->log('warning', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function notice($message, array $context = []): void {
    $this->log('notice', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function info($message, array $context = []): void {
    $this->log('info', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function debug($message, array $context = []): void {
    $this->log('debug', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = []): void {
    $record = [
      'level' => (string) $level,
      'message' => (string) $message,
      'context' => $context,
    ];
    file_put_contents($this->path, json_encode($record) . PHP_EOL, FILE_APPEND | LOCK_EX);
  }

}
