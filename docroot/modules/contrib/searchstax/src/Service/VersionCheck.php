<?php

declare(strict_types=1);

namespace Drupal\searchstax\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\search_api\ServerInterface;
use Drupal\searchstax\Service\Data\AppInfo;
use Drupal\searchstax\Service\Data\VersionCheckResult;

/**
 * Provides a service for checking compatibility of SearchStax app and Drupal.
 */
class VersionCheck implements VersionCheckInterface {

  /**
   * The prefix for the keys used to store data in Drupal state.
   */
  protected const STATE_KEY_PREFIX = 'searchstax.version_check';

  /**
   * The API service.
   */
  protected ApiInterface $api;

  /**
   * The SearchStax utility service.
   */
  protected SearchStaxServiceInterface $utility;

  /**
   * The Drupal state.
   */
  protected StateInterface $state;

  /**
   * The cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * The key repository service, if available.
   */
  protected ?KeyRepositoryInterface $keyRepository;

  /**
   * Per-request cache of app information, keyed by server IDs.
   *
   * @var array<string, \Drupal\searchstax\Service\Data\AppInfo>
   *
   * @see self::getAppInformation()
   */
  protected array $appInfoCache = [];

  /**
   * Per-request cache of update endpoints, keyed by server IDs.
   *
   * @var array<string, string>
   *
   * @see self::getUpdateEndpoint()
   */
  protected array $updateEndpointCache = [];

