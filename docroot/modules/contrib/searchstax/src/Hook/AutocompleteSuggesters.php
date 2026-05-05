<?php

namespace Drupal\searchstax\Hook;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_autocomplete\SearchApiAutocompleteException;
use Drupal\searchstax\Service\SearchStaxServiceInterface;

/**
 * Hides unsupported Autocomplete suggesters.
 */
class AutocompleteSuggesters {

  use StringTranslationTrait;

  /**
   * The plugin IDs of suggesters that are currently not supported.
   */
  protected const UNSUPPORTED_SUGGESTERS = [
    'search_api_solr_spellcheck',
    'search_api_solr_suggester',
    'search_api_solr_terms',
  ];

  /**
   * The SearchStax utility service.
   */
  protected SearchStaxServiceInterface $utility;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  public function __construct(
    SearchStaxServiceInterface $utility,
    ConfigFactoryInterface $config_factory,
    TranslationInterface $string_translation
  ) {
    $this->utility = $utility;
    $this->configFactory = $config_factory;
    $this->stringTranslation = $string_translation;
  }

  /**
   * Implements hook_form_FORM_ID_alter() for form "search_api_autocomplete_search_edit_form".
   *
   * @see \Drupal\search_api_autocomplete\Form\SearchEditForm
   */
  #[Hook('form_search_api_autocomplete_search_edit_form_alter')]
  public function alterSearchEditForm(
    &$form,
    FormStateInterface $form_state,
    string $form_id
  ): void {
    /** @var \Drupal\search_api_autocomplete\SearchInterface $search */
    $search = $form_state->getFormObject()->getEntity();

    // Make sure this search is attached to a SearchStax server.
    try {
      $server = $search->getIndex()->getServerInstance();
      if (!$server || !$this->utility->isSearchStaxSolrServer($server)) {
        return;
      }
    }
    catch (SearchApiException | SearchApiAutocompleteException $ignored) {
      return;
    }

    // Generate the list of unsupported suggesters by taking the default list
    // and removing any listed in the
    // "autocomplete.override_supported_suggesters" config value.
    $unsupported = self::UNSUPPORTED_SUGGESTERS;
    $override_supported = $this->configFactory->get('searchstax.settings')
      ->get('autocomplete.override_supported_suggesters') ?: [];
    $unsupported = array_diff($unsupported, $override_supported);

    if (!$unsupported) {
      return;
    }

    $enabled_suggesters = array_flip($search->getSuggesterIds());
    foreach ($unsupported as $suggester_id) {
      if (!isset($enabled_suggesters[$suggester_id])) {
        unset(
          $form['suggesters']['enabled']['#options'][$suggester_id],
          $form['suggesters']['enabled'][$suggester_id],
          $form['suggesters']['weights'][$suggester_id],
          $form['suggesters']['settings'][$suggester_id],
        );
      }
      else {
        $warning ??= $this->t('<strong>Warning:</strong> This suggester is not compatible with SearchStax servers and should be disabled.');
        $old_description = $form['suggesters']['enabled'][$suggester_id]['#description'];
        if ($old_description) {
          $new_description = new FormattableMarkup('@old_description @warning', [
            '@old_description' => $old_description,
            '@warning' => $warning,
          ]);
        }
        else {
          $new_description = $warning;
        }
        $form['suggesters']['enabled'][$suggester_id]['#description'] = $new_description;
      }
    }
  }

}
