<?php

namespace Drupal\Tests\ilas_test\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies security hardening of the ilas_test module.
 *
 * Covers findings H-4 (path traversal) and L-7 (state-changing GET,
 * exception info leak, deprecated functions).
 *
 * @group ilas_test
 */
#[Group('ilas_test')]
class TestModuleSecurityTest extends TestCase {

  /**
   * Path to the module directory.
   */
  protected string $moduleDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->moduleDir = dirname(__DIR__, 3);
    $this->assertDirectoryExists($this->moduleDir,
      'ilas_test module directory not found at: ' . $this->moduleDir);
  }

  /**
   * H-4: Report route must have regex constraint on report_id.
   */
  public function testReportRouteHasRegexConstraint(): void {
    $routing = Yaml::parseFile($this->moduleDir . '/ilas_test.routing.yml');
    $this->assertArrayHasKey('ilas_test.report', $routing);

    $route = $routing['ilas_test.report'];
    $this->assertArrayHasKey('requirements', $route);
    $this->assertArrayHasKey('report_id', $route['requirements'],
      'SECURITY H-4: report route must have a regex constraint on report_id to prevent path traversal.');
    $this->assertSame('[a-zA-Z0-9_-]+', $route['requirements']['report_id'],
      'report_id constraint must only allow alphanumeric, hyphens, and underscores.');
  }

  /**
   * L-7: Run route must require POST method.
   */
  public function testRunRouteRequiresPost(): void {
    $routing = Yaml::parseFile($this->moduleDir . '/ilas_test.routing.yml');
    $this->assertArrayHasKey('ilas_test.run', $routing);

    $route = $routing['ilas_test.run'];
    $this->assertArrayHasKey('methods', $route,
      'SECURITY L-7: ilas_test.run route must specify methods to prevent state-changing GET requests.');
    $this->assertContains('POST', $route['methods'],
      'ilas_test.run route must require POST method.');
    $this->assertNotContains('GET', $route['methods'],
      'ilas_test.run route must not allow GET method.');
  }

  /**
   * L-7: Run route must require Drupal's CSRF token.
   *
   * POST-only is not sufficient on its own — a controller endpoint without
   * `_csrf_token: TRUE` can still be triggered cross-site by an authenticated
   * admin's browser.
   */
  public function testRunRouteRequiresCsrfToken(): void {
    $routing = Yaml::parseFile($this->moduleDir . '/ilas_test.routing.yml');
    $this->assertArrayHasKey('ilas_test.run', $routing);

    $route = $routing['ilas_test.run'];
    $this->assertArrayHasKey('requirements', $route);
    $this->assertArrayHasKey('_csrf_token', $route['requirements'],
      'SECURITY L-7: ilas_test.run must require _csrf_token to block cross-site POSTs.');
    $this->assertSame('TRUE', $route['requirements']['_csrf_token'],
      'ilas_test.run _csrf_token requirement must be the string "TRUE".');
    $this->assertArrayHasKey('_permission', $route['requirements'],
      'ilas_test.run must require an explicit permission.');
    $this->assertSame('run ilas tests', $route['requirements']['_permission'],
      'ilas_test.run must be gated by the restricted "run ilas tests" permission.');
  }

  /**
   * H-4: Controller loadTestReport() must validate report_id.
   */
  public function testControllerValidatesReportId(): void {
    $controllerSource = file_get_contents(
      $this->moduleDir . '/src/Controller/TestDashboardController.php'
    );

    // Must contain a preg_match validation for report_id.
    $this->assertMatchesRegularExpression(
      '/preg_match.*\[a-zA-Z0-9_\-\]/',
      $controllerSource,
      'SECURITY H-4: loadTestReport() must validate report_id with preg_match as defense-in-depth.'
    );
  }

  /**
   * L-7: Controller must not leak exception messages to client.
   */
  public function testControllerDoesNotLeakExceptionMessages(): void {
    $controllerSource = file_get_contents(
      $this->moduleDir . '/src/Controller/TestDashboardController.php'
    );

    // The runTests catch block must not return $e->getMessage() in the response.
    // It should log the error and return a generic message.
    $this->assertStringNotContainsString(
      "'error' => \$e->getMessage()",
      $controllerSource,
      'SECURITY L-7: runTests() must not return exception messages in JSON response.'
    );
  }

  /**
   * L-7: Form must not leak exception messages to users.
   */
  public function testFormDoesNotLeakExceptionMessages(): void {
    $formSource = file_get_contents(
      $this->moduleDir . '/src/Form/ExecuteTestsForm.php'
    );

    // Must not use $e->getMessage() in user-visible output.
    $this->assertStringNotContainsString(
      "'@error' => \$e->getMessage()",
      $formSource,
      'SECURITY L-7: ExecuteTestsForm must not display exception messages to users.'
    );
  }

  /**
   * L-7: Controller must not use deprecated file_scan_directory().
   */
  public function testNoDeprecatedFileScanDirectory(): void {
    $controllerSource = file_get_contents(
      $this->moduleDir . '/src/Controller/TestDashboardController.php'
    );

    $this->assertStringNotContainsString(
      'file_scan_directory(',
      $controllerSource,
      'SECURITY L-7: Must use FileSystemInterface::scanDirectory() instead of deprecated file_scan_directory().'
    );

    // Verify the replacement is present.
    $this->assertStringContainsString(
      '->scanDirectory(',
      $controllerSource,
      'Controller must use injected file_system service for directory scanning.'
    );
  }

  /**
   * L-7: Controller must inject FileSystemInterface.
   */
  public function testControllerInjectsFileSystem(): void {
    $controllerSource = file_get_contents(
      $this->moduleDir . '/src/Controller/TestDashboardController.php'
    );

    $this->assertStringContainsString(
      'FileSystemInterface',
      $controllerSource,
      'Controller must import and use FileSystemInterface.'
    );
  }

  /**
   * H-4: Path traversal payloads must not pass validation.
   *
   * Tests the regex pattern used in the route and controller.
   */
  public function testPathTraversalPayloadsRejected(): void {
    $pattern = '/^[a-zA-Z0-9_-]+$/';

    $malicious_ids = [
      '../../../etc/passwd',
      '..%2F..%2Fetc%2Fpasswd',
      'report/../../settings',
      'valid-report/../secret',
      'report\x00.json',
      'report;rm -rf /',
      'report$(whoami)',
      '../private/settings',
      '..',
      '.',
      '',
    ];

    foreach ($malicious_ids as $id) {
      $this->assertDoesNotMatchRegularExpression($pattern, $id,
        "Path traversal payload must be rejected: '{$id}'");
    }

    // Valid IDs must pass.
    $valid_ids = [
      'test-report-2026-02-11',
      'security_audit_001',
      'report123',
      'a',
    ];

    foreach ($valid_ids as $id) {
      $this->assertMatchesRegularExpression($pattern, $id,
        "Valid report ID must be accepted: '{$id}'");
    }
  }

}
