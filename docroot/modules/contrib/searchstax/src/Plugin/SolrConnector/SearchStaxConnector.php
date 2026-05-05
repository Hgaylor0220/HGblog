<?php

namespace Drupal\searchstax\Plugin\SolrConnector;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\key\KeyRepositoryInterface;
use Drupal\search_api\Backend\BackendInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_solr\Controller\SolrConfigSetController;
use Drupal\search_api_solr\Plugin\SolrConnector\StandardSolrConnector;
use Drupal\search_api_solr\SearchApiSolrException;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Response;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\Exception\HttpException;
use Solarium\QueryType\Update\Query\Command\Add;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;

// cspell:ignore configset solrcore ulog

/**
 * Provides a plugin for connecting to a SearchStax Solr server with token auth.
 *
 * @SolrConnector(
 *   id = "searchstax",
 *   label = @Translation("SearchStax Cloud with Token Auth"),
 *   description = @Translation("Index items protected by token authentication for SearchStax."),
 * )
 */
class SearchStaxConnector extends StandardSolrConnector {

  /**
   * The minimum Solr version SearchStax might use.
   */
  public const SEARCHSTAX_MINIMUM_SOLR_VERSION = '8.11.1';

  /**
   * The maximum request size allowed by SearchStax servers, in bytes.
   */
  public const SEARCHSTAX_MAX_REQUEST_SIZE = 10485760;

  /**
   * Cached version strings, keyed by server ID.
   *
   * @var array<string, string>
   */
  protected static array $versionStrings = [];

  /**
   * Cached Solr config files, keyed by server ID.
   *
   * @var array<string, array<string, string>>
   */
  protected static array $cachedFiles = [];

  /**
   * The server to which this connector plugin is linked.
   */
  protected ServerInterface $server;

  /**
   * The Solr config set controller.
   */
  protected SolrConfigSetController $solrConfigSetController;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The key repository service, if available.
   */
  protected ?KeyRepositoryInterface $keyRepository;

  /**
   * Cached endpoint components (per-request only, never persisted).
   *
   * @var array{host: string, context: string, core: string}|null
   */
  protected ?array $cachedEndpointComponents = NULL;

  /**
   * Constructs a new class instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\search_api_solr\Controller\SolrConfigSetController $solr_config_set_controller
   *   The Solr config set controller.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\key\KeyRepositoryInterface|null $key_repository
   *   The key repository service, if available.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    SolrConfigSetController $solr_config_set_controller,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    ?KeyRepositoryInterface $key_repository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->solrConfigSetController = $solr_config_set_controller;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->keyRepository = $key_repository;

    // Try to retrieve the server entity from the call stack.
    $this->setServerFromBacktrace();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ): self {
    if ($container->has('search_api_solr.configset_controller')) {
      $solr_config_set_controller = $container->get('search_api_solr.configset_controller');
    }
    else {
      $solr_config_set_controller = new SolrConfigSetController($container->get('extension.list.module'));
    }

    // Only inject key repository if the Key module is enabled.
    $key_repository = NULL;
    if ($container->get('module_handler')->moduleExists('key')) {
      $key_repository = $container->get('key.repository');
    }

    $plugin = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $solr_config_set_controller,
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $key_repository
    );

    /** @var \Psr\Log\LoggerInterface $logger */
    $logger = $container->get('logger.channel.searchstax');
    $plugin->setLogger($logger);

    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'scheme' => 'https',
      'host' => '',
      'port' => 443,
      'context' => '',
      'core' => '',
      self::INDEX_TIMEOUT => 15,
      'update_endpoint' => '',
      'update_token' => '',
      'autosuggest_endpoint' => '',
      'key_id' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    parent::setConfiguration($configuration);

