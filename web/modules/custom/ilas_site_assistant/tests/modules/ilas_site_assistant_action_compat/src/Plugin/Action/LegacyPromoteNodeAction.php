<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_action_compat\Plugin\Action;

use Drupal\Core\Field\FieldUpdateActionBase;
use Drupal\node\NodeInterface;

/**
 * Test-only fallback action for legacy plugin ID node_promote_action.
 */
class LegacyPromoteNodeAction extends FieldUpdateActionBase {

  /**
   * {@inheritdoc}
   */
  protected function getFieldsToUpdate(): array {
    return ['promote' => NodeInterface::PROMOTED];
  }

}