  /**
   * Constructs a new class instance.
   *
   * @param \Drupal\searchstax\Service\ApiInterface $api
   *   The API service.
   * @param \Drupal\searchstax\Service\SearchStaxServiceInterface $utility
   *   The SearchStax utility service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The Drupal state.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\key\KeyRepositoryInterface|null $key_repository
   *   The key repository service, if available.
   */
  public function __construct(
    ApiInterface $api,
    SearchStaxServiceInterface $utility,
    StateInterface $state,
    CacheBackendInterface $cache,
    TimeInterface $time,
    ?KeyRepositoryInterface $key_repository
  ) {
    $this->api = $api;
    $this->utility = $utility;
    $this->state = $state;
    $this->cache = $cache;
    $this->time = $time;
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public function getDrupalMajorVersion(): int {
    return (int) \Drupal::VERSION;
  }

  /**
   * {@inheritdoc}
   */
  public function getAppInformation(ServerInterface $server): ?AppInfo {
    $server_id = $server->id();
    if (!array_key_exists($server_id, $this->appInfoCache)) {
      $update_endpoint = $this->getUpdateEndpoint($server);
      if (!$update_endpoint) {
        $this->appInfoCache[$server_id] = NULL;
        return NULL;
      }
      $cid = "searchstax.app_info.$update_endpoint";
      $cache = $this->cache->get($cid);
      if ($cache) {
        $this->appInfoCache[$server_id] = $cache->data;
      }
      else {
        $app_info = $this->doGetAppInformation($update_endpoint);
        $this->cache->set($cid, $app_info);
        $this->appInfoCache[$server_id] = $app_info;
      }
    }
    return $this->appInfoCache[$server_id];
  }

  /**
   * {@inheritdoc}
   */
  public function hasCompatibilityDataStored(ServerInterface $server): bool {
    return (bool) $this->readStoredData($server);
  }

  /**
   * {@inheritdoc}
   */
  public function checkCompatibility(ServerInterface $server, bool $reset = FALSE): VersionCheckResult {
    $major_version = $this->getDrupalMajorVersion();
    if (!$reset) {
      $data = $this->readStoredData($server);
    }
    if (empty($data)) {
      $app_info = $this->getAppInformation($server);
      $response = $this->api->checkDrupalVersionCompatibility(
        $app_info->getAccount(),
        $app_info->getAppId(),
        $major_version,
      );
      $compatible = !empty($response['compatible']);
      $message = NULL;
      if (!$compatible) {
        $message = $response['message'] ?? 'Configs are incompatible (no details provided)';
      }
      $data = new VersionCheckResult(
        $major_version,
        $compatible,
        $this->time->getRequestTime(),
        $response,
        $message,
      );
      $this->writeStoredData($server, $data);
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function upgradeApp(ServerInterface $server): void {
    $app_info = $this->getAppInformation($server);
    $this->api->upgradeForDrupalVersionCompatibility(
      $app_info->getAccount(),
      $app_info->getAppId(),
      $this->getDrupalMajorVersion(),
    );
    $this->checkCompatibility($server, TRUE);
  }

  /**
   * Retrieves the SearchStax update endpoint for the given server.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server.
   *
   * @return string|null
   *   The URL of the server's SearchStax update endpoint; or NULL if there is
   *   none.
   */
  protected function getUpdateEndpoint(ServerInterface $server): ?string {
    $server_id = $server->id();
    if (!array_key_exists($server_id, $this->updateEndpointCache)) {
      $backend_config = $server->getBackendConfig();
      $config = $backend_config['connector_config'] ?? [];
      if (!$this->utility->isSearchStaxSolrServer($server)) {
        $this->updateEndpointCache[$server_id] = NULL;
      }
      elseif ($this->keyRepository && !empty($config['key_id'])) {
        $this->updateEndpointCache[$server_id] = NULL;
        $key = $this->keyRepository->getKey($config['key_id']);
        if ($key) {
          $credentials = json_decode($key->getKeyValue(), TRUE);
          if (isset($credentials['update_endpoint'])) {
            $this->updateEndpointCache[$server_id] = $credentials['update_endpoint'];
          }
        }
      }
      elseif (!empty($config['update_endpoint'])) {
        $this->updateEndpointCache[$server_id] = $config['update_endpoint'];
      }
      else {
        $url = "{$config['scheme']}://{$config['host']}";
        if (!in_array($config['port'] ?? 80, [80, 443])) {
          $url .= ":{$config['port']}";
        }
        foreach (['path', 'context', 'core'] as $key) {
          if (!empty($config[$key])) {
            $url .= "/{$config[$key]}";
            $url = rtrim($url, '/');
          }
        }
        $url .= '/update';
        $this->updateEndpointCache[$server_id] = $url;
      }
    }
    return $this->updateEndpointCache[$server_id];
  }

  /**
   * Retrieves the app information for a given SearchStax update endpoint.
   *
   * @param string $update_endpoint
   *   The update endpoint URL to match.
   *
   * @return \Drupal\searchstax\Service\Data\AppInfo|null
   *   Information about the SearchStax app matching the given update endpoint,
   *   or NULL if none could be found.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  protected function doGetAppInformation(string $update_endpoint): ?AppInfo {
    foreach ($this->api->getAccounts() as $account => $account_info) {
      foreach ($this->api->getApps($account) as $app_id => $app) {
        if (($app['update_endpoint'] ?? '') === $update_endpoint) {
          return new AppInfo($account, $app['name'], $app_id);
        }
      }
    }
    return NULL;
  }

  /**
   * Retrieves stored compatibility data for the given server.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server.
   *
   * @return array|null
   *   The compatibility check result if a stored value matching the current
   *   Drupal major version could be found; NULL otherwise.
   */
  protected function readStoredData(ServerInterface $server): ?VersionCheckResult {
    $update_endpoint = $this->getUpdateEndpoint($server);
    if (!$update_endpoint) {
      return NULL;
    }
    /** @var \Drupal\searchstax\Service\Data\VersionCheckResult $data */
    $data = $this->state->get($this->getStateKey($update_endpoint));
    if ($data && $data->getDrupalVersion() !== $this->getDrupalMajorVersion()) {
      $this->deleteStoredData($server);
      return NULL;
    }
    return $data;
  }

  /**
   * Saves stored compatibility data for the given server.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server.
   * @param \Drupal\searchstax\Service\Data\VersionCheckResult $data
   *   The compatibility data in the internally used format.
   */
  protected function writeStoredData(ServerInterface $server, VersionCheckResult $data): void {
    $update_endpoint = $this->getUpdateEndpoint($server);
    if ($update_endpoint) {
      $this->state->set($this->getStateKey($update_endpoint), $data);
    }
  }

  /**
   * Deletes the stored compatibility data for the given server.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server.
   */
  protected function deleteStoredData(ServerInterface $server): void {
    $update_endpoint = $this->getUpdateEndpoint($server);
    if ($update_endpoint) {
      $this->state->delete($this->getStateKey($update_endpoint));
    }
  }

  /**
   * Retrieves the state key used to store compatibility data for the given app.
   *
   * @param string $update_endpoint
   *   The update endpoint used by the SearchStax app.
   *
   * @return string
   *   The key used for storing data for the given app in the Drupal state.
   */
  protected function getStateKey(string $update_endpoint): string {
    return static::STATE_KEY_PREFIX . ".$update_endpoint";
  }

}
