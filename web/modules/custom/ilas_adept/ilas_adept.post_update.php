<?php

/**
 * @file
 * Post update functions for the ILAS ADEPT module.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Reconcile field_adept_attachments installed definition with config.
 */
function ilas_adept_post_update_fix_attachments_definition() {
  // Re-save the field storage to sync installed definition.
  $storage = FieldStorageConfig::loadByName('node', 'field_adept_attachments');
  if ($storage) {
    $storage->save();
  }

  // Re-save the field instance to sync installed definition.
  $field = FieldConfig::loadByName('node', 'adept_lesson', 'field_adept_attachments');
  if ($field) {
    $field->save();
  }

  return t('Reconciled field_adept_attachments installed definition with config.');
}
