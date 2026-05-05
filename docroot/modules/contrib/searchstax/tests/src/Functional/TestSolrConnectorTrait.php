<?php

namespace Drupal\Tests\searchstax\Functional;

use Drupal\Tests\search_api\Functional\ExampleContentTrait;

/**
 * Provides helper methods for dealing with the test Solr connector plugin.
 *
 * @see \Drupal\searchstax_test\Plugin\SolrConnector\SearchStaxTestSolrConnector
 */
trait TestSolrConnectorTrait {

  use ExampleContentTrait;

  /**
   * Adds one or more expected Solr request for the test connector plugin.
   *
   * @param string $uri_regex
   *   A regular expression matching the expected Solr request URI.
   * @param int[]|null $results
   *   The results to return in the Solr response, as their index in
   *   $this->entities. Or NULL to return all items.
   * @param int $count
   *   (optional) The number of requests to expect for this regular expression.
   * @param string $solr_core
   *   (optional) The Solr core to which the request is expected to be sent.
   * @param array|null $response
   *   (optional) The HTTP response to return for the Solr request. If set,
   *   $results will be ignored, otherwise a response will be constructed from
   *   $results.
   *
   * @see \Drupal\searchstax_test\Plugin\SolrConnector\SearchStaxTestSolrConnector::executeRequest()
   */
  protected function addExpectedSolrRequests(
    string $uri_regex,
    ?array $results = NULL,
    int $count = 2,
    string $solr_core = 'searchstax-test',
    ?array $response = NULL
  ): void {
    $results = $results ?? array_keys($this->entities);
    if ($response === NULL) {
      $response = [
        'response' => [
          'numFound' => count($results),
          'start' => 0,
          'numFoundExact' => TRUE,
          'docs' => [],
        ],
      ];
      foreach ($results as $result_key) {
        $response['response']['docs'][] = [
          'ss_search_api_id' => $this->ids[$result_key],
          'ss_search_api_language' => 'en',
          'score' => 1.0,
        ];
      }
    }
    $key_value = \Drupal::keyValue('searchstax_test');
    $expected_requests = $key_value->get('expected_requests', []);
    $expected_requests[] = [
      'regex' => $uri_regex,
      'core' => $solr_core,
      'response' => $response,
      'count' => $count,
    ];
    $key_value->set('expected_requests', $expected_requests);
  }

  /**
   * Asserts visiting the given page triggers a specific Solr request.
   *
   * @param string $path
   *   The path to visit.
   * @param array $query
   *   The GET parameters to use.
   * @param string $uri_regex
   *   A regular expression against which to match the Solr request.
   *
   * @return array
   *   The GET parameters of the Solr request.
   */
  protected function assertPageVisitTriggersSolrRequest(
    string $path,
    array $query,
    string $uri_regex
  ): array {
    $this->drupalGet($path, ['query' => $query]);
    $this->assertNoWarningsLogged();
    $this->assertSession()->pageTextNotContains('An error occurred while searching, try again later.');
    $this->assertSession()->pageTextNotContains('Unexpected Solr request:');
    return $this->assertSolrRequestHappened($uri_regex);
  }

  /**
   * Asserts a search on the test view triggers a specific Solr request.
   *
   * @param string|null $keys
   *   The search keywords to pass, if any.
   * @param string $uri_regex
   *   A regular expression against which to match the Solr request.
   *
   * @return array
   *   The GET parameters of the Solr request.
   *
   * @see views.view.searchstax_test_view.yml
   */
  protected function assertSearchViewTriggersSolrRequest(
    ?string $keys,
    string $uri_regex
  ): array {
    $query = [];
    if ($keys !== NULL) {
      $query['search_api_fulltext'] = $keys;
    }
    return $this->assertPageVisitTriggersSolrRequest('test-search-view', $query, $uri_regex);
  }

