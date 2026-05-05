<?php

declare(strict_types=1);

namespace Drupal\searchstax_migration_test\Mock;

use Drupal\acquia_search\PreferredCoreService;

/**
 * Provides a mock "preferred core" service for testing purposes.
 */
class MockPreferredCoreService extends PreferredCoreService {

  /**
   * The list of cores to report.
   */
  protected const CORES = [
    'abc-12345.prod.example' => [
      'balancer' => 'example.prod.acquia.com',
      'core_id' => 'abc-12345.prod.example',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public function getAvailableCores(): array {
    return static::CORES;
  }

  /**
   * {@inheritdoc}
   */
  public function getPreferredCore(): ?array {
    return static::CORES['abc-12345.prod.example'];
  }

  /**
   * {@inheritdoc}
   */
  public function getListOfPossibleCores(): array {
    return array_keys(static::CORES);
  }

  /**
   * {@inheritdoc}
   */
  public function isReadOnly(): bool {
    return FALSE;
  }

}
