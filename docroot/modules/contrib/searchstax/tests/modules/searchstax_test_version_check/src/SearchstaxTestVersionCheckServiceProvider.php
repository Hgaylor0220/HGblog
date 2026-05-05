<?php

namespace Drupal\searchstax_test_version_check;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Overrides the version check service with a subclass for testing.
 */
class SearchstaxTestVersionCheckServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    parent::alter($container);

    $definition = $container->getDefinition('searchstax.version_check');
    $definition->setClass(DecoratedVersionCheck::class);
    $args = $definition->getArguments();
    $args[] = new Reference('keyvalue');
    $definition->setArguments($args);
  }

}
