<?php

namespace Drupal\Tests\searchstax\Functional;

use Drupal\searchstax_test_mock_http\MockHttpTestTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Version Check functionality.
 *
 * @group searchstax
 */
#[RunTestsInSeparateProcesses]
class VersionCheckTest extends BrowserTestBase {

  use MockHttpTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'searchstax',
    'searchstax_test',
    'searchstax_test_mock_http',
    'searchstax_test_version_check',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Prepare the mock HTTP client.
    $this->setDataDirectory(__DIR__ . '/../../data/version-check');
  }

  /**
   * Tests the version check form.
   */
  public function testVersionCheckForm(): void {
    // Create a user with permission to administer the site configuration.
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));

    $assert = $this->assertSession();

    // Mock the Drupal version used by the version check.
    $this->setMockDrupalVersion(9);

    // Visit the status report. It should warn us that the version check has not
    // been performed.
    $this->drupalGet('admin/reports/status');
    $assert->statusCodeEquals(200);
    $status_report_ok = 'The configuration files of all SearchStax apps used on this site are compatible with the currently used version of Drupal.';
    $status_report_check_needed = 'The SearchStax version check needs to be executed for at least one of your search servers.';
    $status_report_incompatible = 'The configuration files of at least one SearchStax app used on this site are incompatible with the currently used version of Drupal. Please go to the SearchStax version check page for details and possible solutions.';
    $assert->pageTextNotContains($status_report_ok);
    $assert->pageTextNotContains($status_report_incompatible);
    $assert->pageTextContains($status_report_check_needed);

    // Visit the settings page.
    $this->drupalGet('/admin/config/search/searchstax/version-check');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Check version compatibility');
    $assert->pageTextContains('Your SearchStax app has not been checked against the current major version of Drupal. Please click the “Check” button.');
    $assert->pageTextContains('SearchStax server');
    $assert->pageTextContains('Not checked');
    $assert->pageTextContains('Never');

    // Execute the version check. It will come back as "compatible".
    $this->submitForm([], 'Check');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('SearchStax login');
    $this->submitForm([
      'password' => 'password123',
      'username' => 'user@example.com',
      'tfa_token' => '123456',
    ], 'Continue');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Successfully checked compatibility status.');
    $assert->pageTextContains('The configuration files of your SearchStax app are compatible with the currently used version of Drupal.');
    $assert->pageTextContains('SearchStax server');
    $assert->pageTextContains('Compatible');
    $assert->pageTextNotContains('Never');

    $this->assertHttpRequests([
      'check-compatibility-drupal-9',
      'get-apps-account1',
      'get-apps-account2',
      'list-accounts',
      'obtain-auth-token',
    ]);

    // Rechecking should yield the same result.
    $this->submitForm([], 'Re-check');
    $assert->pageTextContains('Successfully checked compatibility status.');
    $assert->pageTextContains('The configuration files of your SearchStax app are compatible with the currently used version of Drupal.');
    $assert->pageTextContains('SearchStax server');
    $assert->pageTextContains('Compatible');
    $assert->pageTextNotContains('Never');

    $this->assertHttpRequests([
      'check-compatibility-drupal-9',
      // "list-accounts" is a test request used in Api::isLoggedIn() to
      // determine whether a login is still valid.
      'list-accounts',
    ]);

    // Check the status report again, it should be fine now.
    $this->drupalGet('admin/reports/status');
    $assert->statusCodeEquals(200);
    $assert->pageTextNotContains($status_report_check_needed);
    $assert->pageTextNotContains($status_report_incompatible);
    $assert->pageTextContains($status_report_ok);

    // Simulate an update of the Drupal version.
    $this->setMockDrupalVersion(10);

    // The status report should complain about a missing check again.
    $this->drupalGet('admin/reports/status');
    $assert->statusCodeEquals(200);
    $assert->pageTextNotContains($status_report_ok);
    $assert->pageTextNotContains($status_report_incompatible);
    $assert->pageTextContains($status_report_check_needed);

    // Execute the version check again. It will now yield "incompatible".
    $this->drupalGet('/admin/config/search/searchstax/version-check');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Check version compatibility');
    $assert->pageTextContains('Your SearchStax app has not been checked against the current major version of Drupal. Please click the “Check” button.');
    $assert->pageTextContains('SearchStax server');
    $assert->pageTextContains('Not checked');
    $assert->pageTextContains('Never');
    $this->submitForm([], 'Check');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Successfully checked compatibility status.');
    $assert->pageTextContains('The configuration files of your SearchStax app are incompatible with the currently used version of Drupal. Please click the “Upgrade” button.');
    $assert->pageTextContains('SearchStax server');
    $assert->pageTextContains('Incompatible');
    $assert->pageTextNotContains('Never');
    $assert->responseContains('"Re-check"');

    $this->assertHttpRequests([
      'check-compatibility-drupal-10--1',
      'list-accounts',
    ]);

    // The status report should reflect this.
    $this->drupalGet('admin/reports/status');
    $assert->statusCodeEquals(200);
    $assert->pageTextNotContains($status_report_ok);
    $assert->pageTextNotContains($status_report_check_needed);
    $assert->pageTextContains($status_report_incompatible);

    // Execute the upgrade.
    $this->drupalGet('/admin/config/search/searchstax/version-check');
    $assert->statusCodeEquals(200);
    $this->submitForm([], 'Upgrade');
    $assert->pageTextContains('Successfully upgraded the SearchStax app.');
    $assert->pageTextContains('The configuration files of your SearchStax app are compatible with the currently used version of Drupal.');
    $assert->pageTextContains('SearchStax server');
    $assert->pageTextContains('Compatible');
    $assert->pageTextNotContains('Never');
    $assert->responseContains('"Re-check"');

    $this->assertHttpRequests([
      'check-compatibility-drupal-10--2',
      'list-accounts',
      'upgrade-for-drupal-10',
    ]);

    // The status report should be fine again.
    $this->drupalGet('admin/reports/status');
    $assert->statusCodeEquals(200);
    $assert->pageTextNotContains($status_report_check_needed);
    $assert->pageTextNotContains($status_report_incompatible);
    $assert->pageTextContains($status_report_ok);
  }

  /**
   * Sets the simulated Drupal version of the version check service.
   *
   * @param int $version
   *   The Drupal major version to mock.
   */
  protected function setMockDrupalVersion(int $version): void {
    \Drupal::keyValue('searchstax_test_version_check')->set('drupal_version', $version);
  }

}
