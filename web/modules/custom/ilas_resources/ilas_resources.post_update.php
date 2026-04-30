<?php

/**
 * @file
 * Post update functions for ILAS Resources module.
 */

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Optimize topic-service area mapping with batch processing.
 */
function ilas_resources_post_update_optimize_topic_mapping(&$sandbox) {
  // Initialize sandbox for batch processing.
  if (!isset($sandbox['progress'])) {
    $sandbox['progress'] = 0;
    $sandbox['max'] = 0;
    $sandbox['messages'] = [];

    // Define the complete mapping data.
    $sandbox['mapping'] = _ilas_resources_get_topic_service_mapping();
    $sandbox['topic_names'] = array_keys($sandbox['mapping']);
    $sandbox['max'] = count($sandbox['topic_names']);

    // Pre-load service area lookup.
    $sandbox['service_lookup'] = _ilas_resources_load_service_area_lookup();

    // Cache the entity type manager and storage.
    $sandbox['topic_storage'] = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  }

  // Process topics in batches of 10.
  $batch_size = 10;
  $end = min($sandbox['progress'] + $batch_size, $sandbox['max']);

  for ($i = $sandbox['progress']; $i < $end; $i++) {
    $topic_name = $sandbox['topic_names'][$i];
    $service_names = $sandbox['mapping'][$topic_name];

    _ilas_resources_process_topic(
      $topic_name,
      $service_names,
      $sandbox['service_lookup'],
      $sandbox['topic_storage']
    );

    $sandbox['progress']++;
  }

  // Update batch status.
  $sandbox['#finished'] = $sandbox['progress'] / $sandbox['max'];

  if ($sandbox['#finished'] >= 1) {
    return t('Optimized @count topic-service area mappings.', ['@count' => $sandbox['max']]);
  }
}

/**
 * Helper function to process a single topic.
 */
function _ilas_resources_process_topic($topic_name, $service_names, $service_lookup, $topic_storage) {
  $vocab_id = 'topics';
  $field_name = 'field_service_areas';

  // Load or create the Topic term.
  $topic_terms = $topic_storage->loadByProperties([
    'name' => $topic_name,
    'vid'  => $vocab_id,
  ]);

  $topic = $topic_terms ? reset($topic_terms) : $topic_storage->create([
    'name' => $topic_name,
    'vid' => $vocab_id,
  ]);

  // Build array of service term IDs.
  $service_tids = [];
  foreach ($service_names as $service_label) {
    if (isset($service_lookup[$service_label])) {
      $service_tids[] = ['target_id' => $service_lookup[$service_label]];
    }
  }

  // Only save if there are changes.
  $current_tids = array_column($topic->get($field_name)->getValue(), 'target_id');
  $new_tids = array_column($service_tids, 'target_id');

  if ($current_tids != $new_tids) {
    $topic->set($field_name, $service_tids);
    $topic->save();
  }
}

/**
 * Load service area lookup table.
 */
function _ilas_resources_load_service_area_lookup() {
  $service_lookup = [];
  $service_terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties(['vid' => 'service_areas']);

  foreach ($service_terms as $term) {
    $service_lookup[$term->getName()] = $term->id();
  }

  return $service_lookup;
}

/**
 * Get the complete topic to service area mapping.
 */
