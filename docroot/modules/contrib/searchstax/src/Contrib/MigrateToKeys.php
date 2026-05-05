<?php

declare(strict_types=1);

namespace Drupal\searchstax\Contrib;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\search_api_solr\SolrBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides methods for migrating sensitive configuration to keys.
 */
class MigrateToKeys implements MigrateToKeysInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The messenger service.
   */
  protected MessengerInterface $messenger;

  /**
   * The logger to use.
   */
  protected LoggerInterface $logger;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    MessengerInterface $messenger,
    LoggerInterface $logger,
    TranslationInterface $string_translation
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function ensureDefaultKeys(): void {
    try {
      $key_storage = $this->entityTypeManager->getStorage('key');
    }
    catch (PluginException $ignored) {
      return;
    }

    // Go through all key entities in "config/optional/" and create them if they
    // do not exist yet.
    $dir = __DIR__ . '/../../config/optional';
    assert(is_dir($dir));
    $source = new FileStorage($dir);
    foreach ($source->listAll('key.key.') as $name) {
      [, , $key_id] = explode('.', $name);
      if (!$key_storage->load($key_id)) {
        $values = $source->read($name);
        $entity = $key_storage->createFromStorageRecord($values);
        $entity->save();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function migrateAnalyticsCredentials(bool $in_update_hook = FALSE): void {
    $config = $this->configFactory->getEditable('searchstax.settings');
    $any_changes = FALSE;

    // Ensure key_id is set.
    $key_id = $config->get('key_id');
    if (!$key_id) {
      $key_id = 'searchstax_analytics_credentials';
      $config->set('key_id', $key_id);
      $any_changes = TRUE;
    }

    // Check if analytics credentials are still in config (not yet migrated).
    $analytics_url = $config->get('analytics_url');
    $analytics_key = $config->get('analytics_key');

    if ($analytics_url || $analytics_key) {
      // Migrate credentials to the key.
      $credentials = [
        'analytics_url' => $analytics_url ?: '',
        'analytics_key' => $analytics_key ?: '',
      ];

      $key_config = $this->configFactory->getEditable("key.key.$key_id");
      // We can/should only handle the "config" provider.
      if ($key_config->get('key_provider') === 'config') {
        $key_config
          ->set('key_provider_settings.key_value', json_encode($credentials));
        if (version_compare(\Drupal::VERSION, '11.3.99', '<=')) {
          $key_config->save($in_update_hook);
        }
        else {
          $key_config->save();
        }
      }

      // Clear the settings from the config.
      $config->clear('analytics_url');
      $config->clear('analytics_key');
      $any_changes = TRUE;
    }

    if ($any_changes) {
      if (version_compare(\Drupal::VERSION, '11.3.99', '<=')) {
        $config->save($in_update_hook);
      }
      else {
        $config->save();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function migrateServersToKeys(): int {
    try {
      $server_storage = $this->entityTypeManager->getStorage('search_api_server');
      $key_storage = $this->entityTypeManager->getStorage('key');
    }
    catch (PluginException $ignored) {
      return 0;
    }

    $existing_ids = $key_storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    $migrated_count = 0;
    /** @var \Drupal\search_api\ServerInterface $server */
    foreach ($server_storage->loadMultiple() as $server) {
      $backend_config = $server->getBackendConfig();

      // Check if this is a Solr server that uses the SearchStax connector.
      if (
        $server->hasValidBackend()
        && $server->getBackend() instanceof SolrBackendInterface
        && ($backend_config['connector'] ?? '') !== 'searchstax'
      ) {
        continue;
      }

      $connector_config = $backend_config['connector_config'] ?? [];

      // Skip if already using Key module.
      if (!empty($connector_config['key_id'])) {
        continue;
      }

      // Check if plain-text credentials exist and need migration.
      $update_endpoint = $connector_config['update_endpoint'] ?? NULL;
      $update_token = $connector_config['update_token'] ?? NULL;
      $autosuggest_endpoint = $connector_config['autosuggest_endpoint'] ?? NULL;

      // Skip if there are no credentials to migrate.
      if (!$update_endpoint && !$update_token && !$autosuggest_endpoint) {
        continue;
      }

      // Create a unique key for this server's connector credentials.
      $key_id = 'searchstax_connector_' . $server->id();

      // Check if the key already exists.
      $existing_key = $key_storage->load($key_id);

      // If the key exists, make sure it contains the correct information.
      // Otherwise, we need to create a new one after all.
      if ($existing_key) {
        $key_data = json_decode($existing_key->getKeyValue(), TRUE);
        if (
          ($key_data['update_endpoint'] ?? NULL) !== $update_endpoint
          || ($key_data['update_token'] ?? NULL) !== $update_token
          || (
            $autosuggest_endpoint
            && ($key_data['autosuggest_endpoint'] ?? NULL) !== $autosuggest_endpoint
          )
        ) {
          $this->messenger->addWarning($this->t('Existing key %key_id did not contain the correct connection information. Creating a new key instead.', [
            '%key_id' => $key_id,
          ]));
          $existing_key = NULL;
          $new_id = $key_id;
          $i = 0;
          while (in_array($new_id, $existing_ids)) {
            $new_id = $key_id . '_' . ++$i;
          }
          $key_id = $new_id;
        }
      }

      if (!$existing_key) {
        // Create new key entity with all credentials.
        $credentials = [
          'update_endpoint' => $update_endpoint,
          'update_token' => $update_token,
        ];

        // Include autosuggest_endpoint in the key if it exists.
        if (!empty($autosuggest_endpoint)) {
          $credentials['autosuggest_endpoint'] = $autosuggest_endpoint;
        }

        $key_storage->create([
          'id' => $key_id,
          'label' => 'SearchStax connector credentials for ' . $server->label(),
          'description' => 'Stores SearchStax connector credentials (update endpoint, token, and autosuggest endpoint) for ' . $server->label() . ' server.',
          'key_type' => 'authentication',
          'key_type_settings' => [],
          'key_provider' => 'config',
          'key_provider_settings' => [
            'key_value' => json_encode($credentials),
          ],
          'key_input' => 'text_field',
          'key_input_settings' => [],
        ])->save();
        $existing_ids[] = $key_id;
      }

      // Update server configuration to use the key.
      $connector_config['key_id'] = $key_id;

      // Clear the plain-text credentials from config.
      unset(
        $connector_config['host'],
        $connector_config['context'],
        $connector_config['core'],
        $connector_config['update_endpoint'],
        $connector_config['update_token'],
        $connector_config['autosuggest_endpoint'],
      );

      // Save the updated server configuration.
      $backend_config['connector_config'] = $connector_config;
      $server->setBackendConfig($backend_config)
        ->save();

      $migrated_count++;

      $this->logger->info('Migrated SearchStax connector credentials for server "@server" to Key module.', [
        '@server' => $server->label(),
      ]);
    }

    return $migrated_count;
  }

}
