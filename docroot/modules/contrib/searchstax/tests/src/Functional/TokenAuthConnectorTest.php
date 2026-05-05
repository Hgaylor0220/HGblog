<?php

declare(strict_types=1);

namespace Drupal\Tests\searchstax\Functional;

use Drupal\Component\Serialization\Yaml;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\searchstax_test_mock_http\MockHttpTestTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Solr connector plugin functionality.
 *
 * @group searchstax
 *
 * @coversDefaultClass \Drupal\searchstax\Plugin\SolrConnector\SearchStaxConnector
 */
#[RunTestsInSeparateProcesses]
class TokenAuthConnectorTest extends BrowserTestBase {

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
    'dblog',
    'search_api_solr',
    'search_api_test_example_content',
    'searchstax',
    'searchstax_test_mock_http',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->setUpExampleStructure();
    $this->insertExampleContent();

    // Set a predictable default timezone and site hash.
    \Drupal::configFactory()->getEditable('system.date')
      ->set('timezone.default', 'Europe/Vienna')
      ->save();
    \Drupal::state()->set('search_api_solr.site_hash', '12rxq3');

    // Prepare the mock HTTP client.
    $this->setDataDirectory(__DIR__ . '/../../data/token-auth');
  }

  /**
   * Tests the Solr connector plugin functionality.
   *
   * @param bool $use_key_module
   *   Whether to use the Key module for all configuration.
   *
   * @dataProvider tokenAuthSolrConnectorTestDataProvider
   */
  public function testTokenAuthSolrConnector(bool $use_key_module): void {
    // Enable the Key module, if applicable.
    $key_storage = NULL;
    if ($use_key_module) {
      \Drupal::service('module_installer')->install(['key']);
      $this->rebuildContainer();
      $key_storage = \Drupal::entityTypeManager()->getStorage('key');
    }

    $assert_session = $this->assertSession();
    $this->drupalLogin($this->drupalCreateUser([], NULL, TRUE));

    $credentials = [
      'analytics_url' => 'https://example.com',
      'analytics_key' => 'test_analytics_key_view',
    ];
    if ($use_key_module) {
      // Create a Key with SearchStax analytics credentials.
      $key = $key_storage->create([
        'id' => 'test_searchstax_analytics',
        'label' => 'Test SearchStax Analytics Credentials',
        'description' => 'Test key for SearchStax Analytics',
        'key_type' => 'authentication',
        'key_type_settings' => [],
        'key_provider' => 'config',
        'key_provider_settings' => [
          'key_value' => json_encode($credentials),
        ],
        'key_input' => 'text_field',
        'key_input_settings' => [],
      ]);
      $key->save();
      $credentials = [
        'key_id' => 'test_searchstax_analytics',
      ];
    }

    // Enable tracking to be able to test this as well.
    $this->drupalGet('admin/config/search/searchstax');
    $this->submitForm($credentials, 'Save configuration');
    $assert_session->pageTextContains('The configuration options have been saved.');
    $this->assertNoWarningsLogged();

    $credentials = [
      'update_endpoint' => 'https://searchcloud.example.searchstax.com/1234/firstapp-100/update',
      'update_token' => '9232215801aa9e201c1afa76270eb8065aa6964a',
    ];
    if ($use_key_module) {
      // Create a Key with SearchStax app credentials.
      $key = $key_storage->create([
        'id' => 'test_searchstax_connector',
        'label' => 'Test SearchStax Connector Credentials',
        'description' => 'Test key for SearchStax connector',
        'key_type' => 'authentication',
        'key_type_settings' => [],
        'key_provider' => 'config',
        'key_provider_settings' => [
          'key_value' => json_encode($credentials),
        ],
        'key_input' => 'text_field',
        'key_input_settings' => [],
      ]);
      $key->save();
      $credentials = [
        'key_id' => 'test_searchstax_connector',
      ];
    }

    // Add a search server.
    $this->drupalGet('admin/config/search/search-api/add-server');
    $assert_session->pageTextContains('Add search server');
    $this->assertNoWarningsLogged();

    $this->submitForm([
      'name' => 'SearchStax server',
      'id' => 'searchstax_server',
      'backend_config[connector]' => 'searchstax',
    ], 'Save');
    $assert_session->pageTextContains('Please configure the selected Solr connector.');
    $this->assertNoWarningsLogged();

    if ($use_key_module) {
      $assert_session->fieldExists('backend_config[connector_config][key_id]');
      $assert_session->fieldExists('backend_config[connector_config][update_endpoint]');
      $assert_session->fieldExists('backend_config[connector_config][update_token]');
    }
    else {
      $assert_session->fieldNotExists('backend_config[connector_config][key_id]');
      $assert_session->fieldExists('backend_config[connector_config][update_endpoint]');
      $assert_session->fieldExists('backend_config[connector_config][update_token]');
    }

    $form_values = [];
    foreach ($credentials as $key => $value) {
      $form_values["backend_config[connector_config][$key]"] = $value;
    }
    $this->submitForm($form_values, 'Save');
    $assert_session->pageTextContains('The server was successfully saved.');
    $assert_session->pageTextContains('9.8.1');
    $this->assertPageRequestWasSuccessful();

    if ($use_key_module) {
      // Verify the server config uses "key_id".
      $server = Server::load('searchstax_server');
      $backend_config = $server->getBackendConfig();
      $this->assertEquals('test_searchstax_connector', $backend_config['connector_config']['key_id']);
      $this->assertEmpty($backend_config['connector_config']['update_endpoint'] ?? NULL);
      $this->assertEmpty($backend_config['connector_config']['update_token'] ?? NULL);
    }

    // Add a test index and view.
    $index_file = __DIR__ . '/../../modules/searchstax_test/config/install/search_api.index.searchstax_index.yml';
    $index_values = Yaml::decode(file_get_contents($index_file));
    Index::create($index_values)->save();
    $view_file = __DIR__ . '/../../modules/searchstax_test/config/install/views.view.searchstax_test_view.yml';
    $view_values = Yaml::decode(file_get_contents($view_file));
    View::create($view_values)->save();
    drupal_flush_all_caches();

    // Visit the view (which should be empty).
    $this->drupalGet('test-search-view');
    $assert_session->pageTextContains('Displaying 0 search results');
    $this->assertPageRequestWasSuccessful();

    // Visit the index page and index all items.
    $this->drupalGet('admin/config/search/search-api/index/searchstax_index');
    $this->submitForm([], 'Index now');
    $assert_session->pageTextContains('Successfully indexed 5 items.');
    $this->assertPageRequestWasSuccessful();

    // Visit the view again but with keywords.
    $this->drupalGet('test-search-view', ['query' => ['search_api_fulltext' => 'test']]);
    $assert_session->pageTextContains('Displaying 4 search results');
    $this->assertPageRequestWasSuccessful();
    $this->assertCurrentPageContainsTracking();

    // Make sure that the search server is recognized as a SearchStax Solr
    // server by our utility service.
    $utility = \Drupal::getContainer()->get('searchstax.utility');
    $server = Server::load('searchstax_server');
    $this->assertTrue($utility->isSearchStaxSolrServer($server));
  }

  /**
   * Provides test data for testTokenAuthSolrConnector().
   *
   * @return array<string, array{0: bool}>
   *   An array of argument arrays for testTokenAuthSolrConnector().
   *
   * @see testTokenAuthSolrConnector()
   */
  public static function tokenAuthSolrConnectorTestDataProvider(): array {
    return [
      'without_key' => [FALSE],
      'with_key' => [TRUE],
    ];
  }

  /**
   * Tests SearchStax connector form validation with invalid Key.
   */
  public function testConnectorValidationWithInvalidKey(): void {
    // Enable the Key module.
    \Drupal::service('module_installer')->install(['key']);
    $this->rebuildContainer();

    $this->drupalLogin($this->drupalCreateUser([], NULL, TRUE));
    $assert_session = $this->assertSession();

    // Create a Key with invalid JSON.
    $key_storage = \Drupal::entityTypeManager()->getStorage('key');
    $key = $key_storage->create([
      'id' => 'invalid_key',
      'label' => 'Invalid Key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => 'not valid json',
      ],
    ]);
    $key->save();

    // Try to create a server with invalid Key.
    $this->drupalGet('admin/config/search/search-api/add-server');
    $this->submitForm([
      'name' => 'Invalid Key Server',
      'id' => 'invalid_key_server',
      'backend_config[connector]' => 'searchstax',
    ], 'Save');

    $this->submitForm([
      'backend_config[connector_config][key_id]' => 'invalid_key',
    ], 'Save');

    // Should show validation error for invalid JSON.
    $assert_session->pageTextContains('The selected key does not contain valid JSON.');

    // Create a Key with valid JSON but missing required fields.
    $key2 = $key_storage->create([
      'id' => 'incomplete_key',
      'label' => 'Incomplete Key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => json_encode(['update_endpoint' => 'https://example.com']),
      ],
    ]);
    $key2->save();

    $this->drupalGet('admin/config/search/search-api/add-server');
    $this->submitForm([
      'name' => 'Incomplete Key Server',
      'id' => 'incomplete_key_server',
      'backend_config[connector]' => 'searchstax',
    ], 'Save');

    $this->submitForm([
      'backend_config[connector_config][key_id]' => 'incomplete_key',
    ], 'Save');

    // Should show validation error for missing fields.
    $assert_session->pageTextContains('The selected key must contain both "update_endpoint" and "update_token" fields.');
  }

  /**
   * Tests that the connector plugin also works without Key module.
   */
  public function testConnectorBackwardCompatibilityWithoutKey(): void {
    // Ensure Key module is not enabled.
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('key'));

    $assert_session = $this->assertSession();
    $this->drupalLogin($this->drupalCreateUser([], NULL, TRUE));

    // Add a server without Key module - should show plain-text fields.
    $this->drupalGet('admin/config/search/search-api/add-server');
    $this->submitForm([
      'name' => 'Plain Text Server',
      'id' => 'plaintext_server',
      'backend_config[connector]' => 'searchstax',
    ], 'Save');

    // Should see plain-text fields, not Key selector.
    $assert_session->fieldExists('backend_config[connector_config][update_endpoint]');
    $assert_session->fieldExists('backend_config[connector_config][update_token]');
    $assert_session->fieldNotExists('backend_config[connector_config][key_id]');

    // Submit with plain-text credentials - should work.
    $this->submitForm([
      'backend_config[connector_config][update_endpoint]' => 'https://searchcloud.example.searchstax.com/1234/firstapp-100/update',
      'backend_config[connector_config][update_token]' => '9232215801aa9e201c1afa76270eb8065aa6964a',
    ], 'Save');
    $assert_session->pageTextContains('The server was successfully saved.');
    $assert_session->pageTextContains('9.8.1');

    // Verify plain-text credentials are stored correctly.
    $server = \Drupal::entityTypeManager()
      ->getStorage('search_api_server')
      ->load('plaintext_server');

    $backend_config = $server->getBackendConfig();
    $this->assertEquals('https://searchcloud.example.searchstax.com/1234/firstapp-100/update',
      $backend_config['connector_config']['update_endpoint']);
    $this->assertEquals('9232215801aa9e201c1afa76270eb8065aa6964a',
      $backend_config['connector_config']['update_token']);
    $this->assertEmpty($backend_config['connector_config']['key_id'] ?? NULL);
  }

}
