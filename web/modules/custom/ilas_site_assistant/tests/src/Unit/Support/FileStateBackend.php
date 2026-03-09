<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit\Support;

use Drupal\Core\State\StateInterface;

/**
 * File-backed State API test double shared across forked processes.
 */
class FileStateBackend implements StateInterface {

  private string $path;
  private int $readDelayMicros;
  private array $valuesSetDuringRequest = [];

  public function __construct(string $path, int $readDelayMicros = 0) {
    $this->path = $path;
    $this->readDelayMicros = max(0, $readDelayMicros);

    $directory = dirname($path);
    if (!is_dir($directory)) {
      mkdir($directory, 0777, TRUE);
    }
    if (!is_file($path)) {
      file_put_contents($path, json_encode([]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function get($key, $default = NULL) {
    $data = $this->readData();
    return array_key_exists($key, $data) ? $data[$key] : $default;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array $keys) {
    $data = $this->readData();
    $results = [];
    foreach ($keys as $key) {
      if (array_key_exists($key, $data)) {
        $results[$key] = $data[$key];
      }
    }
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value): void {
    $data = $this->readData();
    $original = array_key_exists($key, $data) ? $data[$key] : NULL;
    $data[$key] = $value;
    $this->writeData($data);
    $this->valuesSetDuringRequest[$key] = [
      'value' => $value,
      'original' => $original,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $data): void {
    $existing = $this->readData();
    foreach ($data as $key => $value) {
      $this->valuesSetDuringRequest[$key] = [
        'value' => $value,
        'original' => $existing[$key] ?? NULL,
      ];
      $existing[$key] = $value;
    }
    $this->writeData($existing);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($key): void {
    $data = $this->readData();
    unset($data[$key]);
    $this->writeData($data);
    unset($this->valuesSetDuringRequest[$key]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $keys): void {
    $data = $this->readData();
    foreach ($keys as $key) {
      unset($data[$key], $this->valuesSetDuringRequest[$key]);
    }
    $this->writeData($data);
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(): void {
    $this->valuesSetDuringRequest = [];
  }

  /**
   * {@inheritdoc}
   */
  public function getValuesSetDuringRequest(string $key): ?array {
    return $this->valuesSetDuringRequest[$key] ?? NULL;
  }

  /**
   * Reads the full backing store under a shared file lock.
   */
  private function readData(): array {
    $handle = fopen($this->path, 'c+');
    if ($handle === FALSE) {
      return [];
    }

    flock($handle, LOCK_SH);
    rewind($handle);
    $contents = stream_get_contents($handle);
    if ($this->readDelayMicros > 0) {
      usleep($this->readDelayMicros);
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    $decoded = json_decode($contents ?: '[]', TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Writes the full backing store under an exclusive file lock.
   */
  private function writeData(array $data): void {
    $handle = fopen($this->path, 'c+');
    if ($handle === FALSE) {
      return;
    }

    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
  }

}
