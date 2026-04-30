<?php

/**
 * @file
 * Mobile + main menu split-tree builder for the B5 Subtheme.
 *
 * Provides _b5subtheme_build_split_menu(), called from
 * b5subtheme_preprocess_page() to populate the centered-logo header layout
 * (main_menu_left, main_menu_right) and the mobile-menu.html.twig include
 * (mobile_menu_items). The hook stays in b5subtheme.theme for Drupal
 * discovery; this file holds only the helper.
 */

use Drupal\Core\Menu\MenuTreeParameters;

/**
 * Builds the split main menu and mobile menu items on the page variables.
 *
 * Sets three keys on $variables:
 *   - main_menu_left: render array of the first $left_count top-level items.
 *   - main_menu_right: render array of the remaining top-level items.
 *   - mobile_menu_items: flat list of #items used by mobile-menu.html.twig.
 *
 * Fails safely: if menu loading throws, all three keys are populated with
 * NULL/empty values so the page still renders.
 *
 * @param array $variables
 *   The page-level variables array (passed by reference).
 */
function _b5subtheme_build_split_menu(array &$variables): void {
  // Split the main menu into left/right chunks for the centered logo layout.
  $menu_name = 'main';
  $left_count = 3;

  try {
    $menu_tree = \Drupal::menuTree();
    $parameters = (new MenuTreeParameters())
      ->onlyEnabledLinks()
      ->setMaxDepth(2);

    $tree = $menu_tree->load($menu_name, $parameters);
    $tree = $menu_tree->transform($tree, [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ]);

    $left_tree = array_slice($tree, 0, $left_count);
    $right_tree = array_slice($tree, $left_count);

    $variables['main_menu_left'] = $menu_tree->build($left_tree);
    $variables['main_menu_right'] = $menu_tree->build($right_tree);

    // Add stable classes for styling.
    foreach (['main_menu_left', 'main_menu_right'] as $key) {
      if (!isset($variables[$key]) || !is_array($variables[$key])) {
        continue;
      }
      $existing = $variables[$key]['#attributes']['class'] ?? [];
      if (!is_array($existing)) {
        $existing = [$existing];
      }
      $variables[$key]['#attributes']['class'] = array_values(array_unique(array_merge(
        $existing,
        ['menu--split', 'navbar-nav']
      )));
    }

    // Build mobile menu items for the mobile-menu.html.twig include.
    // The include inherits variables from page.html.twig context.
    $mobile_build = $menu_tree->build($tree);
    $variables['mobile_menu_items'] = $mobile_build['#items'] ?? [];
  }
  catch (\Throwable $e) {
    // Fail safely—page still renders even if menu splitting fails for any reason.
    $variables['main_menu_left'] = NULL;
    $variables['main_menu_right'] = NULL;
    $variables['mobile_menu_items'] = [];
  }
}
