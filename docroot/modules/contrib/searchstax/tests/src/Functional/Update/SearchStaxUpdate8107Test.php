<?php

namespace Drupal\Tests\searchstax\Functional\Update;

use Drupal\FunctionalTests\Update\UpdatePathTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_autocomplete\Suggestion\SuggestionFactory;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\searchstax\Plugin\search_api_autocomplete\suggester\SearchstaxSuggester;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Solarium\Core\Client\Adapter\AdapterInterface;
use Solarium\Core\Client\Adapter\Curl;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;

// cspell:ignore macto tokenname

/**
 * Tests the functionality of the searchstax_update_8107() update hook.
 *
 * @see searchstax_update_8107()
 *
 * @group searchstax
 * @legacy
 */
#[RunTestsInSeparateProcesses]
class SearchStaxUpdate8107Test extends UpdatePathTestBase {

  /**
   * The endpoints passed for executed Solarium requests.
   *
   * @var list<\Solarium\Core\Client\Endpoint>
   *
   * @see static::executeSolariumRequest()
   */
  protected array $requestEndpoints = [];

  /**
   * The Curl handles generated for executed Solarium requests.
   *
   * @var list<\CurlHandle>
   *
   * @see static::executeSolariumRequest()
   */
  protected array $createdCurlHandles = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // We need to manually set the needed entity types as "installed".
    $entity_type_ids = [
      'search_api_autocomplete_search',
      'search_api_index',
      'search_api_server',
      'search_api_task',
      'solr_cache',
      'solr_field_type',
      'solr_request_dispatcher',
      'solr_request_handler',
    ];
    foreach ($entity_type_ids as $entity_type_id) {
      $entity_type = \Drupal::getContainer()
        ->get('entity_type.manager')
        ->getDefinition($entity_type_id);
      \Drupal::getContainer()
        ->get('entity_type.listener')
        ->onEntityTypeCreate($entity_type);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $core_dump_file = glob(DRUPAL_ROOT . '/core/modules/system/tests/fixtures/update/drupal-*.bare.standard.php.gz')[0];
    $this->databaseDumpFiles = [
      $core_dump_file,
      __DIR__ . '/../../../fixtures/update/searchstax-update-8107.php',
    ];
  }

  /**
   * Tests whether searchstax_update_8107() works correctly.
   */
  public function testUpdate8107(): void {
    // Check that the reported auto-suggest endpoints are as expected both
    // before and after the update hook. The update hook should not change them.
    $expected_endpoints = [
      'other_solr_server' => NULL,
      'searchstax_server_basic_auth' => 'https://searchcloud-2-us-east-1.searchstax.com/solr/test-1234-suggester/emsuggest',
      'searchstax_server_basic_auth_2' => 'https://searchcloud-5-us-east-1.searchstax.com/solr/test-5678-suggester/emsuggest',
      'searchstax_server_token_auth' => 'https://searchcloud-5-us-east-1.searchstax.com/12345/test-1234_suggester/emsuggest',
      'searchstax_server_token_auth_2' => 'https://searchcloud-2-us-east-1.searchstax.com/12345/test-1123_suggester/emsuggest',
    ];
    $this->assertEquals($expected_endpoints, $this->collectServerAutosuggestEndpoints());

    $this->runUpdates();

    $this->assertEquals($expected_endpoints, $this->collectServerAutosuggestEndpoints());

    // Now make sure that the suggester plugin actually uses the correct
    // endpoints.
    unset($expected_endpoints['other_solr_server']);
    $expected_auth_headers = [
      'searchstax_server_basic_auth' => 'Basic ' . base64_encode('searchstax-test:password123'),
      'searchstax_server_basic_auth_2' => 'Basic ' . base64_encode('searchstax-test-2:password456'),
      'searchstax_server_token_auth' => 'Token 1234567890abcdef1234567890abcdef12345678',
      'searchstax_server_token_auth_2' => 'Token 00000000aaaaaaaa00000000aaaaaaaa00000000',
    ];
    foreach ($expected_endpoints as $server_id => $expected_endpoint) {
      $this->assertAutosuggestEndpointEquals($expected_endpoint, $expected_auth_headers[$server_id], $server_id);
    }
  }

  /**
   * Collects the auto-suggest endpoints detected for all search servers.
   *
   * @return array<string, string>
   *   Associative array mapping search server IDs to the auto-suggest endpoint
   *   detected for them by our utility service.
   *
   * @see \Drupal\searchstax\Service\SearchStax::getAutosuggestEndpoint()
   */
  protected function collectServerAutosuggestEndpoints(): array {
    $utility = \Drupal::getContainer()->get('searchstax.utility');
    $info = [];
    foreach (Server::loadMultiple() as $server) {
      $info[$server->id()] = $utility->getAutosuggestEndpoint($server);
    }
    return $info;
  }

