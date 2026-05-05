<?php

declare(strict_types=1);

namespace Drupal\Tests\searchstax\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests proper caching of tracking information.
 *
 * @group searchstax
 */
#[RunTestsInSeparateProcesses]
class TrackingCacheTest extends BrowserTestBase {

  use ExampleContentTrait;
  use TestAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api',
    'search_api_test_example_content',
    'search_api_test_views',
    'searchstax',
    'dblog',
    'image',
    'link',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The test users used in this test.
   *
   * @var \Drupal\Core\Session\AccountInterface[]
   */
  protected array $users = [];

  /**
   * The RID of the test user role.
   */
  protected string $testRole;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    // Set up example content.
    $this->setUpExampleStructure();
    $this->insertExampleContent();

    // Create some test users, some of them sharing roles.
    $this->testRole = $this->drupalCreateRole([]);
    $this->assertNotEmpty($this->testRole);
    $this->users[0] = $this->drupalCreateUser([], NULL, FALSE, [
      'roles' => [$this->testRole],
    ]);
    $this->users[1] = $this->drupalCreateUser([], NULL, FALSE, [
      'roles' => [$this->testRole],
    ]);
    $this->users[2] = $this->drupalCreateUser();
    $this->users[3] = $this->drupalCreateUser([], NULL, TRUE);

    // Everyone should be able to view search pages and test entities.
    foreach ([Role::ANONYMOUS_ID, Role::AUTHENTICATED_ID] as $role_id) {
      $this->grantPermissions(Role::load($role_id), [
        'view test entity',
        'view test entity translations',
      ]);
    }

    // Users with our test role should not be tracked.
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('analytics_url', 'https://example.com')
      ->set('analytics_key', 'foobar')
      ->set('untracked_roles', [$this->testRole])
      ->save();

    $this->indexItems('database_search_index');
    drupal_flush_all_caches();
  }

  /**
   * Tests that caching works correctly in the search view.
   */
  public function testSearchViewCaching(): void {
    $visit_view = function () {
      return $this->drupalGet('search-api-test-search-view-caching-tag', [
        'query' => [
          'search_api_fulltext' => 'foo',
        ],
      ]);
    };

    $this->drupalLogin($this->users[0]);
    $visit_view();
    $this->assertNoWarningsLogged();
    $this->assertCurrentPageNotContainsTracking();
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $visit_view();
    $this->assertNoWarningsLogged();
    $this->assertCurrentPageNotContainsTracking();
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'HIT');
    $this->drupalLogout();

    $this->drupalLogin($this->users[1]);
    $visit_view();
    $this->assertNoWarningsLogged();
    $this->assertCurrentPageNotContainsTracking();
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'HIT');
    $this->drupalLogout();

    $this->drupalLogin($this->users[2]);
    $visit_view();
    $this->assertNoWarningsLogged();
    $this->assertCurrentPageContainsTracking();
    $this->assertPageUncacheable();
    $visit_view();
    $this->assertNoWarningsLogged();
    $this->assertCurrentPageContainsTracking();
    $this->assertPageUncacheable();
    $this->drupalLogout();

    $this->drupalLogin($this->users[3]);
    $visit_view();
    $this->assertNoWarningsLogged();
    $this->assertCurrentPageContainsTracking();
    $this->assertPageUncacheable();
    $visit_view();
    $this->assertNoWarningsLogged();
    $this->assertCurrentPageContainsTracking();
    $this->assertPageUncacheable();
    $this->drupalLogout();

    $visit_view();
    $this->assertNoWarningsLogged();
    $this->assertCurrentPageContainsTracking();
    $this->assertPageUncacheable();
    $visit_view();
    $this->assertNoWarningsLogged();
    $this->assertPageUncacheable();
    $this->assertCurrentPageContainsTracking();
  }

  /**
   * Asserts that the current page was uncacheable.
   *
   * Extracted into a separate method since the exact value of the
   * "X-Drupal-Dynamic-Cache" header will vary between Drupal versions.
   */
  protected function assertPageUncacheable(): void {
    $header_name = 'X-Drupal-Dynamic-Cache';
    $header = $this->getSession()->getResponseHeader($header_name);
    $this->assertContains($header, ['UNCACHEABLE', 'UNCACHEABLE (poor cacheability)'], "Uncacheable response expected, but value of \"$header_name\" was \"$header\".");
  }

}
