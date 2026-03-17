<?php

namespace Drupal\ilas_site_assistant\Access;

use Drupal\Core\Access\AccessCheckInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Allows assistant diagnostics routes for operators or machine clients.
 */
final class AssistantDiagnosticsAccessCheck implements AccessCheckInterface {

  /**
   * Route requirement key used by this access checker.
   */
  private const REQUIREMENT_KEY = '_ilas_diagnostics_access';

  /**
   * Permission that still grants ordinary Drupal-user access.
   */
  private const PERMISSION = 'view ilas site assistant reports';

  /**
   * Header used by authenticated machine callers.
   */
  private const HEADER_NAME = 'X-ILAS-Observability-Key';

  /**
   * Runtime settings key for the diagnostics token.
   */
  private const SETTINGS_KEY = 'ilas_assistant_diagnostics_token';

  /**
   * Environment variable fallback for the diagnostics token.
   */
  private const ENV_NAME = 'ILAS_ASSISTANT_DIAGNOSTICS_TOKEN';

  /**
   * {@inheritdoc}
   */
  public function applies(Route $route) {
    return array_key_exists(self::REQUIREMENT_KEY, $route->getRequirements());
  }

  /**
   * Checks access.
   */
  public function access(Request $request, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission(self::PERMISSION)) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }

    $configured_token = $this->resolveDiagnosticsToken();
    $provided_token = trim((string) $request->headers->get(self::HEADER_NAME, ''));

    if ($configured_token !== '' && $provided_token !== '' && hash_equals($configured_token, $provided_token)) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }

    return AccessResult::forbidden()
      ->setReason('Assistant diagnostics access denied')
      ->setCacheMaxAge(0);
  }

  /**
   * Resolves the runtime-only diagnostics token.
   */
  private function resolveDiagnosticsToken(): string {
    $settings_token = trim((string) Settings::get(self::SETTINGS_KEY, ''));
    if ($settings_token !== '') {
      return $settings_token;
    }

    $env_token = getenv(self::ENV_NAME);
    return is_string($env_token) ? trim($env_token) : '';
  }

}
