<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;

// Fake Guzzle response class used
// when Guzzle exception doesn't have response
// so that clients can't treat the case as same
// as errors with response.
class HttpHelperErrorResponse {

    public function __construct(private $message)
    {
    }

    public function getStatusCode() {
        return 500;
    }

    public function getHeaders() {
        return [];
    }

    public function getHeader($h) {
        return '';
    }

    public function getBody() {
        return $this->message;
    }

}

// Helper class for making Guzzle http requests.
// Mostly handles correctly dealing with and logging
// Guzzle exceptions.
class HttpHelper {

    public function __construct(private Client $client, private $baseUri, private LoggerInterface $logger)
    {
    }

    public function request($method, $path, $body, $headers) {
        $uri = $this->baseUri . $path;
        $response = null;
        try {
            $response = $this->client->request($method, $uri, ['body' => $body, 'headers' => $headers]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $response = new HttpHelperErrorResponse($e->getMessage());
            $this->logger->error('RequestException calling: ' . $uri);
            $this->logger->error($e);
        } catch (\Exception $e) {
            // Despite what Guzzle doc says, there are cases where
            // Guzzle throws an exception that is not derived
            // from RequestException.
            $response = new HttpHelperErrorResponse($e->getMessage());
            $this->logger->error('Exception calling: ' . $uri);
            $this->logger->error($e);
        }

        return $response;
    }

}
