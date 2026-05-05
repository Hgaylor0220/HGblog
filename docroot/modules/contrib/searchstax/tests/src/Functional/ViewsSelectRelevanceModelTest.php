<?php

namespace Drupal\Tests\searchstax\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\search_api\Entity\Server;
use Drupal\searchstax\Hook\ViewsSelectRelevanceModel;
use Drupal\searchstax\Service\Data\AppInfo;
use Drupal\searchstax_test_mock_api\Mock\MockApiService;
use Drupal\searchstax_test_mock_http\MockHttpTestTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore défaut deuxième drittes erstes troisième zweites

/**
 * Tests selecting the relevance model for SearchStax views.
 *
 * Uses views.view.searchstax_test_view.yml as its main search view.
 *
 * @group searchstax
 */
#[RunTestsInSeparateProcesses]
class ViewsSelectRelevanceModelTest extends BrowserTestBase {

  use MockHttpTestTrait;
  use TestAssertionsTrait;
  use TestSolrConnectorTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api',
    'search_api_solr',
    'searchstax',
    'searchstax_test',
    'searchstax_test_mock_http',
    'dblog',
    'language',
    'views',
    'views_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Add extra languages.
    ConfigurableLanguage::createFromLangcode('de')->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();

    // Enable path prefix negotiation.
    $this->config('language.negotiation')->set('url', [
      'source' => 'path_prefix',
      'prefixes' => [
        'en' => '',
        'de' => 'de',
        'fr' => 'fr',
      ],
    ])->save();

    // Set a predictable site hash.
    \Drupal::state()->set('search_api_solr.site_hash', '123456');

    // Enable routing of searches to the /emselect handler.
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('searches_via_searchstudio', TRUE)
      ->save();

    // Disable live preview.
    \Drupal::configFactory()->getEditable('views.settings')
      ->set('ui.always_live_preview', FALSE)
      ->save();

