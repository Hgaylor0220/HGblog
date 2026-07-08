<?php
namespace Drupal\content_remediation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Content Remediation settings.
 */
class ContentRemediationConfigForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a ContentRemediationConfigForm object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_remediation_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['content_remediation.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('content_remediation.settings');

    // 1. Fetch all Node bundles (Content Types)
    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $type_options = ['' => $this->t('- Select a Content Type -')];
    foreach ($node_types as $type) {
      $type_options[$type->id()] = $type->label();
    }

    // Determine the currently selected content type (either from AJAX state or saved config)
    $selected_type = $form_state->getValue('target_content_type') ?: $config->get('target_content_type');

    $form['target_content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Target Content Type'),
      '#description' => $this->t('Select the content type to evaluate.'),
      '#options' => $type_options,
      '#default_value' => $selected_type,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateFieldDropdown',
        'wrapper' => 'date-field-wrapper',
        'event' => 'change',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Discovering date fields...'),
        ],
      ],
    ];

    // 2. Fetch Date fields dynamically based on the selected content type
    $field_options = ['' => $this->t('- Select a Date Field -')];
    if (!empty($selected_type)) {
      $fields = $this->entityFieldManager->getFieldDefinitions('node', $selected_type);
      foreach ($fields as $machine_name => $field) {
        // Only allow date, datetime, or timestamp fields (e.g., 'created', 'changed', or custom fields)
        if (in_array($field->getType(), ['datetime', 'timestamp', 'created', 'changed'])) {
          $field_options[$machine_name] = $field->getLabel() . ' (' . $machine_name . ')';
        }
      }
    }

    $form['date_field_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'date-field-wrapper'],
    ];

    $form['date_field_wrapper']['target_date_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Date Field to Evaluate'),
      '#description' => $this->t('Select the date field to use for the stale content calculation.'),
      '#options' => $field_options,
      '#default_value' => $config->get('target_date_field'),
      '#required' => TRUE,
      '#disabled' => empty($selected_type),
    ];

    $form['age_threshold'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Age Threshold'),
      '#description' => $this->t('A relative PHP time string. e.g., "-2 years", "-6 months".'),
      '#default_value' => $config->get('age_threshold') ?: '-2 years',
      '#required' => TRUE,
    ];

    $form['batch_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Cron Batch Limit'),
      '#description' => $this->t('Max nodes to unpublish per cron run.'),
      '#default_value' => $config->get('batch_limit') ?: 50,
      '#min' => 1,
      '#max' => 500,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback to update the date field dropdown.
   */
  public function updateFieldDropdown(array &$form, FormStateInterface $form_state) {
    return $form['date_field_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('content_remediation.settings')
      ->set('target_content_type', $form_state->getValue('target_content_type'))
      ->set('target_date_field', $form_state->getValue('target_date_field'))
      ->set('age_threshold', $form_state->getValue('age_threshold'))
      ->set('batch_limit', $form_state->getValue('batch_limit'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}