<?php

namespace Drupal\searchstax\EventSubscriber;

use Drupal\search_api_solr\SearchApiSolrException;
use Solarium\Core\Event\PostCreateResult as PostCreateResultEvent;
use Solarium\Core\Event\PostExecuteRequest as PostExecuteRequestEvent;
use Solarium\Exception\HttpException;
use Solarium\Exception\UnexpectedValueException;
use Solarium\QueryType\Server\Api\Query as ApiQuery;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Detects error responses from SearchStax Solr servers.
 */
class SolrErrorDetectionSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PostExecuteRequestEvent::class => 'postExecuteRequest',
      PostCreateResultEvent::class => 'postCreateResult',
    ];
  }

  /**
   * Reacts to the "post execute request" event.
   *
   * Provides a more helpful error message for failed MLT requests.
   *
   * @param \Solarium\Core\Event\PostExecuteRequest $event
   *   The event.
   *
   * @noinspection PhpMissingParamTypeInspection
   */
  public function postExecuteRequest(/* PostExecuteRequestEvent */ $event): void {
    if (
      $event->getRequest()->getHandler() !== 'mlt'
      || $event->getResponse()->getStatusCode() !== 403
    ) {
      return;
    }
    $body = $event->getResponse()->getBody();
    if (!$body || $body[0] !== '{') {
      return;
    }
    $data = json_decode($body, TRUE);
    if (!$data) {
      return;
    }
    if (substr($data['message'] ?? '', 0, 22) === 'Invalid key=value pair') {
      // Unfortunately, the Solarium version used in Drupal 8 does not have the
      // Request::setBody() method so we need to throw the exception that would
      // otherwise be thrown in SearchApiSolrBackend::search() ourselves to make
      // this work across Drupal versions.
      throw new SearchApiSolrException('More Like This functionality is currently not supported by SearchStax servers.', 403);
    }
  }

  /**
   * Reacts to the "post create result" event.
   *
   * Checks whether the response is JSON and either has an error status code in
   * responseHeader.status or has an "error" property.
   *
   * @param \Solarium\Core\Event\PostCreateResult $event
   *   The event.
   *
   * @noinspection PhpMissingParamTypeInspection
   */
  public function postCreateResult(/*PostCreateResultEvent*/ $event): void {
    if ($event->getResponse()->getStatusCode() !== 200) {
      return;
    }

    // Unfortunately, there is no way to tell whether the response is actually
    // JSON. We skip this method for "…/admin/file?file=…" requests where we
    // know it won't be, and also if the body is empty or does not start with
    // "{", but otherwise just catch the resulting exception and bail.
    $query = $event->getQuery();
    if (
      $query instanceof ApiQuery
      && substr($query->getHandler(), -10) === 'admin/file'
      && !empty($query->getParams()['file'])
    ) {
      return;
    }
    $body = $event->getResponse()->getBody();
    if (!$body || $body[0] !== '{') {
      return;
    }
    try {
      $data = $event->getResult()->getData();
    }
    catch (UnexpectedValueException $ignored) {
      return;
    }

    $status = $data['responseHeader']['status'] ?? NULL;
    // The responseHeader.status key is sometimes set to 0, which indicates a
    // successful request.
    if ($status === NULL || $status === 0) {
      $status = $data['error']['code'] ?? 200;
    }
    if ($status != 200 || !empty($data['error'])) {
      $message = $data['error']['msg'] ?? 'Error';
      $status = $status == 200 ? 500 : $status;
      throw new HttpException($message, (int) $status, $event->getResponse()->getBody());
    }
  }

}