  /**
   * Asserts a search on the test search page triggers a specific Solr request.
   *
   * @param string|null $keys
   *   The search keywords to pass, if any.
   * @param string $uri_regex
   *   A regular expression against which to match the Solr request.
   *
   * @return array
   *   The GET parameters of the Solr request.
   *
   * @see search_api_page.search_api_page.searchstax_test_search.yml
   */
  protected function assertSearchPageTriggersSolrRequest(
    ?string $keys,
    string $uri_regex
  ): array {
    $path = 'test-search-page';
    if ($keys !== NULL) {
      $path .= "/$keys";
    }
    return $this->assertPageVisitTriggersSolrRequest($path, [], $uri_regex);
  }

  /**
   * Asserts that a Solr request matching the given URI regex has been made.
   *
   * @param string $uri_regex
   *   The regular expression.
   *
   * @return array
   *   The GET parameters of the Solr request.
   */
  protected function assertSolrRequestHappened(string $uri_regex): array {
    $key_value = \Drupal::keyValue('searchstax_test');
    $seen_requests = $key_value->get('seen_requests', []);
    $requests = [];
    foreach ($seen_requests as $i => $request_uri) {
      if (preg_match($uri_regex, $request_uri)) {
        unset($seen_requests[$i]);
        $key_value->set('seen_requests', array_values($seen_requests));
        [, $params_string] = explode('?', $request_uri, 2) + [1 => ''];
        // Unfortunately, parse_str() doesn't understand the Solr style of
        // multi-valued GET parameters, so we need to do our own parsing.
        $params = [];
        foreach (explode('&', $params_string) as $param_pair) {
          [$key, $value] = array_map('urldecode', explode('=', $param_pair, 2));
          if (!isset($params[$key])) {
            $params[$key] = $value;
          }
          else {
            if (!is_array($params[$key])) {
              $params[$key] = [$params[$key]];
            }
            $params[$key][] = $value;
          }
        }
        return $params;
      }
      $requests[] = $request_uri;
    }
    $requests = $requests ? implode("\n- ", $requests) : '(none)';
    $this->fail("No Solr request recorded that matches regex \"$uri_regex\". Executed requests:\n- $requests");
  }

  /**
   * Asserts that the given Solr request matches the expected.
   *
   * @param string $expected_q
   *   The expected "q" parameter, or a substring expected to be contained in
   *   it.
   * @param bool $q_exact
   *   TRUE if $expected_q should match the "q" parameter exactly, FALSE if it
   *   should be just a substring.
   * @param array $expected_params
   *   An associative array of expected parameters mapped to their values (need
   *   not be exhaustive).
   * @param string[] $unexpected_params
   *   A list of parameter names that are not expected in the request.
   * @param array $actual_params
   *   The actual Solr request parameters to check.
   */
  protected function assertSolrRequestMatches(
    string $expected_q,
    bool $q_exact,
    array $expected_params,
    array $unexpected_params,
    array $actual_params
  ): void {
    $actual_q = $actual_params['q'];
    if ($q_exact) {
      $this->assertEquals($expected_q, $actual_q);
    }
    else {
      $this->assertStringContainsString($expected_q, $actual_q);
      $this->assertNotEquals($expected_q, $actual_q);
    }
    $this->assertEquals($expected_params, array_intersect_key($actual_params, $expected_params));
    foreach ($unexpected_params as $param) {
      $this->assertArrayNotHasKey($param, $actual_params);
    }
  }

  /**
   * Asserts that exactly the given search results appear on the current page.
   *
   * @param int[]|null $results
   *   The results to expect in the Solr response, as their index in
   *   $this->entities. Or NULL to expect all items.
   */
  protected function assertCurrentPageContainsSearchResults(?array $results = NULL): void {
    $assert = $this->assertSession();
    $results = array_flip($results ?? array_keys($this->entities));
    foreach (array_intersect_key($this->entities, $results) as $result) {
      $assert->pageTextContains("default | {$result->label()}");
    }
    foreach (array_diff_key($this->entities, $results) as $result) {
      $assert->pageTextNotContains("default | {$result->label()}");
    }
  }

}
