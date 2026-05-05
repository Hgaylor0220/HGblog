<?php

declare(strict_types=1);

namespace Drupal\Tests\solr_to_searchstax_ss_migration\Functional;

use Drupal\Component\Serialization\Yaml;
use Drupal\facets\Entity\Facet;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\searchstax_test_mock_http\MockHttpTestTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use Drupal\Tests\searchstax\Functional\TestAssertionsTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore wrongpassword firstapp

/**
 * Tests the complete module functionality.
 *
 * @group searchstax
 */
#[RunTestsInSeparateProcesses]
class IntegrationTest extends BrowserTestBase {

  use ExampleContentTrait;
  use MockHttpTestTrait;
  use TestAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'searchstax_migration_test',
  ];

  /**
   * The path prefix of the current test site.
   */
  protected string $pathPrefix;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up example content.
    $this->setUpExampleStructure();
    $this->insertExampleContent();

    \Drupal::keyValue('searchstax_test_mock_http')
      ->set('data_dir', __DIR__ . '/../../data');
  }

  /**
   * Tests the complete module functionality.
   */
  public function testModuleFunctionality(): void {
    $assert_session = $this->assertSession();
    $overview_path = 'admin/config/search/solr-to-searchstax-ss-migration';

    // First log in as an editor and make sure we do not have access to our
    // admin page (which should require "administer search_api").
    $this->drupalLogin($this->drupalCreateUser(['administer entity_test content']));
    $this->drupalGet($overview_path);
    $assert_session->statusCodeEquals(403);

    // In case the test site runs in a sub-directory of the host domain, save
    // the internal path so we can more easily check for correct URLs.
    $url = $this->getSession()->getCurrentUrl();
    $path = parse_url($url, PHP_URL_PATH);
    $this->assertStringContainsString("/$overview_path", $path);
    $this->pathPrefix = substr($path, 0, strpos($path, "/$overview_path"));

    // Log in as admin.
    $this->drupalLogin($this->drupalCreateUser([], NULL, TRUE));

    $this->drupalGet('admin/config/search');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Assists you in migrating search configuration from Solr to SearchStax Site Search.');
    $this->clickLink('Migrate from Solr to SearchStax Site Search');
    $assert_session->statusCodeEquals(200);

    $expected_table_cells = [
      [
        '<a href="/admin/config/search/search-api/server/acquia_search_server">Acquia Search API Solr server</a>',
        'Ready to be migrated.',
        '<a href="/admin/config/search/solr-to-searchstax-ss-migration/migrate-server/acquia_search_server">Migrate</a>',
      ],
      [
        '<a href="/admin/config/search/search-api/index/acquia_index">Acquia test index</a>',
        '<a href="/admin/config/search/search-api/server/acquia_search_server">Acquia Search API Solr server</a>',
        'The associated server has not been migrated yet.',
        '',
      ],
      [
        'Acquia Test view',
        '<a href="/admin/config/search/search-api/index/acquia_index">Acquia test index</a>',
        'This Solr index has not been copied yet.',
        '',
      ],
    ];
    $this->assertTableCells($expected_table_cells);

    $this->clickLink('Migrate');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('SearchStax login');

    // First, try with wrong credentials and verify this leads to an error.
    $this->submitForm([
      'password' => 'wrongpassword',
      'username' => 'user@example.com',
    ], 'Continue');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('The login failed');
    $assert_session->pageTextContains('Unable to log in with provided credentials.');
    $assert_session->pageTextContains('SearchStax login');

    $this->submitForm([
      'password' => 'password123',
      'username' => 'user@example.com',
    ], 'Continue');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Two-Factor Authentication is enabled for this account. Please provide a valid TFA token.');
    $assert_session->pageTextContains('SearchStax login');

    $this->assertHttpRequests([
      'searchstax/login-missing-tfa',
      'searchstax/login-wrong-password',
    ]);

    $this->submitForm([
      'password' => 'password123',
      'username' => 'user@example.com',
      'tfa_token' => '123456',
    ], 'Continue');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('SearchStax account');
    $assert_session->responseContains('<option value="AccountWithoutTokenSupport">AccountWithoutTokenSupport</option>');
    $assert_session->responseContains('<option value="AccountWithTokenSupport">AccountWithTokenSupport</option>');
    $assert_session->responseContains('<option value="ThirdAccount">ThirdAccount</option>');
    $assert_session->pageTextNotContains('SearchStax app to which to migrate');
    $assert_session->pageTextNotContains('Languages to migrate');
    $assert_session->responseNotContains('value="Migrate server now"');

    $this->assertHttpRequests([
      'searchstax/get-accounts',
      'searchstax/obtain-auth-token',
    ]);

    $this->submitForm([
      'searchstax_account' => 'AccountWithoutTokenSupport',
    ], 'Confirm account');
    $assert_session->pageTextNotContains('SearchStaxException');
    $assert_session->pageTextContains('SearchStax account');
    $assert_session->pageTextContains('SearchStax app to which to migrate');
    $assert_session->responseContains('<option value="200">FirstApp</option>');
    $assert_session->responseContains('<option value="201">SecondApp</option>');
    $assert_session->pageTextContains('Languages to migrate');
    $assert_session->pageTextContains('English (Solr type text_en)');
    $assert_session->pageTextContains('German (Solr type text_de)');
    $assert_session->pageTextContains('French (Solr type text_fr)');
    $assert_session->responseContains('value="Migrate server now"');

    $this->assertHttpRequests([
      'acquia/get-file-list',
      'acquia/get-schema',
      'acquia/get-schema_extra_fields',
      'acquia/get-schema_extra_types',
      'searchstax/get-all-languages',
      'searchstax/get-apps-without-token-support',
    ]);

    $this->submitForm([
      'searchstax_account' => 'ThirdAccount',
    ], 'Confirm account');
    $assert_session->pageTextNotContains('SearchStaxException');
    $assert_session->pageTextContains('SearchStax account');
    $assert_session->pageTextContains('There are no apps available for this SearchStax account.');
    $assert_session->pageTextNotContains('SearchStax app to which to migrate');
    $assert_session->elementExists('css', 'input.form-submit[value="Migrate server now"][disabled="disabled"]');

    $this->assertHttpRequests([
      'searchstax/get-apps-third-account',
    ]);

    $this->submitForm([
      'searchstax_account' => 'AccountWithTokenSupport',
    ], 'Confirm account');
    $assert_session->pageTextNotContains('SearchStaxException');
    $assert_session->pageTextContains('SearchStax account');
    $assert_session->pageTextContains('SearchStax app to which to migrate');
    $assert_session->responseContains('<option value="100">FirstApp</option>');
    $assert_session->responseContains('<option value="101">SecondApp</option>');
    $assert_session->responseContains('<option value="102">ThirdApp</option>');
    $assert_session->pageTextContains('Languages to migrate');
    $assert_session->pageTextContains('English (Solr type text_en)');
    $assert_session->pageTextContains('German (Solr type text_de)');
    $assert_session->pageTextContains('French (Solr type text_fr)');
    $assert_session->responseContains('value="Migrate server now"');

    $this->assertHttpRequests([
      'searchstax/get-apps-with-token-support',
    ]);

    $this->submitForm([
      'searchstax_account' => 'AccountWithTokenSupport',
      'searchstax_app' => '100',
      'search_view' => 'acquia_test_view:page_1',
      'languages[list][text_en]' => TRUE,
      'languages[list][text_de]' => TRUE,
      'languages[list][text_fr]' => FALSE,
    ], 'Migrate server now');
    $assert_session->statusCodeEquals(200);

    $assert_session->pageTextContains('Successfully created search server SearchStax server (app FirstApp).');
    $assert_session->pageTextContains('Enabled the following languages in the SearchStax app: English, German');
    $assert_session->pageTextContains('Added English stopwords to the SearchStax app.');
    $assert_session->pageTextContains('Added German stopwords to the SearchStax app.');
    $assert_session->pageTextContains('Added English synonyms to the SearchStax app.');
    $assert_session->pageTextContains('Added German synonyms to the SearchStax app.');
    $assert_session->pageTextContains('Set the searched fields for language code "en".');
    $assert_session->pageTextContains('Set the searched fields for language code "de".');
    $assert_session->pageTextContains('Set the displayed result fields for language code "en".');
    $assert_session->pageTextContains('Set the displayed result fields for language code "de".');
    $assert_session->pageTextContains('Enabled sorting via a dropdown select for language code "en".');
    $assert_session->pageTextContains('Set the sort field(s) for language code "en".');
    $assert_session->pageTextContains('Enabled sorting via a dropdown select for language code "de".');
    $assert_session->pageTextContains('Set the sort field(s) for language code "de".');
    $assert_session->pageTextContains('Migration finished successfully.');
    $this->assertPageTextDoesNotContain('error');
    $this->assertPageTextDoesNotContain('Exception');
    $this->assertPageTextDoesNotContain('Unable to reach the Solr server (yet).');
    $this->assertPageTextDoesNotContain('Nothing left to migrate.');

    $expected_table_cells[0][1] = 'Migrated to server <a href="/admin/config/search/search-api/server/searchstax_server">SearchStax server (app FirstApp)</a>.';
    $expected_table_cells[0][2] = '';
    $expected_table_cells[1][2] = 'Ready to be copied.';
    $expected_table_cells[1][3] = '<input type="submit" name="acquia_index" value="Create copy">';
    $this->assertTableCells($expected_table_cells);

    $this->assertHttpRequests([
      'acquia/get-stopwords-de',
      'acquia/get-stopwords-en',
      'acquia/get-synonyms-de',
      'acquia/get-synonyms-en',
      'searchstax/core-info',
      'searchstax/enable-sort-select-de',
      'searchstax/enable-sort-select-en',
      'searchstax/get-accounts',
      'searchstax/get-models-de',
      'searchstax/get-models-en',
      'searchstax/publish-config-de',
      'searchstax/publish-config-en',
      'searchstax/publish-model-de',
      'searchstax/publish-model-en',
      'searchstax/set-languages',
      'searchstax/set-result-fields-de',
      'searchstax/set-result-fields-en',
      'searchstax/set-searched-fields-de',
      'searchstax/set-searched-fields-en',
      'searchstax/set-sort-fields-de',
      'searchstax/set-sort-fields-en',
      'searchstax/set-stopwords-de',
      'searchstax/set-stopwords-en',
      'searchstax/set-synonyms-de',
      'searchstax/set-synonyms-en',
      'searchstax/solr-ping',
    ]);

    // Make sure the connection details are correct.
    $server = Server::load('searchstax_server');
    $this->assertEquals('search_api_solr', $server->getBackendId());
    $config = $server->getBackendConfig();
    $this->assertEquals('searchstax', $config['connector']);
    $this->assertEquals('https', $config['connector_config']['scheme']);
    $this->assertEquals('searchcloud.example.searchstax.com', $config['connector_config']['host']);
    $this->assertEquals(443, $config['connector_config']['port']);
    $this->assertEquals('1234', $config['connector_config']['context']);
    $this->assertEquals('firstapp-100', $config['connector_config']['core']);
    // Make sure the rest of the Solr config got copied correctly.
    $old_config = Server::load('acquia_search_server')->getBackendConfig();
    unset(
      $config['connector'],
      $config['connector_config'],
      $old_config['connector'],
      $old_config['connector_config'],
    );
    $this->assertEquals($old_config, $config);

    $this->submitForm([], 'Create copy');
    $assert_session->statusCodeEquals(200);

    $assert_session->pageTextContains('Successfully created index SearchStax index.');
    $this->assertPageTextDoesNotContain('error');
    $this->assertPageTextDoesNotContain('Exception');
    $this->assertPageTextDoesNotContain('Nothing left to migrate.');
    $expected_table_cells[1][2] = 'Copied to index <a href="/admin/config/search/search-api/index/searchstax_index">SearchStax index</a>.';
    $expected_table_cells[1][3] = '';
    $expected_table_cells[2][2] = '<ul>'
      . '<li>Ready to be switched to index <a href="/admin/config/search/search-api/index/searchstax_index">SearchStax index</a>.</li>'
      . '<li>Warning: 5 of 5 items remain to be indexed on the target index. It is advised to finish indexing before switching a view to use this index.</li>'
      . '</ul>';
    $expected_table_cells[2][3] = '<input type="submit" name="acquia_test_view" value="Switch to copy">';
    $this->assertTableCells($expected_table_cells);

    Index::load('acquia_index')->save();
    $acquia_index = Index::load('acquia_index');
    $searchstax_index = Index::load('searchstax_index');
    $ignored = array_flip([
      '_core',
      'dependencies',
      'description',
      'id',
      'name',
      'server',
      'third_party_settings',
      'uuid',
    ]);
    $this->assertEquals(
      array_diff_key($acquia_index->toArray(), $ignored),
      array_diff_key($searchstax_index->toArray(), $ignored),
    );
    $this->assertEquals(
      $acquia_index->getThirdPartySettings('search_api_solr'),
      $searchstax_index->getThirdPartySettings('search_api_solr'),
    );
    // Mark all items as indexed.
    $tracker = $acquia_index->getTrackerInstance();
    $tracker->trackItemsIndexed($tracker->getRemainingItems());
    $tracker = $searchstax_index->getTrackerInstance();
    $tracker->trackItemsIndexed($tracker->getRemainingItems());

    $this->drupalGet($overview_path);
    $this->assertPageTextDoesNotContain('error');
    $this->assertPageTextDoesNotContain('Exception');
    $this->assertPageTextDoesNotContain('Nothing left to migrate.');
    $expected_table_cells[2][2] = 'Ready to be switched to index <a href="/admin/config/search/search-api/index/searchstax_index">SearchStax index</a>.';
    $this->assertTableCells($expected_table_cells);

    $this->submitForm([], 'Switch to copy');
    $assert_session->pageTextContains('The index of the Acquia Test view search view was successfully switched.');
    $assert_session->pageTextContains('Nothing left to migrate.');
    $this->assertPageTextDoesNotContain('error');
    $this->assertPageTextDoesNotContain('Exception');

    $expected_table_cells[2][1] = '<a href="/admin/config/search/search-api/index/searchstax_index">SearchStax index</a>';
    $expected_table_cells[2][2] = 'Can be rolled back to use index <a href="/admin/config/search/search-api/index/acquia_index">Acquia test index</a> in case of problems.';
    $expected_table_cells[2][3] = '<input type="submit" name="acquia_test_view" value="Roll back change">';
    $this->assertTableCells($expected_table_cells);

    $yaml_file = __DIR__ . '/../../modules/searchstax_migration_test/config/install/views.view.acquia_test_view.yml';
    $file_contents = file_get_contents($yaml_file);
    $file_contents = str_replace('acquia_index', 'searchstax_index', $file_contents);
    $expected = Yaml::decode($file_contents);
    $view = View::load('acquia_test_view');
    $this->assertEquals($expected, array_intersect_key($expected, $view->toArray()));

    // Uninstall the migration module and make sure it does not delete any of
    // the entities.
    $this->drupalGet('admin/modules/uninstall');
    $this->submitForm([
      'uninstall[solr_to_searchstax_ss_migration]' => TRUE,
    ], 'Uninstall');
    $this->assertPageTextDoesNotContain('Acquia Search API Solr server');
    $this->assertPageTextDoesNotContain('SearchStax server');
    $this->assertPageTextDoesNotContain('Acquia test index');
    $this->assertPageTextDoesNotContain('SearchStax index');
    $this->assertPageTextDoesNotContain('Acquia Test view');
    $this->submitForm([], 'Uninstall');

    $acquia_server = Server::load('acquia_search_server');
    $this->assertNotEmpty($acquia_server);
    $this->assertNotEmpty(Server::load('searchstax_server'));
    $acquia_index = Index::load('acquia_index');
    $this->assertNotEmpty($acquia_index);
    $this->assertNotEmpty(Index::load('searchstax_index'));
    $this->assertNotEmpty(View::load('acquia_test_view'));

    // Make sure that visiting the status reports page does not lead to a fatal
    // error.
    $this->drupalGet('admin/reports/status');
    $assert_session->statusCodeEquals(200);

    // Delete the original index and server to make sure that this does not
    // affect any other configuration anymore.
    // Set the index to read-only first to avoid the Solr delete request.
    $acquia_index->set('read_only', TRUE)->save();
    $acquia_index->delete();
    $acquia_server->delete();
    $this->assertEmpty(Server::load('acquia_search_server'));
    $this->assertEmpty(Index::load('acquia_index'));
    $this->assertNotEmpty(Server::load('searchstax_server'));
    $this->assertNotEmpty(Index::load('searchstax_index'));
    $this->assertNotEmpty(View::load('acquia_test_view'));
    $this->assertNotEmpty(Facet::load('type'));
  }

  /**
   * Asserts that tables on the current page have the given rows.
   *
   * @param string[][] $expected_table_cells
   *   The expected table cells, grouped by row.
   */
  protected function assertTableCells(array $expected_table_cells): void {
    $table_cells = [];
    foreach ($this->getSession()->getPage()->findAll('css', 'tbody > tr') as $row) {
      $row_cells = [];
      foreach ($row->findAll('css', 'td') as $cell) {
        // Special case for form input elements.
        $inputs = $cell->findAll('css', 'input');
        $cell_contents = [];
        if ($inputs) {
          foreach ($inputs as $input) {
            $type = $input->getAttribute('type');
            $name = $input->getAttribute('name');
            $value = $input->getAttribute('value');
            $cell_contents[] = "<input type=\"{$type}\" name=\"{$name}\" value=\"{$value}\">";
          }
        }
        else {
          $cell_contents[] = $cell->getHtml();
        }
        $row_cells[] = count($cell_contents) > 1 ? $cell_contents : reset($cell_contents);
      }
      $table_cells[] = $row_cells;
    }
    if ($this->pathPrefix !== '') {
      $expected_table_cells = array_map(function (array $strings): array {
        return str_replace('href="/', "href=\"{$this->pathPrefix}/", $strings);
      }, $expected_table_cells);
    }
    $this->assertEquals($expected_table_cells, $table_cells);
  }

}
