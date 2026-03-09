<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit\Support;

use Drupal\Core\Lock\LockBackendInterface;

/**
 * Flock-based lock backend shared across forked processes.
 */
class FlockLockBackend implements LockBackendInterface {

  private string $directory;
  private array $handles = [];
  private ?string $lockId = NULL;

  public function __construct(string $directory) {
    $this->directory = $directory;
    if (!is_dir($directory)) {
      mkdir($directory, 0777, TRUE);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function acquire($name, $timeout = 30.0) {
    if (isset($this->handles[$name])) {
      return TRUE;
    }

    $handle = fopen($this->getLockPath((string) $name), 'c+');
    if ($handle === FALSE) {
      return FALSE;
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
      fclose($handle);
      return FALSE;
    }

    $this->handles[$name] = $handle;
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function lockMayBeAvailable($name) {
    $handle = fopen($this->getLockPath((string) $name), 'c+');
    if ($handle === FALSE) {
      return TRUE;
    }

    $available = flock($handle, LOCK_EX | LOCK_NB);
    if ($available) {
      flock($handle, LOCK_UN);
    }
    fclose($handle);

    return $available;
  }

  /**
   * {@inheritdoc}
   */
  public function wait($name, $delay = 30) {
    $deadline = microtime(TRUE) + (int) $delay;
    while (microtime(TRUE) < $deadline) {
      usleep(25000);
      if ($this->lockMayBeAvailable($name)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function release($name): void {
    if (!isset($this->handles[$name])) {
      return;
    }

    flock($this->handles[$name], LOCK_UN);
    fclose($this->handles[$name]);
    unset($this->handles[$name]);
  }

  /**
   * {@inheritdoc}
   */
  public function releaseAll($lockId = NULL): void {
    foreach (array_keys($this->handles) as $name) {
      $this->release($name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getLockId() {
    if ($this->lockId === NULL) {
      $this->lockId = uniqid((string) mt_rand(), TRUE);
    }
    return $this->lockId;
  }

  /**
   * Returns the filesystem path for a normalized lock name.
   */
  private function getLockPath(string $name): string {
    return $this->directory . '/' . hash('sha256', $name) . '.lock';
  }

}
