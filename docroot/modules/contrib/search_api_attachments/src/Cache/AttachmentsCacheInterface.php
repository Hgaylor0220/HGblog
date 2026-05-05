<?php

namespace Drupal\search_api_attachments\Cache;

use Drupal\file\FileInterface;

/**
 * Provides an interface for a service that extracts files content.
 */
interface AttachmentsCacheInterface {

  /**
   * Clears the cached data for the given file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file object.
   */
  public function clear(FileInterface $file);

  /**
   * Clears all cached data.
   */
  public function clearAll();

  /**
   * Gets cached data for the given file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file object.
   *
   * @return string|null
   *   The cached data or NULL.
   */
  public function get(FileInterface $file);

  /**
   * Sets cached data for the given file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file object.
   * @param string $data
   *   The data to cache.
   */
  public function set(FileInterface $file, $data);

  /**
   * Get the label of the Caching service.
   *
   * @return string
   *   The label string.
   */
  public function getLabel();

}
