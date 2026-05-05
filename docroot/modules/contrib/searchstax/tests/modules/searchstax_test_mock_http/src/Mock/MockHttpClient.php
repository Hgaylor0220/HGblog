<?php

declare(strict_types=1);

namespace Drupal\searchstax_test_mock_http\Mock;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

// cspell:ignore cscore

/**
 * Provides an HTTP client that just reads responses from local files.
 *
 * @noinspection PhpDocFinalChecksInspection
 */
class MockHttpClient extends Client implements ClientInterface {

  /**
   * The messenger service.
   */
  protected MessengerInterface $messenger;

  /**
   * The key-value factory.
   */
  protected KeyValueFactoryInterface $keyValueFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    MessengerInterface $messenger,
    KeyValueFactoryInterface $key_value_factory
  ) {
    /* @see \Drupal\Core\Http\ClientFactory::fromOptions() */
    parent::__construct([
      'verify' => TRUE,
      'timeout' => 30,
      'headers' => [],
      'proxy' => [
        'http' => NULL,
        'https' => NULL,
        'no' => [],
      ],
    ]);

    $this->messenger = $messenger;
    $this->keyValueFactory = $key_value_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface {
    // Unfortunately, the Drupal 8 tests run with a version of Guzzle that does
    // not have Create::promiseFor() yet but uses a function instead. We need to
    // support both for now.
    // @todo Remove once we depend on Drupal 9+.
    $f = '\GuzzleHttp\Promise\promise_for';
    $promise_for = function_exists($f) ? $f : [Create::class, 'promiseFor'];

    // Special case: SearchStax forbids access to the /admin handlers, so report
    // an error for those accordingly.
    if (
      substr($request->getUri()->getHost(), -15) === '.searchstax.com'
      && substr($request->getUri()->getPath(), -18) === '/admin/info/system'
    ) {
      $response = new Response(403);
      return $promise_for($response);
    }

    $key_value = $this->keyValueFactory->get('searchstax_test_mock_http');
    $data_dir = $key_value->get('data_dir');
    if (!$data_dir || !is_dir($data_dir)) {
      throw new BadResponseException('The "data_dir" setting is not set.', $request, new Response(500));
    }
    $lookup_file = "$data_dir/lookup.json";
    $lookup = json_decode(file_get_contents($lookup_file), TRUE);
    assert(is_array($lookup));
    $uri_str = $request->getUri()->__toString();
    $request_data = [
      'method' => $request->getMethod(),
      'uri' => $uri_str,
    ];
    $auth_header = $request->getHeader('Authorization');
    if ($auth_header) {
      if (count($auth_header) === 1) {
        $auth_header = reset($auth_header);
      }
      $request_data['auth_header'] = $auth_header;
    }
    $body = $request->getBody()->getContents();
    if ($body) {
      $content_type = $request->getHeader('Content-type');
      if (count($content_type) === 1) {
        $content_type = reset($content_type);
      }
      if ($content_type) {
        $request_data['content_type'] = $content_type;
      }
      // Replace any datetime values with a placeholder as these would be hard
      // to impossible to correctly predict.
      $body = preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', '{{SOLR_DATE_TIME}}', $body);
      // Replace the base URL, which is included in Solr documents. We need to
      // account for JSON-encoding, though.
      $base_url = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
      $body = str_replace(json_encode($base_url), '"{{SITE}}"', $body);
      if ($content_type === 'application/json') {
        $data = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
        ksort($data);
        $request_data['body'] = $data;
      }
      else {
        $request_data['body'] = $body;
      }
    }
    // Special handling for requests that were redirected from requestAsync().
    foreach ($options['requestAsync']['multipart'] ?? [] as $part) {
      // For file uploads, use the MD5 hash of the file contents.
      if (is_resource($part['contents'] ?? NULL)) {
        $file = $part['contents'];
        $part['contents'] = md5(fread($file, 104857600));
      }
      $request_data['multipart'][] = $part;
    }

    // Apply some workarounds to make tests pass against a wider variety of
    // Drupal versions.
    static::workaroundSolrRequestsInDrupal8($request_data);

    $request_data_json = json_encode($request_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $request_hash = md5($request_data_json);
    $request_id = $lookup[$request_hash] ?? NULL;
    if (empty($request_id)) {
      $message = "Could not find HTTP response stored for the following request data:\n$request_data_json";
      // Try to find a semi-match, to make debugging easier.
      foreach ($lookup as $other_request_label) {
        $file = "$data_dir/requests/$other_request_label.json";
        $other_request_data = json_decode(file_get_contents($file), TRUE, JSON_THROW_ON_ERROR);
        $mismatches = static::getMismatchedKeys($request_data, $other_request_data);
        if (!$mismatches) {
          $message .= "\n\"$other_request_label\" matches, but has wrong hash or order.";
        }
        elseif (count($mismatches) === 1 && $mismatches !== ['uri']) {
          $field = reset($mismatches);
          $message .= "\n\"$other_request_label\" almost matches, but has wrong \"$field\".";
        }
      }
      // To make such errors easier to debug (especially if some code catches
      // this exception), also add it as a message to the page.
      $this->messenger->addError(new FormattableMarkup('<pre>@message</pre>', ['@message' => $message]));
      throw new BadResponseException($message, $request, new Response(500));
    }

    // Make it possible to return different responses for the same request by
    // keeping count of the number of times each request has been sent.
    $request_counts = $key_value->get('request_counts', []);
    $request_counts += [$request_id => 0];
    ++$request_counts[$request_id];
    $key_value->set('request_counts', $request_counts);
    if (is_dir("$data_dir/responses/{$request_id}--{$request_counts[$request_id]}")) {
      $request_id .= "--{$request_counts[$request_id]}";
    }

    $requests = $key_value->get('requests');
    $requests[] = $request_id;
    $key_value->set('requests', $requests);

    $response_dir = "$data_dir/responses/{$request_id}";
    $metadata = json_decode(file_get_contents("$response_dir/metadata.json"), TRUE);
    $response = new Response(
      $metadata['status'],
      [
        'Content-type' => [$metadata['content_type']],
      ],
      file_get_contents("$response_dir/{$metadata['body_file']}"),
    );
    return $promise_for($response);
  }

  /**
   * Detects mismatched keys in two associative arrays.
   *
   * @param array $array_1
   *   An associative array.
   * @param array $array_2
   *   Another associative array.
   *
   * @return string[]
   *   All keys with mismatched values in the two arrays.
   */
  protected static function getMismatchedKeys(array $array_1, array $array_2): array {
    $fields = [];
    foreach ($array_1 as $key => $value) {
      if (!array_key_exists($key, $array_2) || $value !== $array_2[$key]) {
        $fields[] = $key;
      }
    }
    foreach (array_diff_key($array_2, $array_1) as $key => $value) {
      $fields[] = $key;
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function sendRequest(RequestInterface $request): ResponseInterface {
    // Copied from the parent since it doesn't implement this method when using
    // Drupal 8.
    $options[RequestOptions::SYNCHRONOUS] = TRUE;
    $options[RequestOptions::ALLOW_REDIRECTS] = FALSE;
    $options[RequestOptions::HTTP_ERRORS] = FALSE;

    return $this->sendAsync($request, $options)->wait();
  }

  /**
   * {@inheritdoc}
   */
  public function requestAsync($method, $uri = '', array $options = []): PromiseInterface {
    $request = new Request($method, $uri, $options['headers'] ?? []);
    unset($options['headers']);
    $options = [
      'requestAsync' => $options,
    ];
    return $this->sendAsync($request, $options);
  }

  /**
   * Fixes inconsistent "fl" params used by different versions of Solr module.
   *
   * @param array $request_data
   *   The request data, passed by reference.
   */
  protected static function workaroundSolrRequestsInDrupal8(array &$request_data): void {
    $request_data['uri'] = str_replace(
      '&fl=%2A%2Cscore&',
      '&fl=ss_search_api_id%2Css_search_api_language%2Cscore&',
      $request_data['uri'],
    );
  }

}
