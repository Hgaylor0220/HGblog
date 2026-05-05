<?php

namespace Drupal\search_api_attachments\Cache;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;

/**
 * Provides KeyValue cache.
 */
class KeyValue implements AttachmentsCacheInterface {

  use StringTranslationTrait;

  /**
   * The key-value collection.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $keyValue;

  /**
   * Constructs a KeyValue object.
   */
  public function __construct(KeyValueFactoryInterface $key_value) {
    $this->keyValue = $key_value->get('search_api_attachments');
  }

  /**
   * {@inheritdoc}
   */
  public function clear(FileInterface $file) {
    $this->keyValue->delete($this->getKey($file));
  }

  /**
   * {@inheritdoc}
   */
  public function clearAll() {
    $this->keyValue->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function get(FileInterface $file) {
    return $this->keyValue->get($this->getKey($file));
  }

  /**
   * {@inheritdoc}
   */
  public function set(FileInterface $file, $data) {
    return $this->keyValue->set($this->getKey($file), $data);
  }

  /**
   * Returns the key-value key for a given file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return string
   *   The key in the key-value storage.
   */
  protected function getKey(FileInterface $file) {
    return 'search_api_attachments:' . $file->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->t('Key value');
  }

}
