<?php

/**
 * @file
 * Script to enable "Show on Forms Page" for specified topics.
 * Run with: drush php:script scripts/enable_forms_topics.php
 */

use Drupal\taxonomy\Entity\Term;

$topics_to_enable = [
  'Adult Conservatorship',
  'Adult Guardianship',
  'Bankruptcy',
  'Caregiving',
  'Child Support',
  'Contempt',
  'Credit',
  'Credit Reports',
  'Custody',
  'Debt Collection',
  'Divorce',
  'Domestic Violence',
  'Employment',
  'End of Life Planning',
  'Evictions',
  'Exemptions',
  'Expungement',
  'Foreclosure',
  'Garnishment',
  'Health Benefits',
  'Housing',
  'Housing Discrimination',
  'Identity Theft',
  'Juvenile Conservatorship',
  'Juvenile Guardianship',
  'Loans',
  'Medicaid',
  'Medical Benefits',
  'Medicare',
  'Minor Conservatorship',
  'Mortgage Loans',
  'Pensions',
  'Personal Safety',
  'Power of Attorney',
  'Probate',
  'Property',
  'Reasonable Accommodations',
  'Renter Safety',
  'Repairs',
  'Retirement Benefits',
  'Security Deposits',
  'Small Claims',
  'Small Estates',
  'Social Security Disability Insurance',
  'Supplemental Security Income',
  'Tenant Rights',
  'Unemployment',
  'Veterans',
  'Wills',
  'Workplace Safety',
];

$updated = [];
$not_found = [];

foreach ($topics_to_enable as $topic_name) {
  // Find term by name in the 'topics' vocabulary.
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'name' => $topic_name,
      'vid' => 'topics',
    ]);

  if (!empty($terms)) {
    $term = reset($terms);
    // Check if the field exists on the term.
    if ($term->hasField('field_show_on_forms_page')) {
      $term->set('field_show_on_forms_page', 1);
      $term->save();
      $updated[] = $topic_name;
    }
    else {
      echo "Warning: Term '$topic_name' does not have field_show_on_forms_page field.\n";
    }
  }
  else {
    $not_found[] = $topic_name;
  }
}

echo "\n=== Forms Topics Update Complete ===\n\n";

if (!empty($updated)) {
  echo "Successfully enabled 'Show on Forms Page' for " . count($updated) . " topics:\n";
  foreach ($updated as $name) {
    echo "  ✓ $name\n";
  }
}

if (!empty($not_found)) {
  echo "\nTopics NOT FOUND in vocabulary (may need to be created):\n";
  foreach ($not_found as $name) {
    echo "  ✗ $name\n";
  }
}

echo "\nDone. Clear cache with 'drush cr' to see changes.\n";
