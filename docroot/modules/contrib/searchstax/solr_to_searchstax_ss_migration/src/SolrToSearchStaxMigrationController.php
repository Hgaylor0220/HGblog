<?php

declare(strict_types=1);

namespace Drupal\solr_to_searchstax_ss_migration;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\solr_to_searchstax_ss_migration\Form\CloneIndexesForm;
use Drupal\solr_to_searchstax_ss_migration\Form\SearchViewSwitchIndexForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides routes for the Solr to SearchStax Site Search Migration module.
 */
class SolrToSearchStaxMigrationController extends ControllerBase {

  /**
   * The module's utility service.
   */
  protected UtilityServiceInterface $utility;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $controller = new SolrToSearchStaxMigrationController();
    $controller->entityTypeManager = $container->get('entity_type.manager');
    $controller->utility = $container->get('solr_to_searchstax_ss_migration.utility');
    return $controller;
  }

  /**
   * Displays an overview of available actions.
   *
   * @return array
   *   A render array.
   */
  public function overview(): array {
    try {
      $server_storage = $this->entityTypeManager->getStorage('search_api_server');
      $index_storage = $this->entityTypeManager->getStorage('search_api_index');
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      // Should never happen.
      throw new \RuntimeException($e->getMessage());
    }

    $build = [];
    $info = (object) [
      'solr_servers_to_migrated' => [],
      'indexes_to_copies' => [],
      'any_migration_available' => FALSE,
    ];

    // List Solr servers.
    /** @var \Drupal\search_api\ServerInterface[] $servers */
    $servers = $server_storage->loadMultiple();
    $this->listSolrServers($build, $servers, $info);

    // List indexes on Solr servers.
    $indexes = $index_storage->loadMultiple();
    $build['indexes'] = $this->formBuilder()->getForm(CloneIndexesForm::class, $indexes, $servers, $info);

    // List views that can be switched between indexes.
    // @todo Check for the "administer views" permission if available?
    $build['views'] = $this->formBuilder()->getForm(SearchViewSwitchIndexForm::class, $indexes, $info);

    if (!$info->any_migration_available) {
      $this->messenger()->addWarning($this->t('Nothing left to migrate. If everything is working correctly, you can now uninstall this module as well as the Solr module.'));
    }

    return $build;
  }

  /**
   * Adds a list of Solr servers to the given build array.
   *
   * @param array $build
   *   The build array, passed by reference.
   * @param \Drupal\search_api\ServerInterface[] $servers
   *   All search servers, keyed by ID.
   * @param object $info
   *   A plain object containing the following (public) properties to fill:
   *   - solr_servers_to_migrated: Should be filled with an associative array
   *     mapping the IDs of all Solr servers to the ID of the SearchStax search
   *     servers to which they were migrated, if available, or to NULL
   *     otherwise.
   *   - any_migration_available: Should be set to TRUE in case any action can
   *     be taken on this form.
   */
  protected function listSolrServers(array &$build, array $servers, object $info): void {
    $build['servers']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Migrate search servers'),
    ];
    $build['servers']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Use an Solr server as the source to set up a SearchStax app and an accompanying Search API server.'),
    ];

    $rows = [];
    foreach ($servers as $server_id => $server) {
      if (!$this->utility->isNonSearchStaxSolrServer($server)) {
        continue;
      }
      $migrated_server = $this->utility->getMigratedServer($server_id, $servers);
      try {
        if ($migrated_server) {
          $info->solr_servers_to_migrated[$server_id] = $migrated_server->id();
          $status = $this->t('Migrated to server <a href=":url">@name</a>.', [
            '@name' => $migrated_server->label(),
            ':url' => $migrated_server->toUrl('canonical')->toString(),
          ]);
          $operation = '';
        }
        else {
          $info->solr_servers_to_migrated[$server_id] = NULL;
          $info->any_migration_available = TRUE;
          $status = $this->t('Ready to be migrated.');
          $url = new Url('solr_to_searchstax_ss_migration.migrate_server_form', [
            'server_id' => $server_id,
          ]);
          $operation = new Link($this->t('Migrate'), $url);
        }
        $label = $server->toLink(NULL, 'canonical');
      }
      catch (EntityMalformedException $e) {
        $label = $server->label();
        $status = $this->t('Server is malformed: @error', ['@error' => $e->getMessage()]);
        $operation = '';
      }
      $rows[(string) $server->label()] = [
        $label,
        $status,
        $operation,
      ];
    }
    ksort($rows);
    $build['list'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Server'),
        $this->t('Status'),
        $this->t('Operations'),
      ],
      '#rows' => array_values($rows),
      '#empty' => $this->t('No Solr servers found.'),
    ];
  }

}
