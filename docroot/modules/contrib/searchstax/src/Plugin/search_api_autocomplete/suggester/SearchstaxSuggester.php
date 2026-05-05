<?php

declare(strict_types=1);

namespace Drupal\searchstax\Plugin\search_api_autocomplete\suggester;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Error;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_autocomplete\Attribute\SearchApiAutocompleteSuggester;
use Drupal\search_api_autocomplete\SearchApiAutocompleteException;
use Drupal\search_api_autocomplete\SearchInterface;
use Drupal\search_api_autocomplete\Suggester\SuggesterPluginBase;
use Drupal\search_api_autocomplete\Suggestion\Suggestion;
use Drupal\search_api_autocomplete\Suggestion\SuggestionFactory;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\search_api_solr\SolrConnectorInterface;
use Drupal\searchstax\Service\SearchStaxServiceInterface;
use Psr\Log\LoggerInterface;
use Solarium\Core\Client\Endpoint;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides autocomplete suggestions using the Searchstax "/emsuggest" endpoint.
 *
 * @SearchApiAutocompleteSuggester(
 *   id = "searchstax",
 *   label = @Translation("SearchStax"),
 *   description = @Translation("Uses the SearchStudio ""/emsuggest"" endpoint to create suggestions."),
 * )
 */
#[SearchApiAutocompleteSuggester('searchstax', new TranslatableMarkup('SearchStax'), new TranslatableMarkup('Uses the SearchStudio "/emsuggest" endpoint to create suggestions.'))]
class SearchstaxSuggester extends SuggesterPluginBase {

  /**
   * The SearchStax utility service.
   *
   * @var \Drupal\searchstax\Service\SearchStaxServiceInterface
   */
  protected SearchStaxServiceInterface $searchstaxUtility;

  /**
   * The logging channel to use.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function supportsSearch(SearchInterface $search): bool {
    try {
      return (bool) \Drupal::getContainer()->get('searchstax.utility')
        ->getAutosuggestEndpoint($search->getIndex()->getServerInstance());
    }
    // @todo Remove variable once we depend on PHP 8+.
    /* @noinspection PhpUnusedLocalVariableInspection */
    catch (SearchApiException | SearchApiAutocompleteException $ignored) {
      // Ignore, just return FALSE.
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $plugin->setSearchStaxUtility($container->get('searchstax.utility'));
    $plugin->setLogger($container->get('logger.factory')->get('searchstax'));

    return $plugin;
  }

  /**
   * Retrieves the SearchStax utility service.
   *
   * @return \Drupal\searchstax\Service\SearchStaxServiceInterface
   *   The SearchStax utility service.
   */
  public function getSearchStaxUtility(): SearchStaxServiceInterface {
    return $this->searchstaxUtility ?? \Drupal::service('searchstax.utility');
  }

  /**
   * Sets the SearchStax utility service.
   *
   * @param \Drupal\searchstax\Service\SearchStaxServiceInterface $searchstax_utility
   *   The new SearchStax utility service.
   *
   * @return $this
   */
  public function setSearchStaxUtility(SearchStaxServiceInterface $searchstax_utility): self {
    $this->searchstaxUtility = $searchstax_utility;
    return $this;
  }

  /**
   * Retrieves the logger.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger.
   */
  public function getLogger(): LoggerInterface {
    return $this->logger ?? \Drupal::logger('searchstax');
  }

