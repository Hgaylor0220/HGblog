<?php

declare(strict_types=1);

namespace Drupal\searchstax\Service;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSet;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\searchstax\Hook\ViewsSelectRelevanceModel;
use Solarium\Core\Client\Response;
use Solarium\Core\Query\QueryInterface as SolariumQueryInterface;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\Exception\UnexpectedValueException;
use Solarium\QueryType\Select\Query\Query as SolariumSelectQuery;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides utility methods for the SearchStax module.
 */
class SearchStax implements SearchStaxServiceInterface {

  // @todo Use constructor property promotion once we depend on PHP 8+.

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The current user account.
   */
  protected AccountInterface $currentUser;

  /**
   * The language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * The parse mode plugin manager.
   */
  protected ParseModePluginManager $parseModePluginManager;

  /**
   * The request stack.
   */
  protected ?KeyRepositoryInterface $keyRepository;

  /**
   * The module handler service.
   */
  protected ModuleHandlerInterface $moduleHandler;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountInterface $current_user,
    LanguageManagerInterface $language_manager,
    RequestStack $request_stack,
    ParseModePluginManager $parse_mode_plugin_manager,
    ?KeyRepositoryInterface $key_repository,
    ModuleHandlerInterface $module_handler
  ) {
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
    $this->requestStack = $request_stack;
    $this->parseModePluginManager = $parse_mode_plugin_manager;
    $this->keyRepository = $key_repository;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Retrieves the module's configuration.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The module's configuration.
   */
  protected function getConfig(): ImmutableConfig {
    return $this->configFactory->get('searchstax.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getSearchLanguage(QueryInterface $query): string {
    // Use the language of the search query, if possible, and fall back to
    // the current content language otherwise.
    $languages = array_diff((array) $query->getLanguages(), [
      LanguageInterface::LANGCODE_NOT_APPLICABLE,
      LanguageInterface::LANGCODE_NOT_SPECIFIED,
    ]);
    if (count($languages) == 1) {
      return reset($languages);
    }
    return $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();
  }

  /**
   * {@inheritdoc}
   */
  public function isTrackingDisabled(?RefinableCacheableDependencyInterface $cache_metadata = NULL): bool {
    $config = $this->getConfig();
    if ($cache_metadata) {
      $cache_metadata->addCacheableDependency($config);
    }
    $roles = $config->get('untracked_roles');
    if (!$roles) {
      return FALSE;
    }
    if ($cache_metadata) {
      $cache_metadata->addCacheContexts(['user.roles']);
    }
    return (bool) array_intersect($this->currentUser->getRoles(), $roles);
  }

  /**
   * {@inheritdoc}
   */
  public function addTracking(ResultSet $result_set, array &$build = [], ?string $keys = NULL): bool {
    $cache = CacheableMetadata::createFromRenderArray($build);

    // Double-check that tracking isn't disabled for the current user.
    if ($this->isTrackingDisabled($cache)) {
      $cache->applyTo($build);
      return FALSE;
    }

    // Extract keys if not passed already.
    $query = $result_set->getQuery();
    $keys = $keys ?? $this->getQueryKeys($query);
    if ($keys === NULL) {
      $cache->applyTo($build);
      return FALSE;
    }

    // Get the analytics key. Return early if it is not set.
    $config = $this->getConfig();
    $credentials = $this->getAnalyticsCredentials();
    $cache->addCacheableDependency($config);
    $config_key = 'search_specific_analytics_keys.' . $query->getSearchId();
    $analytics_key = $config->get($config_key) ?: $credentials['analytics_key'];
    if (!$analytics_key) {
      $cache->applyTo($build);
      return FALSE;
    }

    $offset = $query->getOption('offset', 0);
    $results = array_values($result_set->getResultItems());
    $urls = [];
    foreach ($results as $i => $item) {
      try {
        $url = $item->getDatasource()->getItemUrl($item->getOriginalObject());
      }
      catch (SearchApiException $ignored) {
        continue;
      }
      if (!$url) {
        continue;
      }
      $url = $url->toString(TRUE);
      $cache->addCacheableDependency($url);
      $urls[] = [
        'url' => $url->getGeneratedUrl(),
        'position' => $offset + $i + 1,
      ];
    }

    $page_no = 1;
    $limit = $query->getOption('limit');
    if ($limit) {
      $page_no += floor($offset / $limit);
    }
    $search_info = [
      'query' => $keys,
      'shownHits' => count($result_set->getResultItems()),
      'totalHits' => $result_set->getResultCount(),
      'pageNo' => $page_no,
      'tracked' => FALSE,
      'language' => $this->languageManager->getCurrentLanguage()->getId(),
    ];
    // For Solr searches, we also support the "latency" key as the query time
    // is already returned by Solr.
    $solr_response = $result_set->getExtraData('search_api_solr_response');
    $latency = $solr_response['responseHeader']['QTime'] ?? NULL;
    if ($latency !== NULL) {
      $search_info['latency'] = $latency;
    }

    // Include the "model" parameter, if configured in the view.
    /** @var \Drupal\views\ViewExecutable $view */
    $view = $query->getOption('search_api_view');
    if ($view) {
      $langcode = $this->getSearchLanguage($query);
      $relevance_model = ViewsSelectRelevanceModel::getViewRelevanceModel(
        $view->storage,
        $langcode,
        $view->current_display,
      );
      if ($relevance_model) {
        $search_info['model'] = $relevance_model;
      }
    }

    foreach (array_values($result_set->getResultItems()) as $i => $item) {
      $item_info = [
        'cDocId' => $item->getId(),
        'position' => $offset + $i + 1,
      ];
      try {
        $object = $item->getOriginalObject(FALSE);
        if ($object) {
          $item_info['cDocTitle'] = $item->getDatasource()
            ->getItemLabel($object);
          $cacheable_object = $object;
          if (!($cacheable_object instanceof CacheableDependencyInterface)) {
            $cacheable_object = $object->getValue();
          }
          $cache->addCacheableDependency($cacheable_object);
        }
      }
      catch (SearchApiException $ignored) {
        // The item label is not that important.
      }
      $search_info['impressions'][] = $item_info;
    }

    $tracking_info = [
      'key' => $analytics_key,
    ];

    // Add the session ID, if there is a session yet. Otherwise, we don't want
    // to create one, as that would probably end up in the static page cache,
    // which would mean different anonymous visitors would all get the same
    // session ID assigned for that page (but still a new one for each
    // different page). Instead, if no session key is passed via the settings,
    // we manually create a random new one in Javascript.
    // @todo This is not persistent for anonymous users. Is this what we want?
    if ($this->requestStack->getCurrentRequest()->hasPreviousSession()) {
      $tracking_info['session'] = $this->requestStack->getCurrentRequest()->getSession()->getId();
      $cache->addCacheContexts(['session']);
    }

    // For non-anonymous users, also include the user ID.
    $uid = $this->currentUser->id();
    $cache->addCacheContexts(['user']);
    if ($uid) {
      $tracking_info['user'] = $uid;
    }

    $build['#attached']['library'][] = 'searchstax/searchstax.tracking';
    $settings = [
      'analytics_url' => rtrim($credentials['analytics_url'], '/'),
      'js_version' => $config->get('js_version'),
      'tracking_base_data' => $tracking_info,
      'searches' => [
        $query->getSearchId() => $search_info,
      ],
    ];
    if ($urls) {
      $settings['results_urls'][$query->getSearchId()] = $urls;
    }
    // Include config settings for the EU Cookie Compliance module integration.
    if ($this->moduleHandler->moduleExists('eu_cookie_compliance')) {
      $settings['eu_cookie_compliance'] = $config->get('eu_cookie_compliance');
      $cookie_config = $this->configFactory->get('eu_cookie_compliance.settings');
      if (
        !$config->get('eu_cookie_compliance')
        || $cookie_config->get('method') !== 'categories'
      ) {
        unset($settings['eu_cookie_compliance']['category']);
      }
    }
    $build['#attached']['drupalSettings']['searchstax'] = $settings;
    $cache->applyTo($build);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterSolrQuery(SolariumQueryInterface $solarium_query, QueryInterface $query): void {
    try {
      // If this is not a select query, don't alter it.
      if (($solarium_query->getHandler() ?: 'select') !== 'select'
        || !($solarium_query instanceof SolariumSelectQuery)) {
        return;
      }

      // Include the response header so we can use it to obtain the latency.
      $solarium_query->setOmitHeader(FALSE);

      // Check whether this is even a SearchStax server, and not a regular Solr
      // server.
      $solr_config = $query->getIndex()->getServerInstance()->getBackendConfig();
      if (!$this->isSearchstaxSolr($solr_config)) {
        return;
      }

      // Check whether user enabled re-routing searches to SearchStudio.
      $config = $this->getConfig();
      $enabled = $config->get('searches_via_searchstudio');
      if (!$enabled) {
        return;
      }

      $solarium_query->setHandler('emselect');
      $langcode = $this->getSearchLanguage($query);
      $solarium_query->addParam('language', $langcode);

      // Check whether this search is based on a view and whether the view is
      // configured to use a specific relevance model.
      /** @var \Drupal\views\ViewExecutable $view */
      $view = $query->getOption('search_api_view');
      if ($view) {
        $langcode = $this->getSearchLanguage($query);
        $relevance_model = ViewsSelectRelevanceModel::getViewRelevanceModel(
          $view->storage,
          $langcode,
          $view->current_display,
        );
        if ($relevance_model) {
          $solarium_query->addParam('model', $relevance_model);
        }
      }

      $discard_parameters = (array) $config->get('discard_parameters');
      if (in_array('keys', $discard_parameters)) {
        // Make sure search keywords are passed as-is, not re-written.
        /** @var \Drupal\search_api\ParseMode\ParseModeInterface $direct */
        $direct = $this->parseModePluginManager->createInstance('direct');
        $query->setParseMode($direct);
        // For some reason, "direct" parse mode is ignored unless
        // defType=edismax is explicitly set.
        $solarium_query->addParam('defType', 'edismax');

        // However, as we don't want query fields ("qf" parameter) passed, we
        // don't actually want the edismax component on the Solarium query.
        // (This way, the Solr backend plugin will also remove the "defType"
        // parameter we set above for us.)
        $solarium_query->removeComponent(SolariumSelectQuery::COMPONENT_EDISMAX);
      }
      elseif (empty($solarium_query->getParams()['defType'])) {
        $solarium_query->addParam('defType', 'lucene');
      }

      // Also remove other feature components or sorts as configured.
      if (in_array('facets', $discard_parameters)) {
        $solarium_query->removeComponent(SolariumSelectQuery::COMPONENT_FACETSET);
      }
      if (in_array('highlight', $discard_parameters)) {
        /** @var \Solarium\Component\Highlighting\Highlighting $highlighting */
        $highlighting = $solarium_query->getComponent(SolariumSelectQuery::COMPONENT_HIGHLIGHTING);
        if ($highlighting) {
          // We still want to include the custom highlighting tags sent in the
          // request so the backend can correctly extract the highlighted words
          // (in case highlighting is enabled in the SearchStax app).
          if ($highlighting->getSimplePrefix()) {
            $solarium_query->addParam('hl.simple.pre', $highlighting->getSimplePrefix());
          }
          if ($highlighting->getSimplePostfix()) {
            $solarium_query->addParam('hl.simple.post', $highlighting->getSimplePostfix());
          }

          $solarium_query->removeComponent(SolariumSelectQuery::COMPONENT_HIGHLIGHTING);
        }
      }
      if (in_array('sort', $discard_parameters)) {
        $solarium_query->clearSorts();
      }
      if (in_array('spellcheck', $discard_parameters)) {
        /** @var \Solarium\Component\SpellcheckInterface $spellcheck */
        $spellcheck = $solarium_query->getComponent(SolariumSelectQuery::COMPONENT_SPELLCHECK);
        if ($spellcheck) {
          if ($spellcheck->getQuery() !== NULL) {
            $spellcheck_keys = mb_strtolower($spellcheck->getQuery());
            $solarium_query->addParam('spellcheck.q', $spellcheck_keys);
          }
          $solarium_query->removeComponent(SolariumSelectQuery::COMPONENT_SPELLCHECK);
        }
      }
    }
    catch (SearchApiException | PluginException $ignored) {
      // Very unlikely, but just ignore if it happens – will cause other errors
      // later in the page request.
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isSearchStaxSolrServer(ServerInterface $server): bool {
    try {
      $backend = $server->getBackend();
    }
    catch (SearchApiException $ignored) {
      return FALSE;
    }
    if (!($backend instanceof SolrBackendInterface)) {
      return FALSE;
    }
    return $this->isSearchstaxSolr($backend->getConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function isSearchstaxSolr(array $config): bool {
    // If our own connector plugin is used we can always assume that this is a
    // SearchStax server. (This also helps circumvent false negatives when the
    // Key module is used.)
    if (($config['connector'] ?? '') === 'searchstax') {
      return TRUE;
    }
    $host = $config['connector_config']['host'] ?? '';
    $host_parts = explode('.', $host);
    $n = count($host_parts);
    // If host is "localhost" or similar,
    // $host_parts just contains a single item.
    if ($n < 2) {
      return FALSE;
    }
    $host_suffix = $host_parts[$n - 2] . '.' . $host_parts[$n - 1];
    return in_array($host_suffix, ['searchstax.com', 'searchstax.co']);
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryKeys(QueryInterface $query): ?string {
    if (!$query->getDisplayPlugin()) {
      return NULL;
    }
    $keys = $query->getOriginalKeys();
    if (is_array($keys)) {
      $keys = $this->stringifyComplexKeys($keys);
    }
    return $keys;
  }

  /**
   * Extracts search keywords from an array.
   *
   * @param array $keys
   *   The keywords, in the array format described in
   *   \Drupal\search_api\ParseMode\ParseModeInterface::parseInput().
   *
   * @return string|null
   *   A string representing the keywords. Or NULL if there were no keywords.
   */
  protected function stringifyComplexKeys(array $keys): ?string {
    $extracted = [];
    foreach ($keys as $i => $key) {
      if (!Element::child($i)) {
        continue;
      }
      if (is_string($key) && $key !== '') {
        $extracted[] = $key;
      }
      elseif (is_array($key)) {
        $key = $this->stringifyComplexKeys($key);
        if (!$key) {
          continue;
        }
        if (substr($key, 0, 2) !== '-(') {
          $key = "($key)";
        }
        $extracted[] = $key;
      }
    }

    if (!$extracted) {
      return NULL;
    }

    $glue = ($keys['#conjunction'] ?? 'AND') == 'AND' ? ' ' : ' OR ';
    $extracted_keys = implode($glue, $extracted);
    if (!empty($keys['#negation'])) {
      $extracted_keys = "-($extracted_keys)";
    }
    return $extracted_keys;
  }

  /**
   * {@inheritdoc}
   */
  public function postCreateSolariumResult(SolariumQueryInterface $query, Response $response, ResultInterface $result): void {
    // If the user chose to configure spellcheck via SearchStax then we removed
    // the spellcheck component above, in alterSolrQuery(), but might still get
    // spellcheck data in the response. To trick the Solr backend into correctly
    // extracting this data, we re-add the spellcheck component to the query.
    // @see \Drupal\search_api_solr\SolrSpellcheckBackendTrait::extractSpellCheckSuggestions()
    try {
      if (
        $query instanceof SolariumSelectQuery
        && !empty($result->getData()['spellcheck'])
      ) {
        $query->getSpellcheck();
      }
    }
    catch (UnexpectedValueException $ignored) {
      // Unfortunately, Solarium doesn't handle non-JSON responses correctly.
      // Just catch the exception here and ignore this case: In general, the
      // Solr module will never request non-JSON responses, so the feature
      // should still work in almost all cases.
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAutosuggestCore(ServerInterface $server): ?string {
    @trigger_error('\Drupal\searchstax\Service\SearchStax::getAutosuggestCore() is deprecated in searchstax:1.11.0 and is removed from searchstax:2.0.0. Use getAutosuggestEndpoint() instead. See https://www.drupal.org/node/3582959', E_USER_DEPRECATED);

    $endpoint = $this->getAutosuggestEndpoint($server);
    if ($endpoint === NULL) {
      return NULL;
    }
    $parts = explode('/', $endpoint);
    array_pop($parts);
    return array_pop($parts);
  }

  /**
   * {@inheritdoc}
   */
  public function getAutosuggestEndpoint(ServerInterface $server): ?string {
    // Bail if this is not a SearchStax server.
    if (!$this->isSearchStaxSolrServer($server)) {
      return NULL;
    }

    $backend_config = $server->getBackendConfig();
    $connector_config = $backend_config['connector_config'];

    // If the server is using our own connector plugin (for apps that use Token
    // Auth) the "autosuggest_core" should be part of the connector config,
    // either directly or in a key.
    if (($backend_config['connector'] ?? '') === 'searchstax') {
      // If the Key module is enabled and the "key_id" config property is set,
      // attempt to retrieve the "autosuggest_core" setting from the key.
      if ($this->keyRepository && !empty($connector_config['key_id'])) {
        $key = $this->keyRepository->getKey($connector_config['key_id']);
        if ($key) {
          $keyValue = $key->getKeyValue();
          $credentials = json_decode($keyValue, TRUE);
          if (isset($credentials['autosuggest_endpoint'])) {
            return $credentials['autosuggest_endpoint'];
          }
          if (isset($credentials['autosuggest_core'])) {
            return static::createAutosuggestEndpointFromCore($credentials);
          }
        }
      }

      // Otherwise, return the "autosuggest_endpoint" connector config value, if
      // set. (This will fall through to also check the third-party settings,
      // just in case the core is set there. Probably no harm in that.)
      if (!empty($connector_config['autosuggest_endpoint'])) {
        return $connector_config['autosuggest_endpoint'];
      }
      if (!empty($connector_config['autosuggest_core'])) {
        return static::createAutosuggestEndpointFromCore($connector_config);
      }
    }

    // For other (Basic Auth) SearchStax servers, the setting would be nested in
    // the third-party settings.
    $settings = $server->getThirdPartySettings('searchstax');
    if (!empty($settings['autosuggest_endpoint'])) {
      return $settings['autosuggest_endpoint'];
    }
    if (!empty($settings['autosuggest_core'])) {
      $connector_config['autosuggest_core'] = $settings['autosuggest_core'];
      return static::createAutosuggestEndpointFromCore($connector_config);
    }

    // Finally, fall back to the (deprecated) global "autosuggest_core" setting.
    $global_core = $this->getConfig()->get('autosuggest_core');
    if ($global_core !== NULL) {
      $connector_config['autosuggest_core'] = $global_core;
      return static::createAutosuggestEndpointFromCore($connector_config);
    }
    return NULL;
  }

  /**
   * Creates an Auto-Suggest endpoint URL by adapting the Update endpoint.
   *
   * Uses the (deprecated) "autosuggest_core" config key.
   *
   * @param array $connector_config
   *   The connector plugin configuration, including a (non-empty)
   *   "autosuggest_core" key.
   *
   * @return string|null
   *   The Auto-Suggest endpoint URL, or NULL if none could be constructed.
   *
   * @internal
   */
  public static function createAutosuggestEndpointFromCore(array $connector_config): ?string {
    $core = $connector_config['autosuggest_core'];

    if (!empty($connector_config['update_endpoint'])) {
      $update_endpoint = $connector_config['update_endpoint'];
      $parts = explode('/', $update_endpoint);
      $parts[count($parts) - 2] = $core;
      $parts[count($parts) - 1] = 'emsuggest';
      return implode('/', $parts);
    }

    if (empty($connector_config['host'])) {
      return NULL;
    }
    $url = "https://{$connector_config['host']}";
    $url .= $connector_config['path'] ?? '/';
    $url .= $connector_config['context'] ?? 'solr';
    $url .= "/$core/emsuggest";
    return $url;
  }

  /**
   * Retrieves the API credentials from either the Key module or configuration.
   *
   * @return array{analytics_url: string, analytics_key: string}
   *   The credentials.
   */
  protected function getAnalyticsCredentials(): array {
    $config = $this->configFactory->get('searchstax.settings');

    if ($this->keyRepository) {
      $key_id = $config->get('key_id');

      if (!empty($key_id) && $key_id !== '_none') {
        $key = $this->keyRepository->getKey($key_id);
        if ($key) {
          $key_value = $key->getKeyValue();
          if (!empty($key_value)) {
            $credentials = json_decode($key_value, TRUE);
            return [
              'analytics_url' => $credentials['analytics_url'] ?? '',
              'analytics_key' => $credentials['analytics_key'] ?? '',
            ];
          }
        }
      }
    }

    // Use direct configuration values.
    return [
      'analytics_url' => $config->get('analytics_url'),
      'analytics_key' => $config->get('analytics_key'),
    ];
  }

}
