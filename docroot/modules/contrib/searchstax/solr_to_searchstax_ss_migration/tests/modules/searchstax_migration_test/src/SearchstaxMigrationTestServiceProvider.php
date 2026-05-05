<?php

declare(strict_types=1);

namespace Drupal\searchstax_migration_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\searchstax_migration_test\Mock\MockHttpClient;
use Drupal\searchstax_migration_test\Mock\MockPreferredCoreServiceFactory;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Implements a service provider for this test module.
 *
 * - Overrides the "acquia_search.solarium.guzzle" service with our own
 *   implementation.
 * - Removes the "acquia_search.search_subscriber" service so it doesn't try to
 *   validate our dummy HTTP requests.
 */
class SearchstaxMigrationTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    parent::alter($container);

    $container->getDefinition('acquia_search.solarium.guzzle')
      ->setClass(MockHttpClient::class)
      ->setArguments([new Reference('messenger'), new Reference('keyvalue')]);
    $container->getDefinition('acquia_search.preferred_core_factory')
      ->setClass(MockPreferredCoreServiceFactory::class);
    $container->removeDefinition('acquia_search.search_subscriber');
  }

}
