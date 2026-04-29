<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\Service\CohereGenerationProbe;
use Drush\Commands\DrushCommands;

/**
 * Drush command for explicit Cohere generation proof.
 */
final class CohereProbeCommands extends DrushCommands {

  public function __construct(
    private readonly CohereGenerationProbe $probe,
  ) {
    parent::__construct();
  }

  /**
   * Sends one harmless exact-output Cohere proof request.
   *
   * @command ilas:cohere-probe
   * @aliases cohere-probe
   * @usage ilas:cohere-probe
   *   Print sanitized Cohere generation proof JSON.
   */
  public function cohereProbe(): int {
    $result = $this->probe->probe();
    $this->output()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return !empty($result['generation_probe_passed']) ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

}
