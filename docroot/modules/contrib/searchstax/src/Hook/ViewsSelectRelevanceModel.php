<?php

namespace Drupal\searchstax\Hook;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\search_api\SearchApiException;
use Drupal\searchstax\Exception\NotLoggedInException;
use Drupal\searchstax\Exception\SearchStaxException;
use Drupal\searchstax\Form\ApiLoginFormTrait;
use Drupal\searchstax\Service\ApiInterface;
use Drupal\searchstax\Service\SearchStaxServiceInterface;
use Drupal\searchstax\Service\VersionCheckInterface;
use Drupal\views\ViewEntityInterface;

/**
 * Provides admins with the option to select relevance models for search views.
 */
class ViewsSelectRelevanceModel {

  use ApiLoginFormTrait {
    showLogInForm as showLogInFormTrait;
    validateLoginForm as validateLoginFormTrait;
    submitLoginForm as submitLoginFormTrait;
  }
  use DependencySerializationTrait;
  use MessengerTrait;
  use StringTranslationTrait;

  /**
   * The Views UI section to which the option will be added.
   */
  protected const VIEWS_UI_SECTION = 'query';

  /**
   * The SearchStax utility service.
   */
  protected SearchStaxServiceInterface $utility;

  /**
   * The SearchStax version check service.
   */
  protected VersionCheckInterface $versionCheck;

  /**
   * The language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * Constructs a new class instance.
   *
   * @param \Drupal\searchstax\Service\ApiInterface $searchStaxApi
   *   The SearchStax API service.
   * @param \Drupal\searchstax\Service\SearchStaxServiceInterface $utility
   *   The SearchStax utility service.
   * @param \Drupal\searchstax\Service\VersionCheckInterface $versionCheck
   *   The SearchStax version check service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    ApiInterface $searchStaxApi,
    SearchStaxServiceInterface $utility,
    VersionCheckInterface $versionCheck,
    MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
    LanguageManagerInterface $languageManager
  ) {
    $this->searchStaxApi = $searchStaxApi;
    $this->utility = $utility;
    $this->versionCheck = $versionCheck;
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
    $this->languageManager = $languageManager;
  }

  /**
   * Retrieves the relevance model to use for the given view and language.
   *
   * @param \Drupal\views\ViewEntityInterface $view
   *   The view in question.
   * @param string $langcode
   *   The code of the current language.
   * @param string|null $display_id
   *   (optional) The Views display ID for which to retrieve the setting, or
   *   NULL to retrieve it for the Default display.
   *
   * @return string
   *   The relevance model selected for the given Views display and language. An
   *   empty string if no model should be explicitly passed.
   */
  public static function getViewRelevanceModel(
    ViewEntityInterface $view,
    string $langcode,
    ?string $display_id = NULL
  ): string {
    $settings = $view->getThirdPartySettings('searchstax');
    // Take care of overridden query settings.
    if (
      $display_id !== NULL
      && !static::usesDefaultDisplayOptions($view, $display_id)
      && isset($settings['relevance_model_overrides'][$display_id])
    ) {
      return $settings['relevance_model_overrides'][$display_id][$langcode] ?? '';
    }
    return $settings['relevance_models'][$langcode] ?? '';
  }

