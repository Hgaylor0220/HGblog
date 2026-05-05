<?php

declare(strict_types=1);

namespace Drupal\Tests\searchstax\Functional;

use Behat\Mink\Exception\ResponseTextException;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Logger\RfcLogLevel;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Provides common assertions for our tests.
 */
#[RunTestsInSeparateProcesses]
trait TestAssertionsTrait {

  /**
   * Asserts that no warnings or errors were logged.
   */
  protected function assertNoWarningsLogged(): void {
    $errors = $this->getWatchdogWarnings();
    if ($errors) {
      $errors_str = implode("\n- ", $errors);
      $this->fail("Warnings/Errors were logged:\n- $errors_str");
    }
  }

  /**
   * Retrieves all warnings (and more severe) from the {watchdog} table.
   *
   * @return list<string>
   *   The logged messages of severity "warning" or higher.
   */
  protected function getWatchdogWarnings(): array {
    $sql = 'SELECT message, variables, severity FROM {watchdog} WHERE severity <= 4';
    $result = \Drupal::database()->query($sql);
    $errors = [];
    $levels = RfcLogLevel::getLevels();
    // @todo Remove once we depend on Drupal 11.2+.
    if (class_exists(FetchAs::class)) {
      $fetch_mode = FetchAs::Associative;
    }
    else {
      $fetch_mode = \PDO::FETCH_ASSOC;
    }
    foreach ($result->fetchAll($fetch_mode) as $row) {
      $severity = strtoupper((string) $levels[$row['severity']]);
      $message = (string) new FormattableMarkup($row['message'], unserialize($row['variables']));
      $message = html_entity_decode(strip_tags($message));
      $errors[] = "$severity: $message";
    }
    return $errors;
  }

  /**
   * Asserts that SearchStax tracking was added to the current page.
   *
   * @param string|null $search_type
   *   (optional) The type of search: "view", "other_view" or "page".
   * @param list<int>|null $results
   *   (optional) The IDs of the expected result items, or NULL if the result
   *   set is expected to contain all test entities.
   * @param int|null $expected_latency
   *   (optional) The expected latency reported, if any.
   *
   * @return array
   *   The Javascript settings included for SearchStax on the current page.
   */
  protected function assertCurrentPageContainsTracking(
    ?string $search_type = NULL,
    ?array $results = NULL,
    ?int $expected_latency = NULL
  ): array {
    $assert = $this->assertSession();
    $assert->responseContains('/searchstax/js/searchstax.tracking.js');
    $assert->responseContains(' data-searchstax-results=');

    $drupal_settings = $this->getDrupalSettings();
    $this->assertArrayHasKey('searchstax', $drupal_settings);
    $settings = $drupal_settings['searchstax'];

    if ($search_type === NULL) {
      return $settings;
    }

    $search_id = [
      'view' => 'views_page:searchstax_test_view__page_1',
      'other_view' => 'views_page:other_solr_test_view__page_1',
      'page' => 'search_api_page:searchstax_test_search',
    ][$search_type];
    $expected_model = [
      'view' => 'experiment1',
      'other_view' => NULL,
      'page' => NULL,
    ][$search_type];
    $impressions = [];
    $result_urls = [];
    $num = 0;
    $result_items = $this->entities;
    if (isset($results)) {
      $result_items = array_intersect_key($result_items, array_flip($results));
    }
    foreach ($result_items as $result_key => $entity) {
      ++$num;
      $impressions[] = [
        'cDocId' => $this->ids[$result_key],
        'position' => $num,
        'cDocTitle' => (string) $entity->label(),
      ];
      $result_urls[] = [
        'url' => $entity->toUrl()->toString(),
        'position' => $num,
      ];
    }
    $expected = [
      'analytics_url' => 'https://example.com',
      'js_version' => '3',
      'tracking_base_data' => [
        'key' => "test_analytics_key_$search_type",
      ],
      'searches' => [
        $search_id => [
          'query' => 'foo',
          'shownHits' => $num,
          'totalHits' => $num,
          'pageNo' => 1,
          'language' => 'en',
          'impressions' => $impressions,
        ],
      ],
      'results_urls' => [
        $search_id => $result_urls,
      ],
    ];
    if ($expected_latency !== NULL) {
      $expected['searches'][$search_id]['latency'] = $expected_latency;
    }
    if ($expected_model !== NULL) {
      $expected['searches'][$search_id]['model'] = $expected_model;
    }
    if ($this->loggedInUser) {
      $expected['tracking_base_data'] += [
        'session' => $this->loggedInUser->sessionId ?? NULL,
        'user' => $this->loggedInUser->id(),
      ];
    }
    else {
      // Javascript will set this to a random string.
      unset($settings['tracking_base_data']['session']);
    }
    // While the Drupal HTTP response will set this to FALSE, the
    // Javascript code will then switch it to TRUE. For the sake of
    // reliable tests, just ignore this property.
    unset($settings['searches'][$search_id]['tracked']);
    $this->assertEquals($expected, $settings);

    return $settings;
  }

  /**
   * Asserts that SearchStax tracking was not added to the current page.
   */
  protected function assertCurrentPageNotContainsTracking(): void {
    $assert = $this->assertSession();
    $assert->responseNotContains('/searchstax/js/searchstax.tracking.js');
    $assert->responseNotContains(' data-searchstax-results=');
    $this->assertArrayNotHasKey('searchstax', $this->getDrupalSettings());
  }

  /**
   * Checks that the previous page request did not trigger errors.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   *   Thrown in case the assertion fails.
   */
  protected function assertPageRequestWasSuccessful(): void {
    $this->assertPageTextDoesNotContain('error');
    $this->assertPageTextDoesNotContain('Exception');
    $this->assertPageTextDoesNotContain('Page not found');
    $this->assertNoWarningsLogged();
  }

  /**
   * Checks that the current page does not contain text.
   *
   * @param string $text
   *   The text to check for, ignoring case.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   *   Thrown in case the assertion fails.
   */
  public function assertPageTextDoesNotContain(string $text): void {
    $actual = $this->getSession()->getPage()->getText();
    $actual = preg_replace('/\s+/u', ' ', $actual) ?? $actual;
    $regex = '/.{,20}' . preg_quote($text, '/') . '.{,20}/ui';
    if (!preg_match($regex, $actual, $matches)) {
      return;
    }
    $message = sprintf('The text "%s" appears in the text of this page, but it should not: "…%s…".', $text, $matches[0]);
    throw new ResponseTextException($message, $this->getSession()->getDriver());
  }

}
