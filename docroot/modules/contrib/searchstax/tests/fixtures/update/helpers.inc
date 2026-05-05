<?php

/**
 * @file
 * Provides helper functions for update test setup fixtures.
 */

use Drupal\Component\Uuid\Php as UuidPhp;
use Drupal\Core\Database\Database;
use Drupal\Core\Serialization\Yaml;

/**
 * Setups the database for the given update.
 *
 * @param int $version
 *   The version of the update function that should be tested.
 */
function _searchstax_setup_update_test(
  int $version,
  array $additional_modules = []
): void {
  $modules = array_merge(['searchstax', 'search_api', 'search_api_solr'], $additional_modules);
  _searchstax_update_test_install_modules($modules);

  $connection = Database::getConnection();
  $uuid = new UuidPhp();

  $connection->insert('key_value')
    ->fields([
      'collection' => 'system.schema',
      'name' => 'searchstax',
      'value' => serialize($version - 1),
    ])
    ->execute();

  foreach (glob(__DIR__ . "/$version/*.yml") as $file) {
    if (!preg_match('#/([^/]+)\.yml$#', $file, $m)) {
      throw new RuntimeException("Unexpected file name \"$file\".");
    }
    $config_name = $m[1];
    $data = Yaml::decode(file_get_contents($file));
    // For config entities, we need to set a UUID before saving. Not trivial to
    // spot a config entity, but this should be good enough.
    if (substr($config_name, -9) !== '.settings' && isset($data['id'])) {
      $data['uuid'] = $uuid->generate();
    }
    $connection->insert('config')
      ->fields([
        'collection' => '',
        'name' => $config_name,
        'data' => serialize($data),
      ])
      ->execute();
  }
}

/**
 * Installs modules for an update test.
 *
 * @param list<string> $modules
 *   The machine names of the modules to install.
 */
function _searchstax_update_test_install_modules(array $modules): void {
  $connection = Database::getConnection();

  // Set schema versions of our dependencies that prevent any updates from
  // running.
  $versions = array_fill_keys($modules, 8999);
  unset($versions['searchstax']);
  foreach ($versions as $name => $version) {
    $connection->insert('key_value')
      ->fields([
        'collection' => 'system.schema',
        'name' => $name,
        'value' => serialize($version),
      ])
      ->execute();
  }

  // Update core.extension.
  $extensions = $connection->select('config')
    ->fields('config', ['data'])
    ->condition('collection', '')
    ->condition('name', 'core.extension')
    ->execute()
    ->fetchField();
  $extensions = unserialize($extensions);
  $extensions['module'] += array_fill_keys($modules, 0);
  $connection->update('config')
    ->fields([
      'data' => serialize($extensions),
    ])
    ->condition('collection', '')
    ->condition('name', 'core.extension')
    ->execute();
}
