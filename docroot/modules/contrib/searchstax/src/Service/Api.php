<?php

declare(strict_types=1);

namespace Drupal\searchstax\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\searchstax\Exception\NotLoggedInException;
use Drupal\searchstax\Exception\SearchStaxException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

/**
 * Provides a service for making API calls to SearchStax.
 */
class Api implements ApiInterface {

  /**
   * The base URL for v1 of the Experience Manager REST API.
   */
  public const BASE_URL_EM_V1 = 'https://app.searchstax.com/api/rest/experience-manager/v1';

  /**
   * The base URL for v2 of the Experience Manager REST API.
   */
  public const BASE_URL_EM_V2 = 'https://app.searchstax.com/api/rest/experience-manager/v2';

  /**
   * The base URL for v1 of the REST API.
   */
  public const BASE_URL_V1 = self::BASE_URL_EM_V1;

  /**
   * The base URL for v2 of the REST API.
   */
  public const BASE_URL_V2 = 'https://app.searchstax.com/api/rest/v2';

  /**
   * The prefix for all cache IDs used by this class.
   */
  protected const CACHE_PREFIX = 'searchstax:api:';

  /**
   * The key used for storing the API token in the key-value store.
   */
  protected const AUTH_TOKEN_KEY = 'api.auth_token';

  /**
   * Cache tags placed on all cache items of this class.
   */
  protected const CACHE_TAGS = ['searchstax_api'];

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * The cache backend.
   */
  protected CacheBackendInterface $cacheBackend;

  /**
   * The expirable key-value store factory.
   */
  protected KeyValueExpirableFactoryInterface $keyValueExpirableFactory;

  /**
   * The cache tags invalidator.
   */
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * The language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * The currently valid auth token.
   */
  protected ?string $authToken;

  /**
   * The timestamp at which the current auth token will expire.
   */
  protected ?int $authTokenExpiry;

