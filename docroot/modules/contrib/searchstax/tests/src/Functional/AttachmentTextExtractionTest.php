<?php

namespace Drupal\Tests\searchstax\Functional;

use Drupal\searchstax_test_mock_http\MockHttpTestTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the functionality of our Search API Attachments plugin.
 *
 * @group searchstax
 *
 * @coversDefaultClass \Drupal\searchstax\Plugin\search_api_attachments\SearchStaxExtractor
 */
#[RunTestsInSeparateProcesses]
class AttachmentTextExtractionTest extends BrowserTestBase {

  use MockHttpTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'file',
    'searchstax',
    'searchstax_test',
    'searchstax_test_mock_http',
    'search_api_attachments',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Prepare the mock HTTP client.
    $this->setDataDirectory(__DIR__ . '/../../data/pdf-extraction');

    // Since there is a bug in earlier version of the Search API Attachments
    // module that would lead to a fatal error, set an empty config for our
    // plugin to work around it.
    \Drupal::configFactory()->getEditable('search_api_attachments.admin_config')
      ->set('searchstax_configuration', [])
      ->save();
  }

  /**
   * Tests the version check form.
   */
  public function testPdfExtraction(): void {
    // Log in as admin.
    $this->drupalLogin($this->drupalCreateUser([], NULL, TRUE));

    $assert = $this->assertSession();

    // Go to the Search API Attachments config page and switch to the SearchStax
    // extractor.
    $this->drupalGet('admin/config/search/search_api_attachments');
    $this->submitForm([
      'extraction_method' => 'searchstax',
    ], 'Submit and test extraction');
    $assert->pageTextContains("Unfortunately, the extraction doesn't seem to work with this configuration! (No endpoint URL set for SearchStax Document Extractor.)");
    $this->submitForm([
      'text_extractor_config[endpoint]' => 'https://extractor-us.searchstax.co/api/v1/1234/extract',
      'text_extractor_config[token]' => '0123456789abcdef0123456789abcdef01234567',
      'text_extractor_config[timeout]' => '45',
    ], 'Submit and test extraction');
    $assert->pageTextContains('Congratulations! The extraction seems working! Yay!');
    $this->assertHttpRequests(['extract-pdf']);
  }

}
