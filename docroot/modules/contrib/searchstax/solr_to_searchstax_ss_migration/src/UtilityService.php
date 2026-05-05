<?php

declare(strict_types=1);

namespace Drupal\solr_to_searchstax_ss_migration;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\searchstax\Service\SearchStaxServiceInterface;

/**
 * Provides common helper methods for this module's functionality.
 */
class UtilityService implements UtilityServiceInterface {

  /**
   * Delete map entries with the given value.
   *
   * @see deleteKeyValueStoreEntry()
   */
  protected const DELETE_VALUE = 1;

  /**
   * Delete the map entry with the given key.
   *
   * @see deleteKeyValueStoreEntry()
   */
  protected const DELETE_KEY = 2;

  /**
   * Delete map entries with the given key or value.
   *
   * @see deleteKeyValueStoreEntry()
   */
  protected const DELETE_BOTH = 3;

  /**
   * The SearchStax utility service.
   */
  protected SearchStaxServiceInterface $searchStaxService;

  /**
   * The key-value store factory.
   */
  protected KeyValueFactoryInterface $keyValueFactory;

  /**
   * Constructs a new class instance.
   *
   * @param \Drupal\searchstax\Service\SearchStaxServiceInterface $searchstax_service
   *   The SearchStax utility service.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key-value store factory.
   */
  public function __construct(
    SearchStaxServiceInterface $searchstax_service,
    KeyValueFactoryInterface $key_value_factory
  ) {
    $this->searchStaxService = $searchstax_service;
    $this->keyValueFactory = $key_value_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function canServerBeMigrated(ServerInterface $server, array $all_servers): bool {
    return $this->isNonSearchStaxSolrServer($server)
      && $this->getMigratedServer($server->id(), $all_servers) === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isNonSearchStaxSolrServer(ServerInterface $server): bool {
    try {
      $backend = $server->getBackend();
    }
    catch (SearchApiException $ignored) {
      return FALSE;
    }
    if (!($backend instanceof SolrBackendInterface)) {
      return FALSE;
    }
    return !$this->searchStaxService->isSearchstaxSolr($backend->getConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function getMigratedServer(string $original_server_id, array $all_servers): ?ServerInterface {
    $migrated_server_id = $this->getMigratedServers()[$original_server_id] ?? '';
    return $all_servers[$migrated_server_id] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getMigratedServers(): array {
    return $this->getKeyvalueStore()->get('migrated_servers', []);
  }

  /**
   * {@inheritdoc}
   */
  public function addMigratedServer(string $original_server_id, string $new_server_id): void {
    $this->addKeyValueStoreEntry('migrated_servers', $original_server_id, $new_server_id);
  }

  /**
   * Removes the deleted server from the list of migrated servers.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The deleted server.
   */
  public function onServerDelete(EntityInterface $entity): void {
    $this->deleteKeyValueStoreEntry('migrated_servers', $entity->id());
  }

  /**
   * {@inheritdoc}
   */
  public function getCopiedIndexes(): array {
    return $this->getKeyvalueStore()->get('copied_indexes', []);
  }

  /**
   * {@inheritdoc}
   */
  public function addCopiedIndex(string $original_index_id, string $new_index_id): void {
    $this->addKeyValueStoreEntry('copied_indexes', $original_index_id, $new_index_id);
  }

  /**
   * Removes the deleted index from the list of copied indexes.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The deleted index.
   */
  public function onIndexDelete(EntityInterface $entity): void {
    $index_id = $entity->id();
    $this->deleteKeyValueStoreEntry('copied_indexes', $index_id);
    $index_base_table = "search_api_index_$index_id";
    $this->deleteKeyValueStoreEntry('views_original_base_tables', $index_base_table, self::DELETE_VALUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getOriginalBaseTables(): array {
    return $this->getKeyvalueStore()->get('views_original_base_tables', []);
  }

  /**
   * {@inheritdoc}
   */
  public function addOriginalBaseTable(string $view_id, string $original_base_table): void {
    $this->addKeyValueStoreEntry('views_original_base_tables', $view_id, $original_base_table);
  }

  /**
   * Removes the deleted view from the map of original base tables.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The deleted view.
   */
  public function onViewDelete(EntityInterface $entity): void {
    $this->deleteKeyValueStoreEntry('views_original_base_tables', $entity->id(), self::DELETE_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function findNewEntityId(string $base_id, EntityStorageInterface $entity_storage): string {
    $existing_ids = $entity_storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    $new_id = $base_id;
    $i = 0;
    while (in_array($new_id, $existing_ids)) {
      $new_id = $base_id . '_' . ++$i;
    }
    return $new_id;
  }

  /**
   * Retrieves this module’s key-value store.
   *
   * @return \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   *   The key-value store.
   */
  protected function getKeyvalueStore(): KeyValueStoreInterface {
    return $this->keyValueFactory->get('solr_to_searchstax_ss_migration');
  }

  /**
   * Adds a new entry to the given map in the key-value store.
   *
   * @param string $map_key
   *   The key of the map.
   * @param string $new_key
   *   The new key to add.
   * @param string $new_value
   *   The new value to add.
   */
  protected function addKeyValueStoreEntry(string $map_key, string $new_key, string $new_value): void {
    $key_value = $this->getKeyvalueStore();
    $map = $key_value->get($map_key, []);
    $map[$new_key] = $new_value;
    $key_value->set($map_key, $map);
  }

  /**
   * Deletes an entry from the given map in the key-value store.
   *
   * @param string $map_key
   *   The key of the map.
   * @param string $entry
   *   The entry to delete.
   * @param int $mode
   *   (optional) Whether to delete the entry as a key, value or both. One of
   *   the self::DELETE_* constants.
   */
  protected function deleteKeyValueStoreEntry(
    string $map_key,
    string $entry,
    int $mode = self::DELETE_BOTH
  ): void {
    $key_value = $this->getKeyvalueStore();
    $map = $key_value->get($map_key, []);
    if ($mode & self::DELETE_KEY) {
      unset($map[$entry]);
    }
    if ($mode & self::DELETE_VALUE) {
      $map = array_diff($map, [$entry]);
    }
    $key_value->set($map_key, $map);
  }

}