  /**
   * Implements hook_form_FORM_ID_alter() for form "views_ui_edit_display_form".
   *
   * Alters the Views query settings form for views based on SearchStax servers.
   */
  #[Hook('form_views_ui_edit_display_form_alter')]
  public function alterForm(&$form, FormStateInterface $form_state): void {
    $section = $form_state->get('section');
    if ($section !== static::VIEWS_UI_SECTION) {
      return;
    }

    /** @var \Drupal\views_ui\ViewUI $view */
    $view = $form_state->get('view');
    $display_id = $form_state->get('display_id');

    // Determine whether this view is linked to a SearchStax server.
    $query = $view->getExecutable()->getQuery();
    if (!($query instanceof SearchApiQuery)) {
      return;
    }
    try {
      $server = $query->getIndex()->getServerInstance();
    }
    catch (SearchApiException $ignored) {
      return;
    }
    if (!$this->utility->isSearchStaxSolrServer($server)) {
      return;
    }

    $subform = &$form['options'][$section]['options']['searchstax'];
    $subform = [
      '#type' => 'fieldset',
      '#title' => $this->t('SearchStax settings'),
    ];
    if (!$this->searchStaxApi->isLoggedIn()) {
      $this->showLogInForm($subform, $form_state);
      return;
    }
    $subform['messages'] = [
      '#type' => 'status_messages',
      '#weight' => -10,
    ];
    try {
      $app_info = $this->versionCheck->getAppInformation($server);
      if (!$app_info) {
        throw new SearchStaxException("Could not determine SearchStax app for server \"{$server->id()}\"");
      }
      foreach ($this->languageManager->getLanguages() as $langcode => $language) {
        $language_name = $language->getName();
        try {
          $models = $this->searchStaxApi->getRelevanceModels(
            $app_info->getAccount(),
            $app_info->getAppId(),
            $langcode,
          );
        }
        catch (SearchStaxException $e) {
          // Just capture the case that the current language is not configured
          // in the SearchStax app.
          if (
            $e instanceof NotLoggedInException
            || substr($e->getMessage(), 0, 44) !== 'Results configuration does not exist for App'
          ) {
            throw $e;
          }
          $subform['relevance_models'][$langcode] = [
            '#type' => 'item',
            '#title' => $this->t('Relevance model for %language.', [
              '%language' => $language_name,
            ]),
            '#description' => $this->t('No relevance models are available for %language in this SearchStax app.', [
              '%language' => $language_name,
            ]),
          ];
          continue;
        }
        $options = [
          '' => $this->t('- Use default -'),
        ];
        foreach ($models as $model) {
          if (!in_array($model['status'], ['published', '(dot) published'])) {
            continue;
          }
          $name = $model['name'];
          if (!empty($model['default'])) {
            $name = $this->t('@model (default)', ['@model' => $name]);
          }
          $options[$model['name']] = $name;
        }
        $default_value = $this->getViewRelevanceModel($view, $langcode, $display_id);
        // In earlier versions of the module we incorrectly saved the model ID
        // instead of its name. Translate the saved IDs to names in that case.
        if (
          $default_value !== ''
          && !isset($options[$default_value])
          && preg_match('/^\d+$/', $default_value)
        ) {
          foreach ($models as $model) {
            if ($model['id'] == $default_value) {
              $default_value = $model['name'];
              $this->messenger()->addWarning($this->t('The relevance model settings for this view/display have been migrated from an earlier version of the module. Please re-save this form and the view to have the settings take effect for searches.'));
              break;
            }
          }
        }
        $subform['relevance_models'][$langcode] = [
          '#type' => 'radios',
          '#title' => $this->t('Relevance model for %language.', [
            '%language' => $language_name,
          ]),
          '#description' => $this->t('The relevance model that should be used for %language searches with this view.', [
            '%language' => $language_name,
          ]),
          '#options' => $options,
          '#default_value' => $default_value,
        ];
      }
    }
    catch (NotLoggedInException $e) {
      $this->showLogInForm($subform, $form_state);
      return;
    }
    catch (SearchStaxException $e) {
      unset($form['options'][$section]['options']['searchstax']);
      $this->messenger()->addError($this->t('Error while retrieving available relevance models from SearchStax server: @message.', [
        '@message' => $e->getMessage(),
      ]));
      return;
    }
    array_unshift($form['actions']['submit']['#submit'], [$this, 'submitViewsQueryForm']);
  }

