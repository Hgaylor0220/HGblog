<?php

declare(strict_types=1);

namespace Drupal\searchstax\Exception;

use GuzzleHttp\Exception\RequestException;

/**
 * Provides an exception class for the SearchStax module.
 */
class SearchStaxException extends \Exception {

  /**
   * The server response that led to this exception, if any.
   */
  protected ?array $response = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    string $message = "",
    int $code = 0,
    ?\Throwable $previous = NULL,
    ?array $response = NULL
  ) {
    parent::__construct($message, $code, $previous);

    $this->response = $response;
  }

  /**
   * Retrieves the server response that led to this exception.
   *
   * @return array|null
   *   The server response that led to this exception, if any.
   */
  public function getResponse(): ?array {
    return $this->response;
  }

  /**
   * Wraps the given throwable.
   *
   * @param \Throwable $previous
   *   The throwable to wrap.
   *
   * @return self
   *   An instance of this class that wraps the given throwable.
   */
  public static function fromPrevious(\Throwable $previous): self {
    $exception = new SearchStaxException($previous->getMessage(), $previous->getCode(), $previous);
    if ($previous instanceof RequestException) {
      $response = $previous->getResponse();
      if ($response) {
        $data = @json_decode($response->getBody()->getContents(), TRUE);
        if ($data) {
          $exception->response = $data;
        }
      }
    }
    return $exception;
  }

}
