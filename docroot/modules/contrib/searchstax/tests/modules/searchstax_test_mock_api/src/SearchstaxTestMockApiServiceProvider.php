<?php

declare(strict_types=1);

namespace Drupal\searchstax_test_mock_api;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\searchstax_test_mock_api\Mock\MockApiService;

/**
 * Implements a service provider for this test module.
 *
 * Overrides the "searchstax.api" service with our own implementation.
 */
class SearchstaxTestMockApiServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    parent::alter($container);

    $container->getDefinition('searchstax.api')
      ->setClass(MockApiService::class)
      ->setArguments([]);
  }

}
