<?php

declare(strict_types=1);

namespace Drupal\Tests\searchstax\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\searchstax\Service\SearchStax;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the utility service.
 *
 * @coversDefaultClass \Drupal\searchstax\Service\SearchStax
 *
 * @group searchstax
 */
#[RunTestsInSeparateProcesses]
class UtilityServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'searchstax',
    'search_api',
    'user',
    'system',
  ];

  /**
   * The SearchStax utility service.
   *
   * @todo Make type non-nullable once we depend on Drupal 9.4+.
   */
  protected ?SearchStax $utility;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->utility = $this->container->get('searchstax.utility');
  }

  /**
   * Tests the isSearchstaxSolr() method.
   *
   * @covers ::isSearchstaxSolr
   */
  public function testIsSearchstaxSolr(): void {
    $config = [
      'connector_config' => [
        'host' => 'example.searchstax.com',
      ],
    ];
    $this->assertTrue($this->utility->isSearchstaxSolr($config));

    $config['connector_config']['host'] = 'example.solr.com';
    $this->assertFalse($this->utility->isSearchstaxSolr($config));
  }

  /**
   * Tests the stringifyComplexKeys() method.
   *
   * @covers ::stringifyComplexKeys
   */
  public function testStringifyComplexKeys(): void {
    $method = new \ReflectionMethod($this->utility, 'stringifyComplexKeys');
    if (version_compare(PHP_VERSION, '8.1', '<')) {
      $method->setAccessible(TRUE);
    }

    // Test case with simple string keys.
    $keys = ['keyword1', 'keyword2'];
    $expected = 'keyword1 keyword2';
    $this->assertEquals($expected, $method->invoke($this->utility, $keys));

    // Test case with nested array keys.
    $keys = [
      'keyword1',
      ['keyword2', 'keyword3'],
    ];
    $expected = 'keyword1 (keyword2 keyword3)';
    $this->assertEquals($expected, $method->invoke($this->utility, $keys));

    // Test case with negation.
    $keys = [
      'keyword1',
      // phpcs:ignore Squiz.Arrays.ArrayDeclaration.KeySpecified
      '#negation' => TRUE,
    ];
    $expected = '-(keyword1)';
    $this->assertEquals($expected, $method->invoke($this->utility, $keys));

    // Test case with OR conjunction.
    $keys = [
      'keyword1',
      'keyword2',
      // phpcs:ignore Squiz.Arrays.ArrayDeclaration.KeySpecified
      '#conjunction' => 'OR',
    ];
    $expected = 'keyword1 OR keyword2';
    $this->assertEquals($expected, $method->invoke($this->utility, $keys));

    // Test case with complex nested keys.
    $keys = [
      'keyword1',
      [
        'keyword2',
        ['keyword3', 'keyword4'],
      ],
    ];
    $expected = 'keyword1 (keyword2 (keyword3 keyword4))';
    $this->assertEquals($expected, $method->invoke($this->utility, $keys));
  }

}
