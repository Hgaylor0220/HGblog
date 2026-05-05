<?php

declare(strict_types=1);

namespace Drupal\solr_to_searchstax_ss_migration\Form;

use Drupal\searchstax\Form\ApiLoginFormTrait;

@trigger_error('\Drupal\solr_to_searchstax_ss_migration\Form\SearchStaxLoginFormTrait is deprecated in searchstax:1.7.0 and is removed from searchstax:2.0.0. Use \Drupal\searchstax\Form\ApiLoginFormTrait instead. See https://www.drupal.org/node/3524385', E_USER_DEPRECATED);

/**
 * Provides methods for displaying a SearchStax login form.
 *
 * @deprecated in searchstax:1.7.0 and is removed from searchstax:2.0.0. Use
 *   \Drupal\searchstax\Form\ApiLoginFormTrait instead.
 *
 * @see https://www.drupal.org/node/3524385
 */
trait SearchStaxLoginFormTrait {

  use ApiLoginFormTrait;

}