    // Clear the endpoint components cache when the config changes.
    $this->cachedEndpointComponents = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $key_unused_states = [];
    if ($this->keyRepository) {
      $empty_option = $this->t('- Do not use Key module -');
      $form['key_id'] = [
        '#type' => 'key_select',
        '#title' => $this->t('Connector Credentials Key'),
        '#empty_option' => $empty_option,
        '#default_value' => $this->configuration['key_id'] ?? '',
        '#description' => $this->t('Select the key that contains your SearchStax connector credentials (Update endpoint, Read & Write token, and optional Auto-suggest endpoint) as JSON, or select "@do_not_use" to enter the connection information directly into this form. If a key is used, any connection information entered into this form will be cleared to protect your data. Expected format of the JSON value of the key: {"update_endpoint": "https://searchcloud-2-us-east-1.searchstax.com/12345/searchstax-test/update", "update_token": "YourSecretToken", "autosuggest_endpoint": "your-autosuggest-endpoint"}. Note: "autosuggest_endpoint" is optional.', [
          '@do_not_use' => $empty_option,
        ]),
      ];
      $key_unused_states = [
        '#states' => [
          'visible' => [
            ':input[name="backend_config[connector_config][key_id]"]' => ['value' => ''],
          ],
        ],
      ];
    }

