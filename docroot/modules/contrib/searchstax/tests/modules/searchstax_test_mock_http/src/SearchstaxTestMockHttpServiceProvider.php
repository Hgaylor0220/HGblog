<?php

declare(strict_types=1);

namespace Drupal\searchstax_test_mock_http;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\searchstax_test_mock_http\Mock\MockHttpClient;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Implements a service provider for this test module.
 *
 * Overrides the "http_client" service with our own implementation.
 */
class SearchstaxTestMockHttpServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    parent::alter($container);

    $container->getDefinition('http_client')
      ->setClass(MockHttpClient::class)
      ->setArguments([new Reference('messenger'), new Reference('keyvalue')])
      ->setFactory(NULL);
  }

}
