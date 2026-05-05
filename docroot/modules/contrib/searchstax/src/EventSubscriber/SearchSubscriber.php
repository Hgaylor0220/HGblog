<?php

declare(strict_types=1);

namespace Drupal\searchstax\EventSubscriber;

use Drupal\search_api_solr\Event\PreQueryEvent;
use Drupal\search_api_solr\Event\SearchApiSolrEvents;
use Drupal\searchstax\Service\SearchStaxServiceInterface;
use Solarium\Core\Event\PostCreateResult;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens to events dispatched by the Search API Solr module.
 */
class SearchSubscriber implements EventSubscriberInterface {

  /**
   * The SearchStax utility service.
   */
  protected SearchStaxServiceInterface $searchStaxUtilityService;

  /**
   * Constructs a new class instance.
   *
   * @param \Drupal\searchstax\Service\SearchStaxServiceInterface|null $searchStaxUtilityService
   *   The searchstax utility service object.
   */
  public function __construct(?SearchStaxServiceInterface $searchStaxUtilityService = NULL) {
    if (!$searchStaxUtilityService) {
      @trigger_error('Constructing \Drupal\searchstax\EventSubscriber\SearchSubscriber without parameters is deprecated in searchstax:1.5.0 and will stop working in searchstax:2.0.0. Pass ($searchStaxUtilityService) instead. See https://www.drupal.org/node/3487182', E_USER_DEPRECATED);
    }
    $this->searchStaxUtilityService = $searchStaxUtilityService ?: \Drupal::service('searchstax.utility');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $listeners = [];
    // Check if the event even exists, otherwise the alter hook implementation
    // will be used.
    if (class_exists(SearchApiSolrEvents::class)) {
      $listeners[SearchApiSolrEvents::PRE_QUERY][] = ['preQueryAlter'];
    }
    $listeners[PostCreateResult::class] = ['postCreateResult'];
    return $listeners;
  }

  /**
   * Reacts to the Search API Solr module's pre-query event.
   *
   * @param \Drupal\search_api_solr\Event\PreQueryEvent $event
   *   The pre-query event.
   *
   * @see \Drupal\searchstax\Service\SearchStax::alterSolrQuery()
   */
  public function preQueryAlter(PreQueryEvent $event): void {
    $this->searchStaxUtilityService->alterSolrQuery($event->getSolariumQuery(), $event->getSearchApiQuery());
  }

  /**
   * Reacts to the creation of a Solarium search result object.
   *
   * @param \Solarium\Core\Event\PostCreateResult $event
   *   The event.
   *
   * @see \Drupal\searchstax\Service\SearchStax::postCreateSolariumResult
   */
  public function postCreateResult(/*PostCreateResult*/ $event): void {
    $this->searchStaxUtilityService->postCreateSolariumResult(
      $event->getQuery(),
      $event->getResponse(),
      $event->getResult(),
    );
  }

}
