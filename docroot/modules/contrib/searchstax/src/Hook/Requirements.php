<?php

namespace Drupal\searchstax\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\searchstax\Service\SearchStaxServiceInterface;
use Drupal\searchstax\Service\VersionCheckInterface;

/**
 * Provides requirements hook implementations.
 */
class Requirements {

  use StringTranslationTrait;

  /**
   * The SearchStax utility service.
   */
  protected SearchStaxServiceInterface $utility;

  /**
   * The SearchStax version check service.
   */
  protected VersionCheckInterface $versionCheck;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The renderer.
   */
  protected RendererInterface $renderer;

  public function __construct(
    SearchStaxServiceInterface $utility,
    VersionCheckInterface $versionCheck,
    ModuleHandlerInterface $moduleHandler,
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    RendererInterface $renderer,
    TranslationInterface $stringTranslation
  ) {
    $this->utility = $utility;
    $this->versionCheck = $versionCheck;
    $this->moduleHandler = $moduleHandler;
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
    $this->renderer = $renderer;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $requirements = [];

    if (version_compare(\Drupal::VERSION, '11.2', '>=')) {
      $ok = RequirementSeverity::OK;
      $warning = RequirementSeverity::Warning;
      $error = RequirementSeverity::Error;
    }
    else {
      $ok = REQUIREMENT_OK;
      $warning = REQUIREMENT_WARNING;
      $error = REQUIREMENT_ERROR;
    }

    if ($this->moduleHandler->moduleExists('search_api_searchstax')) {
      $requirements['searchstax_module_conflict'] = [
        'title' => t('SearchStax module conflict'),
        'description' => t('The “Search API SearchStax” module is no longer required for using a SearchStax server with token authentication as long as the “SearchStax” module is installed. You are advised to <a href=":url">uninstall</a> the “Search API SearchStax” module.', [
          ':url' => (new Url('system.modules_uninstall'))->toString(),
        ]),
        'severity' => $warning,
      ];
    }

    if ($this->configFactory->get('searchstax.settings')->get('autosuggest_core')) {
      $requirements['deprecated_autosuggest_core_config'] = [
        'title' => t('Deprecated global SearchStax setting'),
        'description' => t('The global “Auto-suggest core” SearchStax setting has been deprecated. Instead, configure the autosuggest core for each search server separately. Afterwards, go to the <a href=":url">SearchStax settings page</a> and remove the global setting.', [
          ':url' => (new Url('searchstax.admin_settings'))->toString(),
        ]),
        'severity' => $warning,
      ];
    }

    $description = t('The configuration files of all SearchStax apps used on this site are compatible with the currently used version of Drupal.');
    $severity = $ok;
    $searchstax_servers_present = FALSE;
    $server_storage = $this->entityTypeManager->getStorage('search_api_server');
    /** @var \Drupal\search_api\ServerInterface $server */
    foreach ($server_storage->loadMultiple() as $server) {
      if (!$this->utility->isSearchStaxSolrServer($server)) {
        continue;
      }

      // Check the index timeout set for the server.
      $config = $server->getBackendConfig();
      $index_timeout = $config['connector_config']['index_timeout'] ?? 15;
      if ($index_timeout < 15) {
        try {
          $url = $server->toUrl('edit-form')->toString();
        }
        catch (EntityMalformedException $e) {
          // Seems highly unlikely, but catch this nonetheless. We really do not
          // want to throw an exception during requirements check.
          $url = '';
        }
        $requirements['searchstax_index_timeout_' . $server->id()] = [
          'title' => t('Low index timeout'),
          'description' => t('The index timeout for SearchStax server <a href=":url">@server</a> is set to @timeout seconds. It is suggested to increase this to at least 15 seconds to allow indexing with larger batch sizes.',
            [
              '@server' => $server->label() ?: $server->id(),
              ':url' => $url,
              '@timeout' => $index_timeout,
            ]),
          'severity' => $warning,
        ];
      }

      // Check whether the version check has been executed for this server.
      // However, only report a single warning regarding the version check, so
      // skip this if we have already detected one.
      if ($searchstax_servers_present) {
        continue;
      }
      $searchstax_servers_present = TRUE;
      if (!$this->versionCheck->hasCompatibilityDataStored($server)) {
        $description = t('The <a href=":url">SearchStax version check</a> needs to be executed for at least one of your search servers.',
          [
            ':url' => Url::fromRoute('searchstax.version_check')->toString(),
          ]);
        $severity = $warning;
      }
      elseif (!$this->versionCheck->checkCompatibility($server)->isCompatible()) {
        $description = t('The configuration files of at least one SearchStax app used on this site are incompatible with the currently used version of Drupal. Please go to <a href=":url">the SearchStax version check</a> page for details and possible solutions.',
          [
            ':url' => Url::fromRoute('searchstax.version_check')->toString(),
          ]);
        $severity = $warning;
      }
    }
    if ($searchstax_servers_present) {
      $requirements['searchstax_version_check'] = [
        'title' => t('SearchStax app configurations'),
        'description' => $description,
        'severity' => $severity,
      ];
    }

    // Check for views with legacy model IDs stored in third-party settings.
    if ($this->moduleHandler->moduleExists('views')) {
      $view_storage = $this->entityTypeManager->getStorage('view');
      $view_ids = $view_storage->getQuery()
        ->accessCheck(FALSE)
        ->exists('third_party_settings.searchstax')
        ->execute();
      $outdated_views = [];
      /** @var \Drupal\views\ViewEntityInterface $view */
      foreach ($view_storage->loadMultiple($view_ids) as $view) {
        $settings = $view->getThirdPartySettings('searchstax');
        // We just need to go over all non-empty model settings to look for a
        // numeric one, so we combine both defaults and per-display settings
        // into one array to simplify the code.
        $models = $settings['relevance_model_overrides'] ?? [];
        $models[] = $settings['relevance_models'] ?? [];
        foreach ($models as $per_display_models) {
          foreach ($per_display_models as $model) {
            if (preg_match('/^\d+$/', $model)) {
              $outdated_views[] = $view;
              continue 3;
            }
          }
        }
      }

      if ($outdated_views) {
        if ($this->moduleHandler->moduleExists('views_ui')) {
          $links = [];
          foreach ($outdated_views as $view) {
            $links[] = [
              'title' => $view->label(),
              'url' => $view->toUrl('edit-form'),
            ];
          }
          $views_list = [
            '#theme' => 'links',
            '#links' => $links,
          ];
        }
        else {
          $items = [];
          foreach ($outdated_views as $view) {
            $items[] = $view->label();
          }
          $views_list = [
            '#theme' => 'item_list',
            '#items' => $items,
          ];
        }
        if (version_compare(\Drupal::VERSION, '10.3', '>=')) {
          $views_list = $this->renderer->renderInIsolation($views_list);
        }
        else {
          $views_list = $this->renderer->renderPlain($views_list);
        }
        $requirements['searchstax_outdated_views'] = [
          'title' => t('Outdated SearchStax "Relevance model" settings'),
          'description' => $this->t('The "Relevance model" settings for one or more views need to be adapted. Please review and resave the settings for the following view(s): @views', [
            '@views' => $views_list,
          ]),
          'severity' => $error,
        ];
      }
    }

    return $requirements;
  }

}
