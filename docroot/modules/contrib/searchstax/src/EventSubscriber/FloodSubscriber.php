<?php

namespace Drupal\searchstax\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\search_api\Event\IndexingItemsEvent;
use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_solr\Solarium\Autocomplete\Query as AutocompleteQuery;
use Solarium\Core\Event\PreExecute as SolariumPreExecuteEvent;
use Solarium\QueryType\Suggester\Query as SuggesterQuery;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Implements flood protection for search and indexing operations.
 */
class FloodSubscriber implements EventSubscriberInterface {

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The flood controller.
   */
  protected FloodInterface $flood;

  public function __construct(ConfigFactoryInterface $config_factory, FloodInterface $flood) {
    $this->configFactory = $config_factory;
    $this->flood = $flood;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiEvents::QUERY_PRE_EXECUTE => 'onQuery',
      SearchApiEvents::INDEXING_ITEMS => 'onIndex',
      SolariumPreExecuteEvent::class => 'onSolrPreExecute',
    ];
  }

  /**
   * Executes a flood protection check for the given search event.
   *
   * @param \Drupal\search_api\Event\QueryPreExecuteEvent $event
   *   The event.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   Thrown in case the limit was reached.
   */
  public function onQuery(QueryPreExecuteEvent $event): void {
    $this->executeCheck('search');
  }

  /**
   * Executes a flood protection check for the given indexing event.
   *
   * @param \Drupal\search_api\Event\IndexingItemsEvent $event
   *   The event.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   Thrown in case the limit was reached.
   */
  public function onIndex(IndexingItemsEvent $event): void {
    $this->executeCheck('update');
  }

  /**
   * Executes a flood protection check for the given event, if applicable.
   *
   * Only used to protect autocomplete searches.
   *
   * @param \Solarium\Core\Event\PreExecute $event
   *   The event.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   Thrown in case the limit was reached.
   *
   * @noinspection PhpMissingParamTypeInspection
   */
  public function onSolrPreExecute(/* SolariumPreExecuteEvent */ $event): void {
    // @todo Use proper type hinting once we depend on Drupal 9+.
    if (
      version_compare(\Drupal::VERSION, '9.0', '>=')
      && !($event instanceof SolariumPreExecuteEvent)
    ) {
      $static = static::class;
      $method = __FUNCTION__;
      $expected = SolariumPreExecuteEvent::class;
      $actual = get_class($event);
      throw new \TypeError("Argument 1 passed to $static::$method() must be an instance of $expected, instance of $actual given");
    }
    $query = $event->getQuery();
    if ($query instanceof SuggesterQuery || $query instanceof AutocompleteQuery) {
      $this->executeCheck('search');
    }
  }

  /**
   * Executes a flood protection check on the given event.
   *
   * @param string $type
   *   The type of event "search" or "update".
   *
   * @throws \Drupal\search_api\SearchApiException
   *   Thrown in case the specific limit was reached.
   */
  public function executeCheck(string $type): void {
    $config = $this->configFactory->get('searchstax.settings')
      ->get('flood_protection');
    if (empty($config['enabled'])) {
      return;
    }
    $limit = $config["{$type}_limit"] ?? 0;
    $window = $config["{$type}_window"] ?? 0;
    if (!$limit || !$window) {
      return;
    }

    if (!$this->flood->isAllowed("searchstax.$type", $limit, $window)) {
      throw new SearchApiException("SearchStax flood protection: $type limit reached.");
    }

    $this->flood->register("searchstax.$type", $window);
  }

}
