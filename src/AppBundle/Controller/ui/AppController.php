<?php

namespace AppBundle\Controller\ui;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;
use AppBundle\Service\HttpHelperErrorResponse;

class AppController extends AbstractController implements TokenAuthenticatedController {

    public function __construct(private LoggerInterface $logger, private HttpHelper $httpHelper) {
        
    }

    #[Route('/apps', name: 'apps', methods: ['GET'])]
    public function viewAppListAction(Request $request): Response {

        $this->denyAccessUnlessGranted('ROLE_APPS_ALL');

        $client = $this->httpHelper;
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        $response = $client->request('GET', 'apps', null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        //unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }
        $apps = json_decode($response->getBody(), null, 512, JSON_THROW_ON_ERROR);
        foreach ($apps as &$app) {
            //convert the application date from utc to default timezone date
            $dateTime = new \DateTime($app->buildTime);
            $dateTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $app->buildTime = $dateTime->format(\DateTime::RFC3339);
        }
        return $this->render('apps.twig', ['apps' => $apps]);
    }

    #[Route('/apps/{appname}', name: 'downloadApp', methods: ['GET'], requirements: ['appname' => '.+'])]
    public function downloadAction(Request $request, $appname): Response {
        $this->denyAccessUnlessGranted('ROLE_APPS_ALL');

        $client = $this->httpHelper;
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        //download the data
        $response = $client->request('GET', 'apps/' . $appname, null, ['Authorization' => $authHeader, 'Accept' => 'application/octet-stream']);

        //unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        $downloadResponse = new Response($response->getBody(), $response->getStatusCode());
        $downloadResponse->headers->set('Content-Disposition', $response->getHeader('Content-Disposition')[0] ?? "");
        return $downloadResponse;
    }

    #[Route('/apps/{appname}', name: 'deleteApp', methods: ['DELETE'], requirements: ['appname' => '.+'])]
    public function deleteAction(Request $request, $appname): Response {
        $this->denyAccessUnlessGranted('ROLE_APPS_ALL');

        $client = $this->httpHelper;
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        //download the data
        $response = $client->request('DELETE', 'apps/' . $appname, null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

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
