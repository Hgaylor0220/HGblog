<?php

declare(strict_types=1);

namespace Drupal\searchstax\Service;

use Drupal\search_api\ServerInterface;
use Drupal\searchstax\Service\Data\AppInfo;
use Drupal\searchstax\Service\Data\VersionCheckResult;

/**
 * Provides an interface for the version check service.
 */
interface VersionCheckInterface {

  /**
   * Retrieves the Drupal major version against which compatibility is checked.
   *
   * @return int
   *   The Drupal major version number.
   */
  public function getDrupalMajorVersion(): int;

  /**
   * Retrieves the SearchStax app to which the given server is linked.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server.
   *
   * @return \Drupal\searchstax\Service\Data\AppInfo|null
   *   Information about the SearchStax app used by the given server; or NULL if
   *   the server is not linked to a SearchStax app.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function getAppInformation(ServerInterface $server): ?AppInfo;

  /**
   * Determines whether the service has data stored for the given server.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server for which to check compatibility.
   *
   * @return bool
   *   TRUE if there is data stored, that is, a call to checkCompatibility()
   *   for this app will not result in an HTTP request or an exception; FALSE
   *   otherwise.
   */
  public function hasCompatibilityDataStored(ServerInterface $server): bool;

  /**
   * Checks compatibility of a search server with the current Drupal version.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server for which to check compatibility.
   * @param bool $reset
   *   (optional) TRUE to force a check even if stored data is available.
   *
   * @return \Drupal\searchstax\Service\Data\VersionCheckResult
   *   The result of the compatibility check.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function checkCompatibility(ServerInterface $server, bool $reset = FALSE): VersionCheckResult;

  /**
   * Upgrades a SearchStax app to be compatible with the current Drupal version.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server for which to upgrade the corresponding SearchStax app.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function upgradeApp(ServerInterface $server): void;

}
