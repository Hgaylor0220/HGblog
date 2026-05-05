<?php

declare(strict_types=1);

namespace Drupal\searchstax\Service;

/**
 * Provides a public interface for the SearchStax API service.
 */
interface ApiInterface {

  /**
   * Determines whether the user is currently logged in.
   *
   * @return bool
   *   TRUE if a valid API token is currently available, FALSE otherwise.
   */
  public function isLoggedIn(): bool;

  /**
   * Retrieves a new valid API token from the server.
   *
   * @param string $username
   *   The username to send.
   * @param string $password
   *   The password to send.
   * @param string|null $tfa_token
   *   (optional) The TFA token, if any.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if the login was rejected or failed.
   */
  public function login(string $username, string $password, ?string $tfa_token = NULL): void;

  /**
   * Retrieves the list of accounts associated with the current login.
   *
   * @return array[]
   *   An associative array of account information, keyed by account name.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function getAccounts(): array;

  /**
   * Retrieves the list of apps associated with the given account.
   *
   * @param string $account
   *   The account name.
   *
   * @return array[]
   *   An associative array of app information, keyed by ID.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function getApps(string $account): array;

  /**
   * Retrieves data about the specified app.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   *
   * @return array
   *   An associative array of app information.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function getApp(string $account, int $app_id): array;

  /**
   * Retrieves the list of available languages.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   *
   * @return string[]
   *   An associative array mapping language codes to labels.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function getAvailableLanguages(string $account, int $app_id): array;

  /**
   * Sets the languages used by the specified app.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param array[] $languages
   *   The languages to set for the app, as a list of associative arrays with
   *   the following keys:
   *   - name: The name of the language.
   *   - language_code: The language code.
   *   - default: (optional) TRUE to set this as the default language. Has to be
   *     set for exactly one of the languages.
   *   - enabled: (optional) FALSE to add the language in disabled state.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function setLanguages(string $account, int $app_id, array $languages): array;

  /**
   * Sets the stopwords for a given app and language.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param string $langcode
   *   The language code.
   * @param string[] $stopwords
   *   A list of stopwords to set.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function setStopwords(string $account, int $app_id, string $langcode, array $stopwords): array;

  /**
   * Sets the synonyms for a given app and language.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param string $langcode
   *   The language code.
   * @param string[] $synonyms
   *   A list of synonyms to set. Each entry is either:
   *   - A comma-separated list of words. If the token matches any of the words,
   *     then all the words in the list are substituted, which will include the
   *     original token.
   *   - Two comma-separated lists of words with the symbol "=>" between them.
   *     If the token matches any word on the left, then the list on the right
   *     is substituted. The original token will not be included unless it is
   *     also in the list on the right.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function setSynonyms(string $account, int $app_id, string $langcode, array $synonyms): array;

  /**
   * Enables or disables sorting via dropdown select.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param string $langcode
   *   The language code.
   * @param bool $enabled
   *   (optional) TRUE to enable sorting via dropdown select, FALSE to disable
   *   it.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function enableSortSelect(string $account, int $app_id, string $langcode, bool $enabled = TRUE): array;

  /**
   * Sets the search sorts.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param string $langcode
   *   The language code.
   * @param array[] $sorts
   *   A list of associative arrays with the following keys:
   *   - name: The Solr field name.
   *   - order: "asc" or "desc".
   *   - label: The human-readable label for the sort.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function setSorts(string $account, int $app_id, string $langcode, array $sorts): array;

  /**
   * Sets the result fields.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param string $langcode
   *   The language code.
   * @param array[] $result_fields
   *   A list of associative arrays with the following keys:
   *   - name: The Solr field name.
   *   - title: The human-readable label for the field.
   *   - result_card: (optional) The result card to use for this field.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function setResultFields(string $account, int $app_id, string $langcode, array $result_fields): array;

  /**
   * Publishes the previously set stopwords, synonyms and result settings.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function publishStopwordsSynonymsAndResultSettings(string $account, int $app_id, string $langcode): array;

  /**
   * Retrieves a list of all configured relevance models.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function getRelevanceModels(string $account, int $app_id, string $langcode): array;

  /**
   * Retrieves the ID of the default relevance model for an app.
   *
   * Will create a new default relevance model if none exists yet.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param string $langcode
   *   The language code.
   *
   * @return int
   *   The ID of the default relevance model.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function getOrCreateDefaultRelevanceModel(string $account, int $app_id, string $langcode): int;

  /**
   * Sets the searched fields for the specified relevance model.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param string $langcode
   *   The language code.
   * @param int $model_id
   *   The relevance model ID.
   * @param string[] $fields
   *   A list of Solr field names.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function setSearchedFields(string $account, int $app_id, string $langcode, int $model_id, array $fields): array;

  /**
   * Publishes the given relevance model.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param string $langcode
   *   The language code.
   * @param int $model_id
   *   The relevance model ID.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function publishRelevanceModel(string $account, int $app_id, string $langcode, int $model_id): array;

  /**
   * Checks compatibility of a SearchStax app with a specific version of Drupal.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param int $major_version
   *   The Drupal major version for which to check compatibility.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function checkDrupalVersionCompatibility(string $account, int $app_id, int $major_version): array;

  /**
   * Upgrades a SearchStax app to be compatible with a specific Drupal version.
   *
   * @param string $account
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param int $major_version
   *   The Drupal major version with which the app should be compatible.
   *
   * @return array
   *   The server response.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  public function upgradeForDrupalVersionCompatibility(string $account, int $app_id, int $major_version): array;

  /**
   * Invalidates all cached API response data.
   */
  public function clearCache(): void;

}