    // Log in as an admin user.
    $this->drupalLogin($this->drupalCreateUser([], NULL, TRUE));
  }

  /**
   * {@inheritdoc}
   */
  protected function getConfigSchemaExclusions(): array {
    $exclusions = parent::getConfigSchemaExclusions();

    // In earlier versions of Views, creating a view with a non-default query
    // plugin via the UI will result in schema errors since the wrong query
    // plugin is set in the saved config. Since this problem is neither our
    // fault we circumvent the problem here by not checking config schema
    // conformity for the one view we create via the UI in earlier versions of
    // Drupal.
    if (version_compare(\Drupal::VERSION, '10.0.0', '<')) {
      $exclusions[] = 'views.view.test_view_1';
    }

    return $exclusions;
  }

  /**
   * Tests selecting the relevance model for SearchStax views.
   *
   * @see views.view.searchstax_test_view.yml
   */
  public function testRelevanceModelOption(): void {
    // Prepare the mock HTTP client.
    $this->setDataDirectory(__DIR__ . '/../../data/views-relevance-model');

    // Do not drop any Drupal settings from requests.
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('discard_parameters', [])
      ->save();

    // We want to get actual HTTP requests (to our mock client) from the Solr
    // connector, so do not use the test connector.
    $server = Server::load('searchstax_server');
    $backend_config = $server->getBackendConfig();
    $backend_config['connector'] = 'searchstax';
    $backend_config['connector_config']['update_endpoint'] = 'https://searchcloud-2-us-east-1.searchstax.com/12345/searchstax-test/update';
    $backend_config['connector_config']['update_token'] = '0123456789abcdef0123456789abcdef01234567';
    $backend_config['connector_config']['context'] = '12345';
    $server->setBackendConfig($backend_config);
    $server->save();

    // Remove the pre-existing relevance model settings from our test view.
    View::load('searchstax_test_view')
      ->unsetThirdPartySetting('searchstax', 'relevance_models')
      ->save();

    $assert = $this->assertSession();
    // Not sure why this is needed here, but in GitLab CI the search view page
    // is sometimes not found without first clearing the cache.
    drupal_flush_all_caches();
    $ignored_requests = ['core-info', 'solr-ping'];

    $this->drupalGet('test-search-view');
    $this->assertHttpRequests(['empty-search-no-model'], $ignored_requests);
    $this->assertNoErrorsOrWarnings();

    $this->drupalGet('test-search-view', ['query' => ['search_api_fulltext' => 'test']]);
    $this->assertHttpRequests(['keyword-search-no-model'], $ignored_requests);
    $this->assertNoErrorsOrWarnings();

    $view_edit_path = 'admin/structure/views/view/searchstax_test_view/edit/default';
    $this->drupalGet($view_edit_path);
    $this->click('#views-default-query');
    $this->assertNoWarningsLogged();
    $assert->pageTextNotContains('The website encountered an unexpected error.');
    $assert->pageTextNotContains('Error message');

    $assert->pageTextContains('SearchStax settings');
    $assert->pageTextContains('You need to log in to the SearchStax app in order to change these settings.');
    $assert->pageTextContains('SearchStax login');
    $this->submitForm([
      'query[options][searchstax][login][password]' => 'password123',
      'query[options][searchstax][login][username]' => 'user@example.com',
      'query[options][searchstax][login][tfa_token]' => '123456',
    ], 'Continue');
    $this->assertHttpRequests([
      'get-account-1',
      'get-models-de',
      'get-models-en',
      'get-models-fr',
      'list-accounts',
      'obtain-auth-token',
    ]);
    $this->assertNoErrorsOrWarnings();

    // The functionality to stay in the dialog after a successful login doesn't
    // work without Javascript. No matter, we just click on the "Query settings"
    // link again.
    if (strpos($this->getSession()->getCurrentUrl(), $view_edit_path) !== FALSE) {
      $this->click('#views-default-query');
    }

    $assert->pageTextContains('Relevance model for English');
    $assert->pageTextContains('default (default)');
    $assert->pageTextContains('experiment1');
    $assert->responseNotContains('experiment2');
    $assert->pageTextContains('experiment3');
    $assert->elementExists('css', 'input[type="radio"][value="default"]');
    $assert->elementExists('css', 'input[type="radio"][value="experiment1"]');
    $assert->elementExists('css', 'input[type="radio"][value="experiment3"]');
    $assert->pageTextContains('Relevance model for German');
    $assert->pageTextContains('Standard (default)');
    $assert->pageTextContains('Erstes Experiment');
    $assert->responseNotContains('Zweites Experiment');
    $assert->pageTextContains('Drittes Experiment');
    $assert->elementExists('css', 'input[type="radio"][value="Standard"]');
    $assert->elementExists('css', 'input[type="radio"][value="Erstes Experiment"]');
    $assert->elementExists('css', 'input[type="radio"][value="Drittes Experiment"]');
    $assert->pageTextContains('Relevance model for French');
    $assert->pageTextContains('défaut (default)');
    $assert->pageTextContains('Premier test');
    $assert->responseNotContains('Deuxième test');
    $assert->pageTextContains('Troisième test');
    $assert->elementExists('css', 'input[type="radio"][value="défaut"]');
    $assert->elementExists('css', 'input[type="radio"][value="Premier test"]');
    $assert->elementExists('css', 'input[type="radio"][value="Troisième test"]');
    $this->submitForm([
      'query[options][searchstax][relevance_models][de]' => 'Standard',
      'query[options][searchstax][relevance_models][en]' => 'experiment1',
      'query[options][searchstax][relevance_models][fr]' => '',
    ], 'Apply');
    $this->assertNoErrorsOrWarnings();

    $this->submitForm([], 'Save');
    $this->assertNoErrorsOrWarnings();

    \Drupal::keyValue('searchstax_test_mock_http')->set('requests', []);
    $this->drupalGet('test-search-view');
    $this->assertHttpRequests(['empty-search-model-experiment1'], $ignored_requests);
    $this->assertNoErrorsOrWarnings();

    $this->drupalGet('test-search-view', [
      'query' => [
        'search_api_language' => 'en',
      ],
    ]);
    $this->assertHttpRequests(['empty-search-model-experiment1'], $ignored_requests);
    $this->assertNoErrorsOrWarnings();

    $this->drupalGet('test-search-view', [
      'query' => [
        'search_api_fulltext' => 'test',
      ],
    ]);
    $this->assertHttpRequests(['keyword-search-model-experiment1'], $ignored_requests);
    $this->assertNoErrorsOrWarnings();

    $this->drupalGet('test-search-view', [
      'query' => [
        'search_api_language' => 'de',
      ],
    ]);
    $this->assertHttpRequests(['empty-search-de'], $ignored_requests);
    $this->assertNoErrorsOrWarnings();

    $this->drupalGet('test-search-view', [
      'query' => [
        'search_api_language' => 'fr',
      ],
    ]);
    $this->assertHttpRequests(['empty-search-fr'], $ignored_requests);
    $this->assertNoErrorsOrWarnings();
  }

  /**
   * Tests that overridden displays are handled correctly.
   */
  public function testHandlingOfDisplayOverrides(): void {
    $this->setUpMockApiService();

    $this->assertCorrectModels([
      'page_1' => [
        'en' => 'experiment1',
        'de' => '',
        'fr' => '',
      ],
      'page_2' => [
        'en' => 'experiment1',
        'de' => '',
        'fr' => '',
      ],
      'page_3' => [
        'en' => 'experiment1',
        'de' => '',
        'fr' => '',
      ],
    ]);

    $query_settings_base_path = 'admin/structure/views/nojs/display/searchstax_test_view';
    $this->drupalGet("$query_settings_base_path/page_1/query");
    $this->assertNoErrorsOrWarnings();
    $assert = $this->assertSession();
    $assert->pageTextContains('default (default)');
    $assert->responseContains(' value="default"');
    $assert->pageTextContains('experiment1');
    $assert->responseContains(' value="experiment1"');
    $assert->pageTextContains('experiment2');
    $assert->responseContains(' value="experiment2"');
    $assert->pageTextContains('Standard');
    $assert->responseContains(' value="Standard"');
    $assert->pageTextContains('Erstes Experiment (default)');
    $assert->responseContains(' value="Erstes Experiment"');
    $assert->pageTextContains('Premier test');
    $assert->responseContains(' value="Premier test"');
    $assert->pageTextContains('Deuxième test (default)');
    $assert->responseContains(' value="Deuxième test"');
    // The following two models are in "draft" status and should therefore not
    // be listed.
    $assert->responseNotContains('Zweites Experiment');
    $assert->responseNotContains('défaut');
    $this->assertDefaultValues([
      'en' => 'experiment1',
      'de' => '',
      'fr' => '',
    ], 'default');
    $this->submitForm([
      'query[options][searchstax][relevance_models][en]' => 'default',
      'query[options][searchstax][relevance_models][de]' => 'Standard',
      'query[options][searchstax][relevance_models][fr]' => '',
    ], 'Apply');
    $this->assertNoErrorsOrWarnings();

    $this->drupalGet("$query_settings_base_path/page_2/query");
    $this->assertNoErrorsOrWarnings();
    $this->assertDefaultValues([
      'en' => 'default',
      'de' => 'Standard',
      'fr' => '',
    ], 'default');
    $this->submitForm([
      'override[dropdown]' => 'page_2',
      'query[options][searchstax][relevance_models][en]' => '',
      'query[options][searchstax][relevance_models][de]' => 'Erstes Experiment',
      'query[options][searchstax][relevance_models][fr]' => 'Premier test',
    ], 'Apply');
    $this->assertNoErrorsOrWarnings();

    $this->drupalGet("$query_settings_base_path/page_3/query");
    $this->assertNoErrorsOrWarnings();
    $this->assertDefaultValues([
      'en' => 'default',
      'de' => 'Standard',
      'fr' => '',
    ], 'default');
    $this->submitForm([
      'override[dropdown]' => 'default',
      'query[options][searchstax][relevance_models][en]' => 'default',
      'query[options][searchstax][relevance_models][de]' => '',
      'query[options][searchstax][relevance_models][fr]' => 'Deuxième test',
    ], 'Apply');
    $this->assertNoErrorsOrWarnings();

    $this->submitForm([], 'Save');
    $this->assertNoErrorsOrWarnings();

    $this->assertCorrectModels([
      'page_1' => [
        'en' => 'default',
        'de' => '',
        'fr' => 'Deuxième test',
      ],
      'page_2' => [
        'en' => '',
        'de' => 'Erstes Experiment',
        'fr' => 'Premier test',
      ],
      'page_3' => [
        'en' => 'default',
        'de' => '',
        'fr' => 'Deuxième test',
      ],
    ]);

    $this->drupalGet("$query_settings_base_path/default/query");
    $this->assertNoErrorsOrWarnings();
    $this->assertDefaultValues([
      'en' => 'default',
      'de' => '',
      'fr' => 'Deuxième test',
    ], 'default');
    $this->submitForm([
      'query[options][searchstax][relevance_models][en]' => 'experiment1',
      'query[options][searchstax][relevance_models][de]' => 'Standard',
      'query[options][searchstax][relevance_models][fr]' => 'Deuxième test',
    ], 'Apply');
    $this->assertNoErrorsOrWarnings();

    $this->drupalGet("$query_settings_base_path/page_1/query");
    $this->assertNoErrorsOrWarnings();
    $this->assertDefaultValues([
      'en' => 'experiment1',
      'de' => 'Standard',
      'fr' => 'Deuxième test',
    ], 'default');
    $this->submitForm([
      'override[dropdown]' => 'page_1',
      'query[options][searchstax][relevance_models][en]' => 'experiment2',
      'query[options][searchstax][relevance_models][de]' => '',
      'query[options][searchstax][relevance_models][fr]' => '',
    ], 'Apply');
    $this->assertNoErrorsOrWarnings();

    $this->drupalGet("$query_settings_base_path/page_2/query");
    $this->assertNoErrorsOrWarnings();
    $this->assertDefaultValues([
      'en' => '',
      'de' => 'Erstes Experiment',
      'fr' => 'Premier test',
    ], 'page_2');
    $this->submitForm([
      'override[dropdown]' => 'default_revert',
      'query[options][searchstax][relevance_models][en]' => 'default',
      'query[options][searchstax][relevance_models][de]' => 'Standard',
      'query[options][searchstax][relevance_models][fr]' => 'Deuxième test',
    ], 'Apply');
    $this->assertNoErrorsOrWarnings();

    $this->drupalGet("$query_settings_base_path/page_3/query");
    $this->assertNoErrorsOrWarnings();
    $this->assertDefaultValues([
      'en' => 'experiment1',
      'de' => 'Standard',
      'fr' => 'Deuxième test',
    ], 'default');
    $this->submitForm([
      'override[dropdown]' => 'page_3',
      'query[options][searchstax][relevance_models][en]' => 'experiment2',
      'query[options][searchstax][relevance_models][de]' => 'Standard',
      'query[options][searchstax][relevance_models][fr]' => '',
    ], 'Apply');
    $this->assertNoErrorsOrWarnings();

    // Before actually saving the view, nothing should have changed.
    $this->assertCorrectModels([
      'page_1' => [
        'en' => 'default',
        'de' => '',
        'fr' => 'Deuxième test',
      ],
      'page_2' => [
        'en' => '',
        'de' => 'Erstes Experiment',
        'fr' => 'Premier test',
      ],
      'page_3' => [
        'en' => 'default',
        'de' => '',
        'fr' => 'Deuxième test',
      ],
    ]);

    $this->drupalGet('admin/structure/views/view/searchstax_test_view/edit/default');
    $this->submitForm([], 'Save');
    $this->assertNoErrorsOrWarnings();

    $this->assertCorrectModels([
      'page_1' => [
        'en' => 'experiment2',
        'de' => '',
        'fr' => '',
      ],
      'page_2' => [
        'en' => 'experiment1',
        'de' => 'Standard',
        'fr' => 'Deuxième test',
      ],
      'page_3' => [
        'en' => 'experiment2',
        'de' => 'Standard',
        'fr' => '',
      ],
    ]);
  }

  /**
   * Tests that views with only a single display are handled correctly.
   */
  public function testSingleDisplayView(): void {
    $this->setUpMockApiService();

    $assert = $this->assertSession();
    $this->drupalGet('admin/structure/views/add');
    $this->submitForm([
      'id' => 'test_view_1',
      'label' => 'Test view 1',
      'show[wizard_key]' => 'standard:search_api_index_searchstax_index',
      'show[sort]' => 'none',
      'page[create]' => TRUE,
      'page[title]' => 'Test view 1',
      'page[path]' => 'test-view-1',
    ], 'Save and edit');
    $this->click('#views-page-1-query');
    $assert->elementNotExists('css', '[name="override[dropdown]"]');
    $this->assertDefaultValues([
      'en' => '',
      'de' => '',
      'fr' => '',
    ]);
    $this->submitForm([
      'query[options][searchstax][relevance_models][en]' => 'default',
      'query[options][searchstax][relevance_models][de]' => 'Standard',
      'query[options][searchstax][relevance_models][fr]' => 'Deuxième test',
    ], 'Apply');
    $this->click('#views-page-1-query');
    $this->assertDefaultValues([
      'en' => 'default',
      'de' => 'Standard',
      'fr' => 'Deuxième test',
    ]);
    $this->submitForm([
      'query[options][searchstax][relevance_models][en]' => 'experiment1',
      'query[options][searchstax][relevance_models][de]' => '',
      'query[options][searchstax][relevance_models][fr]' => 'Deuxième test',
    ], 'Apply');
    $this->submitForm([], 'Save');
    $view = View::load('test_view_1');
    $this->assertEquals([
      'relevance_models' => [
        'en' => 'experiment1',
        'de' => '',
        'fr' => 'Deuxième test',
      ],
    ], $view->getThirdPartySettings('searchstax'));
    $this->assertEquals('experiment1', ViewsSelectRelevanceModel::getViewRelevanceModel($view, 'en', 'page_1'));
    $this->assertEquals('', ViewsSelectRelevanceModel::getViewRelevanceModel($view, 'de', 'page_1'));
    $this->assertEquals('Deuxième test', ViewsSelectRelevanceModel::getViewRelevanceModel($view, 'fr', 'page_1'));
    $this->assertEquals('experiment1', ViewsSelectRelevanceModel::getViewRelevanceModel($view, 'en', 'default'));
    $this->assertEquals('', ViewsSelectRelevanceModel::getViewRelevanceModel($view, 'de', 'default'));
    $this->assertEquals('Deuxième test', ViewsSelectRelevanceModel::getViewRelevanceModel($view, 'fr', 'default'));
    $this->assertEquals('experiment1', ViewsSelectRelevanceModel::getViewRelevanceModel($view, 'en'));
    $this->assertEquals('', ViewsSelectRelevanceModel::getViewRelevanceModel($view, 'de'));
    $this->assertEquals('Deuxième test', ViewsSelectRelevanceModel::getViewRelevanceModel($view, 'fr'));
  }

  /**
   * Tests that legacy settings (model IDs, not names) are handled correctly.
   */
  public function testHandlingOfLegacySettings(): void {
    $this->setUpMockApiService();

    $view = View::load('searchstax_test_view');
    $view->setThirdPartySetting('searchstax', 'relevance_models', [
      'en' => 'experiment1',
      'de' => '',
      'fr' => 'Deuxième test',
    ]);
    $view->setThirdPartySetting('searchstax', 'relevance_model_overrides', [
      'page_2' => [
        'en' => '102',
        'de' => '202',
        'fr' => '',
      ],
      'page_3' => [
        'en' => '',
        'de' => '201',
        'fr' => '303',
      ],
    ]);
    $default_display = $view->getDisplay('default');
    $page_2 = &$view->getDisplay('page_2');
    $page_2['display_options']['query'] = $default_display['display_options']['query'];
    $page_2['display_options']['defaults']['query'] = FALSE;
    $page_3 = &$view->getDisplay('page_3');
    $page_3['display_options']['query'] = $default_display['display_options']['query'];
    $page_3['display_options']['defaults']['query'] = FALSE;
    $view->save();

    $assert = $this->assertSession();
    $this->drupalGet('admin/reports/status');
    $assert->pageTextContains('Outdated SearchStax "Relevance model" settings');
    $requirements_warning = 'The "Relevance model" settings for one or more views need to be adapted. Please review and resave the settings for the following view(s):';
    $assert->pageTextContains($requirements_warning);
    $test_view_url = $view->toUrl('edit-form')->toString();
    $link_to_test_view = "<a href=\"$test_view_url\">SearchStax Test view</a>";
    $assert->responseContains($link_to_test_view);

    $query_settings_base_path = 'admin/structure/views/nojs/display/searchstax_test_view';
    $migration_warning = 'The relevance model settings for this view/display have been migrated from an earlier version of the module. Please re-save this form and the view to have the settings take effect for searches.';
    $this->drupalGet("$query_settings_base_path/page_1/query");
    $this->assertNoErrorsOrWarnings();
    $assert->pageTextNotContains($migration_warning);
    $this->assertDefaultValues([
      'en' => 'experiment1',
      'de' => '',
      'fr' => 'Deuxième test',
    ], 'default');
    $this->submitForm([], 'Apply');
    $this->drupalGet("$query_settings_base_path/page_2/query");
    $assert->pageTextContains($migration_warning);
    $this->assertDefaultValues([
      'en' => 'experiment1',
      'de' => 'Erstes Experiment',
      'fr' => '',
    ], 'page_2');
    $this->submitForm([], 'Apply');
    $this->submitForm([], 'Save');
    $this->assertNoErrorsOrWarnings();

    // Verify that the third-party settings have been updated correctly.
    $view = View::load('searchstax_test_view');
    $this->assertEquals([
      'en' => 'experiment1',
      'de' => '',
      'fr' => 'Deuxième test',
    ], $view->getThirdPartySetting('searchstax', 'relevance_models'));
    $this->assertEquals([
      'page_2' => [
        'en' => 'experiment1',
        'de' => 'Erstes Experiment',
        'fr' => '',
      ],
      'page_3' => [
        'en' => '',
        'de' => '201',
        'fr' => '303',
      ],
    ], $view->getThirdPartySetting('searchstax', 'relevance_model_overrides'));

    // Only one display has been migrated, "page_3" still has legacy settings.
    // Therefore, the requirements check should still display a warning.
    $this->drupalGet('admin/reports/status');
    $assert->pageTextContains('Outdated SearchStax "Relevance model" settings');
    $assert->pageTextContains($requirements_warning);
    $assert->responseContains($link_to_test_view);

    $this->drupalGet("$query_settings_base_path/page_1/query");
    $this->assertNoErrorsOrWarnings();
    $assert->pageTextNotContains($migration_warning);
    $this->assertDefaultValues([
      'en' => 'experiment1',
      'de' => '',
      'fr' => 'Deuxième test',
    ], 'default');
    $this->submitForm([], 'Apply');
    $this->drupalGet("$query_settings_base_path/page_2/query");
    $this->assertNoErrorsOrWarnings();
    $assert->pageTextNotContains($migration_warning);
    $this->assertDefaultValues([
      'en' => 'experiment1',
      'de' => 'Erstes Experiment',
      'fr' => '',
    ], 'page_2');
    $this->submitForm([], 'Apply');
    $this->drupalGet("$query_settings_base_path/page_3/query");
    $assert->pageTextContains($migration_warning);
    $this->assertDefaultValues([
      'en' => '',
      'de' => 'Standard',
      'fr' => 'Deuxième test',
    ], 'page_3');
    $this->submitForm([], 'Apply');
    $this->submitForm([], 'Save');
    $this->assertNoErrorsOrWarnings();

    // Verify that the third-party settings have been updated correctly.
    $view = View::load('searchstax_test_view');
    $this->assertEquals([
      'en' => 'experiment1',
      'de' => '',
      'fr' => 'Deuxième test',
    ], $view->getThirdPartySetting('searchstax', 'relevance_models'));
    $this->assertEquals([
      'page_2' => [
        'en' => 'experiment1',
        'de' => 'Erstes Experiment',
        'fr' => '',
      ],
      'page_3' => [
        'en' => '',
        'de' => 'Standard',
        'fr' => 'Deuxième test',
      ],
    ], $view->getThirdPartySetting('searchstax', 'relevance_model_overrides'));

    // The requirements warning should be gone now.
    $this->drupalGet('admin/reports/status');
    $assert->pageTextNotContains('Outdated SearchStax "Relevance model" settings');
  }

  /**
   * Asserts that no errors or warnings were displayed or logged.
   */
  protected function assertNoErrorsOrWarnings(): void {
    $assert = $this->assertSession();
    $this->assertNoWarningsLogged();
    $assert->pageTextNotContains('The website encountered an unexpected error.');
    $assert->pageTextNotContains('Error message');
    $assert->pageTextNotContains('Warning message');
  }

  /**
   * Asserts that the default values on the form are as expected.
   *
   * @param array<string, string> $expected_models
   *   The expected defaults for the relevance model settings, keyed by language
   *   code.
   * @param string|null $expected_override_status
   *   (optional) The expected default setting for the override dropdown; or
   *   NULL if the dropdown is not expected to be present.
   */
  protected function assertDefaultValues(array $expected_models, ?string $expected_override_status = NULL): void {
    $assert = $this->assertSession();
    $actual = [];
    foreach ($expected_models as $langcode => $model) {
      $element = $assert->elementExists('css',
        "input[type=\"radio\"][name=\"query[options][searchstax][relevance_models][$langcode]\"][checked=\"checked\"]");
      $actual[$langcode] = $element->getAttribute('value');
    }
    $this->assertEquals($expected_models, $actual);

    if ($expected_override_status === NULL) {
      $assert->elementNotExists('css', 'select[name="override[dropdown]"]');
    }
    else {
      $element = $this->getSession()->getPage()->find('css', 'select[name="override[dropdown]"] > option[selected="selected"]');
      // Since Views cannot do anything straight-forward, in case the display is
      // defaulted we do not get "default" as the explicitly selected value but
      // only implicitly by being the first option.
      $selected_value = $element ? $element->getAttribute('value') : 'default';
      $this->assertEquals($expected_override_status, $selected_value);
    }
  }

  /**
   * Verifies that the relevance models currently used are as expected.
   *
   * @param array<string, array<string, string>> $expected
   *   The expected relevance models for our search pages in different
   *   languages, keyed by display ID and language code.
   */
  protected function assertCorrectModels(array $expected): void {
    static $languages;
    static $paths = [
      'page_1' => 'test-search-view',
      'page_2' => 'test-search-view-page-2',
      'page_3' => 'test-search-view-page-3',
    ];

    if (!isset($languages)) {
      $languages = \Drupal::languageManager()->getLanguages();
    }

    $key_value = \Drupal::keyValue('searchstax_test');
    $key_value->set('expected_requests', []);
    $this->addExpectedSolrRequests('#^emselect\?#', [], 9);
    $actual = [];
    foreach ($languages as $langcode => $language) {
      foreach ($paths as $display_id => $path) {
        $key_value->set('seen_requests', []);
        $this->drupalGet($path, ['language' => $language]);
        $seen_requests = $key_value->get('seen_requests', []);
        $this->assertCount(1, $seen_requests, "Visit to \"$path\" triggered a request.");
        $uri = reset($seen_requests);
        preg_match('#^emselect\?.*(?<=[?&])model=([-+.%\w]+)(?=$|&)#', $uri, $match);
        $actual[$display_id][$langcode] = urldecode($match[1] ?? '');
      }
    }
    $this->assertEquals($expected, $actual);
  }

  /**
   * Activates the mock API service to avoid HTTP requests.
   *
   * For tests that send many similar searches, just with different
   * configuration, using the mock HTTP client would be cumbersome. We therefore
   * instead mock the API service and set internal caches to avoid any complex
   * operations in this area.
   *
   * @see \Drupal\searchstax_test_mock_api\Mock\MockApiService
   * @see \Drupal\searchstax\Service\VersionCheck::getAppInformation()
   */
  protected function setUpMockApiService(): void {
    \Drupal::getContainer()->get('module_installer')
      ->install(['searchstax_test_mock_api']);
    $this->rebuildContainer();
    $update_endpoint = 'https://searchcloud-2-us-east-1.searchstax.com/searchstax-test/update';
    $cid = "searchstax.app_info.$update_endpoint";
    \Drupal::cache('data')->set($cid, new AppInfo('account1', 'app1', 123));

    // Make sure this setup worked correctly.
    $this->assertInstanceOf(MockApiService::class, \Drupal::service('searchstax.api'));
    $server = Server::load('searchstax_server');
    $app_info = \Drupal::getContainer()->get('searchstax.version_check')
      ->getAppInformation($server);
    $this->assertEquals(new AppInfo('account1', 'app1', 123), $app_info);
  }

}
