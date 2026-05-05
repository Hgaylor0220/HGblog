<?php

namespace Drupal\searchstax\Service\Data;

/**
 * Provides a data object for encapsulating the result of a version check.
 */
final class VersionCheckResult {

  /**
   * The Drupal major version for which compatibility was checked.
   */
  protected int $drupalVersion;

  /**
   * Whether the SearchStax app configuration was compatible.
   */
  protected bool $compatible;

  /**
   * The UNIX timestamp at which the check was performed.
   */
  protected int $checkedAt;

  /**
   * The response received from the SearchStax server.
   */
  protected array $response;

  /**
   * If the app configuration was incompatible, the associated message.
   */
  protected ?string $message = NULL;

  /**
   * Constructs a new class instance.
   *
   * @param int $drupalVersion
   *   The Drupal major version for which compatibility was checked.
   * @param bool $compatible
   *   Whether the SearchStax app configuration was compatible.
   * @param int $checkedAt
   *   The UNIX timestamp at which the check was performed.
   * @param array $response
   *   The response received from the SearchStax server.
   * @param string|null $message
   *   (optional) If the app configuration was incompatible, the associated
   *   message.
   */
  public function __construct(
    int $drupalVersion,
    bool $compatible,
    int $checkedAt,
    array $response,
    ?string $message = NULL
  ) {
    $this->drupalVersion = $drupalVersion;
    $this->compatible = $compatible;
    $this->checkedAt = $checkedAt;
    $this->response = $response;
    $this->message = $message;
  }

  /**
   * Retrieves the Drupal major version for which compatibility was checked.
   *
   * @return int
   *   The Drupal major version for which compatibility was checked.
   */
  public function getDrupalVersion(): int {
    return $this->drupalVersion;
  }

  /**
   * Determines whether the SearchStax app configuration was compatible.
   *
   * @return bool
   *   TRUE if the SearchStax app configuration was compatible, FALSE otherwise.
   */
  public function isCompatible(): bool {
    return $this->compatible;
  }

  /**
   * Retrieves the UNIX timestamp at which the check was performed.
   *
   * @return int
   *   The UNIX timestamp at which the check was performed.
   */
  public function getCheckedAt(): int {
    return $this->checkedAt;
  }

  /**
   * Retrieves the response received from the SearchStax server.
   *
   * @return array
   *   The response received from the SearchStax server.
   */
  public function getResponse(): array {
    return $this->response;
  }

  /**
   * Retrieves the error message in case the config was incompatible.
   *
   * @return string|null
   *   The message detailing how the app configuration was incompatible, or NULL
   *   in case it was compatible.
   */
  public function getMessage(): ?string {
    return $this->message;
  }

}
