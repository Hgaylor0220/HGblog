<?php

declare(strict_types=1);

namespace Drupal\searchstax\Form;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\search_api\Display\DisplayPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for changing the module's settings.
 */
class SettingsForm extends ConfigFormBase {

  use ConfigFormBcTrait;

  /**
   * The plugin manager search api display.
   *
   * @var \Drupal\search_api\Display\DisplayPluginManagerInterface
   */
  protected DisplayPluginManagerInterface $displayPluginManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The key repository service, if available.
   */
  protected ?KeyRepositoryInterface $keyRepository = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $form = parent::create($container);

    $form->setDisplayPluginManager($container->get('plugin.manager.search_api.display'));
    $form->setModuleHandler($container->get('module_handler'));
    $form->setLoggerFactory($container->get('logger.factory'));

    // Only inject key repository if the Key module is enabled.
    if ($container->get('module_handler')->moduleExists('key')) {
      $form->setKeyRepository($container->get('key.repository'));
    }

    return $form;
  }

  /**
   * Retrieves the plugin manager search api display.
   *
   * @return \Drupal\search_api\Display\DisplayPluginManagerInterface
   *   The plugin manager search api display.
   */
  public function getDisplayPluginManager(): DisplayPluginManagerInterface {
    return $this->displayPluginManager ?? \Drupal::service('plugin.manager.search_api.display');
  }

  /**
   * Sets the plugin manager search api display.
   *
   * @param \Drupal\search_api\Display\DisplayPluginManagerInterface $display_plugin_manager
   *   The new plugin manager search api display.
   *
   * @return $this
   */
  public function setDisplayPluginManager(DisplayPluginManagerInterface $display_plugin_manager): self {
    $this->displayPluginManager = $display_plugin_manager;
    return $this;
  }

  /**
   * Retrieves the module handler.
   *
   * @return \Drupal\Core\Extension\ModuleHandlerInterface
   *   The module handler.
   */
  public function getModuleHandler(): ModuleHandlerInterface {
    return $this->moduleHandler ?? \Drupal::moduleHandler();
  }

  /**
   * Sets the module handler.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The new module handler.
   *
   * @return $this
   */
  public function setModuleHandler(ModuleHandlerInterface $module_handler): self {
    $this->moduleHandler = $module_handler;
    return $this;
  }

  /**
   * Retrieves the key repository.
   *
   * @return \Drupal\key\KeyRepositoryInterface|null
   *   The key repository, or NULL if the Key module is not installed.
   */
  public function getKeyRepository(): ?KeyRepositoryInterface {
    if ($this->keyRepository) {
      return $this->keyRepository;
    }
    if ($this->getModuleHandler()->moduleExists('key')) {
      return \Drupal::service('key.repository');
    }
    return NULL;
  }

  /**
   * Sets the key repository.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   *
   * @return $this
   */
  public function setKeyRepository(KeyRepositoryInterface $key_repository): self {
    $this->keyRepository = $key_repository;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'searchstax_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      'searchstax.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('searchstax.settings');

    // API Credentials configuration section.
    $form['credentials_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Analytics Credentials'),
      '#tree' => FALSE,
    ];

    $key_module_exists = $this->moduleHandler->moduleExists('key');
    $key_unused_states = [];
    if ($key_module_exists) {
      $empty_option = $this->t('- Do not use Key module -');
      $form['credentials_section']['key_id'] = [
        '#type' => 'key_select',
        '#title' => $this->t('Analytics Credentials Key'),
        '#empty_option' => $empty_option,
        '#default_value' => $config->get('key_id') ?? '',
        '#description' => $this->t('Select the key that contains your SearchStax Analytics credentials (Analytics URL and Global analytics key) as JSON, or select "@do_not_use" to enter the credentials directly into this form. If a key is used, any credentials entered into this form will be cleared to protect your data. Expected format of the JSON value of the key: {"analytics_url": "https://app.searchstax.com", "analytics_key": "YourSecretKey"}', [
          '@do_not_use' => $empty_option,
        ]),
        '#config_target' => 'searchstax.settings:key_id',
      ];
      $key_unused_states = [
        '#states' => [
          'visible' => [
            ':input[name="key_id"]' => ['value' => ''],
          ],
        ],
      ];
    }

    $form['credentials_section']['analytics_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Analytics URL'),
      '#description' => $this->t('The Site Search Analytics URL associated with this website. You can find this by navigating to your App Settings > All APIs > Analytics.'),
      '#config_target' => 'searchstax.settings:analytics_url',
    ] + $key_unused_states;

    $form['credentials_section']['analytics_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Global analytics key'),
      '#description' => $this->t('The Site Search Analytics key associated with this website.'),
      '#config_target' => 'searchstax.settings:analytics_key',
    ] + $key_unused_states;

    $form['autosuggest_core'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Auto-suggest core'),
      '#description' => $this->t("<strong>This setting has been deprecated.</strong> Instead, set the auto-suggest core to use for each search server separately, in that server's settings. Afterwards, you should remove this setting."),
      '#element_validate' => ['::validateAutosuggestCore'],
      '#config_target' => 'searchstax.settings:autosuggest_core',
      // This setting is deprecated. Only display if it has a value, to allow
      // users to remove it.
      '#access' => (bool) $config->get('autosuggest_core'),
    ];

    // @todo Check whether /emselect is available before allowing to enable?
    $form['searches_via_searchstudio'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Re-route searches via Site Search configurations'),
      '#description' => $this->t('Re-route all searches through the Site Search search handler. When enabled, this allows you to select various search settings in Drupal to be ignored so that the settings configured in Site Search will take effect instead. You can configure which settings exactly will be affected. Fulltext keys, filters and paging parameters are always passed to the Solr server and are never affected by this setting.'),
      '#config_target' => 'searchstax.settings:searches_via_searchstudio',
    ];
    $form['discard_parameters'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Which Drupal search settings should be ignored?'),
      '#description' => $this->t('The selected Drupal settings will be ignored in the search request sent to Site Search. Instead, the settings made within Site Search will take effect. Otherwise, the Drupal settings will override the ones in the Site Search configurations. For more information on each Drupal setting to ignore and the corresponding Site Search features you can refer to <a href=":url">the Site Search documentation.</a>.', [
        ':url' => 'https://support.searchstax.com/hc/en-us/articles/45147885078157',
      ]),
      '#options' => [
        'keys' => $this->t('Parse mode and searched fields'),
        'highlight' => $this->t('Highlighting settings'),
        'spellcheck' => $this->t('Spellcheck settings'),
        'sort' => $this->t('Sorts'),
        'facets' => $this->t('Facets'),
      ],
      'sort' => [
        '#description' => $this->t('This option is only displayed for backwards-compatibility and should be disabled for most sites.'),
        '#access' => in_array('sort', $config->get('discard_parameters')),
      ],
      'facets' => [
        '#description' => $this->t('This will in most cases not work correctly with the Facets module, so custom code handling the returned facets will be needed.'),
        '#access' => in_array('facets', $config->get('discard_parameters')),
      ],
      '#config_target' => 'searchstax.settings:discard_parameters',
      '#states' => [
        'visible' => [
          ':input[name="searches_via_searchstudio"]' => ['checked' => TRUE],
        ],
      ],
    ];

    if (static::manualConfigHandlingNeeded()) {
      static::setConfigDefaultValues($form, $config);
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Normalize field value to a list.
    $value = $form_state->getValue('discard_parameters') ?: [];
    $form_state->setValue('discard_parameters', array_filter($value));

    parent::validateForm($form, $form_state);

    // Validate Key module configuration, if present.
    if (empty($form['credentials_section']['key_id'])) {
      return;
    }

    $key_id = $form_state->getValue('key_id');
    if (!empty($key_id)) {
      // Validate that the key contains valid JSON with required fields.
      $key = $this->getKeyRepository()->getKey($key_id);
      if ($key) {
        $credentials = json_decode($key->getKeyValue(), TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
          $form_state->setError(
            $form['credentials_section']['key_id'],
            $this->t('The selected key does not contain valid JSON.'),
          );
        }
        elseif (!isset($credentials['analytics_url']) || !isset($credentials['analytics_key'])) {
          $form_state->setError(
            $form['credentials_section']['key_id'],
            $this->t('The selected key must contain both "analytics_url" and "analytics_key" fields.'),
          );
        }
      }
      else {
        $form_state->setError(
          $form['credentials_section']['key_id'],
          $this->t('The selected key does not exist anymore.'),
        );
      }
    }
  }

  /**
   * Validates input for the "Auto-suggest core" form field.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @internal
   */
  public static function validateAutosuggestCore(array &$element, FormStateInterface $form_state): void {
    $value = $form_state->getValue($element['#parents']);
    /* @noinspection PhpStrFunctionsInspection */
    if (strpos($value, '/') !== FALSE) {
      $vars = ['@name' => $element['#title']];
      $message = t('Please enter @name without any slashes (/).', $vars);
      $form_state->setError($element, $message);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $was_key_set = $form_state->getValue('key_id', '') !== '';

    if (static::manualConfigHandlingNeeded()) {
      $form_state->cleanValues();
      $config = $this->configFactory()->getEditable('searchstax.settings');
      $config->setData($form_state->getValues() + $config->get());
      // Handle Key module settings.
      if ($was_key_set) {
        $config
          ->clear('analytics_url')
          ->clear('analytics_key');
      }
      else {
        // Key module not available, save credentials directly.
        $config->clear('key_id');
      }
      $config->save();
    }

    if ($was_key_set) {
      $form_state->setValue('analytics_url', '');
      $form_state->setValue('analytics_key', '');
    }
    else {
      // Key module not available, save credentials directly.
      $form_state->setValue('key_id', '');
    }

    parent::submitForm($form, $form_state);

    // If the "autosuggest_core" field was present but the user cleared it,
    // remove the setting from the config object completely.
    if (
      !empty($form['autosuggest_core']['#access'])
      && $form_state->getValue('autosuggest_core') === ''
    ) {
      $config = $this->configFactory()->getEditable('searchstax.settings');
      $config->clear('autosuggest_core');
      $config->save();
    }
  }

}
