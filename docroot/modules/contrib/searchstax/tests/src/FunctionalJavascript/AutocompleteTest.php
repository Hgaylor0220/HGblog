<?php

namespace Drupal\Tests\searchstax\FunctionalJavascript;

use Behat\Mink\Driver\GoutteDriver;
use Behat\Mink\Element\NodeElement;
use Drupal\Core\Session\AccountInterface;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\search_api_autocomplete\Entity\Search;
use Drupal\search_api_test\PluginTestTrait;
use Drupal\Tests\searchstax\Functional\TestAssertionsTrait;
use Drupal\Tests\searchstax\Functional\TestSolrConnectorTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerInterface;

// cspell:ignore goutte

/**
 * Tests the "SearchStax" autocomplete suggester plugin.
 *
 * @group searchstax
 */
#[RunTestsInSeparateProcesses]
class AutocompleteTest extends WebDriverTestBase {

  use PluginTestTrait;
  use TestAssertionsTrait;
  use TestSolrConnectorTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api',
    'search_api_autocomplete',
    'search_api_page',
    'search_api_solr',
    'search_api_solr_autocomplete',
    'search_api_test_example_content',
    'searchstax',
    'searchstax_test',
    'dblog',
    'field_ui',
    'image',
    'link',
    'node',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The admin user used in this test.
   */
  protected AccountInterface $adminUser;

  /**
   * The role of the admin user used in this test.
   */
  protected string $adminRole;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    // Set up example content.
    $this->setUpExampleStructure();
    $this->insertExampleContent();
    // Give example entity 3 a more distinctive name since it's otherwise harder
    // to check whether or not it is part of a search result.
    $this->entities[3]->set('name', 'foobar foobar baz')->save();

    // Create an admin role and user.
    $permissions = [
      'administer site configuration',
      'administer search_api',
      'administer search_api_autocomplete',
      'access administration pages',
      'administer nodes',
      'bypass node access',
      'administer content types',
      'administer node fields',
    ];
    $this->adminRole = $this->createRole($permissions);
    $this->adminUser = $this->drupalCreateUser([], NULL, FALSE, [
      'roles' => [$this->adminRole],
    ]);

    // Everyone should be able to view search pages and test entities.
    foreach ([Role::ANONYMOUS_ID, Role::AUTHENTICATED_ID] as $role_id) {
      $this->grantPermissions(Role::load($role_id), [
        'view search api pages',
        'view test entity',
        'view test entity translations',
      ]);
    }

