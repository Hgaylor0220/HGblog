<?php

namespace Drupal\Tests\searchstax\Functional\Update;

use Drupal\FunctionalTests\Update\UpdatePathTestBase;
use Drupal\search_api\Entity\Server;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the functionality of the searchstax_update_8103() update hook.
 *
 * @see searchstax_update_8103()
 *
 * @group searchstax
 * @legacy
 */
#[RunTestsInSeparateProcesses]
class SearchStaxUpdate8103Test extends UpdatePathTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // We need to manually set the needed entity types as "installed".
    $entity_type_ids = [
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
      __DIR__ . '/../../../fixtures/update/searchstax-update-8103.php',
    ];
  }

  /**
   * Tests whether searchstax_update_8103() works correctly.
   */
  public function testUpdate8103(): void {
    // Before running the update hook, the globally set auto-suggest core should
    // be reported for all SearchStax servers.
    $this->assertEquals([
      'other_solr_server' => NULL,
      'searchstax_server_basic_auth' => 'drupal-1234_suggester',
      'searchstax_server_basic_auth_2' => 'drupal-1234_suggester',
      'searchstax_server_token_auth' => 'drupal-1234_suggester',
      'searchstax_server_token_auth_2' => 'drupal-1234_suggester',
    ], $this->collectServerAutosuggestCores());

    $this->runUpdates();

    // The global config value should not have been removed (since there were
    // SearchStax servers that did not match) so we should still get the same
    // core reported for all SearchStax servers.
    $config_factory = \Drupal::configFactory();
    $config_value = $config_factory->get('searchstax.settings')->get('autosuggest_core');
    $this->assertEquals('drupal-1234_suggester', $config_value);
    $this->assertEquals([
      'other_solr_server' => NULL,
      'searchstax_server_basic_auth' => 'drupal-1234_suggester',
      'searchstax_server_basic_auth_2' => 'drupal-1234_suggester',
      'searchstax_server_token_auth' => 'drupal-1234_suggester',
      'searchstax_server_token_auth_2' => 'drupal-1234_suggester',
    ], $this->collectServerAutosuggestCores());

    // Go to our Settings page and remove the setting.
    $this->drupalLogin($this->createUser([], NULL, TRUE));
    $this->drupalGet('admin/config/search/searchstax');
    $assert_session = $this->assertSession();
    $element = $assert_session->elementExists('css', 'input[name="autosuggest_core"]');
    $this->assertEquals('drupal-1234_suggester', $element->getAttribute('value'));
    $this->submitForm([
      'autosuggest_core' => '',
    ], 'Save configuration');
    $assert_session->elementNotExists('css', 'input[name="autosuggest_core"]');
    $config_factory->reset();
    $this->assertArrayNotHasKey('autosuggest_core', $config_factory->get('searchstax.settings')->getRawData());

    // Now only the matching servers should have the auto-suggest core set.
    $this->assertEquals([
      'other_solr_server' => NULL,
      'searchstax_server_basic_auth' => 'drupal-1234_suggester',
      'searchstax_server_basic_auth_2' => NULL,
      'searchstax_server_token_auth' => 'drupal-1234_suggester',
      'searchstax_server_token_auth_2' => NULL,
    ], $this->collectServerAutosuggestCores());

    // Check that all the server edit forms look correctly.
    $this->drupalGet('admin/config/search/search-api/server/other_solr_server/edit');
    $assert_session->elementNotExists('css', 'input[name*="autosuggest_"]');
    $this->drupalGet('admin/config/search/search-api/server/searchstax_server_basic_auth/edit');
    $element = $assert_session->elementExists('css', 'input[name="third_party_settings[searchstax][autosuggest_endpoint]"]');
    $this->assertStringContainsString('/drupal-1234_suggester/', $element->getAttribute('value'));
    $assert_session->elementNotExists('css', 'input[name="backend_config[connector_config][autosuggest_endpoint]"]');
    $this->drupalGet('admin/config/search/search-api/server/searchstax_server_basic_auth_2/edit');
    $element = $assert_session->elementExists('css', 'input[name="third_party_settings[searchstax][autosuggest_endpoint]"]');
    $this->assertEquals('', $element->getAttribute('value'));
    $assert_session->elementNotExists('css', 'input[name="backend_config[connector_config][autosuggest_endpoint]"]');
    $this->drupalGet('admin/config/search/search-api/server/searchstax_server_token_auth/edit');
    $element = $assert_session->elementExists('css', 'input[name="backend_config[connector_config][autosuggest_endpoint]"]');
    $this->assertStringContainsString('/drupal-1234_suggester/', $element->getAttribute('value'));
    $assert_session->elementNotExists('css', 'input[name="third_party_settings[searchstax][autosuggest_endpoint]"]');
    $this->drupalGet('admin/config/search/search-api/server/searchstax_server_token_auth_2/edit');
    $element = $assert_session->elementExists('css', 'input[name="backend_config[connector_config][autosuggest_endpoint]"]');
    $this->assertEquals('', $element->getAttribute('value'));
    $assert_session->elementNotExists('css', 'input[name="third_party_settings[searchstax][autosuggest_endpoint]"]');
  }

  /**
   * Collects the auto-suggest cores detected for all search servers.
   *
   * @return array<string, string>
   *   Associative array mapping search server IDs to the auto-suggest core
   *   detected for them by our utility service.
   *
   * @see \Drupal\searchstax\Service\SearchStax::getAutosuggestCore()
   */
  protected function collectServerAutosuggestCores(): array {
    $utility = \Drupal::getContainer()->get('searchstax.utility');
    $info = [];
    foreach (Server::loadMultiple() as $server) {
      $info[$server->id()] = $utility->getAutosuggestCore($server);
    }
    return $info;
  }

}
