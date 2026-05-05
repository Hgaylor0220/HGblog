<?php

declare(strict_types=1);

namespace Drupal\searchstax_test\Plugin\SolrConnector;

use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_solr\SolrConnector\SolrConnectorPluginBase;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;
use Solarium\Core\Query\QueryInterface;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\QueryType\Select\Query\Query as SelectQuery;
use Solarium\QueryType\Server\Api\Query as ApiQuery;
use Solarium\QueryType\Suggester\Query as SuggesterQuery;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a test Solr connector plugin.
 *
 * @SolrConnector(
 *    id = "searchstax_test",
 *    label = @Translation("SearchStax Test"),
 *  )
 */
class SearchStaxTestSolrConnector extends SolrConnectorPluginBase {

  /**
   * The "searchstax_test" key-value store.
   */
  protected KeyValueStoreInterface $keyValue;

  /**
   * The page cache kill switch service.
   */
  protected KillSwitch $killSwitch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $plugin->keyValue = $container->get('keyvalue')->get('searchstax_test');
    $plugin->killSwitch = $container->get('page_cache_kill_switch');
    $plugin->setMessenger($container->get('messenger'));

    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(QueryInterface $query, ?Endpoint $endpoint = NULL): ResultInterface {
    // @todo Remove all cases except "SuggesterQuery" once we depend on
    //   search_api_solr 4.3+.
    $result_class = $query->getResultClass();
    if (
      $query instanceof SelectQuery
      || $query instanceof SuggesterQuery
      || $query instanceof UpdateQuery
    ) {
      $response = $this->executeRequest($this->solr->createRequest($query), $endpoint);
      return new $result_class($query, $response);
    }
    if ($query instanceof ApiQuery) {
      $handler = $query->getOption('handler');
      if (substr($handler, -18) === '/schema/fieldtypes') {
        return new $result_class($query, new Response('{"fieldTypes": [{"name": "boolean", "class": "solr.BoolField"}]}', [
          'HTTP 200 OK',
        ]));
      }
    }
    $class = get_class($query);
    $params = json_encode($query->getParams(), JSON_UNESCAPED_SLASHES);
    $options = json_encode($query->getOptions(), JSON_UNESCAPED_SLASHES);
    $trace = [];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $call) {
      $function = "{$call['function']}()";
      if (isset($call['class'])) {
        $function = "{$call['class']}::$function";
      }
      $trace[] = $function;
    }
    $trace = implode(', ', $trace);
    throw new SearchApiSolrException("Not supported: $class. (Params: $params; Options: $options; Trace: $trace)");
  }

  /**
   * {@inheritdoc}
   */
  public function executeRequest(Request $request, ?Endpoint $endpoint = NULL): Response {
    // Explicitly stop this page from being cached since it otherwise gets
    // pretty hard to keep track of which Solr requests would have been sent.
    $this->killSwitch->trigger();

    $uri = $request->getUri();
    $expected_requests = $this->keyValue->get('expected_requests', []);
    foreach ($expected_requests as $i => $expected) {
      // Check that this has the correct Core set.
      if ($endpoint->getCore() !== $expected['core']) {
        $error = "Unexpected Solr core \"{$endpoint->getCore()}\", \"{$expected['core']}\" expected.";
        $this->messenger()->addError($error);
        throw new \RuntimeException($error);
      }

      if (preg_match($expected['regex'], $uri)) {
        // Decrement the number of remaining visits and remove the URL if they
        // were all spent.
        --$expected_requests[$i]['count'];
        if ($expected_requests[$i]['count'] === 0) {
          unset($expected_requests[$i]);
        }
        $this->keyValue->set('expected_requests', array_values($expected_requests));

        // Record this request.
        $seen_requests = $this->keyValue->get('seen_requests', []);
        $seen_requests[] = $uri;
        $this->keyValue->set('seen_requests', $seen_requests);

        // Return the response.
        return new Response(json_encode($expected['response']), [
          'HTTP 200 OK',
        ]);
      }
    }
    $error = "Unexpected Solr request: $uri";
    $this->messenger()->addError($error);
    throw new \RuntimeException($error);
  }

  /**
   * {@inheritdoc}
   */
  public function reloadCore(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDataFromHandler($handler, $reset = FALSE): array {
    $info['lucene']['solr-spec-version'] = '8.0.0';
    return $info;
  }

}
