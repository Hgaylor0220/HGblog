<?php

declare(strict_types=1);

namespace Drupal\Tests\searchstax\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the SettingsForm functionality.
 *
 * @group searchstax
 */
#[RunTestsInSeparateProcesses]
class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['searchstax'];

  /**
   * Tests that the settings form saves configuration.
   */
  public function testSettingsFormSave() {
    // Create a user with permission to administer the site configuration.
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));

    $page = $this->getSession()->getPage();
    $assert = $this->assertSession();

    // Visit the settings page.
    $this->drupalGet('/admin/config/search/searchstax');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('SearchStax settings');

    // Check if the fields exist.
    $assert->fieldExists('analytics_url');
    $assert->fieldExists('analytics_key');
    $assert->fieldExists('searches_via_searchstudio');
    $assert->fieldExists('discard_parameters[keys]');
    $assert->fieldExists('discard_parameters[highlight]');
    // Key module is not installed, so the "key_id" field should not be shown.
    $assert->fieldNotExists('key_id');

    // Fill the fields.
    $page->fillField('analytics_url', 'https://example.com/analytics');
    $page->fillField('analytics_key', 'test_key');
    $page->checkField('searches_via_searchstudio');
    $page->checkField('discard_parameters[keys]');
    $page->checkField('discard_parameters[highlight]');

    // Submit the form.
    $page->pressButton('Save configuration');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('SearchStax settings');
    $assert->pageTextContains('The configuration options have been saved.');

    // Verify that the configuration was saved.
    $config = $this->config('searchstax.settings');
    $this->assertEquals('3', $config->get('js_version'));
    $this->assertEquals('https://example.com/analytics', $config->get('analytics_url'));
    $this->assertEquals('test_key', $config->get('analytics_key'));
    $this->assertTrue($config->get('searches_via_searchstudio'));
    $this->assertEquals(['highlight', 'keys', 'spellcheck'], $config->get('discard_parameters'));

    // Simulate enabling the Key module.
    \Drupal::service('module_installer')->install(['key']);
    $this->rebuildContainer();

    // Verify that the configuration was not migrated to a key.
    $config = $this->config('searchstax.settings');
    $this->assertNotEmpty($config->get('analytics_url'));
    $this->assertNotEmpty($config->get('analytics_key'));
    $this->assertEmpty($config->get('key_id'));

    // Reload the settings page and verify that the "key_id" setting defaults to
    // "Do not use".
    $this->drupalGet('/admin/config/search/searchstax');
    $assert->statusCodeEquals(200);
    $element = $assert->elementExists('css', 'select[name="key_id"] option[selected="selected"]');
    $this->assertEquals('', $element->getAttribute('value'));

    // Trigger automatic migration to key-based storage.
    $key_migration = \Drupal::getContainer()->get('searchstax.migrate_to_keys');
    $key_migration->ensureDefaultKeys();
    $key_migration->migrateAnalyticsCredentials();
    $key_migration->migrateServersToKeys();

    // Reload the page.
    $this->drupalGet('/admin/config/search/searchstax');
    $assert->statusCodeEquals(200);

    // Should see "key_id" field.
    $assert->fieldExists('key_id');
    // Should still see "analytics_url" and "analytics_key" fields.
    $assert->fieldExists('analytics_url');
    $assert->fieldExists('analytics_key');

    // Verify that the configuration was migrated to a key.
    $config = $this->config('searchstax.settings');
    $this->assertNull($config->get('analytics_url'));
    $this->assertNull($config->get('analytics_key'));
    $this->assertEquals('searchstax_analytics_credentials', $config->get('key_id'));

    $key = \Drupal::getContainer()->get('key.repository')
      ->getKey($config->get('key_id'));
    $this->assertNotEmpty($key);
    $key_value = $key->getKeyValue();
    $this->assertNotEmpty($key_value);
    $credentials = json_decode($key_value, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertEquals('https://example.com/analytics', $credentials['analytics_url']);
    $this->assertEquals('test_key', $credentials['analytics_key']);
  }

  /**
   * Tests that the advanced settings form saves configuration.
   */
  public function testAdvancedSettingsFormSave() {
    // Create a user with permission to administer the site configuration.
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));

    $page = $this->getSession()->getPage();
    $assert = $this->assertSession();

    // Visit the settings page.
    $this->drupalGet('/admin/config/search/searchstax/advanced-settings');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Advanced SearchStax settings');

    // Check if the fields exist.
    $assert->fieldExists('untracked_roles[anonymous]');
    $assert->fieldExists('untracked_roles[authenticated]');

    // Fill the fields.
    $page->checkField('untracked_roles[anonymous]');

    // Submit the form.
    $page->pressButton('Save configuration');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Advanced SearchStax settings');
    $assert->pageTextContains('The configuration options have been saved.');

    // Verify that the configuration was saved.
    $config = $this->config('searchstax.settings');
    $this->assertEquals(['anonymous'], $config->get('untracked_roles'));

    // Verify that tracking is disabled for anonymous users but still enabled
    // for logged-in users.
    $this->drupalLogout();
    $this->assertTrue($this->container->get('searchstax.utility')->isTrackingDisabled());
    $this->drupalLogin($this->drupalCreateUser());
    $this->assertFalse($this->container->get('searchstax.utility')->isTrackingDisabled());
  }

}
