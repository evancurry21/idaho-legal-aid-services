<?php

namespace Drupal\Tests\employment_application\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\employment_application\Controller\EmploymentApplicationController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit coverage for the jobs endpoint cache-metadata builder.
 *
 * The helper is the contract that replaced the legacy 300s max-age. These
 * tests pin its observable behavior so a regression to a fixed stale window
 * — or to missing per-entity tags — fails loudly.
 */
#[CoversClass(EmploymentApplicationController::class)]
#[Group('employment_application')]
class JobsCacheMetadataTest extends TestCase {

  /**
   * Invokes the private helper via reflection.
   */
  private function build(array $nodeIds, array $paragraphIds, ?int $nextExpiry): CacheableMetadata {
    $controller = (new ReflectionClass(EmploymentApplicationController::class))
      ->newInstanceWithoutConstructor();
    $method = (new ReflectionClass(EmploymentApplicationController::class))
      ->getMethod('buildJobsCacheMetadata');
    $method->setAccessible(TRUE);
    return $method->invoke($controller, $nodeIds, $paragraphIds, $nextExpiry);
  }

  /**
   * Per-entity tags are emitted alongside the bundle list tags.
   */
  public function testTagsIncludePerEntityIds(): void {
    $metadata = $this->build([42], [101, 202, 202], NULL);

    $tags = $metadata->getCacheTags();
    $this->assertContains('node_list:employment', $tags);
    $this->assertContains('paragraph_list', $tags);
    $this->assertContains('employment_jobs', $tags);
    $this->assertContains('node:42', $tags);
    $this->assertContains('paragraph:101', $tags);
    $this->assertContains('paragraph:202', $tags);
    // Duplicate paragraph ids must not produce duplicate tags.
    $this->assertCount(count(array_unique($tags)), $tags);
  }

  /**
   * With no expiry, the response is permanently cacheable — tags drive freshness.
   */
  public function testMaxAgeIsPermanentWithoutExpiry(): void {
    $metadata = $this->build([1], [10], NULL);
    $this->assertSame(Cache::PERMANENT, $metadata->getCacheMaxAge());
  }

  /**
   * A future expiry produces a precise, positive max-age — never 300.
   */
  public function testMaxAgeMatchesFutureExpiry(): void {
    $futureDelta = 7200;
    $metadata = $this->build([1], [10], time() + $futureDelta);

    $maxAge = $metadata->getCacheMaxAge();
    $this->assertGreaterThan(0, $maxAge);
    $this->assertLessThanOrEqual($futureDelta, $maxAge);
    $this->assertNotSame(300, $maxAge, 'The legacy 300s max-age must not reappear.');
  }

  /**
   * Past expiry collapses to permanent — invalidation will come from tags.
   */
  public function testPastExpiryFallsBackToPermanent(): void {
    $metadata = $this->build([1], [10], time() - 60);
    $this->assertSame(Cache::PERMANENT, $metadata->getCacheMaxAge());
  }

  /**
   * Required cache contexts are present so translated/filtered responses vary.
   */
  public function testCacheContextsCoverLanguageAndQueryArgs(): void {
    $metadata = $this->build([1], [10], NULL);
    $contexts = $metadata->getCacheContexts();

    $this->assertContains('url.query_args', $contexts);
    $this->assertContains('languages:language_content', $contexts);
  }

}
