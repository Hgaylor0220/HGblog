<?php

declare(strict_types=1);

namespace Drupal\searchstax_test_mock_api\Mock;

use Drupal\searchstax\Service\ApiInterface;

// cspell:ignore défaut deuxième erstes zweites

/**
 * Provides a mock API service implementation with fixed return values.
 */
class MockApiService implements ApiInterface {

  /**
   * {@inheritdoc}
   */
  public function isLoggedIn(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function login(string $username, string $password, ?string $tfa_token = NULL): void {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function getAccounts(): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function getApps(string $account): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function getApp(string $account, int $app_id): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailableLanguages(string $account, int $app_id): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function setLanguages(string $account, int $app_id, array $languages): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function setStopwords(string $account, int $app_id, string $langcode, array $stopwords): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function setSynonyms(string $account, int $app_id, string $langcode, array $synonyms): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function enableSortSelect(string $account, int $app_id, string $langcode, bool $enabled = TRUE): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function setSorts(string $account, int $app_id, string $langcode, array $sorts): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function setResultFields(string $account, int $app_id, string $langcode, array $result_fields): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function publishStopwordsSynonymsAndResultSettings(string $account, int $app_id, string $langcode): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function getRelevanceModels(string $account, int $app_id, string $langcode): array {
    assert($account === 'account1');
    assert($app_id === 123);
    switch ($langcode) {

      case 'en':
        return [
          [
            'id' => 101,
            'name' => 'default',
            'default' => TRUE,
            'status' => 'published',
          ],
          [
            'id' => 102,
            'name' => 'experiment1',
            'default' => FALSE,
            'status' => '(dot) published',
          ],
          [
            'id' => 103,
            'name' => 'experiment2',
            'default' => FALSE,
            'status' => 'published',
          ],
        ];

      case 'de':
        return [
          [
            'id' => 201,
            'name' => 'Standard',
            'default' => FALSE,
            'status' => 'published',
          ],
          [
            'id' => 202,
            'name' => 'Erstes Experiment',
            'default' => TRUE,
            'status' => '(dot) published',
          ],
          [
            'id' => 203,
            'name' => 'Zweites Experiment',
            'default' => FALSE,
            'status' => 'draft',
          ],
        ];

      case 'fr':
        return [
          [
            'id' => 301,
            'name' => 'défaut',
            'default' => FALSE,
            'status' => 'draft',
          ],
          [
            'id' => 302,
            'name' => 'Premier test',
            'default' => FALSE,
            'status' => '(dot) published',
          ],
          [
            'id' => 303,
            'name' => 'Deuxième test',
            'default' => TRUE,
            'status' => '(dot) published',
          ],
        ];

      default:
        throw new \RuntimeException("Unexpected language code \"$langcode\"");

    }
  }

  /**
   * {@inheritdoc}
   */
  public function getOrCreateDefaultRelevanceModel(string $account, int $app_id, string $langcode): int {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
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
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function publishRelevanceModel(string $account, int $app_id, string $langcode, int $model_id): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function checkDrupalVersionCompatibility(string $account, int $app_id, int $major_version): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function upgradeForDrupalVersionCompatibility(string $account, int $app_id, int $major_version): array {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

  /**
   * {@inheritdoc}
   */
  public function clearCache(): void {
    throw new \RuntimeException(__FUNCTION__ . '() not implemented');
  }

}
