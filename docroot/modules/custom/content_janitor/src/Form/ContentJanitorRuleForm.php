<?php

namespace Drupal\content_janitor\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

class ContentJanitorRuleForm extends EntityForm {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * Constructs a new ContentJanitorRuleForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    // $this->entityTypeManager is already declared in the parent EntityForm class!
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

  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $rule = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rule Name (e.g., "Clean up Old Events")'),
      '#default_value' => $rule->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $rule->id(),
      '#machine_name' => [
        'exists' => '\Drupal\content_janitor\Entity\ContentJanitorRule::load',
      ],
      '#disabled' => !$rule->isNew(),
    ];

    // 1. Build Content Type Dropdown Options
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $type_options = ['' => $this->t('- Select a Content Type -')];
    foreach ($content_types as $id => $type) {
      $type_options[$id] = $type->label();
    }

    // Determine the currently selected type (from AJAX form state, or saved rule)
    $selected_type = $form_state->getValue('target_content_type') ?: $rule->target_content_type;

    $form['target_content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Target Content Type'),
      '#options' => $type_options,
      '#default_value' => $selected_type,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateDateFieldDropdown',
        'wrapper' => 'date-field-wrapper',
        'event' => 'change',
      ],
    ];

    // 2. Build Date Field Dropdown Options based on the selected content type
    $field_options = ['' => $this->t('- Select a Date Field -')];
    
    if ($selected_type) {
      $fields = $this->entityFieldManager->getFieldDefinitions('node', $selected_type);
      
      // Always include standard core date fields
      $field_options['created'] = $this->t('Authored on (created)');
      $field_options['changed'] = $this->t('Last updated (changed)');

      // Search for any custom date/datetime fields
      foreach ($fields as $field_name => $field_definition) {
        $type = $field_definition->getType();
        if (in_array($type, ['datetime', 'daterange', 'date'])) {
          $field_options[$field_name] = $field_definition->getLabel() . ' (' . $field_name . ')';
        }
      }
    }

    $form['target_date_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Target Date Field'),
      '#options' => $field_options,
      '#default_value' => $rule->target_date_field,
      '#required' => TRUE,
      '#prefix' => '<div id="date-field-wrapper">',
      '#suffix' => '</div>',
      // If no content type is selected yet, disable this dropdown
      '#disabled' => empty($selected_type), 
    ];

    $form['age_threshold'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Age Threshold'),
      '#default_value' => $rule->age_threshold ?: '-2 years',
      '#required' => TRUE,
      '#description' => $this->t('Use standard PHP relative formats (e.g., "-6 months", "-1 year", "-30 days").'),
    ];

    $form['batch_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Limit'),
      '#default_value' => $rule->batch_limit ?: 50,
      '#description' => $this->t('How many nodes to unpublish per cron run to prevent memory timeouts.'),
    ];

    return $form;
  }

  /**
   * AJAX callback to update the Date Field dropdown.
   */
  public function updateDateFieldDropdown(array &$form, FormStateInterface $form_state) {
    return $form['target_date_field'];
  }

  public function save(array $form, FormStateInterface $form_state) {
    $rule = $this->entity;
    $status = $rule->save();

    if ($status === SAVED_NEW) {
      $this->messenger()->addMessage($this->t('Created the %label rule.', ['%label' => $rule->label()]));
    } else {
      $this->messenger()->addMessage($this->t('Saved the %label rule.', ['%label' => $rule->label()]));
    }
    
    // Redirect back to the dashboard table.
    $form_state->setRedirectUrl($rule->toUrl('collection'));
  }

}