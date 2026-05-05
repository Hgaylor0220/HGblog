<?php

declare(strict_types=1);

namespace Drupal\searchstax_migration_test\Mock;

use Drupal\acquia_search\PreferredCoreService;
use Drupal\acquia_search\PreferredCoreServiceFactory;

/**
 * Provides a mock "preferred core factory" service for testing purposes.
 */
class MockPreferredCoreServiceFactory extends PreferredCoreServiceFactory {

  /**
   * {@inheritdoc}
   */
  public function get(string $server_id): PreferredCoreService {
    return new MockPreferredCoreService();
  }

}
