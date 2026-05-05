<?php

namespace Drupal\Tests\searchstax\FunctionalJavascript;

use Behat\Mink\Driver\GoutteDriver;
use Drupal\Core\Session\AccountInterface;
use Drupal\eu_cookie_compliance\Entity\CookieCategory;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\searchstax\Functional\TestAssertionsTrait;
use Drupal\Tests\searchstax\Functional\TestSolrConnectorTrait;
use Drupal\user\Entity\Role;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore goutte

/**
 * Tests whether cookie consent code is handled correctly.
 *
 * @group searchstax
 */
#[RunTestsInSeparateProcesses]
class CookieConsentTest extends WebDriverTestBase {

  use TestAssertionsTrait;
  use TestSolrConnectorTrait;

  /**
   * The default value of the "eu_cookie_compliance" config for this test.
   */
  protected const DEFAULT_COOKIE_CONFIG = [
    'enabled' => TRUE,
    'category' => 'tracking',
  ];

  /**
   * Modifier for checking whether searches are tracked.
   */
  protected const TRACK_SEARCHES = 1;

  /**
   * Modifier for checking whether clicks are tracked.
   */
  protected const TRACK_CLICK = 2;

  /**
   * Modifier for checking whether a cookie is set.
   */
  protected const SET_COOKIE = 4;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'eu_cookie_compliance',
    'search_api',
    'search_api_solr',
    'search_api_test_example_content',
    'searchstax',
    'searchstax_test',
    'searchstax_test_mock_tracking',
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

    // Everyone should be able to view test entities.
    foreach ([Role::ANONYMOUS_ID, Role::AUTHENTICATED_ID] as $role_id) {
      $this->grantPermissions(Role::load($role_id), [
        'view test entity',
        'view test entity translations',
      ]);
    }

    // Add some cookie categories for testing.
    foreach (['tracking', 'preferences', 'misc'] as $category) {
      CookieCategory::create([
        'id' => $category,
        'label' => $category,
      ])->save();
    }

    // Set up tracking.
    \Drupal::configFactory()
      ->getEditable('searchstax.settings')
      ->set('analytics_key', 'foobar')
      ->save();

    // We don't care about the Solr requests sent for this test.
    $this->addExpectedSolrRequests('#.#', NULL, 1000);

    // Switch the search to display the name as a link so we can test click
    // tracking.
    $view = View::load('searchstax_test_view');
    $display = $view->get('display');
    $display['default']['display_options']['row'] = [
      'type' => 'fields',
    ];
    $display['default']['display_options']['fields'] = [
      'name' => [
        'id' => 'name',
        'table' => 'search_api_index_searchstax_index',
        'field' => 'name',
        'relationship' => 'none',
        'group_type' => 'group',
        'plugin_id' => 'search_api_field',
        'label' => '',
        'alter' => [],
        'type' => 'string',
        'settings' => [
          'link_to_entity' => TRUE,
        ],
        'field_rendering' => TRUE,
        'fallback_handler' => 'search_api',
        'fallback_options' => [],
      ],
    ];
    $view->set('display', $display);
    $view->save();

