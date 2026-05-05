<?php

declare(strict_types=1);

namespace Drupal\solr_to_searchstax_ss_migration\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Utility\Error;
use Drupal\solr_to_searchstax_ss_migration\UtilityServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for cloning indexes on migrated Solr servers.
 */
class CloneIndexesForm extends FormBase implements TrustedCallbackInterface {

  /**
   * The module's utility service.
   */
  protected UtilityServiceInterface $utility;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new class instance.
   *
   * @param \Drupal\solr_to_searchstax_ss_migration\UtilityServiceInterface $utility
   *   The module's utility service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    UtilityServiceInterface $utility,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->utility = $utility;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $form = new static(
      $container->get('solr_to_searchstax_ss_migration.utility'),
      $container->get('entity_type.manager'),
    );
    $form->setMessenger($container->get('messenger'));
    $form->setStringTranslation($container->get('string_translation'));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'solr_to_searchstax_clone_indexes';
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
   * @param \Drupal\search_api\ServerInterface[]|null $servers
   *   All available search servers.
   * @param object|null $info
   *   A plain object containing the following (public) properties:
   *   - solr_servers_to_migrated: An associative array mapping the IDs of all
   *     Solr servers to the ID of the SearchStax search servers to which they
   *     were migrated, if available, or to NULL otherwise.
   *   - indexes_to_copies: Should be filled with an associative array mapping
   *     the IDs of Solr indexes to their copies, if they have been copied
   *     already, or to NULL otherwise.
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
    ?array $servers = NULL,
    ?object $info = NULL
  ): array {
    if ($indexes === NULL || !isset($info->solr_servers_to_migrated)) {
      throw new \RuntimeException(static::class . ' built without proper form arguments.');
    }
    $solr_servers_to_migrated = $info->solr_servers_to_migrated;
    $info->indexes_to_copies = $this->utility->getCopiedIndexes();

    $form['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Copy search indexes'),
    ];
    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('For Solr servers that have already been migrated to SearchStax servers, this will make copies of their search indexes and point them to the SearchStax server. You can then use this new search index to index content to the SearchStax server to finally switch the search views to use this new index.'),
    ];
    $form['operations'] = [];
    $form['#pre_render'][] = [$this, 'preRenderForm'];
    // Build a table of rows containing all indexes on Solr servers.
    $rows = [];
    foreach ($indexes as $index_id => $index) {
      $server_id = $index->getServerId();
      if (
        !array_key_exists($server_id, $solr_servers_to_migrated)
        || empty($servers[$server_id])
      ) {
        continue;
      }
      $migrated_server_id = $solr_servers_to_migrated[$server_id];
      $info->indexes_to_copies += [$index_id => NULL];
      $row_key = (string) $index->label();
      if ($migrated_server_id === NULL) {
        $status = $this->t('The associated server has not been migrated yet.');
      }
      else {
        $migrated_index_id = $info->indexes_to_copies[$index_id] ?? '';
        $migrated_index = $indexes[$migrated_index_id] ?? NULL;
        if ($migrated_index) {
          $status = $this->t('Copied to index <a href=":url">@name</a>.', [
            '@name' => $migrated_index->label(),
            ':url' => $migrated_index->toUrl('canonical')->toString(),
          ]);
        }
        else {
          $status = $this->t('Ready to be copied.');
          $form['operations'][$index_id] = [
            '#type' => 'submit',
            '#name' => $index_id,
            '#value' => $this->t('Create copy'),
            '#index' => $index,
            '#new_server' => $migrated_server_id,
            '#row_key' => $row_key,
          ];
          $info->any_migration_available = TRUE;
        }
      }
      $rows[$row_key] = [
        'index' => $index->toLink(NULL, 'canonical'),
        'server' => $servers[$server_id]->toLink(NULL, 'canonical'),
        'status' => $status,
        'operations' => ['data' => ''],
      ];
    }
    ksort($rows);
    $form['list'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Index'),
        $this->t('Server'),
        $this->t('Status'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No indexes found on Solr servers.'),
    ];

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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $button = $form_state->getTriggeringElement();
    if (!$button) {
      $this->messenger()->addError($this->t('Could not determine clicked button.'));
      return;
    }

    /** @var \Drupal\search_api\IndexInterface $original_index */
    $original_index = $button['#index'];
    try {
      $index_storage = $this->entityTypeManager->getStorage('search_api_index');
    }
    catch (\Exception $e) {
      // Should never happen.
      $this->messenger()->addError($e->getMessage());
      return;
    }

    $new_index_values = $original_index->toArray();
    unset(
      $new_index_values['uuid'],
      $new_index_values['dependencies'],
      $new_index_values['third_party_settings']['acquia_search'],
      $new_index_values['_core'],
    );
    $new_index_values['id'] = $this->utility->findNewEntityId('searchstax_index', $index_storage);
    $new_index_values['name'] = $this->t('SearchStax index');
    $new_index_values['description'] = $this->t('Copy of index %name.', [
      '%name' => $original_index->label(),
    ]);
    $new_index_values['server'] = $button['#new_server'];

    try {
      // @todo Use EntityInterface::createDuplicate() instead?
      $new_index = $index_storage->create($new_index_values);
      $new_index->save();
      $this->messenger()->addStatus($this->t('Successfully created index <a href=":url">@name</a>.', [
        '@name' => $new_index->label(),
        ':url' => $new_index->toUrl('canonical')->toString(),
      ]));

      $this->utility->addCopiedIndex($original_index->id(), $new_index->id());
    }
    catch (\Exception $e) {
      $variables = Error::decodeException($e);
      $this->messenger()->addError($this->t('%type while saving new index: @message in %function (line %line of %file).', $variables));
    }
  }

}
