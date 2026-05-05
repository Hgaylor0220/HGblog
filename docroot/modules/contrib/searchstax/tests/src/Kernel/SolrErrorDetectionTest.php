<?php

namespace Drupal\Tests\searchstax\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api_solr\Plugin\SolrConnector\StandardSolrConnector;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_solr\Solarium\EventDispatcher\Psr14Bridge;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\MockObject;
use Solarium\Client as SolariumClient;
use Solarium\Core\Client\Adapter\AdapterInterface;
use Solarium\Core\Client\Response;
use Solarium\Core\Query\QueryInterface;
use Solarium\QueryType\MoreLikeThis\Query as MoreLikeThisQuery;
use Solarium\QueryType\Select\Query\Query as SelectQuery;
use Solarium\QueryType\Server\Api\Query as ApiQuery;
use Solarium\QueryType\Update\Query\Document;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;

/**
 * Tests the Solr error detection subscriber.
 *
 * @group searchstax
 *
 * @coversDefaultClass \Drupal\searchstax\EventSubscriber\SolrErrorDetectionSubscriber
 */
#[RunTestsInSeparateProcesses]
class SolrErrorDetectionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'searchstax',
    'search_api',
    'search_api_solr',
    'user',
    'system',
  ];

  /**
   * The connector plugin tested.
   *
   * @todo Make type non-nullable once we depend on Drupal 9.4+.
   */
  protected ?StandardSolrConnector $connector;

  /**
   * The mock Solr HTTP adapter.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject&\Solarium\Core\Client\Adapter\AdapterInterface|null
   *
   * @todo Make type non-nullable once we depend on Drupal 9.4+.
   */
  protected ?MockObject $adapter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->connector = new StandardSolrConnector([], '', []);

    $this->adapter = $this->createMock(AdapterInterface::class);
    // In Drupal versions before 9.1, the event dispatcher does not implement
    // the correct interface for Solarium so we need to use a wrapper (provided
    // in all Search API Solr versions compatible with those Drupal versions).
    // @todo Remove once we depend on Drupal 9.1+.
    $event_dispatcher = \Drupal::getContainer()->get('event_dispatcher');
    if (version_compare(\Drupal::VERSION, '9.1.0', '<')) {
      // Drupal < 9.1.
      $event_dispatcher = new Psr14Bridge($event_dispatcher);
    }
    $solr = new SolariumClient($this->adapter, $event_dispatcher);
    $property = new \ReflectionProperty($this->connector, 'solr');
    if (version_compare(PHP_VERSION, '8.1', '<')) {
      $property->setAccessible(TRUE);
    }
    $property->setValue($this->connector, $solr);
  }

  /**
   * Verifies that SearchStax Solr errors are reliably detected.
   *
   * @param \Solarium\Core\Query\QueryInterface $query
   *   The Solr query to send.
   * @param \Solarium\Core\Client\Response $response
   *   The response to return.
   * @param bool $expect_exception
   *   Whether to expect an exception.
   *
   * @dataProvider errorDetectionTestDataProvider
   *
   * @covers ::postCreateResult
   */
  public function testErrorDetection(
    QueryInterface $query,
    Response $response,
    bool $expect_exception
  ): void {
    $this->adapter->method('execute')->willReturn($response);
    if ($expect_exception) {
      $this->expectException(SearchApiSolrException::class);
    }
    $this->connector->execute($query);
    $this->assertTrue(TRUE);
  }

  /**
   * Provides test data sets for testErrorDetection().
   *
   * @return array<string, array{'query': \Solarium\Core\Query\QueryInterface, 'response': \Solarium\Core\Client\Response, 'expect_exception': bool}>
   *   An array of argument arrays for testErrorDetection(), keyed by data set
   *   label.
   *
   * @see testErrorDetection()
   */
  public static function errorDetectionTestDataProvider(): array {
    $faulty_doc = new Document(['id' => 'item_1', 'ds_created' => 'foobar']);
    $valid_doc = new Document(['id' => 'item_2', 'ss_type' => 'foobar']);
    return [
      'http 200 error' => [
        'query' => (new UpdateQuery())->addDocuments([$faulty_doc]),
        'response' => new Response(
          <<<'JSON'
{
    "responseHeader": {
        "rf": 2147483647,
        "status": 400,
        "QTime": 4
    },
    "error": {
        "metadata": [
            "error-class",
            "org.apache.solr.common.SolrException"
        ],
        "msg": "ERROR: [doc=item_1] Error adding field 'ds_created'='foobar' msg=Invalid Date String:'foobar'",
        "code": 400
    }
}
JSON,
          ['HTTP/1.1 200 OK'],
        ),
        'expect_exception' => TRUE,
      ],
      'missing responseHeader' => [
        'query' => (new UpdateQuery())->addDocuments([$faulty_doc]),
        'response' => new Response(
          <<<'JSON'
{
    "error": {
        "metadata": [
            "error-class",
            "org.apache.solr.common.SolrException"
        ],
        "msg": "ERROR: [doc=item_1] Error adding field 'ds_created'='foobar' msg=Invalid Date String:'foobar'",
        "code": 400
    }
}
JSON,
          ['HTTP/1.1 200 OK'],
        ),
        'expect_exception' => TRUE,
      ],
      'http 400 error' => [
        'query' => (new UpdateQuery())->addDocuments([$faulty_doc]),
        'response' => new Response(
          <<<'JSON'
{
    "responseHeader": {
        "rf": 2147483647,
        "status": 400,
        "QTime": 4
    },
    "error": {
        "metadata": [
            "error-class",
            "org.apache.solr.common.SolrException"
        ],
        "msg": "ERROR: [doc=item_1] Error adding field 'ds_created'='foobar' msg=Invalid Date String:'foobar'",
        "code": 400
    }
}
JSON,
          ['HTTP/1.1 400 User error'],
        ),
        'expect_exception' => TRUE,
      ],
      'status 0' => [
        'query' => (new UpdateQuery())->addDocuments([$valid_doc]),
        'response' => new Response(
          <<<'JSON'
{
    "responseHeader": {
        "rf": 2,
        "status": 0,
        "QTime": 21
    }
}
JSON,
          ['HTTP/1.1 200 OK'],
        ),
        'expect_exception' => FALSE,
      ],
      'status 200' => [
        'query' => (new UpdateQuery())->addDocuments([$valid_doc]),
        'response' => new Response(
          <<<'JSON'
{
    "responseHeader": {
        "rf": 2,
        "status": 200,
        "QTime": 21
    }
}
JSON,
          ['HTTP/1.1 200 OK'],
        ),
        'expect_exception' => FALSE,
      ],
      'xml response' => [
        'query' => (new SelectQuery())->setQuery('*:*')->addParam('wt', 'xml'),
        'response' => new Response(
          <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<response>

<result name="response" numFound="1" start="0" numFoundExact="true">
  <doc>
    <str name="id">item_2</str>
    <str name="ss_type">foobar</str>
  </doc>
</result>
</response>
XML,
          ['HTTP/1.1 200 OK'],
        ),
        'expect_exception' => FALSE,
      ],
      'file response' => [
        'query' => (new ApiQuery())
          ->setHandler('drupal/admin/file')
          ->addParam('file', 'stopwords.txt'),
        'response' => new Response(
          <<<'TXT'
{word}
and
or
TXT,
          ['HTTP/1.1 200 OK'],
        ),
        'expect_exception' => FALSE,
      ],
    ];
  }

  /**
   * Tests that failure of the More Like This handler is handled correctly.
   *
   * The server unfortunately returns a very unhelpful error message which we
   * want to replace with a more helpful version.
   */
  public function testMoreLikeThisFailure(): void {
    $query = (new MoreLikeThisQuery())
      ->setHandler('mlt');
    $response = new Response(
      <<<'JSON'
{
  "message": "Invalid key=value pair (missing equal-sign) in Authorization header (hashed with SHA-256 and encoded with Base64): 'abcdefghijklmnopqrstuvwxyz01234567890ABCDEF='."
}

JSON,
      ['HTTP/1.1 403 OK'],
    );

    $this->adapter->method('execute')->willReturn($response);
    $this->expectException(SearchApiSolrException::class);
    $this->expectExceptionMessage('More Like This functionality is currently not supported by SearchStax servers.');
    $this->expectExceptionCode(403);
    $this->connector->execute($query);
  }

}
