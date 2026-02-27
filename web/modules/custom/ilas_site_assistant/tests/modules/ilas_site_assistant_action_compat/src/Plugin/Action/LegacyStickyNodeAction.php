<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_action_compat\Plugin\Action;

use Drupal\Core\Field\FieldUpdateActionBase;
use Drupal\node\NodeInterface;

/**
 * Test-only fallback action for legacy plugin ID node_make_sticky_action.
 */
class LegacyStickyNodeAction extends FieldUpdateActionBase {

  /**
   * {@inheritdoc}
   */
  protected function getFieldsToUpdate(): array {
    return ['sticky' => NodeInterface::STICKY];
  }

}