    drupal_flush_all_caches();
  }

  /**
   * {@inheritdoc}
   */
  protected function installModulesFromClassProperty(ContainerInterface $container): void {
    // The "search_api_solr_autocomplete" module does not exist in all versions
    // of the Solr module against which we want to test. Therefore, we include
    // it here conditionally.
    $modules = \Drupal::getContainer()->get('extension.list.module')
      ->reset()
      ->getList();
    if (!isset($modules['search_api_solr_autocomplete'])) {
      static::$modules = array_diff(static::$modules, ['search_api_solr_autocomplete']);
    }

    parent::installModulesFromClassProperty($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function getConfigSchemaExclusions(): array {
    $excluded = parent::getConfigSchemaExclusions();

    // For Drupal 8/9, skip schema validation of the Autocomplete Search entity
    // as the Solr module didn't provide the correct config schema for its
    // suggesters back then.
    if (version_compare(\Drupal::VERSION, '10.0.0', '<')) {
      $excluded[] = 'search_api_autocomplete.search.searchstax_test_view';
    }

    return $excluded;
  }

  /**
   * Tests all module functionality.
   */
  public function testModule(): void {
    $web_assert = $this->assertSession();

    // At first, all SearchStax functionality is disabled so we're expecting no
    // tracking and regular Solr requests.
    // Search view request with empty keys:
    $q = urlencode('*:*');
    $uri_regex_empty_keys = "#^select\\?(.+&)?q=$q#";
    $this->addExpectedSolrRequests($uri_regex_empty_keys);
    $view_request_empty_keys = $this->assertSearchViewTriggersSolrRequest(NULL, $uri_regex_empty_keys);
    $this->assertSolrRequestMatches(
      '*:*',
      TRUE,
      [
        'start' => '0',
        'rows' => '10',
        'sort' => 'ss_search_api_id asc',
        'hl' => 'true',
        'facet' => 'true',
        'spellcheck' => 'true',
      ],
      ['qf', 'defType'],
      $view_request_empty_keys
    );
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults();

    // Search page request with empty keys:
    $page_request_empty_keys = $this->assertSearchPageTriggersSolrRequest(NULL, $uri_regex_empty_keys);
    $this->assertSolrRequestMatches(
      '*:*',
      TRUE,
      [
        'start' => '0',
        'rows' => '10',
        'hl' => 'true',
        'facet' => 'true',
        'spellcheck' => 'true',
      ],
      ['qf', 'defType'],
      $page_request_empty_keys
    );
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults();

    // Search view request for keyword "foo":
    $uri_regex_foo_keys = '#^select\\?(.+&)?q=[^&]*foo#';
    $uri_regex_foo_keys_original = $uri_regex_foo_keys;
    $foo_results = [1, 2, 4, 5];
    $this->addExpectedSolrRequests($uri_regex_foo_keys, $foo_results);
    $view_request_foo = $this->assertSearchViewTriggersSolrRequest('foo', $uri_regex_foo_keys);
    $view_request_foo_original = $view_request_foo;
    $this->assertSolrRequestMatches(
      'foo',
      FALSE,
      [
        'start' => '0',
        'rows' => '10',
        'sort' => 'ss_search_api_id asc',
        'hl' => 'true',
        'facet' => 'true',
        'spellcheck' => 'true',
      ],
      ['qf', 'defType'],
      $view_request_foo
    );
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults($foo_results);

    // Search page request for keyword "foo":
    $page_request_foo = $this->assertSearchPageTriggersSolrRequest('foo', $uri_regex_foo_keys);
    $this->assertSolrRequestMatches(
      'foo',
      FALSE,
      [
        'start' => '0',
        'rows' => '10',
        'hl' => 'true',
        'facet' => 'true',
        'spellcheck' => 'true',
      ],
      ['qf', 'defType'],
      $page_request_foo
    );
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults($foo_results);

    // Now activate tracking and see whether that works as intended.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/search');
    $this->clickLink('SearchStax settings');
    $web_assert->fieldValueEquals('analytics_url', 'https://app.searchstax.com');
    $web_assert->checkboxNotChecked('searches_via_searchstudio');
    $this->submitForm([
      'analytics_url' => 'https://example.com',
      'analytics_key' => 'test_analytics_key_view',
    ], 'Save configuration');

    // Check the configuration was successfully updated.
    $expected = [
      'analytics_url' => 'https://example.com',
      'analytics_key' => 'test_analytics_key_view',
      'searches_via_searchstudio' => FALSE,
    ];
    $actual = \Drupal::config('searchstax.settings')->get();
    $this->assertEquals($expected, array_intersect_key($actual, $expected));

    // To test legacy "search-specific analytics keys" functionality, set that
    // setting as well.
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('search_specific_analytics_keys', [
        'search_api_page:searchstax_test_search' => 'test_analytics_key_page',
        'views_page:other_solr_test_view__page_1' => 'test_analytics_key_other_view',
      ])
      ->set('discard_parameters', ['facets'])
      ->save();

    // Now create the Autocomplete search.
    (Search::create([
      'id' => 'searchstax_test_view',
      'langcode' => 'en',
      'status' => TRUE,
      'label' => 'SearchStax search',
      'index_id' => 'searchstax_index',
      'suggester_settings' => [
        'searchstax' => [],
      ],
      'search_settings' => [
        'views:searchstax_test_view' => [],
      ],
      'options' => [
        'show_count' => TRUE,
      ],
    ]))->save();
    $this->grantPermissions(Role::load(Role::ANONYMOUS_ID), ['use search_api_autocomplete for searchstax_test_view']);
    $this->grantPermissions(Role::load(Role::AUTHENTICATED_ID), ['use search_api_autocomplete for searchstax_test_view']);

    // Visit the pages again. The page with keywords should contain tracking JS.
    $this->drupalLogout();
    $this->addExpectedSolrRequests($uri_regex_empty_keys);
    $request = $this->assertSearchViewTriggersSolrRequest(NULL, $uri_regex_empty_keys);
    $this->assertEquals($view_request_empty_keys, $request);
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults();
    $request = $this->assertSearchPageTriggersSolrRequest(NULL, $uri_regex_empty_keys);
    $this->assertEquals($page_request_empty_keys, $request);
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults();

    $this->addExpectedSolrRequests($uri_regex_foo_keys, $foo_results);
    $request = $this->assertSearchViewTriggersSolrRequest('foo', $uri_regex_foo_keys);
    $this->assertEquals($view_request_foo, $request);
    $this->assertCurrentPageContainsTracking('view', $foo_results);
    $this->assertCurrentPageContainsSearchResults($foo_results);
    $request = $this->assertSearchPageTriggersSolrRequest('foo', $uri_regex_foo_keys);
    $this->assertEquals($page_request_foo, $request);
    $this->assertCurrentPageContainsTracking('page', $foo_results);
    $this->assertCurrentPageContainsSearchResults($foo_results);

    // Do the same while logged in as admin, which should currently not make a
    // difference.
    $this->drupalLogin($this->adminUser);
    $this->addExpectedSolrRequests($uri_regex_empty_keys);
    $request = $this->assertSearchViewTriggersSolrRequest(NULL, $uri_regex_empty_keys);
    $this->assertEquals($view_request_empty_keys, $request);
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults();
    $request = $this->assertSearchPageTriggersSolrRequest(NULL, $uri_regex_empty_keys);
    $this->assertEquals($page_request_empty_keys, $request);
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults();

    $this->addExpectedSolrRequests($uri_regex_foo_keys, $foo_results);
    $request = $this->assertSearchViewTriggersSolrRequest('foo', $uri_regex_foo_keys);
    $this->assertEquals($view_request_foo, $request);
    $this->assertCurrentPageContainsTracking('view', $foo_results);
    $this->assertCurrentPageContainsSearchResults($foo_results);
    $request = $this->assertSearchPageTriggersSolrRequest('foo', $uri_regex_foo_keys);
    $this->assertEquals($page_request_foo, $request);
    $this->assertCurrentPageContainsTracking('page', $foo_results);
    $this->assertCurrentPageContainsSearchResults($foo_results);

    // Enable the "Configure searches via SearchStudio" option and disable
    // tracking for admins.
    $this->drupalGet('admin/config/search/searchstax');
    $this->submitForm([
      'searches_via_searchstudio' => TRUE,
      'discard_parameters[keys]' => TRUE,
      'discard_parameters[highlight]' => TRUE,
      'discard_parameters[spellcheck]' => TRUE,
    ], 'Save configuration');
    $this->drupalGet('admin/config/search/searchstax/advanced-settings');
    $this->submitForm([
      "untracked_roles[{$this->adminRole}]" => TRUE,
    ], 'Save configuration');

    // Check the configuration was successfully updated.
    $expected = [
      'analytics_url' => 'https://example.com',
      'analytics_key' => 'test_analytics_key_view',
      'search_specific_analytics_keys' => [
        'search_api_page:searchstax_test_search' => 'test_analytics_key_page',
        'views_page:other_solr_test_view__page_1' => 'test_analytics_key_other_view',
      ],
      'untracked_roles' => [
        $this->adminRole,
      ],
      'searches_via_searchstudio' => TRUE,
      'discard_parameters' => [
        'facets',
        'highlight',
        'keys',
        'spellcheck',
      ],
    ];
    $actual = \Drupal::config('searchstax.settings')->get();
    $this->assertEquals($expected, array_intersect_key($actual, $expected));

    // Adapt the expected URLs accordingly.
    $uri_regex_empty_keys = "#^emselect\\?(.+&)?q=$q#";
    $uri_regex_foo_keys = '#^emselect\\?(.+&)?q=[^&]*foo#';

    // Make sure there now isn't tracking on the "foo" search results page for
    // the admin account.
    $this->addExpectedSolrRequests($uri_regex_foo_keys, $foo_results);
    $view_request_foo = $this->assertSearchViewTriggersSolrRequest('foo', $uri_regex_foo_keys);
    $this->assertSolrRequestMatches(
      'foo',
      TRUE,
      [
        'start' => '0',
        'rows' => '10',
      ],
      [
        'facet',
        'hl',
        'qf',
        'spellcheck',
      ],
      $view_request_foo
    );
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults($foo_results);
    $page_request_foo = $this->assertSearchPageTriggersSolrRequest('foo', $uri_regex_foo_keys);
    $this->assertSolrRequestMatches(
      'foo',
      TRUE,
      [
        'start' => '0',
        'rows' => '10',
      ],
      [
        'facet',
        'hl',
        'qf',
        'spellcheck',
      ],
      $page_request_foo
    );
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults($foo_results);

    // After logging out, tracking should be there again.
    $this->drupalLogout();
    $this->addExpectedSolrRequests($uri_regex_empty_keys);
    $view_request_empty_keys = $this->assertSearchViewTriggersSolrRequest(NULL, $uri_regex_empty_keys);
    $this->assertSolrRequestMatches(
      '*:*',
      TRUE,
      [
        'start' => '0',
        'rows' => '10',
      ],
      [
        'facet',
        'hl',
        'qf',
        'spellcheck',
      ],
      $view_request_empty_keys
    );
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults();
    $page_request_empty_keys = $this->assertSearchPageTriggersSolrRequest(NULL, $uri_regex_empty_keys);
    $this->assertSolrRequestMatches(
      '*:*',
      TRUE,
      [
        'start' => '0',
        'rows' => '10',
      ],
      [
        'facet',
        'hl',
        'qf',
        'spellcheck',
      ],
      $page_request_empty_keys
    );
    $this->assertCurrentPageNotContainsTracking();
    $this->assertCurrentPageContainsSearchResults();

    $this->addExpectedSolrRequests($uri_regex_foo_keys, $foo_results);
    $request = $this->assertSearchViewTriggersSolrRequest('foo', $uri_regex_foo_keys);
    $this->assertEquals($view_request_foo, $request);
    $this->assertCurrentPageContainsTracking('view', $foo_results);
    $this->assertCurrentPageContainsSearchResults($foo_results);
    $request = $this->assertSearchPageTriggersSolrRequest('foo', $uri_regex_foo_keys);
    $this->assertEquals($page_request_foo, $request);
    $this->assertCurrentPageContainsTracking('page', $foo_results);
    $this->assertCurrentPageContainsSearchResults($foo_results);

    // Verify that a second, non-SearchStax Solr server is unaffected.
    $this->addExpectedSolrRequests($uri_regex_foo_keys_original, $foo_results, 1, 'solr-test');
    $other_solr_request = $this->assertPageVisitTriggersSolrRequest('other-solr-test-search-view', ['search_api_fulltext' => 'foo'], $uri_regex_foo_keys_original);
    // By just adapting the filter on "index_id" the request should be the same
    // as for the SearchStax view.
    $other_solr_request['fq'] = str_replace('other_solr_index', 'searchstax_index', $other_solr_request['fq']);
    $this->assertEquals($view_request_foo_original, $other_solr_request);
    $this->assertCurrentPageContainsSearchResults($foo_results);
    // Tracking is not tied to a SearchStax (or even Solr) server at all, so
    // there should be tracking on this page.
    $this->assertCurrentPageContainsTracking('other_view', $foo_results);

    // Disable discarding of parse mode and facets again to see if a subset also
    // works fine.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/search/searchstax');
    $this->submitForm([
      'discard_parameters[keys]' => FALSE,
      'discard_parameters[facets]' => FALSE,
    ], 'Save configuration');
    // Check the configuration was successfully updated.
    $expected = [
      'highlight',
      'spellcheck',
    ];
    $actual = \Drupal::config('searchstax.settings')->get('discard_parameters');
    $this->assertEquals($expected, $actual);

    // Log out and check again. (Just the Solr requests of the search view, to
    // not waste too much time.)
    $this->drupalLogout();
    $this->addExpectedSolrRequests($uri_regex_empty_keys, [], 1);
    $view_request_empty_keys = $this->assertSearchViewTriggersSolrRequest(NULL, $uri_regex_empty_keys);
    $this->assertSolrRequestMatches(
      '*:*',
      TRUE,
      [
        'start' => '0',
        'rows' => '10',
        'defType' => 'lucene',
        'facet' => 'true',
      ],
      [
        'hl',
        'qf',
        'spellcheck',
      ],
      $view_request_empty_keys
    );
    $this->addExpectedSolrRequests($uri_regex_foo_keys, $foo_results, 1);
    $view_request_foo = $this->assertSearchViewTriggersSolrRequest('foo', $uri_regex_foo_keys);
    $this->assertSolrRequestMatches(
      'foo',
      FALSE,
      [
        'start' => '0',
        'rows' => '10',
        'defType' => 'lucene',
        'facet' => 'true',
      ],
      [
        'hl',
        'qf',
        'spellcheck',
      ],
      $view_request_foo
    );

    // Check that the autocomplete suggester works correctly.
    $this->addExpectedSolrRequests(
      '#^emsuggest\\?(.+&)?q=bar(&|$)#',
      NULL,
      1,
      'searchstax-test_suggester',
      [
        'suggest' => [
          'studio_suggestor_en' => [
            'foo' => [
              'numFound' => 3,
              'suggestions' => [
                [
                  'term' => '<b>bar</b>n',
                  'weight' => 100,
                  'payload' => '',
                ],
                [
                  'term' => 'ban',
                  'weight' => 75,
                  'payload' => '',
                ],
                [
                  'term' => 'car',
                  'weight' => 50,
                  'payload' => '',
                ],
              ],
            ],
          ],
        ],
      ]
    );

    $assert_session = $this->assertSession();
    $input_selector = 'input[data-drupal-selector="edit-search-api-fulltext"]';
    $assert_session->elementAttributeContains(
      'css',
      $input_selector,
      'data-search-api-autocomplete-search',
      'searchstax_test_view'
    );

    $page = $this->getSession()->getPage();
    /** @var \Drupal\FunctionalJavascriptTests\JSWebAssert $assert_session */
    $assert_session = $this->assertSession();
    $field = $assert_session->elementExists('css', $input_selector);
    $field->setValue('bar');
    $this->getSession()
      ->getDriver()
      ->keyDown($field->getXpath(), 'r');

    $element = $assert_session->waitOnAutocomplete();
    $this->logPageChange('Autocomplete');
    $this->assertNoWarningsLogged();
    $this->assertTrue($element && $element->isVisible());

    // Contrary to documentation, this can also return NULL. Therefore, we need
    // to make sure to return an array even in this case.
    $elements = $page->findAll('css', '.ui-autocomplete .ui-menu-item') ?: [];
    $suggestions = [];
    foreach ($elements as $element) {
      $label = $this->getElementText($element, '.autocomplete-suggestion-label');
      $user_input = $this->getElementText($element, '.autocomplete-suggestion-user-input');
      $suffix = $this->getElementText($element, '.autocomplete-suggestion-suggestion-suffix');
      $suggestions[] = $label . $user_input . $suffix;
    }
    $expected = ['barn', 'ban', 'car'];
    $this->assertEquals($expected, $suggestions);

    // Check that unsupported suggester plugins are correctly hidden.
    $this->drupalLogin($this->adminUser);
    $edit_search_url = 'admin/config/search/search-api/index/searchstax_index/autocomplete/searchstax_test_view/edit';
    $this->drupalGet($edit_search_url);
    $suggester_manager = \Drupal::getContainer()
      ->get('plugin.manager.search_api_autocomplete.suggester');
    $suggester_plugins = $suggester_manager
      ->getDefinitions();
    $solr_suggesters = array_filter(
      $suggester_plugins,
      function (array $definition) {
        return strpos($definition['class'], "\\search_api_solr") !== FALSE;
      }
    );
    $this->assertNotEmpty($solr_suggesters);
    foreach ($solr_suggesters as $definition) {
      $assert_session->pageTextNotContains((string) $definition['label']);
    }

    // Check that the override via config works correctly and that enabled
    // suggesters are always displayed.
    $this->assertGreaterThanOrEqual(2, count($solr_suggesters));
    $solr_suggester_ids = array_keys($solr_suggesters);
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('autocomplete.override_supported_suggesters', [$solr_suggester_ids[0]])
      ->save();
    Search::load('searchstax_test_view')
      ->addSuggester($suggester_manager->createInstance($solr_suggester_ids[1]))
      ->save();
    $this->drupalGet($edit_search_url);
    $assert_session->pageTextContains((string) $solr_suggesters[$solr_suggester_ids[0]]['label']);
    $assert_session->pageTextContains((string) $solr_suggesters[$solr_suggester_ids[0]]['description']);
    $assert_session->pageTextContains((string) $solr_suggesters[$solr_suggester_ids[1]]['label']);
    $expected_description = strip_tags((string) $solr_suggesters[$solr_suggester_ids[1]]['description']);
    $expected_description .= " Warning: This suggester is not compatible with SearchStax servers and should be disabled.";
    $assert_session->pageTextContains($expected_description);
    for ($i = 2; $i < count($solr_suggesters); ++$i) {
      $assert_session->pageTextNotContains((string) $solr_suggesters[$solr_suggester_ids[$i]]['label']);
    }
    Search::load('searchstax_test_view')
      ->removeSuggester($solr_suggester_ids[1])
      ->save();
  }

  /**
   * Retrieves the text contents of a descendant of the given element.
   *
   * @param \Behat\Mink\Element\NodeElement $element
   *   The element.
   * @param string $css_selector
   *   The CSS selector defining the descendant to look for.
   *
   * @return string|null
   *   The text contents of the descendant, or NULL if it couldn't be found.
   */
  protected function getElementText(NodeElement $element, string $css_selector): ?string {
    $element = $element->find('css', $css_selector);
    return $element ? $element->getText() : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function clickLink($label, $index = 0): void {
    parent::clickLink($label, $index);

    $this->logPageChange("Clicked link with label \"$label\" (#$index)");
  }

  /**
   * Saves the current page contents to the debug HTML output, if enabled.
   *
   * Used since the base class unfortunately does not always re-save the HTML
   * when the page changes.
   *
   * @param string $label
   *   (optional) An explanation of the user action to print at the top of the
   *   saved HTML output.
   */
  protected function logPageChange(string $label = ''): void {
    // @see \Drupal\Tests\UiHelperTrait::click()
    $should_log = $this->htmlOutputEnabled
      && (!method_exists($this, 'isTestUsingGuzzleClient') || !$this->isTestUsingGuzzleClient())
      && !($this->getSession()->getDriver() instanceof GoutteDriver);
    if ($should_log) {
      $html_output = '';
      if ($label !== '') {
        $html_output .= htmlspecialchars($label) . '<hr />';
      }
      $current_url = htmlspecialchars($this->getSession()->getCurrentUrl());
      $html_output .= "Ending URL: $current_url";
      $html_output .= "<hr />{$this->getSession()->getPage()->getContent()}";
      $html_output .= $this->getHtmlOutputHeaders();
      $this->htmlOutput($html_output);
    }
  }

}
