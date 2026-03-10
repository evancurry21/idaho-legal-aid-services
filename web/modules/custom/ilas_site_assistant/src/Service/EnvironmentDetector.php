<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Resolves the current Pantheon environment in a consistent way.
 */
final class EnvironmentDetector {

  /**
   * Returns the normalized Pantheon environment name, if present.
   */
  public function getPantheonEnvironment(): ?string {
    $pantheon_env = getenv('PANTHEON_ENVIRONMENT');
    if (is_string($pantheon_env) && trim($pantheon_env) !== '') {
      return strtolower(trim($pantheon_env));
    }

    $pantheon_env = $_ENV['PANTHEON_ENVIRONMENT'] ?? NULL;
    if (is_string($pantheon_env) && trim($pantheon_env) !== '') {
      return strtolower(trim($pantheon_env));
    }

    return NULL;
  }

  /**
   * Returns TRUE when running in Pantheon live environment.
   */
  public function isLiveEnvironment(): bool {
    return $this->getPantheonEnvironment() === 'live';
  }

}
