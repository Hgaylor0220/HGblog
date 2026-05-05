<?php

declare(strict_types=1);

namespace Drupal\solr_to_searchstax_ss_migration\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Error;
use Drupal\key\KeyRepositoryInterface;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Plugin\views\filter\SearchApiFulltext;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\ServerInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\search_api_solr\SolrConnectorInterface;
use Drupal\search_api_solr\Utility\Utility as SolrUtility;
use Drupal\searchstax\Exception\NotLoggedInException;
use Drupal\searchstax\Exception\SearchStaxException;
use Drupal\searchstax\Form\ApiLoginFormTrait;
use Drupal\searchstax\Service\ApiInterface;
use Drupal\solr_to_searchstax_ss_migration\UtilityServiceInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides a form for migrating from Solr to SearchStax Site Search.
 */
class MigrateServerForm extends FormBase {

  use ApiLoginFormTrait;

  /**
   * Cached Solr connector plugins, keyed by server ID.
   *
   * @var \Drupal\search_api_solr\SolrBackendInterface[]
   */
  protected static array $solrBackends = [];

  /**
   * The module's utility service.
   */
  protected UtilityServiceInterface $utility;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The key repository service, if available.
   *
   * @var \Drupal\key\KeyRepositoryInterface|null
   */
  protected ?KeyRepositoryInterface $keyRepository;

