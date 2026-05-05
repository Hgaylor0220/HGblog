<?php

declare(strict_types=1);

namespace Drupal\searchstax\Contrib;

/**
 * Provides an interface for the "migrate to keys" service.
 */
interface MigrateToKeysInterface {

  /**
   * Ensures default SearchStax keys exist.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if saving of any entity failed.
   */
  public function ensureDefaultKeys(): void;

  /**
   * Migrates SearchStax analytics credentials to Key-based storage.
   *
   * @param bool $in_update_hook
   *   Whether the method was called from inside an update hook.
   */
  public function migrateAnalyticsCredentials(bool $in_update_hook = FALSE): void;

  /**
   * Migrates SearchStax server credentials to Key-based storage.
   *
   * @return int
   *   The number of servers migrated.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if saving of any entity failed.
   */
  public function migrateServersToKeys(): int;

}
