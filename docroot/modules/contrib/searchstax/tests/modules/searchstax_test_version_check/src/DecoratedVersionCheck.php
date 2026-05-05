<?php

namespace Drupal\searchstax_test_version_check;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\searchstax\Service\ApiInterface;
use Drupal\searchstax\Service\SearchStaxServiceInterface;
use Drupal\searchstax\Service\VersionCheck;

/**
 * Overrides the version check service to make the Drupal version variable.
 */
class DecoratedVersionCheck extends VersionCheck {

  /**
   * The key-value factory.
   */
  protected KeyValueFactoryInterface $keyValue;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ApiInterface $api,
    SearchStaxServiceInterface $utility,
    StateInterface $state,
    CacheBackendInterface $cache,
    TimeInterface $time,
    ?KeyRepositoryInterface $key_repository,
    KeyValueFactoryInterface $key_value
  ) {
    parent::__construct($api, $utility, $state, $cache, $time, $key_repository);

    $this->keyValue = $key_value;
  }

  /**
   * {@inheritdoc}
   */
  public function getDrupalMajorVersion(): int {
    return $this->keyValue->get('searchstax_test_version_check')->get('drupal_version')
      ?: parent::getDrupalMajorVersion();
  }

}
