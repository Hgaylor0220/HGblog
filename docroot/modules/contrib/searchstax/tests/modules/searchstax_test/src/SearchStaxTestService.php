<?php

declare(strict_types=1);

namespace Drupal\searchstax_test;

use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Provides test support for our integration test.
 */
class SearchStaxTestService implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiEvents::QUERY_PRE_EXECUTE => 'onQueryPreExecute',
    ];
  }

  /**
   * Reacts to the Search API module's "query pre-execute" event.
   *
   * Adds some additional query options to make testing easier.
   *
   * @param \Drupal\search_api\Event\QueryPreExecuteEvent $event
   *   The event.
   */
  public function onQueryPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();
    $query->setOption('search_api_facets', [
      'category' => [
        'field' => 'category',
        'limit' => 10,
        'operator' => 'and',
        'min_count' => 1,
        'missing' => FALSE,
      ],
    ]);
    $query->setOption('search_api_spellcheck', []);
  }

}
