<?php

namespace Drupal\search_api_attachments\Plugin\search_api_attachments;

use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\search_api_attachments\TextExtractorPluginBase;
use Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides solr extractor.
 *
 * @SearchApiAttachmentsTextExtractor(
 *   id = "solr_extractor",
 *   label = @Translation("Solr Extractor"),
 *   description = @Translation("Adds Solr extractor support."),
 * )
 */
class SolrExtractor extends TextExtractorPluginBase {

  /**
   * Entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Extract file with a search api solr backend.
   *
   * @param \Drupal\file\Entity\File $file
   *   A file object.
   *
   * @return string
   *   The text extracted from the file.
   */
  public function extract(File $file) {
    $filepath = $this->getRealpath($file->getFileUri());
    // Load the chosen Solr server entity.
    $conditions = [
      'status' => TRUE,
      'id' => $this->configuration['solr_server'],
    ];
    $server = $this->entityTypeManager->getStorage('search_api_server')->loadByProperties($conditions);
    $server = reset($server);
    // Get the Solr backend.
    /** @var \Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend $backend */
    $backend = $server->getBackend();

    if (!$backend->isAvailable()) {
      throw new \Exception('Solr Extractor is not available.');
    }

    // Extract the content.
    $xml_data = $backend->extractContentFromFile($filepath);

    return self::extractBody($xml_data);
  }

  /**
   * Extract the body from XML response.
   */
  public static function extractBody($xml_data) {
    if (!preg_match(',<body[^>]*>(.*)</body>,sim', $xml_data, $matches)) {
      // If the body can't be found return just the text. This will be safe
      // and contain any text to index.
      return strip_tags($xml_data);
    }
    // Return the full content of the body. Including tags that can optionally
    // be used for index weight.
    return $matches[1];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = [];
    $conditions = [
      'status' => TRUE,
    ];

    $search_api_solr_servers = $this->entityTypeManager->getStorage('search_api_server')->loadByProperties($conditions);
    $options = [];
    foreach ($search_api_solr_servers as $solr_server) {
      if ($solr_server->hasValidBackend() && $solr_server->getBackend() instanceof SearchApiSolrBackend) {
        $options[$solr_server->id()] = $solr_server->label();
      }
    }

    $form['solr_server'] = [
      '#type' => 'select',
      '#title' => $this->t('Solr server'),
      '#description' => $this->t('Select the solr server you want to use.'),
      '#empty_value' => '',
      '#options' => $options,
      '#default_value' => $this->configuration['solr_server'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['solr_server'] = $form_state->getValue(['text_extractor_config', 'solr_server']);
    parent::submitConfigurationForm($form, $form_state);
  }

}
