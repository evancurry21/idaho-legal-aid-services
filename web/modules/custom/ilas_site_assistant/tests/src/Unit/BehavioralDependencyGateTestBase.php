<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Shared helpers for behavioral dependency gate tests.
 */
abstract class BehavioralDependencyGateTestBase extends TestCase {

  /**
   * Returns the repository root path.
   */
  protected static function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Returns the gate metrics CLI path.
   */
  protected static function gateMetricsScript(): string {
    return self::repoRoot() . '/promptfoo-evals/scripts/gate-metrics.js';
  }

  /**
   * Reads a repository file after existence checks.
   */
  protected static function readFile(string $relativePath): string {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected file does not exist: {$relativePath}");

    $contents = file_get_contents($path);
    self::assertIsString($contents, "Failed reading file: {$relativePath}");
    return $contents;
  }

  /**
   * Parses a YAML file from the repository.
   */
  protected static function parseYamlFile(string $relativePath): array {
    $path = self::repoRoot() . '/' . ltrim($relativePath, '/');
    self::assertFileExists($path, "Expected YAML file does not exist: {$relativePath}");

    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed, "Expected YAML file to parse as an array: {$relativePath}");
    return $parsed;
  }

  /**
   * Executes a node command and returns trimmed stdout.
   */
  protected static function runNodeCommand(array $arguments): string {
    $nodeBin = trim((string) shell_exec('command -v node 2>/dev/null'));
    self::assertNotSame('', $nodeBin, 'node binary is required for behavioral dependency gate tests.');

    $escaped = [escapeshellarg($nodeBin)];
    foreach ($arguments as $argument) {
      $escaped[] = escapeshellarg($argument);
    }

    $output = [];
    $exitCode = 0;
    exec(implode(' ', $escaped), $output, $exitCode);

    self::assertSame(
      0,
      $exitCode,
      'Node command failed: ' . implode(' ', $arguments) . PHP_EOL . implode(PHP_EOL, $output),
    );

    return trim(implode(PHP_EOL, $output));
  }

  /**
   * Executes a node command and decodes JSON output.
   *
   * @return array<string, mixed>
   *   The decoded JSON payload.
   */
  protected static function runNodeJson(array $arguments): array {
    $output = self::runNodeCommand($arguments);
    $decoded = json_decode($output, TRUE);
    self::assertIsArray($decoded, 'Expected node command to emit a JSON object.');
    return $decoded;
  }

  /**
   * Parses the threshold report emitted by the gate metrics helper.
   *
   * @param string[] $metricNames
   *   The metric names to evaluate.
   *
   * @return array{
   *   overall_fail: bool,
   *   metrics: array<string, array{
   *     rate: float,
   *     score: int,
   *     count: int,
   *     count_fail: bool,
   *     fail: bool
   *   }>
   * }
   *   Parsed threshold evaluation state.
   */
  protected function thresholdReport(string $resultsRelativePath, float $threshold, int $minCount, array $metricNames): array {
    $lines = preg_split(
      '/\r?\n/',
      self::runNodeCommand([
        self::gateMetricsScript(),
        'evaluate-thresholds',
        self::repoRoot() . '/' . ltrim($resultsRelativePath, '/'),
        (string) $threshold,
        (string) $minCount,
        ...$metricNames,
      ]) ?: ''
    );

    $report = [
      'overall_fail' => FALSE,
      'metrics' => [],
    ];

    foreach ($lines as $line) {
      if ($line === NULL || $line === '') {
        continue;
      }

      $parts = explode('|', $line);
      if (($parts[0] ?? '') === 'overall') {
        $report['overall_fail'] = ($parts[1] ?? 'no') === 'yes';
        continue;
      }

      if (($parts[0] ?? '') !== 'metric') {
        continue;
      }

      $metricName = (string) ($parts[1] ?? '');
      $report['metrics'][$metricName] = [
        'rate' => (float) ($parts[2] ?? 0),
        'score' => (int) ($parts[3] ?? 0),
        'count' => (int) ($parts[4] ?? 0),
        'count_fail' => ($parts[5] ?? 'no') === 'yes',
        'fail' => ($parts[6] ?? 'no') === 'yes',
      ];
    }

    return $report;
  }

}
