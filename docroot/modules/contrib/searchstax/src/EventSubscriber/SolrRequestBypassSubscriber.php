<?php

namespace Drupal\searchstax\EventSubscriber;

use Drupal\searchstax\Service\SearchStaxServiceInterface;
use Solarium\Core\Client\Response;
use Solarium\Core\Event\PreExecuteRequest as PreExecuteRequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens to general Solarium requests.
 *
 * Bypasses calls to blocked SearchStax endpoints.
 */
class SolrRequestBypassSubscriber implements EventSubscriberInterface {

  /**
   * The SearchStax utility service.
   */
  protected SearchStaxServiceInterface $utility;

  /**
   * Constructs a new class instance.
   *
   * @param \Drupal\searchstax\Service\SearchStaxServiceInterface $utility
   *   The SearchStax utility service.
   */
  public function __construct(SearchStaxServiceInterface $utility) {
    $this->utility = $utility;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreExecuteRequestEvent::class => [['preExecuteRequest']],
    ];
  }

  /**
   * Reacts to a Solarium client "pre execute" event.
   *
   * @param \Solarium\Core\Event\PreExecuteRequest $event
   *   The event.
   *
   * @noinspection PhpMissingParamTypeInspection
   */
  public function preExecuteRequest($event): void {
    $config = ['connector_config' => $event->getEndpoint()->getOptions()];
    if (!$this->utility->isSearchstaxSolr($config)) {
      return;
    }
    $path = $event->getRequest()->getHandler();
    $responses = [
      'admin/info/system' => ['lucene' => ['solr-spec-version' => '8.11.1']],
    ];
    if (isset($responses[$path])) {
      $response = json_encode($responses[$path]);
      $event->setResponse(new Response($response, [
        'HTTP/1.1 200 OK',
        'Content-type: application/json',
      ]));
    }
  }

}
