<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\Service\ProviderHealthCheck;
use Drush\Commands\DrushCommands;

/**
 * Drush command for live Cohere + Voyage + Pinecone readiness.
 */
final class ProviderHealthCommands extends DrushCommands {

  public function __construct(
    private readonly ProviderHealthCheck $check,
  ) {
    parent::__construct();
  }

  /**
   * Live readiness check for Cohere generation, Voyage rerank, and Pinecone.
   *
   * Real network calls. API keys are never echoed (only key_present + an
   * 8-char sha256 fingerprint). Exits non-zero if any provider is unhealthy.
   *
   * @command ilas:providers-health
   * @aliases providers-health
   * @usage ilas:providers-health
   *   Print sanitized live provider health JSON.
   */
  public function providersHealth(): int {
    $result = $this->check->run();
    $this->output()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return ($result['ok'] ?? FALSE) === TRUE ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

}