  /**
   * Sets the logger.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The new logger.
   *
   * @return $this
   */
  public function setLogger(LoggerInterface $logger): self {
    $this->logger = $logger;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAutocompleteSuggestions(QueryInterface $query, $incomplete_key, $user_input): array {
    $suggestions = [];
    try {
      $index = $query->getIndex();
      $backend = static::getSolrBackend($index);
      if (!$backend) {
        return $suggestions;
      }
      $connector = $backend->getSolrConnector();
      $suggester_query = $connector->getSuggesterQuery();
      $suggester_query->setHandler('emsuggest');
      $suggester_query->addParam('q', $user_input);
      $langcode = $this->getSearchStaxUtility()->getSearchLanguage($query);
      $suggester_query->addParam('language', $langcode);
      $server = $index->getServerInstance();
      $endpoint = $this->createAutosuggestEndpoint($server, $connector);
      if (!$endpoint) {
        return $suggestions;
      }
      $response = $connector->execute($suggester_query, $endpoint);
      $data = $response->getData();
      // Actual data is nested three levels down (suggest.suggester1[user_input]
      // – but no point in hard-coding that). Just "suggest" needs to be
      // hard-coded as that will have a sibling property, "metadata". All other
      // objects just have one property so it's easy to drill down.
      if (isset($data['suggest'])) {
        $data = $data['suggest'];
      }
      while (count($data) === 1) {
        $tmp = reset($data);
        if (!is_array($tmp)) {
          break;
        }
        $data = $tmp;
      }

      $suggestion_factory = new SuggestionFactory($user_input);
      foreach ($data['suggestions'] ?? [] as $suggestion) {
        if (!empty($suggestion['term'])) {
          $term = strip_tags($suggestion['term']);
          if (preg_match('#(.*)<b>(.*)</b>(.*)#', $suggestion['term'], $m)) {
            $suggestions[] = (new Suggestion($term))
              ->setSuggestionPrefix($m[1])
              ->setUserInput($m[2])
              ->setSuggestionSuffix($m[3]);
          }
          else {
            $suggestions[] = $suggestion_factory->createFromSuggestedKeys($term);
          }
        }
      }
    }
    catch (SearchApiException $e) {
      // @todo Remove once we depend on Drupal 10.1+.
      if (method_exists(Error::class, 'logException')) {
        Error::logException($this->getLogger(), $e, '%type while fetching autocomplete suggestions: @message in %function (line %line of %file).');
      }
      else {
        /* @noinspection PhpUndefinedFunctionInspection */
        watchdog_exception('searchstax', $e, '%type while fetching autocomplete suggestions: @message in %function (line %line of %file).');
      }
    }
    return $suggestions;
  }

  /**
   * Retrieves the Solr backend plugin associated with the given index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return \Drupal\search_api_solr\SolrBackendInterface|null
   *   The backend plugin of the Solr server attached to the index. Or NULL if
   *   the index isn't attached to a Solr server.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   Thrown if an error occurred while retrieving the server backend plugin.
   */
  protected static function getSolrBackend(IndexInterface $index): ?SolrBackendInterface {
    if (!$index->hasValidServer()) {
      return NULL;
    }
    $server = $index->getServerInstance();
    $backend = $server->getBackend();
    if ($backend instanceof SolrBackendInterface) {
      return $backend;
    }
    return NULL;
  }

  /**
   * Creates a Solarium endpoint for the given server's auto-suggest endpoint.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server.
   * @param \Drupal\search_api_solr\SolrConnectorInterface $connector
   *   The Solr connector.
   *
   * @return \Solarium\Core\Client\Endpoint|null
   *   The endpoint, or NULL if necessary data was missing.
   */
  protected function createAutosuggestEndpoint(ServerInterface $server, SolrConnectorInterface $connector): ?Endpoint {
    $endpoint_url = $this->getSearchStaxUtility()->getAutosuggestEndpoint($server);
    if (!$endpoint_url) {
      $this->getLogger()->warning("Could not use SearchStax autocomplete suggester because server %server (@server_id) has no auto-suggest endpoint set.", [
        '%server' => $server->label() ?: $server->id(),
        '@server_id' => $server->id(),
      ]);
      return NULL;
    }
    $parsed_url = parse_url($endpoint_url);
    $path_components = explode('/', $parsed_url['path'] ?? '');
    if (empty($parsed_url['host']) || count($path_components) < 3) {
      $this->getLogger()->warning("Could not use SearchStax autocomplete suggester because the auto-suggest endpoint configured for server %server (@server_id) is malformed.", [
        '%server' => $server->label() ?: $server->id(),
        '@server_id' => $server->id(),
      ]);
      return NULL;
    }
    $endpoint = clone $connector->getEndpoint();
    $endpoint->setHost($parsed_url['host'])
      ->setContext($path_components[1])
      ->setCore($path_components[2]);
    return $endpoint;
  }

}