  /**
   * Constructs a new class instance.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value_expirable_factory
   *   The expirable key-value store factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   *   The cache tags invalidator.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    ClientInterface $http_client,
    KeyValueExpirableFactoryInterface $key_value_expirable_factory,
    CacheBackendInterface $cache_backend,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
    LanguageManagerInterface $language_manager,
    TimeInterface $time
  ) {
    $this->httpClient = $http_client;
    $this->keyValueExpirableFactory = $key_value_expirable_factory;
    $this->cacheBackend = $cache_backend;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
    $this->languageManager = $language_manager;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public function isLoggedIn(): bool {
    if (isset($this->authToken)) {
      assert(isset($this->authTokenExpiry));
      return TRUE;
    }
    $key_value = $this->getKeyValueExpirable();
    if (!$key_value->has(self::AUTH_TOKEN_KEY)) {
      return FALSE;
    }
    $data = $key_value->get(self::AUTH_TOKEN_KEY);
    $this->authToken = $data['token'];
    $this->authTokenExpiry = (int) $data['expire'];
    // Send a test request to see if the login is really still valid.
    try {
      $this->sendApiRequest('GET', '/account/', [], NULL, self::BASE_URL_V2);
    }
    catch (SearchStaxException $e) {
      $this->authToken = $this->authTokenExpiry = NULL;
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function login(string $username, string $password, ?string $tfa_token = NULL): void {
    $body = [
      'username' => $username,
      'password' => $password,
    ];
    if ($tfa_token !== NULL) {
      $body['tfa_token'] = $tfa_token;
    }
    $data = $this->sendApiRequest('POST', '/obtain-auth-token/', [], $body, self::BASE_URL_V2, FALSE);
    if (empty($data['token'])) {
      throw new SearchStaxException("Invalid JSON returned from server: The \"token\" key is missing.\nResponse: " . json_encode($data));
    }

    // At a new login, invalidate all cache items.
    $this->clearCache();

    $this->authToken = $data['token'];
    $this->authTokenExpiry = $this->time->getCurrentTime() + 86400;
    $stored_data = [
      'token' => $this->authToken,
      'expire' => $this->authTokenExpiry,
    ];
    $this->getKeyValueExpirable()
      ->setWithExpire(self::AUTH_TOKEN_KEY, $stored_data, 86400);
  }

  /**
   * {@inheritdoc}
   */
  public function getAccounts(): array {
    return $this->getCachedData('accounts', function () {
      $data = $this->sendApiRequest('GET', '/account/', [], NULL, self::BASE_URL_V2);
      $accounts = [];
      // @todo The response contains "next"/"previous" keys, so there might be
      //   paging?
      foreach ($data['results'] ?? [] as $account) {
        $accounts[$account['name']] = $account;
      }
      return $accounts;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getApps(string $account): array {
    return $this->getCachedData("apps:$account", function () use ($account) {
      $data = $this->sendApiRequest('GET', '/apps', ['account' => $account]);
      $apps = [];
      foreach ($data as $app) {
        $apps[$app['id']] = $app;
      }
      return $apps;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getApp(string $account, int $app_id): array {
    $apps = $this->getApps($account);
    if (empty($apps[$app_id])) {
      throw new SearchStaxException("Account $account has no app with ID $app_id.");
    }
    return $apps[$app_id];
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailableLanguages(string $account, int $app_id): array {
    return $this->getCachedData("available_languages:$account:$app_id", function () use ($account, $app_id) {
      $response = $this->sendApiRequest(
        'GET',
        '/studio-languages',
        [
          'account' => $account,
          'appId' => $app_id,
        ]
      );
      return array_column($response['data'], 'name', 'language_code');
    });
  }

  /**
   * {@inheritdoc}
   */
  public function setLanguages(string $account, int $app_id, array $languages): array {
    $response = $this->sendApiRequest(
      'PUT',
      '/apps/lang',
      [
        'account' => $account,
        'appId' => $app_id,
      ],
      $languages
    );
    $langcodes = array_column($languages, 'language_code');
    $added = array_diff($response['languages'], $langcodes);
    $missing = array_diff($langcodes, $response['languages']);
    if ($added || $missing) {
      $errors = [
        'Setting app languages failed.',
      ];
      if ($added) {
        $errors[] = 'The following languages were enabled in the app even though they were not specified: ' . implode(', ', $added) . '.';
      }
      if ($missing) {
        $errors[] = 'The following languages were not enabled in the app even though they were specified: ' . implode(', ', $missing) . '.';
      }
      throw new SearchStaxException(implode("\n", $errors));
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function setStopwords(string $account, int $app_id, string $langcode, array $stopwords): array {
    return $this->sendApiRequest(
      'POST',
      '/apps/stopwords',
      [
        'account' => $account,
        'appId' => $app_id,
        'language' => $langcode,
      ],
      [
        'stopwords' => $stopwords,
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setSynonyms(string $account, int $app_id, string $langcode, array $synonyms): array {
    return $this->sendApiRequest(
      'POST',
      '/apps/synonyms',
      [
        'account' => $account,
        'appId' => $app_id,
        'language' => $langcode,
      ],
      [
        'synonyms' => $synonyms,
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function enableSortSelect(string $account, int $app_id, string $langcode, bool $enabled = TRUE): array {
    return $this->sendApiRequest(
      'POST',
      '/apps/search/sort',
      [
        'account' => $account,
        'appId' => $app_id,
        'language' => $langcode,
      ],
      [
        'enabled' => $enabled,
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setSorts(string $account, int $app_id, string $langcode, array $sorts): array {
    return $this->sendApiRequest(
      'POST',
      '/apps/search/sort/fields',
      [
        'account' => $account,
        'appId' => $app_id,
        'language' => $langcode,
      ],
      [
        'fields' => $sorts,
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setResultFields(string $account, int $app_id, string $langcode, array $result_fields): array {
    return $this->sendApiRequest(
      'POST',
      '/apps/search/results/fields',
      [
        'account' => $account,
        'appId' => $app_id,
        'language' => $langcode,
      ],
      [
        'fields' => $result_fields,
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function publishStopwordsSynonymsAndResultSettings(string $account, int $app_id, string $langcode): array {
    return $this->sendApiRequest(
      'POST',
      '/apps/config/publish',
      [
        'account' => $account,
        'appId' => $app_id,
        'language' => $langcode,
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getRelevanceModels(string $account, int $app_id, string $langcode): array {
    return $this->getCachedData(
      "relevance_models:$account:$app_id:$langcode",
      function () use ($account, $app_id, $langcode) {
        return $this->sendApiRequest('GET', '/apps/models', [
          'account' => $account,
          'appId' => $app_id,
          'language' => $langcode,
        ]);
      }
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getOrCreateDefaultRelevanceModel(string $account, int $app_id, string $langcode): int {
    return $this->getCachedData(
      "default_relevance_model:$account:$app_id:$langcode",
      function () use ($account, $app_id, $langcode) {
        foreach ($this->getRelevanceModels($account, $app_id, $langcode) as $model) {
          if (!empty($model['default'])) {
            return (int) $model['id'];
          }
        }
        // The name needs to be unique per app, so use different names for
        // different languages.
        $response = $this->sendApiRequest(
          'POST',
          '/apps/models',
          [
            'account' => $account,
            'appId' => $app_id,
            'language' => $langcode,
          ],
          ['name' => "Default ($langcode)"],
        );
        return (int) $response['model_id'];
      }
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setSearchedFields(
    string $account,
    int $app_id,
    string $langcode,
    int $model_id,
    array $fields
  ): array {
    return $this->sendApiRequest(
      'POST',
      '/apps/search/query/fields',
      [
        'account' => $account,
        'appId' => $app_id,
        'language' => $langcode,
        'modelId' => $model_id,
      ],
      [
        'fields' => $fields,
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function publishRelevanceModel(string $account, int $app_id, string $langcode, int $model_id): array {
    return $this->sendApiRequest(
      'POST',
      '/apps/models/publish',
      [
        'account' => $account,
        'appId' => $app_id,
        'language' => $langcode,
        'modelId' => $model_id,
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkDrupalVersionCompatibility(string $account, int $app_id, int $major_version): array {
    return $this->sendApiRequest(
      'GET',
      '/apps/check-version-compatibility/',
      [
        'account' => $account,
        'appId' => $app_id,
        'version' => $major_version,
      ],
      NULL,
      static::BASE_URL_EM_V2
    );
  }

  /**
   * {@inheritdoc}
   */
  public function upgradeForDrupalVersionCompatibility(string $account, int $app_id, int $major_version): array {
    return $this->sendApiRequest(
      'POST',
      '/apps/upgrade/',
      [
        'account' => $account,
        'appId' => $app_id,
      ],
      [
        'version' => $major_version,
      ],
      static::BASE_URL_EM_V2
    );
  }

  /**
   * {@inheritdoc}
   */
  public function clearCache(): void {
    $this->cacheTagsInvalidator->invalidateTags(self::CACHE_TAGS);
  }

  /**
   * Retrieves this module's expirable key-value store.
   *
   * @return \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   *   The expirable key-value store.
   */
  protected function getKeyValueExpirable(): KeyValueStoreExpirableInterface {
    return $this->keyValueExpirableFactory->get('searchstax');
  }

  /**
   * Sends an API request.
   *
   * @param string $method
   *   The HTTP method to use.
   * @param string $path
   *   The path to which to send the request.
   * @param array $params
   *   (optional) An associative array of GET parameters to pass in the request.
   *   Unless the "language" parameter is included it will be set to the current
   *   site language.
   * @param array|null $body
   *   (optional) A JSON response body to send in the request.
   * @param string $base_url
   *   (optional) The base URL for the request. Will be prepended to $path to
   *   determine the actual request URL.
   * @param bool $send_auth_token
   *   (optional) TRUE to include an "Authorization: Token $TOKEN" header with
   *   the currently active login. Will throw NotLoggedInException if there is
   *   no active login.
   *
   * @return array
   *   The parsed JSON response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown in case of any errors.
   */
  protected function sendApiRequest(
    string $method,
    string $path,
    array $params = [],
    ?array $body = NULL,
    string $base_url = self::BASE_URL_V1,
    bool $send_auth_token = TRUE
  ): array {
    $url = "$base_url$path?" . http_build_query($params);
    $headers = [
      'Referer' => 'https://searchstudio.searchstax.com/',
    ];
    $json_body = NULL;
    if ($body !== NULL) {
      $headers['Content-type'] = 'application/json';
      $json_body = json_encode($body);
    }
    if ($send_auth_token) {
      if (!$this->isLoggedIn()) {
        throw new NotLoggedInException();
      }
      $headers['Authorization'] = "Token {$this->authToken}";
    }
    $request = new Request($method, $url, $headers, $json_body);
    try {
      $response = $this->httpClient->send($request, [
        RequestOptions::TIMEOUT => 120,
      ]);
      $body = $response->getBody()->getContents();
      $data = NULL;
      $content_type = $response->getHeader('Content-type')[0] ?? '';
      if (strpos($content_type, 'json') !== FALSE) {
        $data = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
      }
      $detail = $data['detail'] ?? $data['message'] ?? '';
      if ($response->getStatusCode() !== 200 || $data === NULL) {
        // Attempt to spot an invalid auth token.
        if (
          $send_auth_token
          && $response->getStatusCode() === 403
          && $detail === 'Invalid token'
        ) {
          throw new NotLoggedInException();
        }
        $reason = $detail ?: $body ?: $response->getReasonPhrase();
        $message = "HTTP {$response->getStatusCode()} response from server: $reason";
        throw new SearchStaxException($message, $response->getStatusCode(), NULL, $data);
      }
    }
    catch (GuzzleException $e) {
      throw SearchStaxException::fromPrevious($e);
    }
    catch (\JsonException $e) {
      throw new SearchStaxException("Invalid JSON returned from server: {$e->getMessage()}.\nResponse: $body", 0, $e);
    }
    if (!($data['success'] ?? TRUE)) {
      throw new SearchStaxException($detail ?: 'Request failed');
    }
    return $data;
  }

  /**
   * Retrieves data from a cache or a callback.
   *
   * If the data is retrieved via the callback, the result will be cached.
   *
   * @param string $cid_suffix
   *   The cache ID suffix. Will automatically be prefixed with
   *   self::CACHE_PREFIX.
   * @param callable $callback
   *   The callback for retrieving the requested data, in case it was not found
   *   in the cache.
   *
   * @return mixed
   *   The (cached) result of the callback.
   */
  protected function getCachedData(string $cid_suffix, callable $callback) {
    $cid = self::CACHE_PREFIX . $cid_suffix;
    $cache = $this->cacheBackend->get($cid);
    if ($cache) {
      return $cache->data;
    }
    $data = $callback();
    $this->cacheBackend->set($cid, $data, $this->authTokenExpiry, self::CACHE_TAGS);
    return $data;
  }

}