  /**
   * Checks whether the SearchStax suggester plugin uses the correct endpoint.
   *
   * @param string $expected_endpoint
   *   The URL of the expected endpoint.
   * @param string|null $expected_auth_header
   *   The expected "Authorization" header for the Solr request, if any.
   * @param string $server_id
   *   The ID of the search server to check.
   */
  protected function assertAutosuggestEndpointEquals(
    string $expected_endpoint,
    ?string $expected_auth_header,
    string $server_id
  ): void {
    // First, a ton of setup to intercept the URL used for the auto-suggest
    // request and to feed back a dummy result.
    $server = Server::load($server_id);
    $this->assertInstanceOf(ServerInterface::class, $server);
    // Set a mock Solr adapter for this server, deep in the guts of Solarium.
    $backend = $server->getBackend();
    $this->assertInstanceOf(SolrBackendInterface::class, $backend);
    $connector = $backend->getSolrConnector();
    // Simple way for the connector to call connect().
    $connector->getSelectQuery();
    /** @see \Drupal\search_api_solr\SolrConnector\SolrConnectorPluginBase::$solr */
    $property = new \ReflectionProperty($connector, 'solr');
    if (version_compare(PHP_VERSION, '8.1', '<')) {
      $property->setAccessible(TRUE);
    }
    /** @var \Solarium\Client $solarium_client */
    $solarium_client = $property->getValue($connector);
    $adapter = $this->createMock(AdapterInterface::class);
    $adapter->method('execute')
      ->willReturnCallback([$this, 'executeSolariumRequest']);
    $solarium_client->setAdapter($adapter);
    // The index needs to have our server instance already set so our mock Solr
    // adapter will be used.
    $index = Index::create([
      'id' => 'test_index',
      'server' => $server_id,
    ]);
    $property = new \ReflectionProperty($index, 'serverInstance');
    if (version_compare(PHP_VERSION, '8.1', '<')) {
      $property->setAccessible(TRUE);
    }
    $property->setValue($index, $server);

    // Now actually retrieve autocomplete suggestions.
    $query = $index->query()->keys();
    $suggestions = (new SearchstaxSuggester([], '', []))
      ->getAutocompleteSuggestions($query, 'mac', 'mac');

    // Check that the correct endpoint was used.
    $this->assertCount(1, $this->createdCurlHandles);
    $handle = array_shift($this->createdCurlHandles);
    $request_url = curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
    $parsed_url = parse_url($request_url);
    $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'];
    $this->assertEquals($expected_endpoint, $base_url);

    // Also check that the correct authentication was used, if any.
    // Unfortunately, it does not really seem possible to retrieve the request
    // headers from the Curl handle so instead we have to check the
    // configuration of the endpoint.
    $this->assertCount(1, $this->requestEndpoints);
    $request_endpoint = array_shift($this->requestEndpoints);
    $auth_header = NULL;
    $auth_data = $request_endpoint->getAuthentication();
    if (!empty($auth_data['username']) && !empty($auth_data['password'])) {
      $auth_header = 'Basic ' . base64_encode("{$auth_data['username']}:{$auth_data['password']}");
    }
    else {
      $token_data = $request_endpoint->getAuthorizationToken();
      if (!empty($token_data['tokenname']) && !empty($token_data['token'])) {
        $auth_header = $token_data['tokenname'] . ' ' . $token_data['token'];
      }
    }
    $this->assertEquals($expected_auth_header, $auth_header);

    // For good measure, also check that the correct suggestions were returned.
    $suggestion_factory = new SuggestionFactory('mac');
    $this->assertEquals([
      $suggestion_factory->createFromSuggestedKeys('macto'),
      $suggestion_factory->createFromSuggestedKeys('macho'),
      $suggestion_factory->createFromSuggestedKeys('manual'),
    ], $suggestions);
  }

  /**
   * Executes a Solarium request, storing the Curl handle that would be created.
   *
   * @param \Solarium\Core\Client\Request $request
   *   The request.
   * @param \Solarium\Core\Client\Endpoint $endpoint
   *   The endpoint.
   *
   * @return \Solarium\Core\Client\Response
   *   A dummy response.
   */
  public function executeSolariumRequest(Request $request, Endpoint $endpoint): Response {
    $this->requestEndpoints[] = $endpoint;
    $this->createdCurlHandles[] = (new Curl())->createHandle($request, $endpoint);
    $json_to_return = <<<'END'
{
  "suggest": {
    "studio_suggestor_en": {
      "mac": {
        "suggestions": [
          {
            "term": "macto",
            "weight": 100,
            "payload": ""
          },
          {
            "term": "macho",
            "weight": 90,
            "payload": ""
          },
          {
            "term": "manual",
            "weight": 80,
            "payload": ""
          }
        ],
        "numFound": 3
      }
    }
  }
}
END;
    return new Response($json_to_return, ['HTTP/1.1 200 OK']);
  }

}
