<?php

namespace Drupal\ilas_site_assistant\Service;

use PHPUnit\Framework\TestCase;

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

  /**
   * Returns TRUE when the current process is a dev or automated-test run.
   *
   * Used by guards that should fail loudly during development and CI but
   * degrade to safe fallbacks in production. Detection covers:
   * - DDEV containers (IS_DDEV_PROJECT env)
   * - PHPUnit / Drupal kernel test bootstrap (DRUPAL_TEST_IN_CHILD_SITE,
   *   SIMPLETEST_DB)
   * - Explicit Pantheon dev/test/multidev environments.
   */
  public function isDevOrTestEnvironment(): bool {
    $pantheon = $this->getPantheonEnvironment();
    if (is_string($pantheon)) {
      if (in_array($pantheon, ['dev', 'test'], TRUE)) {
        return TRUE;
      }
      if ($pantheon !== 'live'
        && preg_match('/^(dev|test|qa|stage|sandbox|multidev|pr-)/i', $pantheon) === 1) {
        return TRUE;
      }
    }

    if (getenv('IS_DDEV_PROJECT') || isset($_ENV['IS_DDEV_PROJECT'])) {
      return TRUE;
    }
    if (defined('DRUPAL_TEST_IN_CHILD_SITE') && DRUPAL_TEST_IN_CHILD_SITE) {
      return TRUE;
    }
    if (getenv('SIMPLETEST_DB') || getenv('SIMPLETEST_BASE_URL')) {
      return TRUE;
    }
    if (defined('PHPUNIT_COMPOSER_INSTALL')
      || class_exists(TestCase::class, FALSE)) {
      return TRUE;
    }

    return FALSE;
  }

}
