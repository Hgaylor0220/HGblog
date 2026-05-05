<?php

namespace Drupal\searchstax\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Adds a config schema for our Attachments extractor plugin.
 */
class AttachmentsExtractorSchema {

  /**
   * Implements hook_config_schema_info_alter().
   *
   * Adds a config schema for our Attachments extractor plugin.
   *
   * @see \Drupal\searchstax\Plugin\search_api_attachments\SearchStaxExtractor
   */
  #[Hook('config_schema_info_alter')]
  public function alterSchema(&$definitions): void {
    if (!empty($definitions['search_api_attachments.admin_config'])) {
      $definitions['search_api_attachments.admin_config']['mapping']['searchstax_configuration'] = [
        'type' => 'mapping',
        'label' => 'SearchStax Document Extractor configuration',
        'mapping' => [
          'endpoint' => [
            'type' => 'string',
            'label' => 'Endpoint URL',
          ],
          'token' => [
            'type' => 'string',
            'label' => 'Authentication token',
          ],
          'timeout' => [
            'type' => 'integer',
            'label' => 'Request timeout',
          ],
        ],
      ];
    }
  }

}