    $form['update_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Endpoint'),
      '#description' => $this->t('Just copy &amp; paste the “Update endpoint” value of the SearchStax app / index as shown in your SearchStax account.'),
      '#default_value' => $this->configuration['update_endpoint'] ?? '',
      '#required' => !$this->keyRepository,
    ] + $key_unused_states;

    $form['update_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read &amp; write token key'),
      '#description' => $this->t('Just copy &amp; paste the “Read &amp; Write token key” value of the SearchStax app / index as shown in your SearchStax account.'),
      '#default_value' => $this->configuration['update_token'] ?? '',
      '#required' => !$this->keyRepository,
    ] + $key_unused_states;

    $form['autosuggest_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Auto-suggest endpoint'),
      '#description' => $this->t('Just copy &amp; paste the “Auto-Suggest Endpoint” value of the SearchStax app as shown in your SearchStax account. (Only needed if you want to use auto-suggest.)'),
      '#default_value' => $this->configuration['autosuggest_endpoint'] ?? '',
    ] + $key_unused_states;
    if (!$this->moduleHandler->moduleExists('search_api_autocomplete')) {
      $suffix = $this->t('Install the <a href=":url">Search API Autocomplete</a> module to use the auto-suggest feature.', [
        ':url' => 'https://www.drupal.org/project/search_api_autocomplete',
      ]);
      $form['autosuggest_endpoint']['#description'] = new FormattableMarkup('@description<br />@suffix', [
        '@description' => $form['autosuggest_endpoint']['#description'],
        '@suffix' => $suffix,
      ]);
    }

    $form += parent::buildConfigurationForm($form, $form_state);

    $form['scheme'] = [
      '#type' => 'value',
      '#value' => 'https',
    ];

    $form['host'] = [
      '#type' => 'value',
      '#value' => '',
    ];

    $form['port'] = [
      '#type' => 'value',
      '#value' => '443',
    ];

    $form['path'] = [
      '#type' => 'value',
      '#value' => '/',
    ];

    $form['core'] = [
      '#type' => 'value',
      '#value' => '',
    ];

    $form['context'] = [
      '#type' => 'value',
      '#value' => '',
    ];

    $form['advanced']['jmx'] = [
      '#type' => 'value',
      '#value' => FALSE,
    ];

    $form['advanced']['solr_install_dir'] = [
      '#type' => 'value',
      '#value' => '',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    $key_id = $form_state->getValue('key_id', '');
    $key_was_used = $key_id !== '';
    if ($key_was_used) {
      // Validate that the key contains valid JSON with required fields.
      $key = $this->keyRepository->getKey($key_id);
      if ($key) {
        $credentials = json_decode($key->getKeyValue(), TRUE);

        if (json_last_error() !== JSON_ERROR_NONE) {
          $form_state->setErrorByName(
            'key_id',
            $this->t('The selected key does not contain valid JSON.'),
          );
          return;
        }
        elseif (!isset($credentials['update_endpoint']) || !isset($credentials['update_token'])) {
          $form_state->setErrorByName(
            'key_id',
            $this->t('The selected key must contain both "update_endpoint" and "update_token" fields.'),
          );
          return;
        }

        $update_endpoint = trim($credentials['update_endpoint']);
        $update_token = trim($credentials['update_token']);
      }
    }
    else {
      // Validation for setup without Key module.
      foreach (['update_endpoint', 'update_token'] as $field) {
        $values[$field] = trim($values[$field]);
        if ($values[$field] === '') {
          $form_state->setErrorByName(
            $field,
            $this->t('@name field is required if no key is selected.', [
              '@name' => $form[$field]['#title'],
            ]),
          );
        }
        $form_state->setValue($field, $values[$field]);
      }

      $update_endpoint = $values['update_endpoint'];
      $update_token = $values['update_token'];
    }

    // Common endpoint and token validation.
    if (isset($update_endpoint)) {
      if (preg_match('@https://([^/:]+)/([^/]+)/([^/]+)/(update|select)$@', $update_endpoint, $matches)) {
        // When Key module is NOT enabled, store the parsed values in form state
        // for backwards compatibility and to populate the parent connector
        // config. When the Key module IS enabled, these values will be
        // extracted at runtime from the key, so we don't store them in config
        // to avoid security issues and stale data problems.
        if (!$key_was_used) {
          $form_state->setValue('host', $matches[1]);
          $form_state->setValue('context', $matches[2]);
          $form_state->setValue('core', $matches[3]);
        }
      }
      else {
        if ($key_was_used) {
          $form_state->setErrorByName(
            'key_id',
            $this->t('The selected key contains an invalid endpoint format in "@property".', [
              '@property' => 'update_endpoint',
            ]),
          );
        }
        else {
          $form_state->setErrorByName('update_endpoint', $this->t('Invalid endpoint format.'));
        }
        return;
      }

      if (empty($update_token)) {
        if ($key_was_used) {
          $form_state->setErrorByName(
            'key_id',
            $this->t('The selected key does not contain a non-empty "@property" property.', [
              '@property' => 'update_token',
            ]),
          );
        }
        else {
          $form_state->setErrorByName('update_token', $this->t('Invalid token format.'));
        }
        return;
      }
    }

    // Check that the necessary method for setting the authorization token
    // exists.
    if (!method_exists(Endpoint::class, 'setAuthorizationToken')) {
      $error_field = $this->keyRepository ? 'key_id' : 'update_token';
      $form_state->setErrorByName($error_field, $this->t('The version of the Solarium library installed on your site does not support token authentication. Upgrade to a version newer than @version to use this Solr connector.', ['@version' => '6.2.7']));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('key_id', '') !== '') {
      $form_state->unsetValue('update_endpoint');
      $form_state->unsetValue('update_token');
      $form_state->unsetValue('autosuggest_endpoint');
    }

    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * Retrieves the update endpoint from Key or configuration.
   *
   * @return string
   *   The update endpoint.
   */
  protected function getUpdateEndpoint(): string {
    // Try to get from Key module first.
    if ($this->keyRepository && !empty($this->configuration['key_id'])) {
      $key = $this->keyRepository->getKey($this->configuration['key_id']);
      if ($key) {
        $keyValue = $key->getKeyValue();
        $credentials = json_decode($keyValue, TRUE);
        if (isset($credentials['update_endpoint'])) {
          return $credentials['update_endpoint'];
        }
      }
    }

    // Fallback to direct configuration.
    return $this->configuration['update_endpoint'] ?? '';
  }

  /**
   * Retrieves the update token from Key or configuration.
   *
   * @return string
   *   The update token.
   */
  protected function getUpdateToken(): string {
    // Try to get from Key module first.
    if ($this->keyRepository && !empty($this->configuration['key_id'])) {
      $key = $this->keyRepository->getKey($this->configuration['key_id']);
      if ($key) {
        $keyValue = $key->getKeyValue();
        $credentials = json_decode($keyValue, TRUE);
        if (isset($credentials['update_token'])) {
          return $credentials['update_token'];
        }
      }
    }

    // Fallback to direct configuration.
    return $this->configuration['update_token'] ?? '';
  }

  /**
   * Parses and caches the endpoint components from Key or configuration.
   *
   * This method caches the result per-request only to avoid repeated Key
   * lookups. Values are retrieved on-the-fly and never stored in database.
   *
   * @return array{host: string, context: string, core: string}
   *   The endpoint information.
   */
  protected function getEndpointComponents(): array {
    // Return cached value if already parsed in this request.
    if ($this->cachedEndpointComponents !== NULL) {
      return $this->cachedEndpointComponents;
    }

    // Initialize defaults from stored configuration (fallback only).
    $components = [
      'host' => $this->configuration['host'] ?? '',
      'context' => $this->configuration['context'] ?? '',
      'core' => $this->configuration['core'] ?? '',
    ];

    // Try to get from Key module by parsing the endpoint. This retrieves the
    // values on-the-fly without storing anything in the database.
    if ($this->keyRepository && !empty($this->configuration['key_id'])) {
      $endpoint = $this->getUpdateEndpoint();
      if (preg_match('@https://([^/:]+)/([^/]+)/([^/]+)/(update|select)$@', $endpoint, $matches)) {
        $components['host'] = $matches[1];
        $components['context'] = $matches[2];
        $components['core'] = $matches[3];
      }
    }

    // Cache for this request only (never persisted to database).
    $this->cachedEndpointComponents = $components;

    return $components;
  }

  /**
   * {@inheritdoc}
   */
  protected function connect(): void {
    if (!$this->solr) {
      $this->callWithEndpointConfig(function () {
        parent::connect();
      });

      // Set the authorization token.
      $this->solr->getEndpoint('search_api_solr')
        ->setAuthorizationToken('Token', $this->getUpdateToken());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getServerLink(): Link {
    return $this->callWithEndpointConfig(function () {
      return parent::getServerLink();
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getCoreLink(): Link {
    return $this->callWithEndpointConfig(function () {
      return parent::getCoreLink();
    });
  }

  /**
   * {@inheritdoc}
   */
  public function pingServer() {
    return $this->pingCore();
  }

  /**
   * {@inheritdoc}
   */
  public function getSolrVersion($force_auto_detect = FALSE) {
    if (!$force_auto_detect && !empty($this->configuration['solr_version'])) {
      return parent::getSolrVersion($force_auto_detect);
    }

    try {
      // The response from the "/[CORE]/admin/system" has a different structure
      // for SearchStax servers than Solr normally uses. We need to adapt
      // accordingly.
      $info = $this->getCoreInfo();
      if (!empty($info['solr_version'])) {
        return $info['solr_version'];
      }
    }
    catch (SearchApiSolrException $e) {
      // At this point, the parent method would call getServerInfo() as a
      // fallback. However, we know that that endpoint is blocked by SearchStax,
      // so no need to even try.
    }
    // As the fallback, return the minimum Solr version that might be used by
    // SearchStax. Otherwise, the Solr backend will fall back to compatibility
    // with Solr 6, which would lead to the use of deprecated field types in the
    // config-set.
    return self::SEARCHSTAX_MINIMUM_SOLR_VERSION;
  }

  /**
   * {@inheritdoc}
   */
  public function getServerInfo($reset = FALSE): array {
    // The "/admin/info/system" is blocked by SearchStax, which is why we
    // override getSolrVersion() to not even try and use it. However, if this
    // method is still invoked, still attempt to return something useful.
    return ['lucene' => ['solr-spec-version' => $this->getSolrVersion()]];
  }

  /**
   * {@inheritdoc}
   */
  public function getCoreInfo($reset = FALSE): array {
    return $this->callWithEndpointConfig(function () use ($reset) {
      return parent::getCoreInfo($reset);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaVersionString($reset = FALSE): string {
    $server_id = $this->getServer()->id();
    if (!isset(static::$versionStrings[$server_id])) {
      static::$versionStrings[$server_id] = 'drupal-0.0.0-solr-8.x';
      $this->connect();
      $query = $this->solr->createApi([
        'handler' => $this->getEndpointComponents()['core'] . '/schema',
      ]);

      if ($response = $this->execute($query)->getResponse()) {
        $body = json_decode($response->getBody(), TRUE);
        if (isset($body['schema']['name'])) {
          static::$versionStrings[$server_id] = $body['schema']['name'];
        }
      }
    }

    return static::$versionStrings[$server_id];
  }

  /**
   * {@inheritdoc}
   */
  public function coreRestGet($path, ?Endpoint $endpoint = NULL): array {
    if (strtolower($path) === 'schema/fieldtypes') {
      return [
        'fieldTypes' => [
          [
            'name' => 'Information about fieldTypes is not provided by SearchStax',
          ],
        ],
      ];
    }

    return $this->callWithEndpointConfig(function () use ($path, $endpoint) {
      return parent::coreRestGet($path, $endpoint);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function coreRestPost($path, $command_json = '', ?Endpoint $endpoint = NULL): array {
    return $this->callWithEndpointConfig(function () use ($path, $command_json, $endpoint) {
      return parent::coreRestPost($path, $command_json, $endpoint);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function reloadCore(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getLuke(): array {
    return [
      'fields' => [],
      'index' => ['numDocs' => -1],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getStatsSummary(): array {
    return [
      '@pending_docs' => 0,
      '@index_size' => 0,
      '@schema_version' => $this->getSchemaVersionString(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function update(UpdateQuery $query, ?Endpoint $endpoint = NULL): ResultInterface {
    // Guard against HTTP errors caused by timeouts or oversized requests.
    try {
      return parent::update($query, $endpoint);
    }
    catch (SearchApiSolrException $e) {
      $previous = $e->getPrevious();
      if (
        $previous instanceof HttpException
        && in_array($previous->getCode(), [CURLE_OPERATION_TIMEDOUT, 413])
      ) {
        $timeout_docs = NULL;
        if ($previous->getCode() === CURLE_OPERATION_TIMEDOUT) {
          $timeout_docs = 0;
          foreach ($query->getCommands() as $command) {
            if ($command instanceof Add) {
              $timeout_docs += count($command->getDocuments());
            }
          }
        }
        return $this->updateFallback($query, $endpoint, $timeout_docs);
      }
      throw $e;
    }
  }

  /**
   * Executes an update query, splitting the request according to size limit.
   *
   * @param \Solarium\QueryType\Update\Query\Query $query
   *   The Solarium update query object.
   * @param \Solarium\Core\Client\Endpoint|null $endpoint
   *   (optional) The Solarium endpoint object.
   * @param int|null $timeout_docs
   *   (optional) If the previous update request failed due to a timeout, the
   *   number of documents for which it failed; NULL otherwise.
   * @param int $retry_level
   *   (optional) Internal use only. Counts the number of nested calls to
   *   prevent an infinite recursion.
   *
   * @return \Solarium\Core\Query\Result\ResultInterface
   *   The Solarium result object.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   *   Thrown in case of any HTTP errors.
   */
  protected function updateFallback(
    UpdateQuery $query,
    ?Endpoint $endpoint = NULL,
    ?int $timeout_docs = NULL,
    int $retry_level = 0
  ): ResultInterface {
    $docs = [];
    $other_commands = [];
    $last_result = NULL;
    foreach ($query->getCommands() as $command) {
      if ($command instanceof Add) {
        $docs = array_merge($docs, $command->getDocuments());
      }
      else {
        $other_commands[] = $command;
      }
    }

    if ($other_commands) {
      $new_update_query = $this->getUpdateQuery();
      foreach ($other_commands as $command) {
        $new_update_query->add(NULL, $command);
      }
      $last_result = parent::update($new_update_query, $endpoint);
    }

    if ($timeout_docs === NULL) {
      $log_success = function (int $count): void {
        $this->getLogger()->info("Indexed a batch of @count documents in fallback after the initial indexing request exceeded the SearchStax server's maximum request size of @limit bytes.", [
          '@count' => $count,
          '@limit' => static::SEARCHSTAX_MAX_REQUEST_SIZE,
        ]);
      };
    }
    else {
      $log_success = function (int $count): void {
        $this->getLogger()->info("Indexed a batch of @count documents in fallback after the initial indexing request hit the index timeout of @timeout seconds.", [
          '@count' => $count,
          '@timeout' => $this->configuration[self::INDEX_TIMEOUT],
        ]);
      };
    }

    $batch = [];
    // The overhead is 1 byte/char as the basis (final "}") plus 15 bytes per
    // document (","/"{" and '"add":{"doc":' before the doc and "}" afterwards).
    // No, this is not a mistake: Solarium does not generate valid JSON for
    // this, it is a single object with one "add" key for each document.
    /* @see \Solarium\QueryType\Update\RequestBuilder\Json::getRawData() */
    $limit = static::SEARCHSTAX_MAX_REQUEST_SIZE - 1;
    $doc_count_limit = isset($timeout_docs) ? (int) ($timeout_docs / 2) : count($docs);
    foreach ($docs as $doc) {
      $doc_json_size = strlen(json_encode($doc)) + 15;
      $current_batch_count = count($batch);
      if ($doc_json_size > $limit || $current_batch_count >= $doc_count_limit) {
        if (!$batch) {
          $item_id = $doc['ss_search_api_id'] ?? $doc['id'] ?? NULL;
          $with_id = isset($item_id) ? " with ID \"$item_id\"" : NULL;
          throw new SearchApiSolrException("Could not index item$with_id because its size exceeded SearchStax server's maximum request size.");
        }
        $new_update_query = $this->getUpdateQuery();
        $new_update_query->addDocuments($batch);
        try {
          $last_result = parent::update($new_update_query, $endpoint);
        }
        catch (SearchApiSolrException $e) {
          // Do not recurse more than five times, or if the remaining batch size
          // is too small.
          if ($current_batch_count <= 20 || $retry_level >= 4) {
            throw $e;
          }
          // In case this error was caused by a timeout, try again with a still
          // smaller batch size.
          $previous = $e->getPrevious();
          if (
            $previous instanceof HttpException
            && $previous->getCode() === CURLE_OPERATION_TIMEDOUT
          ) {
            return $this->updateFallback(
              $query,
              $endpoint,
              $current_batch_count,
              $retry_level + 1,
            );
          }
          throw $e;
        }
        $log_success($current_batch_count);
        $batch = [];
        $limit = static::SEARCHSTAX_MAX_REQUEST_SIZE - 1;
      }
      $limit -= $doc_json_size;
      $batch[] = $doc;
    }
    if ($batch) {
      $new_update_query = $this->getUpdateQuery();
      $new_update_query->addDocuments($batch);
      $last_result = parent::update($new_update_query, $endpoint);
      $log_success(count($batch));
    }

    return $last_result;
  }

  /**
   * {@inheritdoc}
   */
  public function getFile($file = NULL) {
    $server = $this->getServer();
    $server_id = $server->id();
    if (!isset(static::$cachedFiles[$server_id])) {
      $this->solrConfigSetController->setServer($server);
      $files = $this->solrConfigSetController->getConfigFiles();
      foreach ($files as $name => $content) {
        $content = preg_replace('/"drupal-\d+\.\d+\.\d+[^"]+"/m', '"' . $this->getSchemaVersionString() . '"', $content);
        $files[$name] = [
          'body' => $content,
          'size' => strlen($content),
        ];
      }
      ksort($files);
      static::$cachedFiles[$server_id] = $files;
    }

    if (!$file) {
      return new Response(json_encode([
        'files' => static::$cachedFiles[$server_id],
      ]));
    }
    if (empty(static::$cachedFiles[$server_id][$file])) {
      throw new SearchApiSolrException('File not found');
    }
    return new Response(static::$cachedFiles[$server_id][$file]['body']);
  }

  /**
   * {@inheritdoc}
   */
  public function alterConfigFiles(array &$files, string $lucene_match_version, string $server_id = ''): void {
    parent::alterConfigFiles($files, $lucene_match_version, $server_id);

    if (strpos($files['solrconfig.xml'], 'numVersionBuckets') === FALSE) {
      $files['solrconfig.xml'] = str_replace('</updateLog>', '<int name="numVersionBuckets">${solr.ulog.numVersionBuckets:65536}</int>' . "\n</updateLog>", $files['solrconfig.xml']);
    }
    $files['solrconfig.xml'] = str_replace('{solr.autoCommit.MaxTime:15000}', '{solr.autoCommit.MaxTime:600000}', $files['solrconfig.xml']);
    $files['solrconfig.xml'] = str_replace('{solr.autoSoftCommit.MaxTime:5000}', '{solr.autoSoftCommit.maxTime:300000}', $files['solrconfig.xml']);

    // Leverage the implicit Solr request handlers with default settings for
    // Solr Cloud.
    // @see https://lucene.apache.org/solr/guide/8_0/implicit-requesthandlers.html
    $files['solrconfig_extra.xml'] = preg_replace("@<requestHandler\s+name=\"/replication\".*?</requestHandler>@s", '', $files['solrconfig_extra.xml']);
    $files['solrconfig_extra.xml'] = preg_replace("@<requestHandler\s+name=\"/get\".*?</requestHandler>@s", '', $files['solrconfig_extra.xml']);

    // Set the StatsCache.
    // @see https://lucene.apache.org/solr/guide/8_0/distributed-requests.html#configuring-statscache-distributed-idf
    if (!empty($this->configuration['stats_cache'])) {
      $files['solrconfig_extra.xml'] .= '<statsCache class="' . $this->configuration['stats_cache'] . '" />' . "\n";
    }

    // solrcore.properties won’t work in SolrCloud mode (it is not read from
    // ZooKeeper). Therefore, we go for a more specific fallback to keep the
    // possibility to set the property as parameter of the virtual machine.
    // @see https://lucene.apache.org/solr/guide/8_6/configuring-solrconfig-xml.html
    $files['solrconfig.xml'] = preg_replace('/solr.luceneMatchVersion:LUCENE_\d+/', 'solr.luceneMatchVersion:' . $this->getLuceneMatchVersion(), $files['solrconfig.xml']);
    unset($files['solrcore.properties']);
  }

  /**
   * Retrieves the server to which this connector plugin is linked.
   *
   * @return \Drupal\search_api\ServerInterface
   *   The server.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   *   Thrown if the server could not be determined.
   */
  protected function getServer(): ServerInterface {
    // First try to retrieve the server from the call stack.
    if (!isset($this->server)) {
      $this->setServerFromBacktrace();
    }
    // If that doesn't work, the only remaining approach is to load all
    // SearchStax servers and match against their connector configs.
    if (!isset($this->server)) {
      try {
        $server_storage = $this->entityTypeManager->getStorage('search_api_server');
        $server_ids = $server_storage->getQuery()
          ->condition('backend', 'search_api_solr')
          ->condition('backend_config.connector', 'searchstax')
          ->execute();
        if ($server_ids) {
          /** @var \Drupal\search_api\ServerInterface $server */
          foreach ($server_storage->loadMultiple($server_ids) as $server) {
            if (($server->getBackendConfig()['connector_config'] ?? []) === $this->configuration) {
              $this->server = $server;
              break;
            }
          }
        }
      }
      catch (\Exception $ignored) {
      }
    }
    // If that didn't work, either, we can only throw an exception to at least
    // prevent a fatal error.
    if (!isset($this->server)) {
      throw new SearchApiSolrException('Could not determine server for connector plugin.');
    }
    return $this->server;
  }

  /**
   * Attempts to extract the plugin's server from the backtrace.
   */
  protected function setServerFromBacktrace(): void {
    $options = DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS;
    $limit = 8;
    $trace = debug_backtrace($options, $limit);
    foreach ($trace as $call) {
      $object = $call['object'] ?? NULL;
      if ($object instanceof ServerInterface) {
        $this->server = $object;
        break;
      }
      if ($object instanceof BackendInterface) {
        $this->server = $object->getServer();
        break;
      }
    }
  }

  /**
   * Invokes the given callback with endpoint configuration in place.
   *
   * Can be used to call parent methods that expect specific endpoint
   * configuration to be present even when this is stored in a key.
   *
   * @param callable $callback
   *   The callback to invoke.
   *
   * @return mixed
   *   The callback's return value.
   */
  protected function callWithEndpointConfig(callable $callback) {
    if (!$this->keyRepository || empty($this->configuration['key_id'])) {
      return $callback();
    }

    $old_config = $this->configuration;
    $this->configuration = $this->getEndpointComponents() + $this->configuration;
    $ret = $callback();
    $this->configuration = $old_config;
    return $ret;
  }

}
