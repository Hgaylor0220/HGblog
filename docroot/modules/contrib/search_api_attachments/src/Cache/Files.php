<?php

namespace Drupal\search_api_attachments\Cache;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;

/**
 * Provides Files cache.
 */
class Files implements AttachmentsCacheInterface {

  use StringTranslationTrait;

  /**
   * Module config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructs a Files object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    protected FileSystemInterface $fileSystem,
    protected StreamWrapperManagerInterface $streamWrapperManager,
  ) {
    $this->config = $config_factory->get('search_api_attachments.admin_config');
  }

  /**
   * {@inheritdoc}
   */
  public function clear(FileInterface $file) {
    $cached_path = $this->getCachedFilePath($file);
    if (file_exists($cached_path)) {
      $this->fileSystem->delete($cached_path);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clearAll() {
    $this->fileSystem->deleteRecursive($this->getDirectory());
  }

  /**
   * {@inheritdoc}
   */
  public function get(FileInterface $file) {
    $cached_path = $this->getCachedFilePath($file);
    if (file_exists($cached_path)) {
      return file_get_contents($cached_path);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function set(FileInterface $file, $data) {
    $cached_path = $this->getCachedFilePath($file);
    $directory = $this->fileSystem->dirname($cached_path);
    if ($this->fileSystem->prepareDirectory($directory, FileSystemInterface::MODIFY_PERMISSIONS | FileSystemInterface::CREATE_DIRECTORY)) {
      $this->fileSystem->saveData($data, $cached_path, FileExists::Replace);
    }
  }

  /**
   * Returns the path string for the cached file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return string
   *   The path string.
   */
  protected function getCachedFilePath(FileInterface $file) {
    return $this->getDirectory() . '/' . StreamWrapperManager::getTarget($file->getFileUri());
  }

  /**
   * Returns the base directory for cached files.
   *
   * @return string
   *   The directory, without trailing /.
   */
  protected function getDirectory() {
    $scheme = $this->config->get('cache_file_scheme') ?? 'private';
    if (!$this->streamWrapperManager->isValidScheme($scheme)) {
      $scheme = 'public';
    }
    return $scheme . '://search_api_attachments';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->t('Files');
  }

}
