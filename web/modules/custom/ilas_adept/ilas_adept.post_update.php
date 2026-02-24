<?php

/**
 * @file
 * Post update functions for the ILAS ADEPT module.
 */

/**
 * Reconcile field_adept_attachments installed definition with config.
 */
function ilas_adept_post_update_fix_attachments_definition() {
  // Re-save the field storage to sync installed definition.
  $storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('node', 'field_adept_attachments');
  if ($storage) {
    $storage->save();
  }

  // Re-save the field instance to sync installed definition.
  $field = \Drupal\field\Entity\FieldConfig::loadByName('node', 'adept_lesson', 'field_adept_attachments');
  if ($field) {
    $field->save();
  }

  return t('Reconciled field_adept_attachments installed definition with config.');
}
