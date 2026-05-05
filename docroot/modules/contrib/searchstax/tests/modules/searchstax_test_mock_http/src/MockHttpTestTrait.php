<?php

namespace Drupal\searchstax_test_mock_http;

/**
 * Provides helper methods for tests that use the mock HTTP client.
 */
trait MockHttpTestTrait {

  /**
   * Sets the data directory used by the mock HTTP client.
   *
   * @param string $dir
   *   The directory.
   *
   * @see \Drupal\searchstax_test_mock_http\Mock\MockHttpClient::sendAsync()
   */
  protected function setDataDirectory(string $dir): void {
    \Drupal::keyValue('searchstax_test_mock_http')
      ->set('data_dir', $dir);
  }

  /**
   * Asserts that the given HTTP requests have been made.
   *
   * The list of HTTP requests is cleared afterwards.
   *
   * @param string[] $expected_requests
   *   The expected HTTP requests, as their labels from lookup.json.
   * @param string[] $ignored_requests
   *   A list of request labels that should be ignored, i.e., we do not know
   *   whether they will get executed or not.
   */
  protected function assertHttpRequests(array $expected_requests, array $ignored_requests = []): void {
    $key_value = \Drupal::keyValue('searchstax_test_mock_http');
    $requests = $key_value->get('requests', []);

    // Since the "searchstax/get-accounts" request is used for checking that a
    // login is still valid, it can occur more often than planned.
    $label = 'searchstax/get-accounts';
    if (in_array($label, $requests)) {
      $requests = array_diff($requests, [$label]);
      // Re-add it once if it is expected. This makes sure we can still get a
      // failed assertion if the request did not occur at all.
      if (in_array($label, $expected_requests)) {
        $requests[] = $label;
      }
    }

    $requests = array_diff($requests, $ignored_requests);
    sort($requests);
    $this->assertEquals($expected_requests, $requests);
    $key_value->set('requests', []);
  }

}
