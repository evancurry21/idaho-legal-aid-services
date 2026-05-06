<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\ilas_site_assistant\Access\AssistantDiagnosticsAccessCheck;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Covers private machine-auth access to assistant diagnostics routes.
 */
#[Group('ilas_site_assistant')]
final class AssistantDiagnosticsAccessCheckTest extends TestCase {

  /**
   * The access checker under test.
   */
  private AssistantDiagnosticsAccessCheck $checker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings([]);
    putenv('ILAS_ASSISTANT_DIAGNOSTICS_TOKEN');
    unset($_ENV['ILAS_ASSISTANT_DIAGNOSTICS_TOKEN']);

    $this->checker = new AssistantDiagnosticsAccessCheck();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    putenv('ILAS_ASSISTANT_DIAGNOSTICS_TOKEN');
    unset($_ENV['ILAS_ASSISTANT_DIAGNOSTICS_TOKEN']);
    parent::tearDown();
  }

  /**
   * The checker applies only when the custom requirement is present.
   */
  public function testAppliesToDiagnosticsRoutes(): void {
    $route = new Route('/assistant/api/health', [], [
      '_ilas_diagnostics_access' => 'TRUE',
      '_method' => 'GET',
    ]);
    $this->assertTrue($this->checker->applies($route));
  }

  /**
   * The checker does not apply to unrelated routes.
   */
  public function testDoesNotApplyWithoutCustomRequirement(): void {
    $route = new Route('/assistant/api/health', [], [
      '_permission' => 'view ilas site assistant reports',
      '_method' => 'GET',
    ]);
    $this->assertFalse($this->checker->applies($route));
  }

  /**
   * Drupal users with the reports permission still have access.
   */
  public function testPermissionAllowsAccessWithoutMachineToken(): void {
    $request = Request::create('/assistant/api/health', 'GET');

    $result = $this->checker->access($request, $this->createAccount(TRUE));

    $this->assertTrue($result->isAllowed());
  }

  /**
   * A valid header token from settings grants access without Drupal auth.
   */
  public function testValidHeaderAllowsAccessWhenSettingsTokenConfigured(): void {
    new Settings([
      'ilas_assistant_diagnostics_token' => 'settings-token',
    ]);

    $request = Request::create('/assistant/api/health', 'GET');
    $request->headers->set('X-ILAS-Observability-Key', 'settings-token');

    $result = $this->checker->access($request, $this->createAccount(FALSE));

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Settings take precedence over getenv() when both are present.
   */
  public function testSettingsTokenWinsOverEnvironmentFallback(): void {
    new Settings([
      'ilas_assistant_diagnostics_token' => 'settings-token',
    ]);
    putenv('ILAS_ASSISTANT_DIAGNOSTICS_TOKEN=env-token');
    $_ENV['ILAS_ASSISTANT_DIAGNOSTICS_TOKEN'] = 'env-token';

    $request = Request::create('/assistant/api/metrics', 'GET');
    $request->headers->set('X-ILAS-Observability-Key', 'env-token');

    $result = $this->checker->access($request, $this->createAccount(FALSE));

    $this->assertTrue($result->isForbidden());

    $request->headers->set('X-ILAS-Observability-Key', 'settings-token');
    $result = $this->checker->access($request, $this->createAccount(FALSE));

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Getenv() is used when settings.php has not injected the runtime token.
   */
  public function testEnvironmentFallbackAllowsValidHeader(): void {
    putenv('ILAS_ASSISTANT_DIAGNOSTICS_TOKEN=env-token');
    $_ENV['ILAS_ASSISTANT_DIAGNOSTICS_TOKEN'] = 'env-token';

    $request = Request::create('/assistant/api/health', 'GET');
    $request->headers->set('X-ILAS-Observability-Key', 'env-token');

    $result = $this->checker->access($request, $this->createAccount(FALSE));

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Missing headers are denied when no Drupal permission is present.
   */
  public function testMissingHeaderIsForbiddenWithoutPermission(): void {
    new Settings([
      'ilas_assistant_diagnostics_token' => 'settings-token',
    ]);

    $request = Request::create('/assistant/api/health', 'GET');

    $result = $this->checker->access($request, $this->createAccount(FALSE));

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Invalid headers are denied when no Drupal permission is present.
   */
  public function testInvalidHeaderIsForbiddenWithoutPermission(): void {
    new Settings([
      'ilas_assistant_diagnostics_token' => 'settings-token',
    ]);

    $request = Request::create('/assistant/api/metrics', 'GET');
    $request->headers->set('X-ILAS-Observability-Key', 'wrong-token');

    $result = $this->checker->access($request, $this->createAccount(FALSE));

    $this->assertTrue($result->isForbidden());
  }

  /**
   * No configured secret falls back to permission-only access.
   */
  public function testNoConfiguredSecretFallsBackToPermissionOnly(): void {
    $request = Request::create('/assistant/api/health', 'GET');
    $request->headers->set('X-ILAS-Observability-Key', 'untrusted-token');

    $result = $this->checker->access($request, $this->createAccount(FALSE));

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Creates a mock account with or without the report-view permission.
   */
  private function createAccount(bool $hasPermission): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->with('view ilas site assistant reports')
      ->willReturn($hasPermission);
    return $account;
  }

}
