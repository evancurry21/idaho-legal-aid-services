#!/usr/bin/env drush php:script
<?php

/**
 * @file
 * Lists Drupal route names safely.
 *
 * Usage:
 *   ddev drush php:script scripts/drush/route-names.php
 *   ddev drush php:script scripts/drush/route-names.php -- --filter=ilas
 *   ddev drush php:script scripts/drush/route-names.php -- --filter=assistant
 *
 * Why this exists:
 *   Drupal's RouteProvider::getAllRoutes() returns an ArrayIterator, not an
 *   array. Calling array_keys() on it triggers a TypeError on PHP 8.x:
 *     TypeError: array_keys(): Argument #1 ($array) must be of type array,
 *               ArrayIterator given
 *   This script uses iterator_to_array() to safely convert the result.
 *
 * @see https://www.drupal.org/project/drupal/issues/XXXXXXX
 */

$filter = NULL;

// Parse --filter=<pattern> from extra arguments.
$extra = drush_get_option('filter', NULL) ?? NULL;
if ($extra === NULL) {
  // Also check $extra_args for php:script passthrough.
  foreach ($extra ?? [] as $arg) {
    if (str_starts_with($arg, '--filter=')) {
      $filter = substr($arg, 9);
      break;
    }
  }
  // Fall back to scanning $_SERVER['argv'].
  foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--filter=')) {
      $filter = substr($arg, 9);
      break;
    }
  }
}

/** @var \Drupal\Core\Routing\RouteProviderInterface $route_provider */
$route_provider = \Drupal::service('router.route_provider');
$routes = $route_provider->getAllRoutes();

// Safe conversion: getAllRoutes() returns ArrayIterator, not array.
$route_names = array_keys(iterator_to_array($routes, TRUE));
sort($route_names);

foreach ($route_names as $name) {
  if ($filter !== NULL && stripos($name, $filter) === FALSE) {
    continue;
  }
  print $name . PHP_EOL;
}
