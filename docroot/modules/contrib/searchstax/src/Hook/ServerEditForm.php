<?php

namespace Drupal\searchstax\Hook;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\search_api\ServerInterface;
use Drupal\searchstax\Form\SettingsForm;
use Drupal\searchstax\Service\SearchStaxServiceInterface;

/**
 * Provides hook implementations for the SearchStax module.
 */
class ServerEditForm {

  use StringTranslationTrait;

  /**
   * The SearchStax utility service.
   */
  protected SearchStaxServiceInterface $searchstaxUtility;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  public function __construct(
    SearchStaxServiceInterface $searchstaxUtility,
    ModuleHandlerInterface $module_handler,
    TranslationInterface $string_translation
  ) {
    $this->searchstaxUtility = $searchstaxUtility;
    $this->moduleHandler = $module_handler;
    $this->stringTranslation = $string_translation;
  }

  /**
   * Implements hook_form_FORM_ID_alter() for form "search_api_server_edit_form".
   */
  #[Hook('form_search_api_server_edit_form_alter')]
  public function alterServerEditForm(&$form, FormStateInterface $form_state, string $form_id): void {
    // We need to restrict by form ID here because this function is also called
    // via hook_form_BASE_FORM_ID_alter.
    if (!in_array($form_id, ['search_api_server_form', 'search_api_server_edit_form'])) {
      return;
    }
    $form_builder = $form_state->getFormObject();
    if (!($form_builder instanceof EntityFormInterface)) {
      return;
    }
    $server = $form_builder->getEntity();
    if (
      !($server instanceof ServerInterface)
      || !$this->searchstaxUtility->isSearchStaxSolrServer($server)
      || ($server->getBackendConfig()['connector'] ?? '') === 'searchstax'
      || empty($form['backend_config']['advanced'])
    ) {
      return;
    }

    $element = [
      '#type' => 'textfield',
      '#title' => $this->t('Auto-suggest endpoint'),
      '#description' => $this->t('Just copy &amp; paste the “Auto-Suggest Endpoint” value of the SearchStax app as shown in your SearchStax account. (Only needed if you want to use auto-suggest.)'),
      '#default_value' => $server->getThirdPartySetting('searchstax', 'autosuggest_endpoint'),
      '#parents' => [
        'third_party_settings',
        'searchstax',
        'autosuggest_endpoint',
      ],
    ];
    if (!$this->moduleHandler->moduleExists('search_api_autocomplete')) {
      $suffix = $this->t('Install the <a href=":url">Search API Autocomplete</a> module to use the auto-suggest feature.', [
        ':url' => 'https://www.drupal.org/project/search_api_autocomplete',
      ]);
      $element['#description'] = new FormattableMarkup('@description<br />@suffix', [
        '@description' => $element['#description'],
        '@suffix' => $suffix,
      ]);
    }
    $form['backend_config']['advanced']['searchstax_autosuggest_endpoint'] = $element;
  }

  /**
   * Validates input for the "Auto-suggest core" form field.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @deprecated in searchstax:1.11.0 and is removed from searchstax:2.0.0.
   *   There.is no replacement.
   *
   * @see https://www.drupal.org/node/3582959
   */
  public static function validateAutosuggestCore(array &$element, FormStateInterface $form_state): void {
    @trigger_error('\Drupal\searchstax\Hook\ServerEditForm::validateAutosuggestCore() is deprecated in searchstax:1.11.0 and is removed from searchstax:2.0.0. There.is no replacement. See https://www.drupal.org/node/3582959', E_USER_DEPRECATED);
    SettingsForm::validateAutosuggestCore($element, $form_state);
  }

}
