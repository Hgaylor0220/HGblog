<?php

namespace Drupal\Tests\searchstax\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api_solr\Controller\SolrConfigSetController;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\searchstax\Plugin\SolrConnector\SearchStaxConnector;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\MockObject;
use Solarium\Client as SolariumClient;
use Solarium\Core\Query\QueryInterface;
use Solarium\Exception\HttpException;
use Solarium\Plugin\CustomizeRequest\CustomizeRequest;
use Solarium\QueryType\Update\Query\Command\Add as AddCommand;
use Solarium\QueryType\Update\Query\Document;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;
use Solarium\QueryType\Update\Result as UpdateResult;

/**
 * Tests the fallback functionality for index requests with too many documents.
 *
 * @coversDefaultClass \Drupal\searchstax\Plugin\SolrConnector\SearchStaxConnector
 *
 * @group searchstax
 */
#[RunTestsInSeparateProcesses]
class IndexingFallbackTest extends KernelTestBase {

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
  protected ?SearchStaxConnector $connector;

  /**
   * The mock Solr client.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject&\Solarium\Client|null
   *
   * @todo Make type non-nullable once we depend on Drupal 9.4+.
   */
  protected ?MockObject $solr;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->connector = new SearchStaxConnector(
      [],
      '',
      [],
      $this->createMock(SolrConfigSetController::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      // Key repository not needed for this test.
      NULL,
    );
    $this->solr = $this->createMock(SolariumClient::class);
    $this->solr->method('getPlugin')->willReturnCallback(function (string $key) {
      $this->assertEquals('customizerequest', $key);
      return new CustomizeRequest();
    });
    $this->solr->method('createUpdate')->willReturnCallback(function () {
      return new UpdateQuery();
    });
    $property = new \ReflectionProperty($this->connector, 'solr');
    if (version_compare(PHP_VERSION, '8.1', '<')) {
      $property->setAccessible(TRUE);
    }
    $property->setValue($this->connector, $this->solr);
  }

  /**
   * Tests the indexing fallback functionality.
   *
   * @param int $num_docs
   *   The number of documents to send for indexing.
   * @param list<array{'expected_docs': array{0: int, 1: int}, 'return': int}> $expected_solr_requests
   *   The Solr update request expected to be sent to the Solr client.
   * @param bool $expect_exception
   *   (optional) TRUE if the call to update() is expected to result in an
   *   exception (after all $expected_solr_requests have been sent).
   * @param int $doc_size
   *   (optional) The size, in bytes, of each document. The default is a little
   *   more than 0.1 MB, meaning that 99 documents can fit into a single update
   *   request before SearchStax's maximum request size is reached.
   *
   * @covers ::updateFallback
   *
   * @dataProvider updateFallbackTestDataProvider
   */
  public function testUpdateFallback(
    int $num_docs,
    array $expected_solr_requests,
    bool $expect_exception = FALSE,
    int $doc_size = 104858
  ): void {
    $data = (object) ['expected_requests' => $expected_solr_requests];

    $execute_callback = function (QueryInterface $query) use ($data) {
      $this->assertInstanceOf(UpdateQuery::class, $query);
      $expected_request = array_shift($data->expected_requests);
      $this->assertNotEmpty($expected_request);
      ['expected_docs' => $expected_docs, 'return' => $status] = $expected_request;
      [$min_id, $max_id] = $expected_docs;
      $commands = $query->getCommands();
      $this->assertCount(1, $commands);
      $command = reset($commands);
      $this->assertInstanceOf(AddCommand::class, $command);
      $expected_ids = array_flip(range($min_id, $max_id));
      foreach ($command->getDocuments() as $document) {
        $id = $document->getFields()['item_id'];
        $this->assertStringStartsWith('doc:', $id);
        $id_num = substr($id, 4);
        $this->assertIsNumeric($id_num);
        $this->assertArrayHasKey($id_num, $expected_ids);
        unset($expected_ids[$id_num]);
      }
      $this->assertEmpty($expected_ids);

      if ($status === 200) {
        return $this->createMock(UpdateResult::class);
      }
      $http_exception = new HttpException('', $status);
      throw new SearchApiSolrException('', $status, $http_exception);
    };
    $this->solr->method('execute')->willReturnCallback($execute_callback);

    $documents = [];
    for ($i = 1; $i <= $num_docs; ++$i) {
      $fields = [
        'item_id' => "doc:$i",
        'tm_body' => '',
      ];
      $body_size = $doc_size - strlen(',"add":' . json_encode(['doc' => $fields]));
      $fields['tm_body'] = str_repeat('a', $body_size);
      $documents[] = new Document($fields);
    }
    $update_query = new UpdateQuery();
    $update_query->addDocuments($documents);

    try {
      $this->connector->update($update_query);
      $this->assertFalse($expect_exception);
    }
    catch (SearchApiSolrException $e) {
      $this->assertTrue($expect_exception);
    }
    $this->assertEmpty($data->expected_requests, count($data->expected_requests) . ' expected request(s) remaining.');
  }

  /**
   * Provides test data sets for testUpdateFallback().
   *
   * @return list<array>
   *   A list of argument arrays for testUpdateFallback().
   *
   * @see testUpdateFallback()
   */
  public static function updateFallbackTestDataProvider(): array {
    return [
      [
        'num_docs' => 300,
        'expected_solr_requests' => [
          [
            'expected_docs' => [1, 300],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 99],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 49],
            'return' => 200,
          ],
          [
            'expected_docs' => [50, 98],
            'return' => 200,
          ],
          [
            'expected_docs' => [99, 147],
            'return' => 200,
          ],
          [
            'expected_docs' => [148, 196],
            'return' => 200,
          ],
          [
            'expected_docs' => [197, 245],
            'return' => 200,
          ],
          [
            'expected_docs' => [246, 294],
            'return' => 200,
          ],
          [
            'expected_docs' => [295, 300],
            'return' => 200,
          ],
        ],
      ],
      [
        'num_docs' => 300,
        'expected_solr_requests' => [
          [
            'expected_docs' => [1, 300],
            'return' => 413,
          ],
          [
            'expected_docs' => [1, 99],
            'return' => 200,
          ],
          [
            'expected_docs' => [100, 198],
            'return' => 200,
          ],
          [
            'expected_docs' => [199, 297],
            'return' => 200,
          ],
          [
            'expected_docs' => [298, 300],
            'return' => 200,
          ],
        ],
      ],
      [
        'num_docs' => 10,
        'expected_solr_requests' => [
          [
            'expected_docs' => [1, 10],
            'return' => 400,
          ],
        ],
        'expect_exception' => TRUE,
      ],
      [
        'num_docs' => 300,
        'expected_solr_requests' => [
          [
            'expected_docs' => [1, 300],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 99],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 49],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 24],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 12],
            'return' => 28,
          ],
        ],
        'expect_exception' => TRUE,
      ],
      [
        'num_docs' => 2048,
        'expected_solr_requests' => [
          [
            'expected_docs' => [1, 2048],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 1024],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 512],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 256],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 128],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 64],
            'return' => 28,
          ],
        ],
        'expect_exception' => TRUE,
        // Smaller docs so we will never run into the max request size.
        'doc_size' => 1024,
      ],
      [
        'num_docs' => 60,
        'expected_solr_requests' => [
          [
            'expected_docs' => [1, 60],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 30],
            'return' => 28,
          ],
          [
            'expected_docs' => [1, 15],
            'return' => 28,
          ],
        ],
        'expect_exception' => TRUE,
      ],
    ];
  }

}