  /**
   * Constructs a new class instance.
   *
   * @param \Drupal\solr_to_searchstax_ss_migration\UtilityServiceInterface $utility
   *   The module's utility service.
   * @param \Drupal\searchstax\Service\ApiInterface $searchstax_api
   *   The SearchStax API service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\key\KeyRepositoryInterface|null $key_repository
   *   The key repository service, if available.
   */
  public function __construct(
    UtilityServiceInterface $utility,
    ApiInterface $searchstax_api,
    EntityTypeManagerInterface $entity_type_manager,
    LanguageManagerInterface $language_manager,
    ?KeyRepositoryInterface $key_repository
  ) {
    $this->utility = $utility;
    $this->searchStaxApi = $searchstax_api;
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager = $language_manager;
    $this->keyRepository = $key_repository;

    // Give any batch operations enough time to finish.
    set_time_limit(600);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    // Only inject key repository if the Key module is enabled.
    $key_repository = NULL;
    if ($container->get('module_handler')->moduleExists('key')) {
      $key_repository = $container->get('key.repository');
    }

    $form = new static(
      $container->get('solr_to_searchstax_ss_migration.utility'),
      $container->get('searchstax.api'),
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $key_repository
    );
    $form->setMessenger($container->get('messenger'));
    $form->setStringTranslation($container->get('string_translation'));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'solr_to_searchstax_migrate_server';
  }

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string|null $server_id
   *   The ID of the search server to migrate.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $server_id = NULL): array {
    try {
      /** @var \Drupal\search_api\ServerInterface $server */
      $server = $form_state->get('server');
      $all_servers = $this->entityTypeManager->getStorage('search_api_server')
        ->loadMultiple();
      if (!$server) {
        $server = $all_servers["$server_id"] ?? NULL;
        if (!$server) {
          throw new NotFoundHttpException("Unknown server \"$server_id\".");
        }
        $form_state->set('server', $server);
      }
      $form['#title'] = $this->t('Migrate server %server', ['%server' => $server->label() ?? $server_id]);
      if (!$this->utility->canServerBeMigrated($server, $all_servers)) {
        $this->messenger()->addError($this->t('Migration is not supported for this server.'));
        return $form;
      }

      if (!$this->searchStaxApi->isLoggedIn()) {
        return $this->showLogInForm($form, $form_state);
      }

      $this->formAddAccountSelect($form, $form_state);
      $selected_account = $form_state->getValue('searchstax_account');
      if (!$selected_account) {
        // If the user clicked the "Refresh apps" button, we want to restore the
        // account from storage.
        $storage = $form_state->getStorage();
        $selected_account = $storage['searchstax_account'] ?? NULL;
        if (!$selected_account) {
          return $form;
        }
        unset($storage['searchstax_account']);
        $form_state->setStorage($storage);
      }
      $this->formAddAppSelect($form['account_specific'], $form_state, $selected_account);
      $disabled = !Element::children($form['account_specific']);
      $this->formAddRefreshAppsButton($form['account_specific'], $form_state);
      $this->formAddViewSelect($form['account_specific'], $form_state);
      $this->formAddDetectedLanguages($form['account_specific'], $form_state);

      $form['account_specific']['actions'] = ['#type' => 'actions'];
      $form['account_specific']['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Migrate server now'),
        '#disabled' => $disabled,
      ];

      return $form;
    }
    catch (\Exception $e) {
      if ($e instanceof HttpExceptionInterface) {
        throw $e;
      }
      if ($e instanceof NotLoggedInException) {
        return $this->showLogInForm($form, $form_state);
      }
      $variables = Error::decodeException($e);
      $this->messenger()->addError($this->t('%type: @message in %function (line %line of %file).', $variables));
      return $form;
    }
  }

  /**
   * Adds the "Select account" section to the form.
   *
   * @param array $form
   *   An associative array containing the structure of the form. Passed by
   *   reference.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown in case of API request errors.
   */
  protected function formAddAccountSelect(array &$form, FormStateInterface $form_state): void {
    $form['searchstax_account'] = [];
    $form['account_specific'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'searchstax-account-specific-form',
      ],
    ];

    // Get the available accounts to determine whether we need to display a
    // select box at all, and in which way.
    $accounts = $this->searchStaxApi->getAccounts();
    if (!$accounts) {
      throw new SearchStaxException('There are no accounts set up for your SearchStax login.');
    }
    $account_names = array_keys($accounts);
    if (count($accounts) === 1) {
      $account = reset($account_names);
      $form['searchstax_account'] = [
        '#type' => 'value',
        '#value' => $account,
      ];
      $form_state->setValue('searchstax_account', $account);
      return;
    }
    $form['searchstax_account'] = [
      '#type' => 'select',
      '#title' => $this->t('SearchStax account'),
      '#description' => $this->t('Select the SearchStax account for which to list the available apps.'),
      '#options' => array_combine($account_names, $account_names),
      '#required' => TRUE,
      '#ajax' => [
        'trigger_as' => ['name' => 'account_confirm'],
        'callback' => '::buildFormAfterAccountSelect',
        'wrapper' => 'searchstax-account-specific-form',
        'method' => 'replaceWith',
        'effect' => 'fade',
      ],
    ];
    $form['account_confirm_button'] = [
      '#type' => 'submit',
      '#name' => 'account_confirm',
      '#value' => $this->t('Confirm account'),
      '#limit_validation_errors' => [['searchstax_account']],
      '#submit' => ['::submitAccountConfirmButton'],
      '#ajax' => [
        'callback' => '::buildFormAfterAccountSelect',
        'wrapper' => 'searchstax-account-specific-form',
      ],
      '#attributes' => ['class' => ['js-hide']],
    ];
  }

  /**
   * Form submission handler for the account select.
   *
   * Takes care of changes in the selected account.
   *
   * @param array $form
   *   The current form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function submitAccountConfirmButton(array $form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * Handles changes to the selected account.
   *
   * @param array $form
   *   The current form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The part of the form to return as AJAX.
   */
  public function buildFormAfterAccountSelect(array $form, FormStateInterface $form_state): array {
    return $form['account_specific'];
  }

  /**
   * Adds the "Select app" section to the form.
   *
   * @param array $form
   *   An associative array containing the structure of the form. Passed by
   *   reference.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param string $account_name
   *   The name of the selected SearchStax account for which to list apps.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown in case of API request errors.
   */
  protected function formAddAppSelect(
    array &$form,
    FormStateInterface $form_state,
    string $account_name
  ): void {
    $options = [];
    $non_token_apps = [];
    $apps = $this->searchStaxApi->getApps($account_name);
    if (!$apps) {
      $this->messenger()->addError($this->t('There are no apps available for this SearchStax account.'));
      return;
    }
    foreach ($apps as $app_id => $app) {
      // Use the first valid app ID we find to retrieve the available
      // languages.
      if (!$form_state->has('available_languages')) {
        $form_state->set('available_languages', $this->searchStaxApi->getAvailableLanguages($account_name, $app_id));
      }
      $options[$app_id] = $app['name'];

      // Check whether this app would need a password or uses token auth.
      if (empty($app['index_write_token'])) {
        $non_token_apps[] = ['value' => $app_id];
      }
    }

    $form['searchstax_app'] = [
      '#type' => 'select',
      '#title' => $this->t('SearchStax app to which to migrate'),
      '#description' => $this->t('Select the SearchStax app to which the Solr server should be migrated. This should be an unused app, not yet associated with any Search API server.'),
      '#options' => $options,
      '#required' => TRUE,
    ];
    $form['searchstax_app_password'] = [
      '#type' => 'password',
      '#title' => $this->t('SearchStax app read-write password'),
      '#description' => $this->t('If the selected app does not support token authentication, provide the password from the “Read-Write Search API Credentials” section of your SearchStax app (found under “App settings” » “Search API”) here.'),
      '#attributes' => [
        'autocomplete' => 'off',
      ],
      '#states' => [
        'visible' => [
          ':input[name="searchstax_app"]' => $non_token_apps,
        ],
      ],
      '#access' => (bool) $non_token_apps,
    ];
  }

  /**
   * Adds the "Refresh app list" section to the form.
   *
   * @param array $form
   *   An associative array containing the structure of the form. Passed by
   *   reference.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  protected function formAddRefreshAppsButton(array &$form, FormStateInterface $form_state): void {
    $form['refresh'] = [
      '#type' => 'details',
      '#title' => $this->t('Refresh app list'),
      '#description' => $this->t('Retrieve the list of apps again from the server.'),
    ];
    $form['refresh']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh'),
      '#submit' => [
        '::submitRefreshApps',
      ],
      '#limit_validation_errors' => [['searchstax_account']],
    ];
  }

  /**
   * Submit handler for the login form.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function submitRefreshApps(array &$form, FormStateInterface $form_state): void {
    $this->searchStaxApi->clearCache();
    $this->messenger()->addStatus($this->t('The list of apps was refreshed from the SearchStax server.'));
    $form_state->set('searchstax_account', $form_state->getValue('searchstax_account'));
    $form_state->setRebuild();
  }

  /**
   * Adds the "Select search view" section to the form.
   *
   * @param array $form
   *   An associative array containing the structure of the form. Passed by
   *   reference.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state. Needs to contain a "server" property with the
   *   search server entity.
   *
   * @throws \Exception
   *   Thrown in case of any problems or errors.
   */
  protected function formAddViewSelect(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\search_api\ServerInterface $server */
    $server = $form_state->get('server');
    $view_storage = $this->entityTypeManager->getStorage('view');
    $index_ids = $this->entityTypeManager->getStorage('search_api_index')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('server', $server->id())
      ->execute();
    $display_infos = [];
    $search_translated = preg_quote((string) $this->t('Search'), '/');
    $search_regex = "/search|$search_translated/i";
    foreach ($index_ids as $index_id) {
      /** @var \Drupal\views\ViewEntityInterface[] $views */
      $views = $view_storage->loadByProperties(['base_table' => "search_api_index_$index_id"]);
      foreach ($views as $view_id => $view) {
        $displays = $view->get('display');
        $default_display_has_fulltext_filter = static::viewsDisplayHasFulltextFilter($displays['default']);
        // Include the default display only if it is the only one (which rarely
        // ever happens).
        if (array_keys($displays) !== ['default']) {
          unset($displays['default']);
        }
        foreach ($displays as $display_id => $display) {
          $display_title = $display['display_title'] ?? $display_id;
          $label_arguments = [
            '%view_name' => $view->label(),
            '%display_title' => $display_title,
          ];
          $has_fulltext_filter = $default_display_has_fulltext_filter;
          if (!($display['display_options']['defaults']['filters'] ?? TRUE)) {
            $has_fulltext_filter = static::viewsDisplayHasFulltextFilter($display);
          }
          $display_infos["$view_id:$display_id"] = [
            'label' => $this->t('View %view_name, display %display_title', $label_arguments),
            'is_page' => $display['display_plugin'] === 'page',
            'has_fulltext_filter' => $has_fulltext_filter,
            'search_in_name' => (bool) preg_match($search_regex, "{$view->label()} $display_title"),
          ];
        }
      }
    }

    if (!$display_infos) {
      $this->messenger()->addWarning($this->t('There were no search views found for this server. Searched fields, display fields and sorts will not be migrated.'));
      return;
    }

    uasort($display_infos, function (array $display_1, array $display_2): int {
      foreach (['is_page', 'has_fulltext_filter', 'search_in_name'] as $key) {
        if ($display_1[$key] !== $display_2[$key]) {
          return $display_1[$key] ? -1 : 1;
        }
      }
      return strcasecmp((string) $display_1['label'], (string) $display_2['label']);
    });
    $options = array_map(function (array $display): TranslatableMarkup {
      return $display['label'];
    }, $display_infos);
    $options = ['' => '- ' . $this->t('None') . ' -'] + $options;
    $form['search_view'] = [
      '#type' => 'radios',
      '#title' => $this->t('Search view and display from which to migrate settings'),
      '#description' => $this->t('Some SearchStax settings (in particular the searched fields, displayed fields and sorting) are not part of the search server but configured on a per-view base. If you want these settings migrated to the SearchStax app, select the search view and display from which they should be migrated.'),
      '#options' => $options,
      '#default_value' => '',
    ];
  }

  /**
   * Determines whether a Views display has a "Search: Fulltext search" filter.
   *
   * @param array $display_config
   *   The Views display configuration.
   *
   * @return bool
   *   TRUE if the display has a "Search: Fulltext search" filter, FALSE
   *   otherwise.
   */
  protected static function viewsDisplayHasFulltextFilter(array $display_config): bool {
    foreach ($display_config['display_options']['filters'] ?? [] as $filter) {
      if (($filter['plugin_id'] ?? NULL) === 'search_api_fulltext') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Adds the "Detected languages" section to the form.
   *
   * @param array $form
   *   An associative array containing the structure of the form. Passed by
   *   reference.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state. Needs to contain a "server" property with the
   *   search server entity.
   *
   * @throws \Exception
   *   Thrown in case of any errors.
   */
  protected function formAddDetectedLanguages(array &$form, FormStateInterface $form_state): void {
    [$language_types, $unsupported_types] = $this->getLanguageTypes($form_state);
    $languages_label = $this->t('Languages to migrate');
    if ($language_types) {
      $form['languages']['#tree'] = TRUE;
      $form['languages']['list'] = [
        '#type' => 'checkboxes',
        '#title' => $languages_label,
        '#description' => $this->t('The listed languages were detected in the configuration of your existing Solr server. Please select those for which you want to migrate the configuration (stopwords and synonyms) to the SearchStax app.'),
        '#options' => [],
        '#default_value' => [],
        '#required' => TRUE,
      ];
      foreach ($language_types as $type_name => $language_info) {
        $form['languages']['list']['#options'][$type_name] = $this->t('@language (Solr type %type)', [
          '@language' => $language_info['name'],
          '%type' => $type_name,
        ]);
        $form['languages']['list']['#default_value'][$type_name] = $type_name;
        $sub_items = [];
        if (!empty($language_info['stopwords'])) {
          $sub_items[] = $this->t('Stopwords file: %file', ['%file' => $language_info['stopwords']]);
        }
        if (!empty($language_info['synonyms'])) {
          $sub_items[] = $this->t('Synonyms file: %file', ['%file' => $language_info['synonyms']]);
        }
        if ($sub_items) {
          $form['languages']['list'][$type_name]['#description'] = [
            '#theme' => 'item_list',
            '#items' => $sub_items,
          ];
        }
      }
    }
    else {
      $form['languages'] = [
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $languages_label,
        ],
        'message' => [
          '#markup' => new FormattableMarkup('<p>@message</p>', [
            '@message' => $this->t('No (supported) languages were detected on the Solr server.'),
          ]),
        ],
      ];
    }

    if ($unsupported_types) {
      $form['languages']['unsupported'] = [
        '#prefix' => $this->t('<p>The following types look like they might be language-specific text types but do not correspond to languages supported by SearchStax Site Search:</p>'),
        '#theme' => 'item_list',
        '#items' => $unsupported_types,
      ];
    }
  }

  /**
   * Retrieves the language types configured on the Solr server.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state. Needs to contain a "server" property with the
   *   search server entity.
   *
   * @return array[]
   *   A list with the following entries:
   *   - 0: An associative array, keyed by type name and containing as values
   *     associative arrays of language information with the following keys:
   *     - name: The (English) name of the language.
   *     - code: The language code.
   *     - stopwords: (optional) The stopwords file for this language on the
   *       Solr server.
   *     - synonyms: (optional) The synonyms file for this language on the Solr
   *       server.
   *   - 1: A list of Solr field types that looked like they might be
   *     language-specific but didn't match any language supported by
   *     SearchStax.
   *
   * @throws \Exception
   *   Thrown in case of any errors.
   */
  protected function getLanguageTypes(FormStateInterface $form_state): array {
    // Maybe we have this stored already, in which case we don't need to compute
    // it again.
    $language_types = $form_state->get('language_types');
    $unsupported_types = $form_state->get('unsupported_types');
    if (isset($language_types, $unsupported_types)) {
      return [$language_types, $unsupported_types];
    }

    /** @var \Drupal\search_api\ServerInterface $server */
    $server = $form_state->get('server');
    /** @var \Drupal\search_api_solr\SolrBackendInterface $backend */
    $backend = $server->getBackend();
    $connector = $backend->getSolrConnector();
    $files = static::getFileList($connector);
    if (!$files) {
      throw new SearchApiSolrException('No config files available.');
    }

    $available_languages = $form_state->get('available_languages');
    $language_types = [];
    $unsupported_types = [];
    $inspected_files = [
      'schema.xml',
      'schema_extra_fields.xml',
      'schema_extra_types.xml',
    ];
    libxml_use_internal_errors();
    foreach (array_intersect($files, $inspected_files) as $file) {
      try {
        $contents = $connector->getFile($file)->getBody();
        if (strpos($file, '_extra_') !== FALSE) {
          $contents = "<schema>$contents</schema>";
        }
        $dom = simplexml_load_string($contents);
        foreach (libxml_get_errors() as $error) {
          $variables = [
            '%config_file' => $file,
            '@message' => $error->message,
            '%line' => $error->line,
            '%column' => $error->column,
          ];
          $error_message = $this->t('Error parsing Solr config file %config_file: @message (line %line, column %column).',
            $variables);
          $this->messenger()->addError($error_message);
        }
        if ($dom === FALSE) {
          continue;
        }
        foreach ($dom->fieldType ?? [] as $field_type) {
          $type_name = (string) $field_type['name'];
          if (substr($type_name, 0, 5) !== 'text_') {
            continue;
          }
          $langcode = substr($type_name, 5);
          if (!isset($available_languages[$langcode])) {
            // Our best chance to identify language codes (without it becoming
            // way too complicated for this trivial information) is that they
            // should have two letters, optionally followed by "_" and a suffix.
            // Explicitly exclude "text_ws", though, the Solr fulltext type that
            // only splits on whitespace.
            if ($langcode !== 'ws' && preg_match('/^[a-z]{2}(_\w+)?$/', $langcode)) {
              $unsupported_types[] = $type_name;
            }
            continue;
          }
          $language_info = [
            'name' => $available_languages[$langcode],
            'code' => $langcode,
          ];
          foreach ($field_type->analyzer ?? [] as $analyzer) {
            foreach ($analyzer->filter ?? [] as $filter) {
              $class = (string) ($filter['class'] ?? '');
              if ($class === 'solr.StopFilterFactory') {
                $stopwords = (string) ($filter['words'] ?? '');
                if ($stopwords === '') {
                  continue;
                }
                if (($language_info['stopwords'] ?? $stopwords) !== $stopwords) {
                  $message = $this->t('Two different stopword files detected for Solr field type %field_type: %file1, %file2. Using %file1.',
                    [
                      '%field_type' => $type_name,
                      '%file1' => $language_info['stopwords'],
                      '%file2' => $stopwords,
                    ]);
                  $this->messenger()->addWarning($message);
                }
                else {
                  if ((string) ($filter['format'] ?? '') === 'snowball') {
                    $message = $this->t('Solr field type %field_type (language %language) uses format "@format" for its stopwords file, which is not supported. Skipping stopwords for this language.',
                      [
                        '%field_type' => $type_name,
                        '%language' => $language_info['name'],
                        '@format' => 'snowball',
                      ]);
                    $this->messenger()->addWarning($message);
                  }
                  $language_info['stopwords'] = $stopwords;
                }
              }
              elseif (in_array($class, ['solr.SynonymFilterFactory', 'solr.SynonymGraphFilterFactory'])) {
                $synonyms = (string) ($filter['synonyms'] ?? '');
                if ($synonyms === '') {
                  continue;
                }
                if (($language_info['synonyms'] ?? $synonyms) !== $synonyms) {
                  $message = $this->t('Two different synonym files detected for Solr field type %field_type: %file1, %file2. Using %file1.',
                    [
                      '%field_type' => $type_name,
                      '%file1' => $language_info['synonyms'],
                      '%file2' => $synonyms,
                    ]);
                  $this->messenger()->addWarning($message);
                }
                else {
                  $language_info['synonyms'] = $synonyms;
                }
              }
            }
          }
          $language_types[$type_name] = $language_info;
        }
      }
      catch (\Exception $e) {
        $variables = Error::decodeException($e);
        $variables['%config_file'] = $file;
        $this->messenger()
          ->addError($this->t('%type while reading Solr config file %config_file: @message in %function (line %line of %file). The retrieved language information might be incomplete', $variables));
      }
    }

    $form_state->set('language_types', $language_types);
    $form_state->set('unsupported_types', $unsupported_types);

    return [$language_types, $unsupported_types];
  }

  /**
   * Retrieves the list of config files used by a Solr server.
   *
   * @param \Drupal\search_api_solr\SolrConnectorInterface $connector
   *   The connector to the Solr server.
   * @param string|null $dir
   *   (optional) The sub-directory to list, if any.
   *
   * @return string[]
   *   A list of file names that exist on the Solr server.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   *   Thrown in case of any errors.
   */
  protected static function getFileList(
    SolrConnectorInterface $connector,
    ?string $dir = NULL
  ): array {
    $prefix = isset($dir) ? "$dir/" : '';
    $response_body = $connector->getFile($dir)->getBody();
    try {
      $data = json_decode($response_body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new SearchApiSolrException("JsonException while parsing Solr response: {$e->getMessage()}.\nResponse: $response_body", 0, $e);
    }
    $files = [];
    foreach ($data['files'] ?? [] as $file => $info) {
      $file = "$prefix$file";
      if (!empty($info['directory'])) {
        $files = array_merge($files, static::getFileList($connector, $file));
      }
      else {
        $files[] = $file;
      }
    }
    return $files;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Do not validate if this was a click on the "Refresh app list" button.
    if (($form_state->getTriggeringElement()['#limit_validation_errors'] ?? NULL) === []) {
      return;
    }

    $account_name = $form_state->getValue('searchstax_account');
    $app_id = $form_state->getValue('searchstax_app');
    try {
      $app = $this->searchStaxApi->getApp($account_name, (int) $app_id);
      $form_state->set('app', $app);
    }
    catch (SearchStaxException $e) {
      $variables = Error::decodeException($e);
      $message = $this->t('%type while retrieving list of SearchStax apps: @message in %function (line %line of %file).', $variables);
      $form_state->setErrorByName('searchstax_app', $message);
      return;
    }

    /* @see \Drupal\searchstax\Plugin\SolrConnector\SearchStaxConnector::validateConfigurationForm() */
    if (preg_match('@https://([^/:]+)/([^/]+)/([^/]+)/(update|select)$@', $app['update_endpoint'], $matches)) {
      $form_state->setValue('connector_config', [
        'scheme' => 'https',
        'host' => $matches[1],
        'port' => 443,
        'context' => $matches[2],
        'core' => $matches[3],
      ]);
    }
    else {
      // There is nothing the user could do about this, if it ever happens. It
      // just seems either the API responses changed or we have an error in the
      // regular expression above. In either case, only the module maintainers
      // will be able to fix this.
      $message = $this->t('The app’s update endpoint has an invalid format: @url. Please contact SearchStax support.', ['@url' => $app['update_endpoint']]);
      $form_state->setErrorByName('searchstax_app', $message);
    }

    if (
      empty($app['index_write_token'])
      && ((string) $form_state->getValue('searchstax_app_password')) === ''
    ) {
      $message = $this->t('A password is required for SearchStax app %app_name.', [
        '%app_name' => $app['name'] ?? $app_id,
      ]);
      $form_state->setErrorByName('searchstax_app_password', $message);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\search_api\ServerInterface $original_server */
    $original_server = $form_state->get('server');
    $account_name = $form_state->getValue('searchstax_account');
    $app_id = $form_state->getValue('searchstax_app');
    $app_id = (int) $app_id;
    $app = $form_state->get('app');

    try {
      [$language_types] = $this->getLanguageTypes($form_state);
      $selected_languages = array_filter($form_state->getValue(['languages', 'list'], []));
      $language_types = array_intersect_key($language_types, $selected_languages);

      $operations = [
        [
          [$this, 'createMigratedSearchServer'],
          [
            $app,
            $account_name,
            $original_server,
            $form_state->getValue('connector_config'),
            $form_state->getValue('searchstax_app_password'),
          ],
        ],
        [
          [$this, 'setAppLanguages'],
          [$account_name, $app_id, $language_types, $original_server->id()],
        ],
      ];
      $view_and_display_id = $form_state->getValue('search_view');
      if ($view_and_display_id) {
        [$view_id, $display_id] = explode(':', $view_and_display_id, 2);
        $languages = array_column($language_types, 'code');
        $operations[] = [
          [$this, 'migrateFromView'],
          [
            $view_id,
            $display_id,
            $account_name,
            $app_id,
            $languages,
            $original_server->id(),
          ],
        ];
      }
      batch_set([
        'operations' => $operations,
        'finished' => [$this, 'finishBatch'],
      ]);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Migration failed: @message', ['@message' => $e->getMessage()]));
      $form_state->setRebuild();
      return;
    }

    $form_state->setRedirect('solr_to_searchstax_ss_migration.overview');
  }

  /**
   * Creates a Search API server pointing to the given SearchStax app.
   *
   * @param array $app
   *   The SearchStax app data.
   * @param string $account_name
   *   The name of the associated SearchStax account.
   * @param \Drupal\search_api\ServerInterface $original_server
   *   The Search API server from which this one is being migrated.
   * @param array $connector_config
   *   The base connector configuration.
   * @param string|null $app_password
   *   The app password, in case the app does not support token authentication.
   * @param array|\ArrayAccess $context
   *   (optional) The current batch context, passed by reference.
   *
   * @throws \Exception
   *   Thrown in case of any errors.
   */
  public function createMigratedSearchServer(
    array $app,
    string $account_name,
    ServerInterface $original_server,
    array $connector_config,
    ?string $app_password,
    &$context = []
  ): void {
    $server_storage = $this->entityTypeManager->getStorage('search_api_server');
    $server_id = $this->utility->findNewEntityId('searchstax_server', $server_storage);
    if (!empty($app['index_write_token'])) {
      $connector_id = 'searchstax';

      // Check if Key module is enabled.
      if ($this->keyRepository) {
        // Create a unique key for this migrated server's connector credentials.
        $key_id = 'searchstax_connector_migrated_' . $server_id;

        // Check if the key entity already exists.
        $key_storage = $this->entityTypeManager->getStorage('key');
        /** @var \Drupal\key\KeyInterface $existing_key */
        $existing_key = $key_storage->load($key_id);

        // If the key exists, make sure it contains the correct information.
        // Otherwise, we need to create a new one after all.
        if ($existing_key) {
          $key_data = json_decode($existing_key->getKeyValue(), TRUE);
          if (
            ($key_data['update_endpoint'] ?? NULL) !== $app['update_endpoint']
            || ($key_data['update_token'] ?? NULL) !== $app['index_write_token']
          ) {
            $this->messenger()->addWarning($this->t('Existing key %key_id did not contain the correct connection information. Creating a new key instead.', [
              '%key_id' => $key_id,
            ]));
            $existing_key = NULL;
            $key_id = $this->utility->findNewEntityId($key_id, $key_storage);
          }
        }

        if (!$existing_key) {
          // Create new key entity with JSON credentials.
          $credentials = [
            'update_endpoint' => $app['update_endpoint'],
            'update_token' => $app['index_write_token'],
          ];
          $key_storage->create([
            'id' => $key_id,
            'label' => 'SearchStax connector credentials for migrated server',
            'description' => 'Stores SearchStax connector credentials (update endpoint and token) for migrated server.',
            'key_type' => 'authentication',
            'key_type_settings' => [],
            'key_provider' => 'config',
            'key_provider_settings' => [
              'key_value' => json_encode($credentials),
            ],
            'key_input' => 'text_field',
            'key_input_settings' => [],
          ])->save();
        }

        // Set "key_id" instead of plain-text credentials.
        $connector_config['key_id'] = $key_id;
        unset(
          $connector_config['host'],
          $connector_config['context'],
          $connector_config['core'],
          $connector_config['update_endpoint'],
          $connector_config['update_token'],
          $connector_config['autosuggest_endpoint'],
        );
      }
      else {
        // Key module not enabled, use direct credentials.
        $connector_config['update_endpoint'] = $app['update_endpoint'];
        $connector_config['update_token'] = $app['index_write_token'];
      }
    }
    else {
      $connector_id = 'solr_cloud_basic_auth';
      $connector_config['username'] = $app['engine_username'];
      $connector_config['password'] = $app_password;
    }

    /** @var \Drupal\search_api\ServerInterface $new_server */
    $new_server = $server_storage->create([
      'id' => $server_id,
      'name' => $this->t('SearchStax server (app @app)', ['@app' => $app['name']]),
      'description' => $this->t("Connects to the %app SearchStax app (account %account).\n\nMigrated from the %original_server server.",
        [
          '%app' => $app['name'],
          '%account' => $account_name,
          '%original_server' => $original_server->label() ?? $original_server->id(),
        ]),
      'backend' => 'search_api_solr',
      'backend_config' => [
        'connector' => $connector_id,
        'connector_config' => $connector_config,
      ] + $original_server->getBackendConfig(),
    ]);
    $new_server->save();
    $backend = $new_server->getBackend();
    if (!($backend instanceof SolrBackendInterface)) {
      $new_server->delete();
      throw new \Exception("New server did not have Solr as its backend – actual backend was \"{$new_server->getBackendId()}\".");
    }
    $context['results']['new_server'] = $new_server;
    $message = $this->t('Successfully created search server <a href=":url">@name</a>.', [
      '@name' => $new_server->label(),
      ':url' => $new_server->toUrl('canonical')->toString(),
    ]);
    $context['results']['messages'][] = [$message];

    $this->utility->addMigratedServer($original_server->id(), $new_server->id());

    if (!$backend->getSolrConnector()->pingCore()) {
      $message = $this->t('Unable to reach the Solr server (yet). Please make sure the server was created with the correct configuration.');
      $context['results']['messages'][] = [$message, MessengerInterface::TYPE_WARNING];
    }
  }

  /**
   * Sets the languages used by the given SearchStax app.
   *
   * Will also add stopwords and synonyms for those languages, if available.
   *
   * @param string $account_name
   *   The name of the SearchStax account.
   * @param int $app_id
   *   The ID of the SearchStax app.
   * @param array[] $language_types
   *   An associative array, keyed by type name and containing as values
   *   associative arrays of language information with the following keys:
   *   - name: The (English) name of the language.
   *   - code: The language code.
   *   - stopwords: (optional) The stopwords file for this language on the Solr
   *     server.
   *   - synonyms: (optional) The synonyms file for this language on the Solr
   *     server.
   * @param string $original_server_id
   *   The ID of the original Search API server from which to migrate.
   * @param array|\ArrayAccess $context
   *   (optional) The current batch context, passed by reference.
   *
   * @throws \Exception
   *   Thrown in case of any errors.
   */
  public function setAppLanguages(
    string $account_name,
    int $app_id,
    array $language_types,
    string $original_server_id,
    &$context = []
  ): void {
    // At the first call of this method, we queue up some more operations which
    // we then just have to execute.
    $sandbox = &$context['sandbox'];
    if (isset($sandbox['operations'])) {
      $operation = array_shift($sandbox['operations']);
      if (!$operation) {
        $context['finished'] = 1;
        return;
      }
      [$callback, $args, $success_message] = $operation;
      // Shorthand for methods on the API class.
      if (is_string($callback) && !function_exists($callback)) {
        $callback = [$this->searchStaxApi, $callback];
      }
      $callback(...$args);
      $context['results']['messages'][] = [$success_message];
      $context['message'] = $success_message;
      $context['finished'] = 1 - (count($sandbox['operations']) / $sandbox['total']);
      return;
    }

    $languages = [];
    $stopword_files = [];
    $synonym_files = [];
    foreach ($language_types as $language_info) {
      ['name' => $name, 'code' => $code] = $language_info;
      $languages[$code] = [
        'name' => $name,
        'language_code' => $code,
      ];
      if (!empty($language_info['stopwords'])) {
        $stopword_files[$code] = $language_info['stopwords'];
      }
      if (!empty($language_info['synonyms'])) {
        $synonym_files[$code] = $language_info['synonyms'];
      }
    }
    // Set a default language.
    $candidates = [
      $this->languageManager->getDefaultLanguage()->getId(),
      $this->languageManager->getCurrentLanguage()->getId(),
      NULL,
    ];
    foreach (array_unique($candidates) as $langcode) {
      if ($langcode === NULL) {
        $langcode = key($languages);
      }
      if (!empty($languages[$langcode])) {
        $languages[$langcode]['default'] = TRUE;
        break;
      }
    }

    $sandbox['operations'] = [];
    $sandbox['operations'][] = [
      /* @see \Drupal\searchstax\Service\ApiInterface::setLanguages() */
      'setLanguages',
      [
        $account_name,
        $app_id,
        array_values($languages),
      ],
      $this->t('Enabled the following languages in the SearchStax app: @languages', [
        '@languages' => implode(', ', array_column($languages, 'name')),
      ]),
    ];
    foreach ($stopword_files as $langcode => $file) {
      $sandbox['operations'][] = [
        [$this, 'addStopwordsToApp'],
        [
          $account_name,
          $app_id,
          $langcode,
          $file,
          $original_server_id,
        ],
        $this->t('Added %language stopwords to the SearchStax app.', [
          '%language' => $languages[$langcode]['name'],
        ]),
      ];
    }
    foreach ($synonym_files as $langcode => $file) {
      $sandbox['operations'][] = [
        [$this, 'addSynonymsToApp'],
        [
          $account_name,
          $app_id,
          $langcode,
          $file,
          $original_server_id,
        ],
        $this->t('Added %language synonyms to the SearchStax app.', [
          '%language' => $languages[$langcode]['name'],
        ]),
      ];
    }
    $sandbox['total'] = count($sandbox['operations']);
    $context['message'] = $this->t('Queued language operations');
    $context['finished'] = $sandbox['operations'] ? 0 : 1;
  }

  /**
   * Adds stopwords for a specific language to a SearchStax app.
   *
   * @param string $account_name
   *   The name of the SearchStax account.
   * @param int $app_id
   *   The ID of the SearchStax app.
   * @param string $langcode
   *   The language code for which to add stopwords.
   * @param string $stopwords_file
   *   The stopwords file on the Solr server.
   * @param string $original_server_id
   *   The ID of the original Search API server from which to migrate.
   *
   * @throws \Exception
   *   Thrown in case of any errors.
   */
  protected function addStopwordsToApp(
    string $account_name,
    int $app_id,
    string $langcode,
    string $stopwords_file,
    string $original_server_id
  ): void {
    $file_contents = static::getSolrBackend($original_server_id)
      ->getSolrConnector()
      ->getFile($stopwords_file)->getBody();
    $lines = preg_split('/\r?\n|\r/', $file_contents);
    $stopwords = [];
    foreach (array_map('trim', $lines) as $line) {
      if ($line !== '' && $line[0] !== '#') {
        $stopwords[] = $line;
      }
    }
    $stopwords = array_values(array_unique($stopwords));
    $this->searchStaxApi->setStopwords($account_name, $app_id, $langcode, $stopwords);
  }

  /**
   * Adds synonyms for a specific language to a SearchStax app.
   *
   * @param string $account_name
   *   The name of the SearchStax account.
   * @param int $app_id
   *   The ID of the SearchStax app.
   * @param string $langcode
   *   The language code for which to add synonyms.
   * @param string $synonyms_file
   *   The synonyms file on the Solr server.
   * @param string $original_server_id
   *   The ID of the original Search API server from which to migrate.
   *
   * @throws \Exception
   *   Thrown in case of any errors.
   */
  protected function addSynonymsToApp(
    string $account_name,
    int $app_id,
    string $langcode,
    string $synonyms_file,
    string $original_server_id
  ): void {
    $file_contents = static::getSolrBackend($original_server_id)
      ->getSolrConnector()
      ->getFile($synonyms_file)->getBody();
    $lines = preg_split('/\r?\n|\r/', $file_contents);
    $synonyms = [];
    foreach (array_map('trim', $lines) as $line) {
      if ($line !== '' && $line[0] !== '#') {
        $synonyms[] = $line;
      }
    }
    $synonyms = array_values(array_unique($synonyms));
    $this->searchStaxApi->setSynonyms($account_name, $app_id, $langcode, $synonyms);
  }

  /**
   * Migrates search settings from the given search view.
   *
   * @param string $view_id
   *   The ID of the search view.
   * @param string $display_id
   *   The ID of the view display.
   * @param string $account_name
   *   The name of the SearchStax account.
   * @param int $app_id
   *   The ID of the SearchStax app.
   * @param string[] $langcodes
   *   The language codes for which to migrate settings.
   * @param string $original_server_id
   *   The ID of the original Search API server from which to migrate.
   * @param array|\ArrayAccess $context
   *   (optional) The current batch context, passed by reference.
   *
   * @throws \Exception
   *   Thrown in case of any errors.
   */
  public function migrateFromView(
    string $view_id,
    string $display_id,
    string $account_name,
    int $app_id,
    array $langcodes,
    string $original_server_id,
    &$context = []
  ): void {
    // At the first call of this method, we queue up some more operations which
    // we then just have to execute.
    $sandbox = &$context['sandbox'];
    if (isset($sandbox['operations'])) {
      $operation = array_shift($sandbox['operations']);
      if (!$operation) {
        $context['finished'] = 1;
        return;
      }
      [$callback, $args, $success_message] = $operation + [2 => NULL];
      // Shorthand for methods on the API class.
      if (is_string($callback) && !function_exists($callback)) {
        $callback = [$this->searchStaxApi, $callback];
      }
      $callback(...$args);
      if ($success_message) {
        $context['results']['messages'][] = [$success_message];
        $context['message'] = $success_message;
      }
      $context['finished'] = 1 - (count($sandbox['operations']) / $sandbox['total']);
      return;
    }

    $sandbox['operations'] = [];
    /** @var \Drupal\views\ViewEntityInterface $view */
    $view = $this->entityTypeManager->getStorage('view')->load($view_id);
    $view_executable = $view->getExecutable();
    $view_executable->setDisplay($display_id);
    $display = $view_executable->getDisplay();

    $solr_backend = static::getSolrBackend($original_server_id);
    $index = SearchApiQuery::getIndexFromTable($view->get('base_table'), $this->entityTypeManager);
    if (!$index) {
      $message = $this->t('No search index associated with view %view. Could not migrate searched fields, displayed fields and sorts to the SearchStax app.', [
        '%view' => $view->label() ?: $view_id,
      ]);
      $context['results']['messages'][] = [$message, MessengerInterface::TYPE_WARNING];
      return;
    }
    $solr_field_mappings = [];
    foreach ($langcodes as $langcode) {
      $solr_field_mappings[$langcode] = $solr_backend->getLanguageSpecificSolrFieldNames($langcode, $index);
    }
    $indexed_fields = $index->getFields(TRUE);
    /** @var \Drupal\search_api\Item\FieldInterface[][] $fields_by_datasource_and_path */
    $fields_by_datasource_and_path = [];
    foreach ($indexed_fields as $field) {
      $fields_by_datasource_and_path["{$field->getDatasourceId()}"][$field->getPropertyPath()] = $field;
    }

    // Migrate the searched fields.
    $fulltext_filter_found = FALSE;
    foreach ($display->getHandlers('filter') as $filter) {
      if (!($filter instanceof SearchApiFulltext)) {
        continue;
      }
      $fulltext_filter_found = TRUE;
      $searched_fields = $filter->options['fields'] ?: $index->getFulltextFields();
      if (!$searched_fields) {
        $message = $this->t('Search index associated with view %view has no fulltext fields configured. Could not migrate searched fields to SearchStax app.', [
          '%view' => $view->label() ?: $view_id,
        ]);
        $context['results']['messages'][] = [$message, MessengerInterface::TYPE_WARNING];
        break;
      }
      foreach ($solr_field_mappings as $langcode => $solr_field_mapping) {
        $solr_fields = array_intersect_key($solr_field_mapping, array_flip($searched_fields));
        $sandbox['operations'][] = [
          [$this, 'setSearchedFields'],
          [$account_name, $app_id, $langcode, array_values($solr_fields)],
          $this->t('Set the searched fields for language code "@langcode".', [
            '@langcode' => $langcode,
          ]),
        ];
      }
      break;
    }
    if (!$fulltext_filter_found) {
      $message = $this->t('Search view %view has no fulltext filter configured. Could not migrate searched fields to SearchStax app.', [
        '%view' => $view->label() ?: $view_id,
      ]);
      $context['results']['messages'][] = [$message, MessengerInterface::TYPE_WARNING];
    }

    if ($display->usesFields()) {
      $fields = [];
      foreach ($display->getHandlers('field') as $field_handler) {
        if (!($field_handler instanceof FieldPluginBase)) {
          continue;
        }
        $search_api_field = NULL;
        if (!empty($field_handler->definition['search_api field'])) {
          $search_api_field = $field_handler->definition['search_api field'];
        }
        elseif (method_exists($field_handler, 'getCombinedPropertyPath')) {
          [$datasource_id, $path] = Utility::splitCombinedId($field_handler->getCombinedPropertyPath());
          if (isset($fields_by_datasource_and_path[$datasource_id][$path])) {
            $search_api_field = $fields_by_datasource_and_path[$datasource_id][$path]->getFieldIdentifier();
          }
        }
        if ($search_api_field !== NULL) {
          $fields[$search_api_field] = $field_handler->label();
        }
      }
      if ($fields) {
        foreach ($solr_field_mappings as $langcode => $solr_field_mapping) {
          $displayed_fields = [];
          foreach ($fields as $field_id => $label) {
            if (empty($solr_field_mapping[$field_id])) {
              continue;
            }
            $displayed_fields[] = [
              'name' => $solr_field_mapping[$field_id],
              'title' => $label,
            ];
          }
          if ($displayed_fields) {
            $sandbox['operations'][] = [
              /* @see \Drupal\searchstax\Service\ApiInterface::setResultFields() */
              'setResultFields',
              [$account_name, $app_id, $langcode, $displayed_fields],
              $this->t('Set the displayed result fields for language code "@langcode".', [
                '@langcode' => $langcode,
              ]),
            ];
          }
        }
      }
      else {
        $message = $this->t('Failed to match any displayed fields of search view %view to indexed fields. Could not migrate displayed fields to SearchStax app.', [
          '%view' => $view->label() ?: $view_id,
        ]);
        $context['results']['messages'][] = [$message, MessengerInterface::TYPE_WARNING];
      }
    }
    else {
      $message = $this->t('Search view %view does not use fields. Could not migrate displayed fields to SearchStax app.', [
        '%view' => $view->label() ?: $view_id,
      ]);
      $context['results']['messages'][] = [$message, MessengerInterface::TYPE_WARNING];
    }

    $sort_fields = [];
    $is_exposed = FALSE;
    $exposed_form = $display->getPlugin('exposed_form');
    $sort_order_exposed = !empty($exposed_form->options['expose_sort_order']);
    $sort_orders = [
      'asc' => $exposed_form->options['sort_asc_label'] ?? $this->t('Asc'),
      'desc' => $exposed_form->options['sort_desc_label'] ?? $this->t('Desc'),
    ];
    /** @var \Drupal\views\Plugin\views\sort\SortPluginBase $sort_handler */
    foreach ($display->getHandlers('sort') as $sort_handler) {
      $field_id = $sort_handler->realField;
      if (empty($indexed_fields[$field_id])) {
        continue;
      }
      $is_exposed = $is_exposed || $sort_handler->isExposed();
      $label = $sort_handler->options['expose']['label'] ?? $indexed_fields[$field_id]->getLabel();
      if ($sort_handler->isExposed() && $sort_order_exposed) {
        foreach ($sort_orders as $order => $order_label) {
          $sort_fields[] = [
            'name' => $field_id,
            'order' => $order,
            'label' => "$label ($order_label)",
          ];
        }
      }
      else {
        $sort_fields[] = [
          'name' => $field_id,
          'order' => strtolower($sort_handler->options['order']),
          'label' => $label,
        ];
      }
    }
    if ($sort_fields) {
      foreach ($solr_field_mappings as $langcode => $solr_field_mapping) {
        $vars = ['@langcode' => $langcode];
        $sandbox['operations'][] = [
          /* @see \Drupal\searchstax\Service\ApiInterface::enableSortSelect() */
          'enableSortSelect',
          [$account_name, $app_id, $langcode, $is_exposed],
          $is_exposed
            ? $this->t('Enabled sorting via a dropdown select for language code "@langcode".', $vars)
            : $this->t('Disabled sorting via a dropdown select for language code "@langcode".', $vars),
        ];
        $solr_sort_fields = [];
        $query = $index->query()->setLanguages([$langcode]);
        $sort_field_mapping = [];
        foreach ($solr_field_mapping as $search_api_field => $solr_field) {
          $sort_field_mapping[$search_api_field][$langcode] = $solr_field;
        }
        foreach ($sort_fields as $sort_field) {
          $sort_field['name'] = SolrUtility::getSortableSolrField(
            $sort_field['name'],
            $sort_field_mapping,
            $query,
          );
          $solr_sort_fields[] = $sort_field;
        }
        $sandbox['operations'][] = [
          /* @see \Drupal\searchstax\Service\ApiInterface::setSorts() */
          'setSorts',
          [$account_name, $app_id, $langcode, $solr_sort_fields],
          $this->t('Set the sort field(s) for language code "@langcode".', $vars),
        ];
      }
    }
    else {
      $message = $this->t('No Solr sort fields found for search view %view. Could not migrate sorts to SearchStax app.', [
        '%view' => $view->label() ?: $view_id,
      ]);
      $context['results']['messages'][] = [$message, MessengerInterface::TYPE_WARNING];
    }

    // Publish sort and display settings.
    foreach ($langcodes as $langcode) {
      $sandbox['operations'][] = [
        /* @see \Drupal\searchstax\Service\ApiInterface::publishStopwordsSynonymsAndResultSettings() */
        'publishStopwordsSynonymsAndResultSettings',
        [$account_name, $app_id, $langcode],
      ];
    }

    $sandbox['total'] = count($sandbox['operations']);
    $context['message'] = $this->t('Queued view migration operations');
    $context['finished'] = $sandbox['operations'] ? 0 : 1;
  }

  /**
   * Retrieves the Solr backend plugin for the given search server.
   *
   * @param string $server_id
   *   The search server's ID.
   *
   * @return \Drupal\search_api_solr\SolrBackendInterface
   *   The Solr backend plugin.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   Thrown in case the search server does not exist or is not a Solr server.
   */
  protected static function getSolrBackend(string $server_id): SolrBackendInterface {
    if (isset(static::$solrBackends[$server_id])) {
      return static::$solrBackends[$server_id];
    }
    $server = Server::load($server_id);
    if ($server) {
      $backend = $server->getBackend();
      if ($backend instanceof SolrBackendInterface) {
        static::$solrBackends[$server_id] = $backend;
        return static::$solrBackends[$server_id];
      }
    }
    throw new SearchApiException("Search server \"$server_id\" does not exist or is not a Solr server.");
  }

  /**
   * Sets the searched fields for the specified relevance model.
   *
   * @param string $account_name
   *   The account name.
   * @param int $app_id
   *   The app ID.
   * @param string $langcode
   *   The language code.
   * @param string[] $fields
   *   A list of Solr field names.
   *
   * @throws \Drupal\searchstax\Exception\SearchStaxException
   *   Thrown if there is no active login or if another problem occurred.
   */
  protected function setSearchedFields(string $account_name, int $app_id, string $langcode, array $fields): void {
    $model_id = $this->searchStaxApi->getOrCreateDefaultRelevanceModel($account_name, $app_id, $langcode);
    $this->searchStaxApi->setSearchedFields($account_name, $app_id, $langcode, $model_id, $fields);
    $this->searchStaxApi->publishRelevanceModel($account_name, $app_id, $langcode, $model_id);
  }

  /**
   * Finishing callback for the migration batch.
   *
   * @param bool $success
   *   TRUE if all batch operations succeeded, FALSE otherwise.
   * @param array $results
   *   The "results" key of the batch context.
   */
  public function finishBatch(bool $success, array $results): void {
    foreach ($results['messages'] ?? [] as $add_message_args) {
      $this->messenger()->addMessage(...$add_message_args);
    }
    if ($success) {
      $this->messenger()->addStatus($this->t('Migration finished successfully.'));
    }
    else {
      /** @var \Drupal\search_api\ServerInterface|null $new_server */
      $new_server = $results['new_server'] ?? NULL;
      if ($new_server) {
        $vars = ['%name' => $new_server->label()];
        try {
          $new_server->delete();
          $this->messenger()->addError($this->t('Migration failed. The newly created search server %name was deleted again.', $vars));
        }
        catch (EntityStorageException $e) {
          // @todo Remove once we depend on Drupal 10.1+.
          if (method_exists(Error::class, 'logException')) {
            Error::logException($this->getLogger('solr_to_searchstax_ss_migration'), $e, '%type while deleting search server %name: @message in %function (line %line of %file).', $vars);
          }
          else {
            /* @noinspection PhpUndefinedFunctionInspection */
            watchdog_exception('solr_to_searchstax_ss_migration', $e, '%type while deleting search server %name: @message in %function (line %line of %file).', $vars);
          }
          $this->messenger()->addError($this->t('Migration failed.'));
        }
      }
      else {
        $this->messenger()->addError($this->t('Migration failed.'));
      }
    }
  }

}
