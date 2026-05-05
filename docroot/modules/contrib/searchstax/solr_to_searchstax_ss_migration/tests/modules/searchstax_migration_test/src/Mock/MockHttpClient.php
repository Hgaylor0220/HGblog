<?php

declare(strict_types=1);

namespace Drupal\searchstax_migration_test\Mock;

use Drupal\acquia_search\Client\Solarium\AcquiaGuzzle;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\searchstax_test_mock_http\Mock\MockHttpClient as ParentMockHttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Provides an HTTP client that just reads responses from local files.
 *
 * Proxies \Drupal\searchstax_test_mock_http\Mock\MockHttpClient to extend the
 * AcquiaGuzzle class.
 *
 * @see \Drupal\searchstax_test_mock_http\Mock\MockHttpClient
 */
class MockHttpClient extends AcquiaGuzzle {

  /**
   * The proxied mock HTTP client.
   */
  protected ParentMockHttpClient $client;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    MessengerInterface $messenger,
    KeyValueFactoryInterface $key_value_factory
  ) {
    /* @see \Drupal\Core\Http\ClientFactory::fromOptions() */
    Client::__construct([
      'verify' => TRUE,
      'timeout' => 30,
      'headers' => [],
      'proxy' => [
        'http' => NULL,
        'https' => NULL,
        'no' => [],
      ],
    ]);

    $this->client = new ParentMockHttpClient($messenger, $key_value_factory);
  }

  /**
   * {@inheritdoc}
   */
  public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface {
    return $this->client->sendAsync($request, $options);
  }

}
