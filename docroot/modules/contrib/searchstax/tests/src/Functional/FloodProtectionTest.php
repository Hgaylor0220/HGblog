<?php

namespace Drupal\Tests\searchstax\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\Entity\Index;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Flood Protection functionality.
 *
 * @group searchstax
 */
#[RunTestsInSeparateProcesses]
class FloodProtectionTest extends BrowserTestBase {

  use TestAssertionsTrait;
  use TestSolrConnectorTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api',
    'search_api_solr',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up example content.
    $this->setUpExampleStructure();
    $this->insertExampleContent();

    $this->adminUser = $this->drupalCreateUser([], NULL, TRUE);

    // Everyone should be able to create test entities so we can test the
    // indexing flood protection.
    foreach ([Role::ANONYMOUS_ID, Role::AUTHENTICATED_ID] as $role_id) {
      $this->grantPermissions(Role::load($role_id), [
        'view test entity',
        'administer entity_test content',
        'administer entity_test fields',
      ]);
    }

    // Enable "Index items immediately" for the test index so we can test the
    // indexing flood protection.
    Index::load('searchstax_index')
      ->set('options', [
        'cron_limit' => -1,
        'index_directly' => TRUE,
      ])
      ->save();

    // Not sure why this is needed here, but in GitLab CI the search view page
    // is sometimes not found without first clearing the cache.
    drupal_flush_all_caches();
  }

  /**
   * Tests the Flood Protection functionality.
   *
   * @covers \Drupal\searchstax\Form\SettingsForm::buildForm
   * @covers \Drupal\searchstax\EventSubscriber\FloodSubscriber::executeCheck
   */
  public function testFloodProtection(): void {
    $this->checkSettingsFormBehavior();
    $this->checkSearchFloodProtection();
    $this->checkIndexingFloodProtection();
  }

  /**
   * Checks that the settings form behaves correctly.
   */
  protected function checkSettingsFormBehavior(): void {
    $assert = $this->assertSession();
    $this->drupalLogin($this->adminUser);

    // Verify the defaults are as expected to avoid hard-to-debug failures
    // further down.
    $this->assertEquals(
      [
        'enabled' => FALSE,
        'search_limit' => 15,
        'search_window' => 10,
        'update_limit' => 50,
        'update_window' => 60,
      ],
      \Drupal::config('searchstax.settings')->get('flood_protection'),
    );

    // Verify that the settings page looks as it should by default.
    $this->drupalGet('admin/config/search/searchstax/advanced-settings');
    $selector = function (string $key): string {
      return "input[name=\"flood_protection[$key]\"]";
    };
    $this->assertFalse($assert->elementExists('css', $selector('enabled'))->isChecked());
    $this->assertEquals('15', $assert->elementExists('css', $selector('search_limit'))->getValue());
    $this->assertEquals('10', $assert->elementExists('css', $selector('search_window'))->getValue());
    $this->assertEquals('50', $assert->elementExists('css', $selector('update_limit'))->getValue());
    $this->assertEquals('60', $assert->elementExists('css', $selector('update_window'))->getValue());

    // Change limits and windows without enabling flood protection.
    $this->submitForm([
      'flood_protection[search_limit]' => 3,
      'flood_protection[search_window]' => 120,
      'flood_protection[update_limit]' => 2,
      'flood_protection[update_window]' => 180,
    ], 'Save configuration');
    $this->assertFalse($assert->elementExists('css', $selector('enabled'))->isChecked());
    $this->assertEquals('3', $assert->elementExists('css', $selector('search_limit'))->getValue());
    $this->assertEquals('120', $assert->elementExists('css', $selector('search_window'))->getValue());
    $this->assertEquals('2', $assert->elementExists('css', $selector('update_limit'))->getValue());
    $this->assertEquals('180', $assert->elementExists('css', $selector('update_window'))->getValue());

    // Enable flood protection.
    $this->submitForm([
      'flood_protection[enabled]' => TRUE,
    ], 'Save configuration');
    $this->assertTrue($assert->elementExists('css', $selector('enabled'))->isChecked());
    $this->assertEquals('3', $assert->elementExists('css', $selector('search_limit'))->getValue());
    $this->assertEquals('120', $assert->elementExists('css', $selector('search_window'))->getValue());
    $this->assertEquals('2', $assert->elementExists('css', $selector('update_limit'))->getValue());
    $this->assertEquals('180', $assert->elementExists('css', $selector('update_window'))->getValue());

    // Check that the config values are as expected.
    $expected = [
      'enabled' => TRUE,
      'search_limit' => 3,
      'search_window' => 120,
      'update_limit' => 2,
      'update_window' => 180,
    ];
    $this->assertEquals(
      $expected,
      \Drupal::config('searchstax.settings')->get('flood_protection'),
    );

    // Test form validation.
    $this->submitForm([
      'flood_protection[search_limit]' => -3,
      // Attempt to change a second value to verify that this change is not
      // applied, either.
      'flood_protection[search_window]' => 20,
    ], 'Save configuration');
    $assert->pageTextContains('Search Limit must be higher than or equal to 0.');
    $this->assertEquals(
      $expected,
      \Drupal::config('searchstax.settings')->get('flood_protection'),
    );
    $this->submitForm([
      'flood_protection[search_limit]' => -3,
      'flood_protection[search_window]' => 20,
    ], 'Save configuration');
    $assert->pageTextContains('Search Limit must be higher than or equal to 0.');
    $this->assertEquals(
      $expected,
      \Drupal::config('searchstax.settings')->get('flood_protection'),
    );
    $this->submitForm([
      'flood_protection[search_limit]' => 'foo',
      'flood_protection[search_window]' => 20,
    ], 'Save configuration');
    $assert->pageTextContains('Search Limit must be a number.');
    $this->assertEquals(
      $expected,
      \Drupal::config('searchstax.settings')->get('flood_protection'),
    );
    $this->submitForm([
      'flood_protection[search_limit]' => '',
      'flood_protection[search_window]' => 20,
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $expected['search_limit'] = NULL;
    $expected['search_window'] = 20;
    $this->assertEquals(
      $expected,
      \Drupal::config('searchstax.settings')->get('flood_protection'),
    );
  }

  /**
   * Checks that flood protection for searches works correctly.
   */
  protected function checkSearchFloodProtection(): void {
    $assert = $this->assertSession();
    $this->drupalLogout();

    // Set a very low limit in a very long window so we can be sure that we
    // trigger the flood protection even on very slow machines.
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('flood_protection.enabled', TRUE)
      ->set('flood_protection.search_limit', 2)
      ->set('flood_protection.search_window', 1200)
      ->save();

    // We expect exactly two search requests here.
    $this->addExpectedSolrRequests('#select\?#');

    $this->drupalGet('test-search-view');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('foo bar baz');
    $flood_protection_message = 'SearchStax flood protection: search limit reached';
    $assert->pageTextNotContains($flood_protection_message);
    $this->assertNoWarningsLogged();

    $this->drupalGet('test-search-view');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('foo bar baz');
    $assert->pageTextNotContains($flood_protection_message);
    $this->assertNoWarningsLogged();

    $this->drupalGet('test-search-view');
    $assert->statusCodeEquals(200);
    $assert->pageTextNotContains('foo bar baz');
    $assert->pageTextContains($flood_protection_message);
    $this->assertNoWarningsLogged();

    // Clear the "window" setting and verify that this disabled search flood
    // protection.
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('flood_protection.search_window', NULL)
      ->save();
    $this->addExpectedSolrRequests('#select\?#', NULL, 1);

    $this->drupalGet('test-search-view');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('foo bar baz');
    $assert->pageTextNotContains($flood_protection_message);
    $this->assertNoWarningsLogged();
  }

  /**
   * Checks that flood protection for indexing works correctly.
   */
  protected function checkIndexingFloodProtection(): void {
    $assert = $this->assertSession();

    // Make sure the current indexing queue size is as expected.
    $index = Index::load('searchstax_index');
    $get_unindexed_items_count = function () use ($index) {
      return $index->getTrackerInstance()->getRemainingItemsCount();
    };
    $this->assertEquals(5, $get_unindexed_items_count());

    // Set a very low limit in a very long window so we can be sure that we
    // trigger the flood protection even on very slow machines.
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('flood_protection.enabled', TRUE)
      ->set('flood_protection.update_limit', 2)
      ->set('flood_protection.update_window', 1200)
      ->save();

    // We expect exactly two update requests here.
    $this->addExpectedSolrRequests('#update(\?|$)#');

    // The path for creating test entities changed in Drupal 11.2.
    if (version_compare(\Drupal::VERSION, '11.2', '<')) {
      $add_entity_path = 'entity_test_mulrev_changed/add';
    }
    else {
      $add_entity_path = 'entity_test_mulrev_changed/add/article';
    }

    $this->drupalGet($add_entity_path);
    $this->submitForm([
      'name[0][value]' => 'flood_test_entity_1',
    ], 'Save');
    $assert->pageTextContains('entity_test_mulrev_changed 6 has been created.');
    $assert->pageTextContains('Edit flood_test_entity_1');
    $this->assertNoWarningsLogged();
    $this->assertEquals(5, $get_unindexed_items_count());

    $this->drupalGet($add_entity_path);
    $this->submitForm([
      'name[0][value]' => 'flood_test_entity_2',
    ], 'Save');
    $assert->pageTextContains('entity_test_mulrev_changed 7 has been created.');
    $assert->pageTextContains('Edit flood_test_entity_2');
    $this->assertNoWarningsLogged();
    $this->assertEquals(5, $get_unindexed_items_count());

    $this->drupalGet($add_entity_path);
    $this->submitForm([
      'name[0][value]' => 'flood_test_entity_3',
    ], 'Save');
    $assert->pageTextContains('entity_test_mulrev_changed 8 has been created.');
    $assert->pageTextContains('Edit flood_test_entity_3');
    $this->assertEquals(6, $get_unindexed_items_count());
    $warnings = $this->getWatchdogWarnings();
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('SearchStax flood protection: update limit reached', reset($warnings));

    // Clear the "window" setting and verify that this disabled index flood
    // protection.
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('flood_protection.update_window', NULL)
      ->save();
    $this->addExpectedSolrRequests('#update(\?|$)#', NULL, 1);

    $this->drupalGet($add_entity_path);
    $this->submitForm([
      'name[0][value]' => 'flood_test_entity_4',
    ], 'Save');
    $assert->pageTextContains('entity_test_mulrev_changed 9 has been created.');
    $assert->pageTextContains('Edit flood_test_entity_4');
    // Verify that just the old unindexed items and warning are returned.
    $this->assertEquals(6, $get_unindexed_items_count());
    $warnings = $this->getWatchdogWarnings();
    $this->assertCount(1, $warnings);
  }

}
