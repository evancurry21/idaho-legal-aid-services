<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Minimal A/B testing framework for the Site Assistant.
 *
 * Assigns deterministic variants per conversation_id using crc32 hashing.
 * Variant assignment is stable: the same conversation_id always gets the
 * same variant for a given experiment.
 */
class AbTestingService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an AbTestingService object.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Checks if A/B testing is enabled.
   *
   * @return bool
   *   TRUE if A/B testing is enabled.
   */
  public function isEnabled(): bool {
    return (bool) $this->configFactory
      ->get('ilas_site_assistant.settings')
      ->get('ab_testing.enabled');
  }

  /**
   * Gets the active experiments from config.
   *
   * @return array
   *   Array of experiment definitions.
   */
  public function getExperiments(): array {
    if (!$this->isEnabled()) {
      return [];
    }

    return $this->configFactory
      ->get('ilas_site_assistant.settings')
      ->get('ab_testing.experiments') ?? [];
  }

  /**
   * Assigns a variant for a given experiment and conversation.
   *
   * Uses crc32 hash of "{experiment_id}:{conversation_id}" for deterministic
   * assignment. The allocation array determines bucket boundaries.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param string $conversation_id
   *   The conversation UUID.
   *
   * @return string|null
   *   The assigned variant name, or NULL if the experiment is not found.
   */
  public function assignVariant(string $experiment_id, string $conversation_id): ?string {
    $experiments = $this->getExperiments();

    foreach ($experiments as $experiment) {
      if (($experiment['id'] ?? '') !== $experiment_id) {
        continue;
      }

      $variants = $experiment['variants'] ?? [];
      $allocation = $experiment['allocation'] ?? [];

      if (empty($variants) || empty($allocation) || count($variants) !== count($allocation)) {
        return NULL;
      }

      // Deterministic hash: same inputs always produce same output.
      $hash_input = $experiment_id . ':' . $conversation_id;
      $hash = abs(crc32($hash_input));
      $bucket = $hash % 100;

      // Walk the allocation array to find which variant this bucket falls in.
      $cumulative = 0;
      foreach ($variants as $i => $variant) {
        $cumulative += (int) ($allocation[$i] ?? 0);
        if ($bucket < $cumulative) {
          return $variant;
        }
      }

      // Fallback to last variant if rounding leaves a gap.
      return end($variants);
    }

    return NULL;
  }

  /**
   * Gets all variant assignments for a conversation across active experiments.
   *
   * @param string $conversation_id
   *   The conversation UUID.
   *
   * @return array
   *   Associative array of experiment_id => variant_name.
   */
  public function getAssignments(string $conversation_id): array {
    $assignments = [];

    foreach ($this->getExperiments() as $experiment) {
      $id = $experiment['id'] ?? '';
      if (empty($id)) {
        continue;
      }

      $variant = $this->assignVariant($id, $conversation_id);
      if ($variant !== NULL) {
        $assignments[$id] = $variant;
      }
    }

    return $assignments;
  }

}
