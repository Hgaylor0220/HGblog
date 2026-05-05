<?php

declare(strict_types=1);

namespace Drupal\searchstax_test_mock_http\Plugin\SolrConnector;

use Drupal\searchstax\Plugin\SolrConnector\SearchStaxConnector;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Psr\Http\Client\ClientInterface;
use Solarium\Client;
use Solarium\Client as SolariumClient;
use Solarium\Core\Client\Adapter\Psr18Adapter;
use Solarium\Core\Client\Endpoint;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a test connector for our migration tests.
 *
 * @see searchstax_test_mock_http_search_api_solr_connector_info_alter()
 */
class SearchStaxMockHttpSolrConnector extends SearchStaxConnector {

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->httpClient = $container->get('http_client');
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  protected function createClient(array &$configuration): SolariumClient {
    /* @see \Drupal\acquia_search\Plugin\SolrConnector\SearchApiSolrAcquiaConnector::createClient() */
    return new Client(
      new Psr18Adapter($this->httpClient, new RequestFactory(), new StreamFactory()),
      $this->eventDispatcher,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function useTimeout(string $timeout = self::QUERY_TIMEOUT, ?Endpoint $endpoint = NULL): void {}

}
