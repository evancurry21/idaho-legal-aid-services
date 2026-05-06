<?php

namespace Drupal\Tests\ilas_seo\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Asserts that every metatag canonical_url token resolves to an absolute URL.
 *
 * Regression coverage for CONCERNS.md L15-18: the ES global override emitted
 * a relative canonical link tag, which is non-conforming and weakens
 * cross-locale signaling. Pure YAML scan, no Drupal bootstrap required.
 */
#[Group('ilas_seo')]
class MetatagCanonicalConfigTest extends TestCase {

  /**
   * Path to the config sync directory.
   */
  protected string $configDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // 7 levels up from Unit/ dir reaches the project root.
    $this->configDir = dirname(__DIR__, 7) . '/config';
    $this->assertDirectoryExists($this->configDir,
      'Config sync directory not found at: ' . $this->configDir);
  }

  /**
   * ES global canonical_url must use the absolute current-page token.
   */
  public function testEsGlobalCanonicalIsAbsolute(): void {
    $file = $this->configDir . '/language/es/metatag.metatag_defaults.global.yml';
    $this->assertFileExists($file);

    $config = Yaml::parseFile($file);
    $value = $config['tags']['canonical_url'] ?? NULL;
    $this->assertSame('[current-page:url:absolute]', $value,
      'SEO: ES global canonical_url must be "[current-page:url:absolute]". '
      . 'Current value: "' . var_export($value, TRUE) . '". '
      . 'Relative tokens emit relative <link rel="canonical"> on Spanish pages.');
  }

  /**
   * ES node canonical_url must resolve to an absolute URL.
   */
  public function testEsNodeCanonicalIsAbsolute(): void {
    $file = $this->configDir . '/language/es/metatag.metatag_defaults.node.yml';
    $this->assertFileExists($file);

    $config = Yaml::parseFile($file);
    $value = $config['tags']['canonical_url'] ?? NULL;
    $this->assertNotNull($value, 'ES node canonical_url override must exist.');
    $this->assertTrue(
      $this->tokenIsAbsolute($value),
      'SEO: ES node canonical_url must resolve to an absolute URL. '
      . 'Current value: "' . $value . '". '
      . 'Use "[node:url:absolute]" or "[current-page:url:absolute]".'
    );
  }

  /**
   * No metatag config (any locale, any bundle) may use a relative canonical.
   *
   * Globs every metatag.metatag_defaults.*.yml under config/ and
   * config/language/*\/, and asserts that any present canonical_url
   * resolves to an absolute URL.
   */
  public function testNoRelativeCanonicalTokensInAnyMetatagConfig(): void {
    $patterns = [
      $this->configDir . '/metatag.metatag_defaults.*.yml',
      $this->configDir . '/language/*/metatag.metatag_defaults.*.yml',
    ];

    $files = [];
    foreach ($patterns as $pattern) {
      $files = array_merge($files, glob($pattern) ?: []);
    }
    $this->assertNotEmpty($files,
      'Expected to find metatag default config files under ' . $this->configDir);

    $offenders = [];
    foreach ($files as $file) {
      $config = Yaml::parseFile($file);
      $value = $config['tags']['canonical_url'] ?? NULL;
      if ($value === NULL) {
        // No override on this bundle/locale; inherits from a parent default.
        continue;
      }
      if (!$this->tokenIsAbsolute($value)) {
        $offenders[] = sprintf('%s -> %s',
          str_replace($this->configDir . '/', '', $file), $value);
      }
    }

    $this->assertEmpty($offenders,
      "SEO: the following metatag canonical_url overrides emit relative URLs:\n  - "
      . implode("\n  - ", $offenders)
      . "\nFix by appending ':absolute' to the token.");
  }

  /**
   * Returns TRUE if the canonical_url value is guaranteed absolute.
   *
   * Accepts: tokens ending in :absolute, the [site:url] token (always
   * absolute), or values that already start with https://.
   */
  protected function tokenIsAbsolute(string $value): bool {
    if (str_ends_with($value, ':absolute]')) {
      return TRUE;
    }
    if ($value === '[site:url]') {
      return TRUE;
    }
    if (str_starts_with($value, 'https://')) {
      return TRUE;
    }
    return FALSE;
  }

}
