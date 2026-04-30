<?php

/**
 * @file
 * Image and lazy-loading helpers for the B5 Subtheme.
 *
 * Shared by hook_preprocess_image(), hook_preprocess_responsive_image(), and
 * hook_preprocess_field__image() — all of which remain in b5subtheme.theme
 * for Drupal hook discovery and delegate the per-image attribute logic to
 * the helper defined here.
 */

/**
 * Helper function to add lazy loading attributes to images.
 *
 * @param array $variables
 *   The image variables array.
 */
function _b5subtheme_add_lazy_loading(array &$variables): void {
  // Skip if loading attribute already set.
  if (!empty($variables['attributes']['loading'])) {
    return;
  }

  // Skip hero images (preserve LCP - Largest Contentful Paint).
  $skip_classes = ['hero-image', 'hero__image', 'above-fold', 'no-lazy'];
  if (!empty($variables['attributes']['class'])) {
    $classes = (array) $variables['attributes']['class'];
    foreach ($skip_classes as $skip_class) {
      if (in_array($skip_class, $classes, TRUE)) {
        // Explicitly set eager loading for above-fold images.
        $variables['attributes']['loading'] = 'eager';
        $variables['attributes']['fetchpriority'] = 'high';
        return;
      }
    }
  }

  // Skip images with fetchpriority="high" (explicitly marked as important).
  if (!empty($variables['attributes']['fetchpriority']) && $variables['attributes']['fetchpriority'] === 'high') {
    $variables['attributes']['loading'] = 'eager';
    return;
  }

  // Add native lazy loading.
  $variables['attributes']['loading'] = 'lazy';

  // Add decoding="async" for better performance (non-blocking decode).
  if (empty($variables['attributes']['decoding'])) {
    $variables['attributes']['decoding'] = 'async';
  }
}
