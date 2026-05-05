<?php

namespace Drupal\searchstax\Form;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Number;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for changing advanced settings of the module.
 */
class AdvancedSettingsForm extends ConfigFormBase {

  use ConfigFormBcTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $form = parent::create($container);

    $form->setEntityTypeManager($container->get('entity_type.manager'));
    $form->setModuleHandler($container->get('module_handler'));
    $form->setLoggerFactory($container->get('logger.factory'));

    return $form;
  }

  /**
   * Retrieves the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  public function getEntityTypeManager(): EntityTypeManagerInterface {
    return $this->entityTypeManager ?? \Drupal::entityTypeManager();
  }

  /**
   * Sets the entity type manager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The new entity type manager.
   *
   * @return $this
   */
  public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager): self {
    $this->entityTypeManager = $entity_type_manager;
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
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'searchstax_advanced_settings_form';
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

    try {
      $roles = $this->getEntityTypeManager()->getStorage('user_role')
        ->loadMultiple();
      $role_options = array_map(
        function ($role) {
          return $role->label();
        },
        $roles,
      );
      $form['untracked_roles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Analytics: Do not track these roles'),
        '#description' => $this->t('Select roles for which you want to disable tracking of search behavior.'),
        '#options' => $role_options,
        '#config_target' => 'searchstax.settings:untracked_roles',
      ];
    }
    catch (\Exception $e) {
      // @todo Remove once we depend on Drupal 10.1+.
      if (method_exists(Error::class, 'logException')) {
        Error::logException($this->logger('searchstax'), $e);
      }
      else {
        /* @noinspection PhpUndefinedFunctionInspection */
        watchdog_exception('searchstax', $e);
      }
    }

    if ($this->getModuleHandler()->moduleExists('eu_cookie_compliance')) {
      $form['eu_cookie_compliance'] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $this->t('Analytics: EU Cookie Compliance'),
        '#description' => $this->t('Configure how the <a href=":url">EU Cookie Compliance settings</a> affect SearchStax tracking.', [
          ':url' => Url::fromRoute('eu_cookie_compliance.settings')->toString(),
        ]),
        '#tree' => TRUE,
      ];
      $form['eu_cookie_compliance']['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable integration'),
        '#description' => $this->t('Let users decide on tracking of their searches based on the settings of the EU Cookie Compliance module.'),
        '#config_target' => 'searchstax.settings:eu_cookie_compliance.enabled',
      ];
      $form['eu_cookie_compliance']['category'] = [
        '#type' => 'select',
        '#title' => $this->t('Category'),
        '#description' => $this->t('If the cookie consent mode is set to "Opt-in with categories", the category to use for enabling/disabling SearchStax tracking.'),
        '#options' => [
          '' => '- ' . $this->t('Ignore categories') . ' -',
        ],
        '#config_target' => 'searchstax.settings:eu_cookie_compliance.category',
        '#states' => [
          'visible' => [
            ':input[name="eu_cookie_compliance[enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
      try {
        $category_storage = $this->getEntityTypeManager()->getStorage('cookie_category');
        foreach ($category_storage->loadMultiple() as $category_id => $category) {
          $form['eu_cookie_compliance']['category']['#options'][$category_id] = $category->label();
        }
      }
      catch (PluginException $ignored) {
        $form['eu_cookie_compliance']['category']['#access'] = FALSE;
      }
    }

    $form['flood_protection'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Flood Protection'),
      '#tree' => TRUE,
    ];
    $form['flood_protection']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Flood Protection'),
      '#description' => $this->t('Flood Protection allows you to put limits on the number of searches or indexing operations a single user can trigger within a given time frame.'),
      '#config_target' => 'searchstax.settings:flood_protection.enabled',
    ];
    $states_flood_protection = [
      'visible' => [
        ':input[name="flood_protection[enabled]"]' => ['checked' => TRUE],
      ],
    ];
    $validate_numbers = [
      [Number::class, 'validateNumber'],
      '::validateNumberField',
    ];
    $form['flood_protection']['search_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Search Window'),
      '#description' => $this->t('Within how many seconds the Search Limit cannot be exceeded by a single IP address. (Recommended to start with 10.) Use 0 to disable flood protection for searches.'),
      '#min' => 0,
      '#config_target' => 'searchstax.settings:flood_protection.search_window',
      '#states' => $states_flood_protection,
      '#element_validate' => $validate_numbers,
    ];
    $form['flood_protection']['search_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Search Limit'),
      '#description' => $this->t('How many search requests a single IP address can invoke within the Search Window. (Recommended to start with a smaller value such as 15 unless searches use Autocomplete or Facet modules.) Use 0 to disable flood protection for searches.'),
      '#min' => 0,
      '#config_target' => 'searchstax.settings:flood_protection.search_limit',
      '#states' => $states_flood_protection,
      '#element_validate' => $validate_numbers,
    ];
    $form['flood_protection']['update_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Update Window'),
      '#description' => $this->t('Within how many seconds the Update Limit cannot be exceeded by a single IP address. (Recommended to start with 60.) Use 0 to disable flood protection for updates.'),
      '#min' => 0,
      '#config_target' => 'searchstax.settings:flood_protection.update_window',
      '#states' => $states_flood_protection,
      '#element_validate' => $validate_numbers,
    ];
    $form['flood_protection']['update_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Update Limit'),
      '#description' => $this->t('How many update requests a single IP can invoke within the Update Window. (Recommended to start with a value such as 50 unless indexing batches are very small.) Use 0 to disable flood protection for updates.'),
      '#min' => 0,
      '#config_target' => 'searchstax.settings:flood_protection.update_limit',
      '#states' => $states_flood_protection,
      '#element_validate' => $validate_numbers,
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
    $value = $form_state->getValue('untracked_roles') ?: [];
    $form_state->setValue('untracked_roles', array_filter($value));

    parent::validateForm($form, $form_state);
  }

  /**
   * Validates a numeric field.
   *
   * Necessary to allow empty strings to be treated as NULL values by Typed Data
   * validation.
   *
   * @param array $element
   *   The form element, which must have type "number".
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @internal
   */
  public static function validateNumberField(
    array $element,
    FormStateInterface $form_state
  ): void {
    if ($element['#value'] === '') {
      $form_state->setValue($element['#parents'], NULL);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (static::manualConfigHandlingNeeded()) {
      $form_state->cleanValues();
      $config = $this->configFactory()->getEditable('searchstax.settings');
      $config->setData($form_state->getValues() + $config->get());
      $config->save();
    }

    parent::submitForm($form, $form_state);
  }

}