  /**
   * Submit handler for our additions to the Views query form.
   */
  public function submitViewsQueryForm(array &$form, FormStateInterface $form_state): void {
    $section = $form_state->get('section');
    if ($section !== static::VIEWS_UI_SECTION) {
      return;
    }

    /** @var \Drupal\views_ui\ViewUI $view */
    $view = $form_state->get('view');
    $display_id = $form_state->get('display_id');
    $is_default_display = $display_id === 'default';

    // If there is only a single display in this view, the "override" dropdown
    // might not be shown, in which case getOverrideValues() returns pretty
    // useless values.
    if ($form_state->hasValue(['override', 'dropdown'])) {
      [$was_defaulted, $is_defaulted, $revert] = $view->getOverrideValues($form, $form_state);
    }
    else {
      $was_defaulted = $is_defaulted = TRUE;
      $revert = FALSE;
    }
    if ($revert) {
      $overridden = $view->getThirdPartySetting('searchstax', 'relevance_model_overrides', []);
      unset($overridden[$display_id]);
      $view->setThirdPartySetting('searchstax', 'relevance_model_overrides', $overridden);
      return;
    }

    $models = $form_state->getValue([$section, 'options', 'searchstax', 'relevance_models'], '');
    $form_state->unsetValue([$section, 'options', 'searchstax']);
    // Correctly handle the default/override setting.
    if ($is_defaulted || $is_default_display) {
      $view->setThirdPartySetting('searchstax', 'relevance_models', $models);
      // If the settings were switched back from "Overridden" to "Default",
      // remove any overridden settings we might have stored for this display.
      if (!$was_defaulted) {
        $overridden = $view->getThirdPartySetting('searchstax', 'relevance_model_overrides', []);
        unset($overridden[$display_id]);
        $view->setThirdPartySetting('searchstax', 'relevance_model_overrides', $overridden);
      }
    }
    else {
      assert($display_id !== 'default');
      $overridden = $view->getThirdPartySetting('searchstax', 'relevance_model_overrides', []);
      $overridden[$display_id] = $models;
      $view->setThirdPartySetting('searchstax', 'relevance_model_overrides', $overridden);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function showLogInForm(&$form, FormStateInterface $form_state): void {
    $form['message'] = [
      '#theme' => 'status_messages',
      '#message_list' => [
        'warning' => [
          t('You need to log in to the SearchStax app in order to change these settings.'),
        ],
      ],
      '#status_headings' => [
        'status' => t('Status message'),
        'error' => t('Error message'),
        'warning' => t('Warning message'),
      ],
    ];
    $form = $this->showLogInFormTrait($form, $form_state);

    $html_id = 'searchstax-login-form';
    $form['#attributes']['id'] = $html_id;
    $form['login']['#type'] = 'details';
    $submit_button = $form['actions']['submit'];
    unset($form['actions']);
    assert(isset($submit_button['#validate']));
    $submit_button['#validate'] = [[$this, 'validateLoginForm']];
    assert(isset($submit_button['#submit']));
    $submit_button['#submit'] = [[$this, 'submitLoginForm']];
    $submit_button['#ajax'] = [
      'callback' => [static::class, 'buildConfigForm'],
      'wrapper' => $html_id,
      'method' => 'replaceWith',
      'effect' => 'fade',
    ];
    $form['submit'] = $submit_button;
  }

  /**
   * {@inheritdoc}
   */
  public function validateLoginForm(array &$form, FormStateInterface $form_state): void {
    $subform = &$form['options'][static::VIEWS_UI_SECTION]['options']['searchstax']['login'];
    $subform_state = SubformState::createForSubform($subform, $form, $form_state);
    $this->validateLoginFormTrait($subform, $subform_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitLoginForm(array &$form, FormStateInterface $form_state): void {
    $this->submitLoginFormTrait($form, $form_state);
    $form_state->setRebuild();
  }

  /**
   * Handles switching the selected backend plugin.
   *
   * @param array $form
   *   The current form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The part of the form to return as AJAX.
   */
  public static function buildConfigForm(array $form, FormStateInterface $form_state): array {
    return $form['options'][static::VIEWS_UI_SECTION]['options']['searchstax'];
  }

  /**
   * Determines whether the given Views display uses the default options.
   *
   * @param \Drupal\views\ViewEntityInterface $view
   *   The view.
   * @param string $display_id
   *   The display ID.
   *
   * @return bool
   *   TRUE if the given Views display uses the default options for the
   *   relevance models, FALSE if the options are overridden for this display.
   */
  protected static function usesDefaultDisplayOptions(
    ViewEntityInterface $view,
    string $display_id
  ): bool {
    $view_executable = $view->getExecutable();
    $view_executable->initDisplay();
    $display = $view_executable->displayHandlers->get($display_id);
    return $display->isDefaultDisplay()
      || $display->isDefaulted(static::VIEWS_UI_SECTION);
  }

}