function _ilas_resources_get_topic_service_mapping() {
  return [
    // Consumer Protection.
    'Money, Debt, Bankruptcy' => ['Consumer Protection'],
    'Small Claims' => ['Consumer Protection'],
    'Debt Collection' => ['Consumer Protection'],
    'Garnishment' => ['Consumer Protection'],
    'Repossession' => ['Consumer Protection'],
    'Credit' => ['Consumer Protection'],
    'Credit Reports' => ['Consumer Protection'],
    'Repairing Credit' => ['Consumer Protection'],
    'Bankruptcy' => ['Consumer Protection'],
    'Loans' => ['Consumer Protection'],
    'Predatory Lending' => ['Consumer Protection'],
    'Pay Day Loans' => ['Consumer Protection'],
    'Student Loans' => ['Consumer Protection'],
    'Mortgage Loans' => ['Consumer Protection'],
    'Identity Theft' => ['Consumer Protection'],
    'Trade Practices' => ['Consumer Protection'],
    'Automobiles' => ['Consumer Protection'],
    'Chapter 7' => ['Consumer Protection'],
    'Chapter 11' => ['Consumer Protection'],
    'Chapter 13' => ['Consumer Protection'],
    'Debt Management' => ['Consumer Protection'],
    'Debt Counseling' => ['Consumer Protection'],
    'Exemptions' => ['Consumer Protection'],

    // Family Safety & Stability.
    'Family Law' => ['Family Safety & Stability'],
    'Domestic Violence' => ['Family Safety & Stability'],
    'Sexual Violence' => ['Family Safety & Stability'],
    'Stalking' => ['Family Safety & Stability'],
    'Personal Safety' => ['Family Safety & Stability'],
    'Divorce' => ['Family Safety & Stability'],
    'Separation' => ['Family Safety & Stability'],
    'Annulment' => ['Family Safety & Stability'],
    'Custody' => ['Family Safety & Stability'],
    'Parenting Time' => ['Family Safety & Stability'],
    'Visitation' => ['Family Safety & Stability'],
    'Child Support' => ['Family Safety & Stability'],
    'Property' => ['Family Safety & Stability'],
    'Contempt' => ['Family Safety & Stability'],
    'Adoption' => ['Family Safety & Stability'],
    'Adult Name Change' => ['Family Safety & Stability'],
    'Minor Name Change' => ['Family Safety & Stability'],
    'Juvenile Guardianship' => ['Family Safety & Stability'],
    'Juvenile Conservatorship' => ['Family Safety & Stability'],
    'Minor Guardianship' => ['Family Safety & Stability'],
    'Minor Conservatorship' => ['Family Safety & Stability'],
    'Accounting' => ['Family Safety & Stability'],
    'Conservator' => ['Family Safety & Stability'],
    'Juveniles/Minors' => ['Family Safety & Stability'],
    'Expungement' => ['Family Safety & Stability'],

    // Individual Rights.
    'Employment' => ['Individual Rights'],
    'Unemployment' => ['Individual Rights'],
    'Workplace Safety' => ['Individual Rights'],
    'Pensions' => ['Individual Rights'],
    'VAWA' => ['Individual Rights'],
    'Naturalization' => ['Individual Rights'],
    'Electronic Filing' => ['Individual Rights'],
    'Voting Rights' => ['Individual Rights'],
    'SSDI' => ['Individual Rights'],
    'Social Security Disability Insurance' => ['Individual Rights'],
    'Veterans' => ['Individual Rights'],
    'Military' => ['Individual Rights'],
    'Indian Law' => ['Individual Rights'],

    // Health & Essential Benefits.
    'Health Benefits' => ['Health & Essential Benefits'],
    'Medical Benefits' => ['Health & Essential Benefits'],
    'SNAP' => ['Health & Essential Benefits'],
    'TANF' => ['Health & Essential Benefits'],
    'Medicare' => ['Health & Essential Benefits'],
    'Medicaid' => ['Health & Essential Benefits'],

    // Safe & Stable Housing.
    'Housing' => ['Safe & Stable Housing'],
    'Evictions' => ['Safe & Stable Housing'],
    'Lockouts' => ['Safe & Stable Housing'],
    'Repairs' => ['Safe & Stable Housing'],
    'Lead Paint' => ['Safe & Stable Housing'],
    'Security Deposits' => ['Safe & Stable Housing'],
    'Reasonable Accommodations' => ['Safe & Stable Housing'],
    'Housing Discrimination' => ['Safe & Stable Housing'],
    'Federal Fair Housing Act' => ['Safe & Stable Housing'],
    'Tenant Rights' => ['Safe & Stable Housing'],
    'Renter Safety' => ['Safe & Stable Housing'],
    'Utility Shutoffs' => ['Safe & Stable Housing'],
    'Subsidized Housing' => ['Safe & Stable Housing'],
    'Public Housing' => ['Safe & Stable Housing'],
    'Mobile Home Issues' => ['Safe & Stable Housing'],
    'Home Equity Conversion' => ['Safe & Stable Housing'],
    'Reverse Mortgage' => ['Safe & Stable Housing'],
    'Foreclosure' => ['Safe & Stable Housing'],

    // Advocacy for Older Adults.
    'Senior Citizens' => ['Advocacy for Older Adults'],
    'Adult Conservatorship' => ['Advocacy for Older Adults'],
    'Adult Guardianship' => ['Advocacy for Older Adults'],
    'Probate' => ['Advocacy for Older Adults'],
    'SSI' => ['Advocacy for Older Adults'],
    'Supplemental Security Income' => ['Advocacy for Older Adults'],
    'Pensions' => ['Advocacy for Older Adults'],
    'Caregivers' => ['Advocacy for Older Adults'],
    'Caregiving' => ['Advocacy for Older Adults'],
    'Power of Attorney' => ['Advocacy for Older Adults'],
    'POA' => ['Advocacy for Older Adults'],
    'Advanced Directive' => ['Advocacy for Older Adults'],
    'Living Will' => ['Advocacy for Older Adults'],
    'Wills' => ['Advocacy for Older Adults'],
    'Small Estates' => ['Advocacy for Older Adults'],
    'End of Life Planning' => ['Advocacy for Older Adults'],
    'Holographic Wills' => ['Advocacy for Older Adults'],
    'Beneficiaries' => ['Advocacy for Older Adults'],
    'Widow/Spouse Benefits' => ['Advocacy for Older Adults'],
    'Retirement Benefits' => ['Advocacy for Older Adults'],
    'Overpayment' => ['Advocacy for Older Adults'],
  ];
}
