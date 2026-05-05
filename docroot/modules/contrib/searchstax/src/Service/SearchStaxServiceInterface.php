<?php

declare(strict_types=1);

namespace Drupal\searchstax\Service;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSet;
use Drupal\search_api\ServerInterface;
use Solarium\Core\Client\Response;
use Solarium\Core\Query\QueryInterface as SolariumQueryInterface;
use Solarium\Core\Query\Result\ResultInterface;

/**
 * Provides an interface for the SearchStax utility service.
 */
interface SearchStaxServiceInterface {

  /**
   * Retrieves the active language for the given search query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return string
   *   Either the (single) language specified by the search query or the
   *   currently active global content language for this page request.
   */
  public function getSearchLanguage(QueryInterface $query): string;

  /**
   * Determines whether tracking is disabled for the current user.
   *
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface|null $cache_metadata
   *   (optional) A cache metadata object to record any cacheable dependencies
   *   of this check.
   *
   * @return bool
   *   TRUE if tracking is disabled for the current user role, FALSE otherwise.
   */
  public function isTrackingDisabled(?RefinableCacheableDependencyInterface $cache_metadata = NULL): bool;

  /**
   * Adds search results URL information to a result set.
   *
   * @param \Drupal\search_api\Query\ResultSet $result_set
   *   The result set to process.
   * @param array $build
   *   (optional) If specified, a build array to which to add cache metadata.
   * @param string|null $keys
   *   (optional) The search keys for which to track. Will be extracted from the
   *   query if not passed.
   *
   * @return bool
   *   TRUE if tracking was added to the build array, FALSE otherwise.
   */
  public function addTracking(ResultSet $result_set, array &$build = [], ?string $keys = NULL): bool;

  /**
   * Alters a Search API Solr query.
   *
   * If enabled and appropriate, re-routes all search requests through
   * SearchStudio's /emselect handler.
   *
   * Events were only introduced in the 4.2.x release cycle of the Search API
   * Solr module, so depending on the module version this will be either called
   * by our alter hook implementation or by our event subscriber.
   *
   * @param \Solarium\Core\Query\QueryInterface $solarium_query
   *   The Solr query to alter.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The underlying Search API query.
   *
   * @see \Drupal\searchstax\EventSubscriber\SearchSubscriber::preQueryAlter()
   * @see searchstax_search_api_solr_query_alter()
   */
  public function alterSolrQuery(SolariumQueryInterface $solarium_query, QueryInterface $query): void;

  /**
   * Determines whether the given search server uses SearchStax.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server.
   *
   * @return bool
   *   TRUE if the search server is linked to a SearchStax app, FALSE otherwise.
   */
  public function isSearchStaxSolrServer(ServerInterface $server): bool;

  /**
   * Determines whether the Solr server uses a SearchStax server.
   *
   * @param array $config
   *   The Solr server's configuration array.
   *
   * @return bool
   *   TRUE if the configuration points to a SearchStax Solr server, FALSE
   *   otherwise.
   */
  public function isSearchstaxSolr(array $config): bool;

  /**
   * Retrieves the fulltext keywords of the given query.
   *
   * Will return NULL for queries that shouldn't be tracked. (Queries without an
   * attached search display shouldn't be tracked.)
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return string|null
   *   The fulltext keywords (converted to a string, if necessary), if there are
   *   any and the query should be tracked. NULL otherwise.
   */
  public function getQueryKeys(QueryInterface $query): ?string;

  /**
   * Reacts to the creation of a Solarium search result.
   *
   * Fixes spellcheck functionality when using the SearchStax app handler.
   *
   * @param \Solarium\Core\Query\QueryInterface $query
   *   The executed query.
   * @param \Solarium\Core\Client\Response $response
   *   The response returned by Solr.
   * @param \Solarium\Core\Query\Result\ResultInterface $result
   *   The constructed Solr result set.
   */
  public function postCreateSolariumResult(SolariumQueryInterface $query, Response $response, ResultInterface $result): void;

  /**
   * Retrieves the autosuggest core configured for the given server, if any.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The server entity.
   *
   * @return string|null
   *   The autosuggest core configured for this server, or NULL if none was
   *   configured for it.
   *
   * @deprecated in searchstax:1.11.0 and is removed from searchstax:2.0.0. Use
   *    getAutosuggestEndpoint() instead.
   *
   * @see https://www.drupal.org/node/3582959
   */
  public function getAutosuggestCore(ServerInterface $server): ?string;

  /**
   * Retrieves the autosuggest endpoint configured for the given server, if any.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The server entity.
   *
   * @return string|null
   *   The autosuggest endpoint configured for this server, or NULL if none was
   *   configured for it.
   */
  public function getAutosuggestEndpoint(ServerInterface $server): ?string;

}
