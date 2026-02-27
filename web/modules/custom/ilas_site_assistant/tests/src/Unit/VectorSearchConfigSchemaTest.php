<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates that the config schema covers all vector_search install defaults.
 *
 * IMP-CONF-01: The vector_search block was missing from the config schema,
 * allowing config drift to go undetected. This test ensures every key defined
 * in the install defaults has a corresponding schema entry.
 *
 * @group ilas_site_assistant
 */
class VectorSearchConfigSchemaTest extends TestCase {

  /**
   * Path to the module root, relative to the repo root.
   */
  private const MODULE_PATH = 'web/modules/custom/ilas_site_assistant';

  /**
   * Returns the repository root by walking up from __DIR__.
   */
  private static function repoRoot(): string {
    // __DIR__ = <repo>/web/modules/custom/ilas_site_assistant/tests/src/Unit
    // dirname(Unit,7) -> src -> tests -> ilas_site_assistant -> custom -> modules -> web -> <repo>
    return dirname(__DIR__, 7);
  }

  /**
   * Tests that every vector_search key in install defaults has a schema entry.
   */
  public function testSchemaCoversAllInstallDefaultKeys(): void {
    $root = self::repoRoot();

    $install_path = $root . '/' . self::MODULE_PATH . '/config/install/ilas_site_assistant.settings.yml';
    $schema_path = $root . '/' . self::MODULE_PATH . '/config/schema/ilas_site_assistant.schema.yml';

    $this->assertFileExists($install_path, 'Install defaults file must exist');
    $this->assertFileExists($schema_path, 'Schema file must exist');

    $install = Yaml::parseFile($install_path);
    $schema = Yaml::parseFile($schema_path);

    // Verify vector_search exists in both.
    $this->assertArrayHasKey('vector_search', $install, 'Install defaults must define vector_search');
    $this->assertArrayHasKey(
      'vector_search',
      $schema['ilas_site_assistant.settings']['mapping'] ?? [],
      'Schema must define vector_search mapping'
    );

    $install_keys = array_keys($install['vector_search']);
    $schema_mapping = $schema['ilas_site_assistant.settings']['mapping']['vector_search']['mapping'] ?? [];
    $schema_keys = array_keys($schema_mapping);

    // Every install key must have a schema entry.
    $missing_from_schema = array_diff($install_keys, $schema_keys);
    $this->assertEmpty(
      $missing_from_schema,
      'Install default keys missing from schema: ' . implode(', ', $missing_from_schema)
    );

    // Every schema key should have an install default (catches orphaned schema).
    $extra_in_schema = array_diff($schema_keys, $install_keys);
    $this->assertEmpty(
      $extra_in_schema,
      'Schema keys without install defaults: ' . implode(', ', $extra_in_schema)
    );
  }

  /**
   * Tests that schema types match the install default value types.
   */
  public function testSchemaTypesMatchInstallDefaults(): void {
    $root = self::repoRoot();

    $install = Yaml::parseFile($root . '/' . self::MODULE_PATH . '/config/install/ilas_site_assistant.settings.yml');
    $schema = Yaml::parseFile($root . '/' . self::MODULE_PATH . '/config/schema/ilas_site_assistant.schema.yml');

    $install_values = $install['vector_search'];
    $schema_mapping = $schema['ilas_site_assistant.settings']['mapping']['vector_search']['mapping'];

    $type_map = [
      'boolean' => 'is_bool',
      'string' => 'is_string',
      'integer' => 'is_int',
      'float' => 'is_float',
    ];

    foreach ($install_values as $key => $value) {
      $this->assertArrayHasKey($key, $schema_mapping, "Schema must define key: $key");
      $schema_type = $schema_mapping[$key]['type'];
      $this->assertArrayHasKey($schema_type, $type_map, "Unknown schema type '$schema_type' for key '$key'");

      // Float 0.70 may parse as float or int depending on YAML parser.
      // Accept int where float is expected (0 is valid for both).
      if ($schema_type === 'float' && is_int($value)) {
        continue;
      }
      // Accept float where integer is expected if value is whole number (e.g. 0.0).
      if ($schema_type === 'integer' && is_float($value) && floor($value) === $value) {
        continue;
      }

      $check_fn = $type_map[$schema_type];
      $this->assertTrue(
        $check_fn($value),
        sprintf(
          "vector_search.%s: schema expects '%s' but install default is %s (%s)",
          $key,
          $schema_type,
          var_export($value, TRUE),
          gettype($value)
        )
      );
    }
  }

  /**
   * Tests that active config vector_search values match install defaults.
   *
   * Catches value-level drift (e.g. min_vector_score changed from 0.70 to
   * 0.50) that key-presence checks would miss.
   */
  public function testActiveVectorSearchValuesMatchInstallDefaults(): void {
    $root = self::repoRoot();

    $install = Yaml::parseFile($root . '/' . self::MODULE_PATH . '/config/install/ilas_site_assistant.settings.yml');
    $active = Yaml::parseFile($root . '/config/ilas_site_assistant.settings.yml');

    $this->assertArrayHasKey('vector_search', $install);
    $this->assertArrayHasKey('vector_search', $active);

    foreach ($install['vector_search'] as $key => $expected) {
      $this->assertArrayHasKey($key, $active['vector_search'],
        "Active config vector_search missing key: {$key}");

      $actual = $active['vector_search'][$key];

      // Use delta comparison for numerics (YAML float/int coercion).
      if (is_numeric($expected) && is_numeric($actual)) {
        $this->assertEqualsWithDelta($expected, $actual, 0.001,
          "vector_search.{$key}: active value {$actual} drifted from install default {$expected}");
      }
      else {
        $this->assertSame($expected, $actual,
          "vector_search.{$key}: active value drifted from install default");
      }
    }
  }

  /**
   * Tests that vector_search is present in the exported active config.
   */
  public function testActiveConfigIncludesVectorSearch(): void {
    $root = self::repoRoot();
    $active_path = $root . '/config/ilas_site_assistant.settings.yml';

    $this->assertFileExists($active_path, 'Active config export must exist');

    $active = Yaml::parseFile($active_path);
    $this->assertArrayHasKey('vector_search', $active, 'Active config must include vector_search block');

    // Verify it has the same keys as install defaults.
    $install = Yaml::parseFile($root . '/' . self::MODULE_PATH . '/config/install/ilas_site_assistant.settings.yml');
    $install_keys = array_keys($install['vector_search']);
    $active_keys = array_keys($active['vector_search']);

    $missing = array_diff($install_keys, $active_keys);
    $this->assertEmpty(
      $missing,
      'Active config vector_search missing keys: ' . implode(', ', $missing)
    );
  }

}