    // To be on the safe side, clear all caches at the end of setup.
    drupal_flush_all_caches();
  }

  /**
   * Tests our integration with the EU Cookie Compliance module.
   */
  public function testEuCookieComplianceIntegration(): void {
    $this->checkSettingsFormBehavior();
    $this->checkTrackingObeysSettings();
    $this->checkUninstallingEuCookieComplianceModule();
    $this->checkCustomModuleTrackingManagement();
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
      ['enabled' => TRUE],
      \Drupal::config('searchstax.settings')->get('eu_cookie_compliance'),
    );

    // Verify that our own settings page looks as it should by default.
    $this->drupalGet('admin/config/search/searchstax/advanced-settings');
    $enabled_selector = 'input[name="eu_cookie_compliance[enabled]"]';
    $checkbox = $assert->elementExists('css', $enabled_selector);
    $this->assertTrue($checkbox->isChecked());
    $category_selector = 'select[name="eu_cookie_compliance[category]"]';
    $select = $assert->elementExists('css', $category_selector);
    $this->assertTrue($select->isVisible());
    $this->assertEquals('', $select->getValue());

    // When unchecking "enabled", the "category" input should vanish.
    $this->click($enabled_selector);
    $select = $assert->elementExists('css', $category_selector);
    $this->assertFalse($select->isVisible());

    // Save the form and make sure the config has been saved as intended.
    $this->click('#edit-submit');
    $assert->pageTextContains('The configuration options have been saved.');
    $this->assertEquals(
      [
        'enabled' => FALSE,
        'category' => '',
      ],
      \Drupal::config('searchstax.settings')->get('eu_cookie_compliance'),
    );
    // The defaults when visiting the form should also have changed.
    $this->drupalGet('admin/config/search/searchstax/advanced-settings');
    $checkbox = $assert->elementExists('css', $enabled_selector);
    $this->assertFalse($checkbox->isChecked());
    $select = $assert->elementExists('css', $category_selector);
    $this->assertFalse($select->isVisible());
    $this->assertEquals('', $select->getValue());

    // Now enable the integration again and set a category.
    $this->click($enabled_selector);
    $select = $assert->elementExists('css', $category_selector);
    $this->assertTrue($select->isVisible());
    $select->setValue('tracking');
    $this->click('#edit-submit');
    $assert->pageTextContains('The configuration options have been saved.');
    $this->assertEquals(
      self::DEFAULT_COOKIE_CONFIG,
      \Drupal::config('searchstax.settings')->get('eu_cookie_compliance'),
    );
    $checkbox = $assert->elementExists('css', $enabled_selector);
    $this->assertTrue($checkbox->isChecked());
    $select = $assert->elementExists('css', $category_selector);
    $this->assertTrue($select->isVisible());
    $this->assertEquals('tracking', $select->getValue());
    $this->drupalLogout();
  }

  /**
   * Checks that tracking is enabled based on settings and user choice.
   */
  protected function checkTrackingObeysSettings(): void {
    // Make sure our own module's configuration is as expected: integration
    // enabled and, in case categories are used, SearchStax is part of category
    // "tracking".
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('eu_cookie_compliance', self::DEFAULT_COOKIE_CONFIG)
      ->save();

    // First, test with visitor cookie management disabled (method "default").
    \Drupal::configFactory()->getEditable('eu_cookie_compliance.settings')
      ->set('method', 'default')
      ->save();

    // Go to the search page. Tracking should happen right away since method is
    // "default".
    $this->visitSearchPage();
    $this->assertTrackingEnabled();

    // Behavior with method "opt_out" should be the same at first.
    \Drupal::configFactory()->getEditable('eu_cookie_compliance.settings')
      ->set('method', 'opt_out')
      ->save();
    $this->visitSearchPage();
    $this->assertTrackingEnabled();
    // However, if we now click on "No, thanks", no tracking should be present
    // on the next page.
    $this->click('.eu-cookie-compliance-banner .decline-button');
    $this->visitSearchPage(FALSE);
    $this->assertTrackingDisabled();

    // Behavior should be the exact opposite with method "opt_in".
    \Drupal::configFactory()->getEditable('eu_cookie_compliance.settings')
      ->set('method', 'opt_in')
      ->save();
    $this->visitSearchPage();
    $this->assertTrackingDisabled();
    $this->click('.eu-cookie-compliance-banner .agree-button');
    $this->visitSearchPage(FALSE);
    $this->assertTrackingEnabled();

    // With method "categories" the behavior should depend on whether the
    // visitor accepts the "tracking" category.
    \Drupal::configFactory()->getEditable('eu_cookie_compliance.settings')
      ->set('method', 'categories')
      ->save();
    $this->visitSearchPage();
    $this->assertTrackingDisabled();
    $this->click('input[name="cookie-categories"][value="tracking"]');
    $this->click('.eu-cookie-compliance-banner .eu-cookie-compliance-save-preferences-button');
    $this->visitSearchPage(FALSE);
    $this->assertTrackingEnabled();

    $this->visitSearchPage();
    $this->assertTrackingDisabled();
    $this->click('input[name="cookie-categories"][value="preferences"]');
    $this->click('.eu-cookie-compliance-banner .eu-cookie-compliance-save-preferences-button');
    $this->visitSearchPage(FALSE);
    $this->assertTrackingDisabled();

    // If the admin disabled the integration with the EU Cookie Compliance
    // module then its popup should be ignored entirely.
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('eu_cookie_compliance.enabled', FALSE)
      ->save();
    $this->visitSearchPage();
    $this->assertTrackingEnabled();
  }

  /**
   * Checks tracking is present if the eu_cookie_compliance is not installed.
   */
  protected function checkUninstallingEuCookieComplianceModule(): void {
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('eu_cookie_compliance', self::DEFAULT_COOKIE_CONFIG)
      ->save();
    \Drupal::getContainer()->get('module_installer')
      ->uninstall(['eu_cookie_compliance']);
    $this->visitSearchPage();
    $this->assertTrackingEnabled();

    // Check the details box is also gone from the settings form.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/search/searchstax/advanced-settings');
    $this->assertSession()->elementNotExists('css', 'input[name="eu_cookie_compliance[enabled]"]');
  }

  /**
   * Checks that preventing tracking via custom Javascript works correctly.
   */
  protected function checkCustomModuleTrackingManagement(): void {
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('eu_cookie_compliance'));
    $key_value = \Drupal::keyValue('searchstax_test_mock_tracking');
    $this->assertEquals([], $key_value->get('reject', []));

    $key_value->set('reject', ['trackSearchResults']);
    $this->visitSearchPage(FALSE);
    $this->assertTrackingDisabled(self::TRACK_SEARCHES);
    $this->assertTrackingEnabled(self::TRACK_CLICK | self::SET_COOKIE);

    $key_value->set('reject', ['trackClick']);
    $this->visitSearchPage(FALSE);
    $this->assertTrackingEnabled(self::TRACK_SEARCHES | self::SET_COOKIE);
    $this->assertTrackingDisabled(self::TRACK_CLICK);

    $key_value->set('reject', ['trackSearchResults', 'trackClick']);
    $this->visitSearchPage(FALSE);
    $this->assertTrackingDisabled();
  }

  /**
   * Visits the search page.
   *
   * @param bool $restart_session
   *   (optional) TRUE to completely restart the session before the visit, to
   *   simulate a new visitor arriving on the site.
   */
  protected function visitSearchPage(bool $restart_session = TRUE): void {
    if ($restart_session) {
      $this->mink->restartSessions();
      $this->initFrontPage();
    }
    $this->drupalGet('test-search-view', ['query' => ['search_api_fulltext' => 'test']]);
    $this->assertNoWarningsLogged();
  }

  /**
   * Asserts that tracking is present on the current search results page.
   *
   * @param int $mask
   *   (optional) Bitmask to check only searches, clicks or cookies.
   */
  protected function assertTrackingEnabled(
    int $mask = self::TRACK_SEARCHES | self::TRACK_CLICK | self::SET_COOKIE
  ): void {
    if ($mask & self::TRACK_SEARCHES) {
      $tracked_searches = $this->getTrackingEvents('track');
      $this->assertCount(1, $tracked_searches);
      [, $data] = reset($tracked_searches);
      $this->assertEquals('foobar', $data['key']);
      $this->assertNotEmpty($data['session']);
      $this->assertEquals('test', $data['query']);
      $this->assertEquals('en', $data['language']);
    }

    if ($mask & self::TRACK_CLICK) {
      $this->clickLink('foo baz');
      $tracked_clicks = $this->getTrackingEvents('trackClick');
      $this->assertCount(1, $tracked_clicks);
      [, $click_data] = reset($tracked_clicks);
      $this->assertEquals('foobar', $click_data['key']);
      if (isset($data)) {
        $this->assertEquals($data['session'], $click_data['session']);
      }
      $this->assertEquals('test', $click_data['query']);
      $this->assertEquals('en', $click_data['language']);
    }

    if ($mask & self::SET_COOKIE) {
      $script_src = $this->getSession()->evaluateScript(
        "return document.getElementsByTagName('script')[0].src;"
      );
      $this->assertEquals('https://static.searchstax.com/studio-js/v3/js/studio-analytics.js', $script_src);
    }
  }

  /**
   * Asserts that tracking is not present on the current search results page.
   *
   * @param int $mask
   *   (optional) Bitmask to check only searches, clicks or cookies.
   */
  protected function assertTrackingDisabled(
    int $mask = self::TRACK_SEARCHES | self::TRACK_CLICK | self::SET_COOKIE
  ): void {
    if ($mask & self::TRACK_SEARCHES) {
      $this->assertEmpty($this->getTrackingEvents('track'));
    }
    if ($mask & self::TRACK_CLICK) {
      $this->clickLink('foo baz');
      $this->assertEmpty($this->getTrackingEvents('trackClick'));
    }
    if ($mask & self::SET_COOKIE) {
      $script_src = $this->getSession()->evaluateScript(
        "return document.getElementsByTagName('script')[0].src;"
      );
      $this->assertNotEquals('https://static.searchstax.com/studio-js/v3/js/studio-analytics.js', $script_src);
    }
  }

  /**
   * Retrieves the tracking events sent on the current page.
   *
   * @param string|null $event_type
   *   (optional) The type of event ("track" or "trackClick") to filter for;
   *   NULL to retrieve all events.
   *
   * @return list<array{0: string, 1: array}>
   *   The events.
   *
   * @see searchstax_test_mock_tracking.js
   */
  protected function getTrackingEvents(?string $event_type = NULL): array {
    $list_entries = $this->assertSession()
      ->elementExists('css', '#searchstax-test-mock-tracking')
      ->findAll('css', 'li');
    $events = [];
    foreach ($list_entries as $entry) {
      $event = json_decode($entry->getText(), TRUE, JSON_THROW_ON_ERROR);
      if ($event_type === NULL || $event[0] === $event_type) {
        $events[] = $event;
      }
    }
    return $events;
  }

  /**
   * {@inheritdoc}
   */
  protected function clickLink($label, $index = 0): void {
    parent::clickLink($label, $index);

    // Log only for WebDriverTestBase tests because for BrowserKitDriver we log
    // with ::getResponseLogHandler.
    if (method_exists($this, '')) {
      $is_guzzle_client = $this->isTestUsingGuzzleClient();
    }
    else {
      $is_guzzle_client = !($this->getSession()->getDriver() instanceof GoutteDriver);
    }
    if ($this->htmlOutputEnabled && !$is_guzzle_client) {
      if ($index > 0) {
        $label .= '#' . $index;
      }
      $html_output = 'Click on link: ' . $label;
      $html_output .= '<hr />' . $this->getSession()->getPage()->getContent();
      $html_output .= $this->getHtmlOutputHeaders();
      $this->htmlOutput($html_output);
    }
  }

}
