<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\ilas_site_assistant\Service\RuntimeTruthSnapshotBuilder;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for override-aware runtime truth verification.
 */
class RuntimeTruthCommands extends DrushCommands {

  /**
   * Constructs a runtime-truth command handler.
   */
  public function __construct(
    protected RuntimeTruthSnapshotBuilder $snapshotBuilder,
  ) {
    parent::__construct();
  }

  /**
   * Print a sanitized snapshot of stored versus effective AILA runtime truth.
   *
   * @command ilas:runtime-truth
   * @aliases runtime-truth
   * @usage ilas:runtime-truth
   *   Print the sanitized override-aware runtime truth snapshot as JSON.
   */
  public function runtimeTruth(): int {
    try {
      $snapshot = $this->snapshotBuilder->buildSnapshot();
    }
    catch (\Throwable $throwable) {
      fwrite(STDERR, sprintf("Failed to build runtime truth snapshot: %s\n", $throwable->getMessage()));
      return 1;
    }

    print json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return 0;
  }

}
