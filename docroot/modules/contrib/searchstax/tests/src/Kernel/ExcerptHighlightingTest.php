<?php

namespace Drupal\Tests\searchstax\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Plugin\search_api\processor\Highlight;
use Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that excerpt highlighting works correctly if configured in the app.
 *
 * @group searchstax
 */
#[RunTestsInSeparateProcesses]
class ExcerptHighlightingTest extends KernelTestBase {

  use ExampleContentTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'field',
    'filter',
    'searchstax',
    'searchstax_test',
    'search_api',
    'search_api_solr',
    'search_api_test_example_content',
    'text',
    'user',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('entity_test_mulrev_changed');
    $this->installConfig('search_api_test_example_content');

    $this->setUpExampleStructure();
    $this->addTestEntity(1, [
      'name' => 'foo bla bar bla baz test',
      'body' => 'test test',
      'type' => 'item',
    ]);
  }

  /**
   * Tests that excerpt highlighting works correctly if configured in the app.
   */
  public function testExcerptHighlighting(): void {
    \Drupal::configFactory()->getEditable('searchstax.settings')
      ->set('searches_via_searchstudio', TRUE)
      ->set('discard_parameters', ['highlight'])
      ->save();

    $server_yml = file_get_contents(__DIR__ . '/../../modules/searchstax_test/config/install/search_api.server.searchstax_server.yml');
    $server = Server::create(Yaml::decode($server_yml));
    $server->save();
    $index_yml = file_get_contents(__DIR__ . '/../../modules/searchstax_test/config/install/search_api.index.searchstax_index.yml');
    $index_properties = Yaml::decode($index_yml);
    $index_properties['processor_settings']['highlight'] = [
      'prefix' => '<strong>',
      'suffix' => '</strong>',
      'excerpt' => TRUE,
    ];
    $index = Index::create($index_properties);
    $this->assertInstanceOf(Index::class, $index);
    $this->assertNotEmpty($index->getProcessor('highlight'));

    $backend = $server->getBackend();
    $this->assertInstanceOf(SearchApiSolrBackend::class, $backend);
    $solr_index_id = $backend->getTargetedIndexId($index);
    $site_hash = $backend->getTargetedSiteHash($index);

    $key_value = \Drupal::keyValue('searchstax_test');
    $expected_requests = $key_value->get('expected_requests', []);
    $solr_item_id = "$site_hash-$solr_index_id-{$this->ids[1]}";
    $pre_tag = urlencode('[HIGHLIGHT]');
    $post_tag = urlencode('[/HIGHLIGHT]');
    $expected_request = [
      // Make sure that the Solr request contains the "hl.simple.*" parameters
      // for the highlighting tags.
      'regex' => "#^emselect\\?(.+&)?hl\\.simple\\.pre=$pre_tag&hl\\.simple\\.post=$post_tag(&|\$)#",
      'core' => 'searchstax-test',
      'response' => [
        'response' => [
          'numFound' => 1,
          'start' => 0,
          'numFoundExact' => TRUE,
          'docs' => [
            [
              'ss_search_api_id' => $this->ids[1],
              'ss_search_api_language' => 'en',
              'score' => 1.0,
            ],
          ],
        ],
        'highlighting' => [
          $solr_item_id => [
            // Simulate "baz" being a synonym of "bar" and "test" being a
            // stopword.
            'tm_X3b_en_name' => [
              '[HIGHLIGHT]foo[/HIGHLIGHT] bla [HIGHLIGHT]bar[/HIGHLIGHT] bla [HIGHLIGHT]baz[/HIGHLIGHT] test',
            ],
          ],
        ],
      ],
      'count' => 1,
    ];
    $expected_requests[] = $expected_request;
    // If the parameters are not passed, return highlighting using "<em>"
    // instead.
    $expected_request['regex'] = '#^emselect\\?#';
    $expected_request['response']['highlighting'][$solr_item_id]['tm_X3b_en_name'][0] =
      '<em>foo</em> bla <em>bar</em> bla <em>baz</em> test';
    $expected_requests[] = $expected_request;
    $key_value->set('expected_requests', $expected_requests);

    $results = $index->query()
      ->keys('foo bar test')
      ->execute();

    $this->assertEquals(1, $results->getResultCount());
    $items = $results->getResultItems();
    $this->assertEquals([$this->ids[1]], array_keys($items));
    $item = $items[$this->ids[1]];
    $excerpt = $item->getExcerpt();
    $this->assertNotEmpty($excerpt);
    $this->assertStringContainsString('<strong>foo</strong>', $excerpt);
    $this->assertStringContainsString('<strong>bar</strong>', $excerpt);
    $this->assertStringContainsString('<strong>baz</strong>', $excerpt);

    // Search API before version 8.x-1.39 had a bug that would still include
    // keywords that were not highlighted by the backend. So we can only check
    // that "test" was not highlighted if the version of Search API is recent
    // enough.
    // @see https://www.drupal.org/project/search_api/issues/3031390
    $class = new \ReflectionClass(Highlight::class);
    if ($class->hasMethod('filterEmptyValuesPreserveKeys')) {
      $this->assertStringNotContainsString('<strong>test</strong>', $excerpt);
    }
  }

}
