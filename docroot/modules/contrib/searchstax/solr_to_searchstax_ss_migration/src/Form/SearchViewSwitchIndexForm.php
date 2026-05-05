<?php

declare(strict_types=1);

namespace Drupal\solr_to_searchstax_ss_migration\Form;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\search_api\Task\IndexTaskManagerInterface;
use Drupal\solr_to_searchstax_ss_migration\UtilityServiceInterface;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for switching a search view to a different index.
 */
class SearchViewSwitchIndexForm extends FormBase implements TrustedCallbackInterface {

  /**
   * The module's utility service.
   */
  protected UtilityServiceInterface $utility;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The view executable factory.
   */
  protected ViewExecutableFactory $viewExecutableFactory;

  /**
   * The index task manager.
   */
  protected IndexTaskManagerInterface $indexTaskManager;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs a new class instance.
   *
   * @param \Drupal\solr_to_searchstax_ss_migration\UtilityServiceInterface $utility
   *   The module's utility service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\views\ViewExecutableFactory $view_executable_factory
   *   The view executable factory.
   * @param \Drupal\search_api\Task\IndexTaskManagerInterface $index_task_manager
   *   The index task manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    UtilityServiceInterface $utility,
    EntityTypeManagerInterface $entity_type_manager,
    ViewExecutableFactory $view_executable_factory,
    IndexTaskManagerInterface $index_task_manager,
    ModuleHandlerInterface $module_handler
  ) {
    $this->utility = $utility;
    $this->entityTypeManager = $entity_type_manager;
    $this->viewExecutableFactory = $view_executable_factory;
    $this->indexTaskManager = $index_task_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $form = new static(
      $container->get('solr_to_searchstax_ss_migration.utility'),
      $container->get('entity_type.manager'),
      $container->get('views.executable'),
      $container->get('search_api.index_task_manager'),
      $container->get('module_handler'),
    );
    $form->setMessenger($container->get('messenger'));
    $form->setStringTranslation($container->get('string_translation'));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'solr_to_searchstax_search_view_switch_index';
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRenderForm'];
  }

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\search_api\IndexInterface[]|null $indexes
   *   All available search indexes.
   * @param object|null $info
   *   A plain object containing the following (public) properties:
   *   - solr_servers_to_migrated: An associative array mapping the IDs of all
   *     Solr servers to the ID of the SearchStax search servers to which they
   *     were migrated, if available, or to NULL otherwise.
   *   - indexes_to_copies: An associative array mapping the IDs of Solr indexes
   *     to their copies, if they have been copied already, or to NULL
   *     otherwise.
   *   - any_migration_available: Should be set to TRUE in case any action can
   *     be taken on this form.
   *
   * @return array
   *   The form structure.
   *
   * @throws \Exception
   *   Thrown in case of any errors.
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?array $indexes = NULL,
    ?object $info = NULL
  ): array {
    if ($indexes === NULL || !isset($info->indexes_to_copies)) {
      throw new \RuntimeException(static::class . ' built without proper form arguments.');
    }

    $form['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Switch index of search views'),
    ];
    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Once a copy of an index has been created, this form can be used to switch the index used by any search view of the original index to use the copy instead. Make sure all items have been reindexed before proceeding. In case of problems, it is also possible to undo this switch and set the view to use the original index again.'),
    ];
    $form['list'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Index'),
        $this->t('Server'),
        $this->t('Status'),
        $this->t('Operations'),
      ],
      '#rows' => [],
      '#empty' => $this->t('No search views found connected to Solr indexes or their copies.'),
    ];
    $form['operations'] = [];
    $form['#pre_render'][] = [$this, 'preRenderForm'];

    $solr_index_ids = array_keys($info->indexes_to_copies);
    $index_copy_ids = array_filter(array_values($info->indexes_to_copies));
    $relevant_index_ids = array_merge($solr_index_ids, $index_copy_ids);
    if (!$relevant_index_ids) {
      return $form;
    }
    $base_tables = [];
    foreach ($relevant_index_ids as $index_id) {
      $base_tables[$index_id] = "search_api_index_$index_id";
    }
    $view_storage = $this->entityTypeManager->getStorage('view');
    $view_ids = $view_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('base_table', array_values($base_tables), 'IN')
      ->execute();

    // Build a table of rows containing all views that were originally created
    // on Solr indexes.
    $original_base_tables = $this->utility->getOriginalBaseTables();
    /** @var \Drupal\views\ViewEntityInterface $view */
    foreach ($view_storage->loadMultiple($view_ids) as $view_id => $view) {
      $base_table = $view->get('base_table');
      $index = SearchApiQuery::getIndexFromTable($base_table, $this->entityTypeManager);
      if (!$index) {
        continue;
      }
      $is_solr_index = in_array($index->id(), $solr_index_ids);

      $original_base_table = $original_base_tables[$view_id] ?? NULL;
      if (!$is_solr_index && !$original_base_table) {
        continue;
      }
      if ($is_solr_index) {
        $other_index_id = $info->indexes_to_copies[$index->id()] ?? '';
        $other_index = $indexes[$other_index_id] ?? NULL;
      }
      else {
        $other_index = SearchApiQuery::getIndexFromTable($original_base_table, $this->entityTypeManager);
      }

      $row_key = (string) $view->label();
      $operation = NULL;
      $details = [];
      if ($original_base_table === $base_table) {
        $details[] = $this->t('Previous switch to index copy has been rolled back.');
      }
      if ($other_index) {
        if ($is_solr_index) {
          assert(!$original_base_table || $original_base_table === $base_table);
          $button_label = $this->t('Switch to copy');
          $details[] = $this->t('Ready to be switched to index <a href=":url">@name</a>.', [
            '@name' => $other_index->label(),
            ':url' => $other_index->toUrl('canonical')->toString(),
          ]);
          $info->any_migration_available = TRUE;
        }
        else {
          $button_label = $this->t('Roll back change');
          $details[] = $this->t('Can be rolled back to use index <a href=":url">@name</a> in case of problems.', [
            '@name' => $other_index->label(),
            ':url' => $other_index->toUrl('canonical')->toString(),
          ]);
        }
        $operation = [
          '#type' => 'submit',
          '#name' => $view_id,
          '#value' => $button_label,
          '#view' => $view,
          '#new_base_table' => $base_tables[$other_index->id()],
          '#was_already_migrated' => (bool) $original_base_table,
          '#from_index_id' => $index->id(),
          '#to_index_id' => $other_index->id(),
          '#broken_handlers' => [],
          '#row_key' => $row_key,
        ];

        // Make sure items have been tracked and reindexed already.
        if ($this->indexTaskManager->isTrackingComplete($other_index)) {
          $tracker = $other_index->getTrackerInstance();
          $remaining = $tracker->getRemainingItemsCount();
          $total = $tracker->getTotalItemsCount();
          if ($remaining > 0 && ($remaining / (float) $total) > 0.1) {
            $details[] = $this->t('Warning: @remaining of @total items remain to be indexed on the target index. It is advised to finish indexing before switching a view to use this index.',
              [
                '@remaining' => $remaining,
                '@total' => $total,
              ]);
          }
        }
        else {
          $details[] = $this->t('Warning: Tracking is not complete on the target index. It is advised to finish tracking and indexing before switching a view to use this index.');
        }
      }
      else {
        $details[] = $this->t('This Solr index has not been copied yet.');
      }

      $broken_handlers = $this->getBrokenHandlers($view->getExecutable());
      if ($broken_handlers) {
        if ($is_solr_index) {
          $description = $this->t('Warning: This view has broken handlers. It is advised to fix the view before switching its index. The following handlers are broken:');
        }
        else {
          $description = $this->t('Warning: This view has broken handlers. Try rolling back the index change to see if this fixes the errors. The following handlers are broken:');
        }
        $details[] = [
          'description' => [
            '#markup' => $description,
          ],
          'list' => [
            '#theme' => 'item_list',
            '#items' => $broken_handlers,
          ],
        ];
        if ($operation) {
          $operation['#broken_handlers'] = $broken_handlers;
        }
      }

      if (count($details) <= 1) {
        $details = reset($details);
      }
      else {
        $details = [
          'data' => [
            '#theme' => 'item_list',
            '#items' => $details,
          ],
        ];
      }

      if ($operation) {
        $form['operations'][$view_id] = $operation;
      }

      if ($view->hasLinkTemplate('edit-form')) {
        $label = $view->toLink(NULL, 'edit-form');
      }
      else {
        $label = $view->label();
      }
      $form['list']['#rows'][$row_key] = [
        'view' => $label,
        'index' => $index->toLink(NULL, 'canonical'),
        'details' => $details,
        'operations' => ['data' => ''],
      ];
    }
    // Order the rows alphabetically by view name.
    ksort($form['list']['#rows']);

    return $form;
  }

  /**
   * Prerender callback for the form.
   *
   * Moves the buttons into the table since otherwise they are not correctly
   * treated as form elements.
   *
   * @param array $form
   *   The form.
   *
   * @return array
   *   The processed form.
   *
   * @see https://www.drupal.org/project/drupal/issues/3486574
   */
  public function preRenderForm(array $form): array {
    foreach (Element::children($form['operations']) as $key) {
      $button = $form['operations'][$key];
      $row_key = $button['#row_key'];
      if (!empty($form['list']['#rows'][$row_key])) {
        $form['list']['#rows'][$row_key]['operations']['data'] = $button;
      }
    }
    unset($form['operations']);
    $form['list']['#rows'] = array_values($form['list']['#rows']);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $button = &$form_state->getTriggeringElement();
    if (!$button) {
      $form_state->setError($form, $this->t('Could not determine clicked button.'));
      return;
    }

    /** @var \Drupal\views\ViewEntityInterface $view */
    $view = $button['#view'];
    if (!$button['#was_already_migrated']) {
      $this->utility->addOriginalBaseTable($view->id(), $view->get('base_table'));
    }
    $view->set('base_table', $button['#new_base_table']);

    $from = $button['#from_index_id'];
    $to = $button['#to_index_id'];
    foreach ($view->toArray() as $key => $value) {
      if (is_array($value) && $this->switchTables($value, $from, $to)) {
        $view->set($key, $value);
      }
    }

    // Try to determine whether the switch would break the view in any way.
    $previously_broken = $button['#broken_handlers'];
    // We cannot use $view->getExecutable() here as that might use the existing
    // executable object.
    $view_executable = $this->viewExecutableFactory->get($view);
    $now_broken = $this->getBrokenHandlers($view_executable);
    $newly_broken = array_diff($now_broken, $previously_broken);
    if ($newly_broken) {
      $form_state->setError($button, $this->t('The following handlers would be broken by this operation: @broken_handlers.', [
        '@broken_handlers' => implode(', ', $newly_broken),
      ]));
    }
  }

  /**
   * Switches Views data tables in "table" keys recursively.
   *
   * @param array $config
   *   The config array, passed by reference.
   * @param string $from
   *   The original index ID.
   * @param string $to
   *   The new index ID.
   *
   * @return bool
   *   TRUE if any replacements occurred, FALSE otherwise.
   */
  protected function switchTables(array &$config, string $from, string $to): bool {
    $changed = FALSE;
    if (is_string($config['table'] ?? NULL)) {
      $new_table = preg_replace("/^(search_api_(?:index|datasource)_)$from(_\w+)?\$/", "\$1$to\$2", $config['table']);
      if ($new_table !== $config['table']) {
        $changed = TRUE;
        $config['table'] = $new_table;
      }
    }
    foreach ($config as &$value) {
      if (is_array($value) && $this->switchTables($value, $from, $to)) {
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\views\ViewEntityInterface $view */
    $view = $form_state->getTriggeringElement()['#view'];
    $view->save();
    $this->messenger()->addStatus($this->t('The index of the @name search view was successfully switched.', [
      '@name' => $view->label(),
    ]));

    $this->resaveAffectedFacets($view);
  }

  /**
   * Retrieves a list of all broken handlers on the given view.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view.
   *
   * @return string[]
   *   A list of broken handlers, in the format "DISPLAY_ID.TYPE.KEY".
   */
  protected function getBrokenHandlers(ViewExecutable $view): array {
    $handler_types = [
      'argument',
      'empty',
      'field',
      'filter',
      'footer',
      'header',
      'relationship',
      'sort',
    ];
    $broken = [];
    $view->initDisplay();
    /** @var \Drupal\views\Plugin\views\display\DisplayPluginInterface $display */
    foreach ($view->displayHandlers as $display_id => $display) {
      foreach ($handler_types as $type) {
        if ($display->isDefaulted($type)) {
          continue;
        }
        foreach ($display->getHandlers($type) as $key => $handler) {
          if ($handler->broken()) {
            $broken[] = "$display_id.$type.$key";
          }
        }
      }
    }
    return $broken;
  }

  /**
   * Saves all facets associated with the given view (unchanged).
   *
   * This will update their "dependencies" property to point to the correct
   * index.
   *
   * @param \Drupal\views\ViewEntityInterface $view
   *   The view that was just updated.
   */
  protected function resaveAffectedFacets(ViewEntityInterface $view): void {
    if (!$this->moduleHandler->moduleExists('facets')) {
      return;
    }

    $view_executable = $view->getExecutable();
    $view_executable->initDisplay();
    $facet_source_ids = [];
    /** @var \Drupal\views\Plugin\views\display\DisplayPluginInterface $display */
    foreach ($view_executable->displayHandlers as $display_id => $display) {
      $display_type = $display->getPluginId();
      if ($display_type === 'rest_export') {
        $display_type = 'rest';
      }
      $facet_source_ids[] = "search_api:views_{$display_type}__{$view->id()}__$display_id";
    }

    if (!$facet_source_ids) {
      return;
    }

    try {
      $facets_storage = $this->entityTypeManager->getStorage('facets_facet');
    }
    catch (PluginException $e) {
      return;
    }
    $facet_ids = $facets_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('facet_source_id', $facet_source_ids, 'IN')
      ->execute();
    if (!$facet_ids) {
      return;
    }
    foreach ($facets_storage->loadMultiple($facet_ids) as $facet) {
      try {
        $facet->save();
      }
      catch (EntityStorageException $e) {
        $url = $facet->toUrl('edit-form', [
          'fragment' => 'edit-actions-submit',
        ]);
        $error = $this->t('Failed to save facet %facet_id. <a href=":url">Re-save it manually</a> to make sure it points to the correct index.', [
          '%facet_id' => $facet->id(),
          ':url' => $url->toString(),
        ]);
        $this->messenger()->addError($error);
      }
    }
  }

}
