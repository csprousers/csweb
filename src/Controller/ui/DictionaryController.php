<?php

namespace App\Controller\ui;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Psr\Log\LoggerInterface;
use App\Security\DictionaryVoter;
use App\Service\HttpHelper;

class DictionaryController extends AbstractController implements TokenAuthenticatedController {
    /*
     * @var string
     */
    /* private $csproRestApiUrl; */

    public function __construct(private \App\Service\HttpHelper $httpHelper /* , string $csproRestApiUrl */) {
        //   $this->csproRestApiUrl = $csproRestApiUrl;
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboardAction(Request $request): Response {
        $client = $this->httpHelper;
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        $response = $client->request('GET', 'dictionaries/', null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        //unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }
        $dictionaries = json_decode($response->getBody(), null, 512, JSON_THROW_ON_ERROR);
        $cspro_rest_api_url = $this->getParameter('cspro_rest_api_url');
        $cspro_rest_api_url = substr($cspro_rest_api_url, 0, -1);
        return $this->render('data.twig', ['dictionaries' => $dictionaries, 'cspro_rest_api_url' => $cspro_rest_api_url]);
    }

    #[Route('/dashboard/dictionaries/{dictname}', name: 'downloadDictionary', methods: ['GET'])]
    public function downloadAction(Request $request, $dictname): Response {
        $dictname = empty($dictname) ? '' : htmlspecialchars(strip_tags($dictname), ENT_QUOTES, 'UTF-8');
        $this->denyAccessUnlessGranted(DictionaryVoter::DATA_READ, $dictname);
        $client = $this->httpHelper;
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        //download the data
        $response = $client->request('GET', 'dictionaries/' . $dictname . '/syncspec', null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        //unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        $downloadResponse = new Response($response->getBody(), $response->getStatusCode());
        if (isset($response->getHeader('Content-Disposition')[0])) {
            $downloadResponse->headers->set('Content-Disposition', $response->getHeader('Content-Disposition')[0]);
        }
        return $downloadResponse;
    }

    #[Route('/dashboard/dictionaries', name: 'upload', methods: ['POST'])]
    public function uploadAction(Request $request): Response {
        $this->denyAccessUnlessGranted(DictionaryVoter::DICTIONARIES_WRITE);
        $client = $this->httpHelper;
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;

        //get the json user info to add
        $body = $request->getContent();

        //upload dictionary
        $response = $client->request('POST', 'dictionaries/', $body, ['Authorization' => $authHeader, 'Content-Type' => 'text/plain; charset=utf-8']);

        //unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        //create a symfony response object to return
        $uploadResponse = new Response($response->getBody(), $response->getStatusCode());
        $uploadResponse->headers->set('Content-Type', $response->getHeader('Content-Type'));
        return $uploadResponse;
    }

    #[Route('/dashboard/dictionaries/{dictname}/data', name: 'deleteDictionaryData', methods: ['DELETE'])]
    public function deleteDictionaryDataAction(Request $request, $dictname) {
        $dictname = empty($dictname) ? '' : htmlspecialchars(strip_tags($dictname), ENT_QUOTES, 'UTF-8');
        $this->denyAccessUnlessGranted(DictionaryVoter::DATA_CLEAR_DASHBOARD, $dictname);
        $client = $this->httpHelper;
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        //delete the dictionary data
        $response = $client->request('DELETE', 'dictionaries/' . $dictname . '/data', null, ['Authorization' => $authHeader, 'Accept' => 'application/json', 'x-csw-data-delete-dashboard' => true]);

        //unauthorized or expired redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        //create a symfony response object to return
        $deleteResponse = new Response($response->getBody(), $response->getStatusCode());
        $deleteResponse->headers->set('Content-Type', $response->getHeader('Content-Type'));
        return $deleteResponse;
    }

    #[Route('/dashboard/dictionaries/{dictname}', name: 'deleteDictionary', methods: ['DELETE'])]
    public function deleteAction(Request $request, $dictname): Response {
        $dictname = empty($dictname) ? '' : htmlspecialchars(strip_tags($dictname), ENT_QUOTES, 'UTF-8');
        $this->denyAccessUnlessGranted(DictionaryVoter::DICTIONARIES_WRITE, $dictname);
        $this->denyAccessUnlessGranted(DictionaryVoter::DATA_CLEAR_DASHBOARD, $dictname);
        $client = $this->httpHelper;
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        //delete the dictionary
        $response = $client->request('DELETE', 'dictionaries/' . $dictname, null, ['Authorization' => $authHeader, 'Accept' => 'application/json',  'x-csw-data-delete-dashboard' => true]);

        //unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        //create a symfony response object to return
        $deleteResponse = new Response($response->getBody(), $response->getStatusCode());
        $deleteResponse->headers->set('Content-Type', $response->getHeader('Content-Type'));
        return $deleteResponse;
    }

}
