<?php

namespace Drupal\searchstax\Plugin\search_api_attachments;

use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\search_api_attachments\TextExtractorPluginBase;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a text extractor that uses the SearchStax Document Extractor API.
 *
 * @SearchApiAttachmentsTextExtractor(
 *   id = "searchstax",
 *   label = @Translation("SearchStax Document Extractor"),
 *   description = @Translation("Uses the SearchStax Document Extractor API."),
 * )
 */
class SearchStaxExtractor extends TextExtractorPluginBase {

  /**
   * The file extensions currently supported by the Document Extractor.
   */
  public const SUPPORTED_EXTENSIONS = [
    '.csv',
    '.doc',
    '.docx',
    '.json',
    '.pdf',
    '.ppt',
    '.pptx',
    '.tsv',
    '.txt',
    '.xls',
    '.xlsx',
  ];

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ): self {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->httpClient = $container->get('http_client');
    $instance->configuration += $instance->defaultConfiguration();
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'endpoint' => '',
      'token' => '',
      'timeout' => 30,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['endpoint'] = [
      '#type' => 'textfield',
      '#title' => t('Endpoint URL'),
      '#description' => t('The URL of the Document Extractor endpoint to use.'),
      '#default_value' => $this->configuration['endpoint'],
      '#required' => TRUE,
    ];

    $form['token'] = [
      '#type' => 'textfield',
      '#title' => t('Authentication token'),
      '#description' => t('The authentication token for the endpoint.'),
      '#default_value' => $this->configuration['token'],
      '#required' => TRUE,
    ];

    $form['timeout'] = [
      '#type' => 'number',
      '#min' => 1,
      '#max' => 180,
      '#title' => $this->t('Request timeout'),
      '#description' => $this->t('The timeout in seconds for requests sent to the extraction endpoint.'),
      '#default_value' => $this->configuration['timeout'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['endpoint'] = $form_state->getValue(['text_extractor_config', 'endpoint']);
    $this->configuration['token'] = $form_state->getValue(['text_extractor_config', 'token']);
    $this->configuration['timeout'] = $form_state->getValue(['text_extractor_config', 'timeout']);
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function extract(File $file): ?string {
    // Bail early in case the given file extension is not supported.
    preg_match('#\.[^./]+$#', $file->getFileUri(), $matches);
    if (!in_array($matches[0] ?? NULL, self::SUPPORTED_EXTENSIONS, TRUE)) {
      return NULL;
    }

    // During initial configuration of the Search API Attachments settings the
    // config values might still be empty.
    if (empty($this->configuration['endpoint'])) {
      throw new \Exception('No endpoint URL set for SearchStax Document Extractor.');
    }

    $response = $this->httpClient->request('POST', $this->configuration['endpoint'], [
      'timeout' => $this->configuration['timeout'],
      'multipart' => [
        [
          'name' => 'file',
          'contents' => fopen($file->getFileUri(), 'r'),
        ],
      ],
      'headers' => [
        'Authorization' => "Token {$this->configuration['token']}",
      ],
      // Disable Guzzle's automatic HTTP exceptions so we can create a custom
      // message in case of an error response.
      'http_errors' => FALSE,
    ]);

    $body = (string) $response->getBody();
    $data = json_decode($body, TRUE);
    if ($response->getStatusCode() === 200 && isset($data['text'])) {
      return $data['text'];
    }
    $message = "SearchStax Document Extractor failed with status code {$response->getStatusCode()}";
    if (!empty($data['message'])) {
      $message .= ": {$data['message']}";
    }
    if (!empty($data['code'])) {
      $message .= " [{$data['code']}]";
    }
    throw new \Exception($message);
  }

}
