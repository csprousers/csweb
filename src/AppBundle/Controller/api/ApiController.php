<?php

namespace AppBundle\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\Service\OAuthHelper;
use AppBundle\CSPro\CSProResponse;
use AppBundle\CSPro\DBConfigSettings;

class ApiController extends AbstractController implements ApiTokenAuthenticatedController {

    public function __construct(private OAuthHelper $oauthService, private PdoHelper $pdo, private LoggerInterface $logger, private string $env) {
        
    }

    #[Route('/token', methods: ['POST'])]
    public function getTokenAction(Request $request): CSProResponse {
        // Handle a request for an OAuth2.0 Access Token and send the response to the client
        //TODO: When running phpUnit tests the global $_SERVER headers are not set.  Adding them manually here to get  token
        $this->logger->debug('processing getToken request');
        if ('test' == $this->env) {
            if (!isset($_SERVER['REQUEST_METHOD'])) {
                $_SERVER['REQUEST_METHOD'] = $request->getMethod();
            }
            if (!isset($_SERVER['CONTENT_TYPE'])) {
                $_SERVER['CONTENT_TYPE'] = 'application/json';
            }
        }

        $oauthRequest = \OAuth2\Request::createFromGlobals();

        //When running phpUnit tests the $_POST or php.input is not set. Setting the JSON body here to get  token
        if ('test' == $this->env) {
            $oauthRequest->request = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        }

        $oauthResponse = $this->oauthService->handleTokenRequest($oauthRequest);

        if ($oauthResponse->isSuccessful()) {
            // Success
            $response = new CSProResponse($oauthResponse->getResponseBody(), $oauthResponse->getStatusCode(), $oauthResponse->getHttpHeaders());
        } else {
            // Translate the oauth error into CSPro error format
            $response = new CSProResponse();
            $oauthError = json_decode($oauthResponse->getResponseBody(), true, 512, JSON_THROW_ON_ERROR);
            $response->setError($oauthResponse->getStatusCode(), $oauthError['error'], $oauthError['error_description']);
            $response->headers->add($oauthResponse->getHttpHeaders());
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        $this->logger->debug($response->getContent());
        return $response;
    }

    #[Route('/server', name: 'server', methods: ['GET'])]
    public function getServerAction(Request $request): CSProResponse {

        $result = [];
        $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
        $result['deviceId'] = $dbConfigSettings->getServerDeviceId(); //server name
        $result['apiVersion'] = $this->getParameter('csweb_api_version');
        $response = new CSProResponse(json_encode($result, JSON_THROW_ON_ERROR));
        //remove quotes around quoted numeric values
        $response->setContent(preg_replace('/"(-?\d+\.?\d*)"/', '$1', $response->getContent()));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        //echo ' total time'.(microtime(true)-$app['start']);
        return $response;
    }

}
