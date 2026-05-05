<?php

namespace Drupal\searchstax\Service\Data;

/**
 * Provides a data object for encapsulating information about a SearchStax app.
 */
final class AppInfo {

  /**
   * The account name.
   */
  protected string $account;

  /**
   * The app name.
   */
  protected string $appName;

  /**
   * The app ID.
   */
  protected int $appId;

  /**
   * Constructs a new class instance.
   *
   * @param string $account
   *   The account name.
   * @param string $app_name
   *   The app name.
   * @param int $app_id
   *   The app ID.
   */
  public function __construct(string $account, string $app_name, int $app_id) {
    $this->account = $account;
    $this->appName = $app_name;
    $this->appId = $app_id;
  }

  /**
   * Retrieves the account name.
   *
   * @return string
   *   The account name.
   */
  public function getAccount(): string {
    return $this->account;
  }

  /**
   * Retrieves the app name.
   *
   * @return string
   *   The app name.
   */
  public function getAppName(): string {
    return $this->appName;
  }

  /**
   * Retrieves the app ID.
   *
   * @return int
   *   The app ID.
   */
  public function getAppId(): int {
    return $this->appId;
  }

}
