<?php

namespace Drupal\search_api_attachments\Cache;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Defines the attachments cache factory.
 */
class AttachmentsCacheFactory {

  /**
   * Module config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Array of cache backends.
   *
   * @var \Drupal\search_api_attachments\Cache\AttachmentsCacheInterface[]
   */
  protected array $backends = [];

  /**
   * Constructs a search api attachments cache factory.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->config = $config_factory->get('search_api_attachments.admin_config');
  }

  /**
   * Returns the configured cache backend.
   *
   * @return \Drupal\search_api_attachments\Cache\AttachmentsCacheInterface
   *   A caching service used.
   *
   * @throws \Exception
   *   Thrown if there is no backend for this ID.
   */
  public function get() {
    $service_name = $this->config->get('cache_backend') ?? 'search_api_attachments.cache_keyvalue';
    return $this->getById($service_name);
  }

  /**
   * Returns a specific cache backend.
   *
   * @param string $id
   *   The ID of the backend.
   *
   * @return \Drupal\search_api_attachments\Cache\AttachmentsCacheInterface
   *   The cache backend.
   *
   * @throws \Exception
   *   Thrown if there is no backend for this ID.
   */
  public function getById(string $id): AttachmentsCacheInterface {
    if (!isset($this->backends[$id])) {
      throw new \Exception('Search api attachments cache service not found');
    }

    return $this->backends[$id];
  }

  /**
   * Get a list of attachment cache services.
   *
   * @return array
   *   A list that can be used in forms etc.
   */
  public function getOptions() {
    $options = [];
    foreach ($this->backends as $id => $backend) {
      $options[$id] = $backend->getLabel();
    }
    return $options;
  }

  /**
   * Adds a cache backend to the factory.
   *
   * Usd by the service collector.
   *
   * @param \Drupal\search_api_attachments\Cache\AttachmentsCacheInterface $backend
   *   The cache backend.
   * @param string $id
   *   The service ID.
   */
  public function addCacheBackend(AttachmentsCacheInterface $backend, string $id) {
    $this->backends[$id] = $backend;
  }

}
