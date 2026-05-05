<?php

declare(strict_types=1);

namespace Drupal\solr_to_searchstax_ss_migration;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\search_api\ServerInterface;

/**
 * Provides an interface for the utility service.
 */
interface UtilityServiceInterface {

  /**
   * Determines whether the given Search API server can be migrated.
   *
   * This is the case if the server uses a Solr server that is not hosted by
   * SearchStax and has not been migrated yet.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server.
   * @param \Drupal\search_api\ServerInterface[] $all_servers
   *   An associative array of all existing search servers, keyed by ID.
   *
   * @return bool
   *   TRUE if the given search server can be migrated, FALSE otherwise.
   */
  public function canServerBeMigrated(ServerInterface $server, array $all_servers): bool;

  /**
   * Determines whether the given server uses a non-SearchStax Solr server.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server.
   *
   * @return bool
   *   TRUE if the given search server uses a non-SearchStax Solr server, FALSE
   *   otherwise.
   */
  public function isNonSearchStaxSolrServer(ServerInterface $server): bool;

  /**
   * Finds the SearchStax server to which the given server has been migrated.
   *
   * @param string $original_server_id
   *   The original search server’s ID.
   * @param \Drupal\search_api\ServerInterface[] $all_servers
   *   An associative array of all existing search servers, keyed by ID.
   *
   * @return \Drupal\search_api\ServerInterface|null
   *   The SearchStax server to which the original server has been migrated, if
   *   any; NULL otherwise.
   */
  public function getMigratedServer(string $original_server_id, array $all_servers): ?ServerInterface;

  /**
   * Retrieves a map of migrated servers.
   *
   * @return string[]
   *   An associative array mapping the IDs of Solr servers that were migrated
   *   to the new server IDs.
   */
  public function getMigratedServers(): array;

  /**
   * Adds a newly migrated server.
   *
   * @param string $original_server_id
   *   The ID of the original Solr server that was migrated.
   * @param string $new_server_id
   *   The ID of the newly created SearchStax server.
   */
  public function addMigratedServer(string $original_server_id, string $new_server_id): void;

  /**
   * Retrieves a map of copied indexes.
   *
   * @return string[]
   *   An associative array mapping the IDs of indexes that were copied to the
   *   new index IDs.
   */
  public function getCopiedIndexes(): array;

  /**
   * Adds a newly copied index.
   *
   * @param string $original_index_id
   *   The ID of the original index that was migrated.
   * @param string $new_index_id
   *   The ID of the newly created index.
   */
  public function addCopiedIndex(string $original_index_id, string $new_index_id): void;

  /**
   * Retrieves a map of views’ original base tables before migration.
   *
   * @return string[]
   *   An associative array mapping the IDs of views whose underlying search
   *   index was switched to their original base tables.
   */
  public function getOriginalBaseTables(): array;

  /**
   * Adds a newly migrated view’s original base table.
   *
   * @param string $view_id
   *   The ID of the search view.
   * @param string $original_base_table
   *   The view’s original base table.
   */
  public function addOriginalBaseTable(string $view_id, string $original_base_table): void;

  /**
   * Finds an available entity ID.
   *
   * @param string $base_id
   *   The ID to use if available.
   * @param \Drupal\Core\Entity\EntityStorageInterface $entity_storage
   *   The entity storage from which existing IDs will be loaded.
   *
   * @return string
   *   An ID that does not exist yet.
   */
  public function findNewEntityId(string $base_id, EntityStorageInterface $entity_storage): string;

}
