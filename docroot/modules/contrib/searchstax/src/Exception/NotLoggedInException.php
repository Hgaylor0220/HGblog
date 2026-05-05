<?php

declare(strict_types=1);

namespace Drupal\searchstax\Exception;

/**
 * Represents an API call error that occurred because there is no active login.
 */
class NotLoggedInException extends SearchStaxException {

  /**
   * {@inheritdoc}
   */
  // phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod
  public function __construct(
    string $message = 'No active login.',
    int $code = 0,
    ?\Throwable $previous = NULL
  ) {
    parent::__construct($message, $code, $previous);
  }

}
