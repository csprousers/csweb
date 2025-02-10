<?php

namespace AppBundle\Controller\ui;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;
use AppBundle\Security\ApiKeyUserProvider;
use AppBundle\CSPro\User\User;

class LoginController extends AbstractController {

    private $userName;
    private $lastError;
    private $lastErrorDetails;

    public function __construct(private HttpHelper $client, private TokenStorageInterface $tokenStorage, private ApiKeyUserProvider $apikeyUserProvider, private LoggerInterface $logger) {
        
    }

    #[Route('/', name: 'login', methods: ['GET'])]
    public function login(): Response {
        $configExists = file_exists(realpath(__DIR__ . '/../../config.php'));
        if ($configExists === false) {
            return new Response('This application needs to be configured. Please run <a href="/setup/index.php">setup</a>', Response::HTTP_FORBIDDEN);
        }
        return $this->render('login.twig', ['error' => $this->lastError, 'errorDetails' => $this->lastErrorDetails, 'last_username' => $this->userName]);
    }

    #[Route('/', name: 'login_check', methods: ['POST'])]
    public function loginAction(Request $request): Response {
        $this->userName = trim($_POST['_username']);

        $password = trim($_POST['_password']);
        $requestBody = json_encode(["client_id" => "cspro_android", "client_secret" => "cspro", "grant_type" => "password", "username" => $this->userName, "password" => $password], JSON_THROW_ON_ERROR);
        $response = $this->client->request('POST', 'token', $requestBody, ['Content-Type' => 'application/json',
            'Accept' => 'application/json']);

        $this->logger->debug('login user "' . $this->userName . '" status=' . $response->getStatusCode());
        $jsonResponse = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        //if the authentication failed.  redirect to the login page and set the lastError and the lastUserName 
        if (isset($jsonResponse['error_description'])) {
            $this->lastError = $jsonResponse['error_description'];
            $this->logger->warning('Login failed:' . $this->lastError);
            return $this->login();
        } else if ($response->getStatusCode() == 401 && isset($jsonResponse['message'])) {
            $this->lastError = $jsonResponse['message'];
            $this->logger->warning('Login failed:' . $this->lastError);
            return $this->login();
        } else if ($response->getStatusCode() == 200 && isset($jsonResponse['access_token'])) {
            $apiKey = $jsonResponse['access_token'];
            $user = $this->apikeyUserProvider->loadUserByApiKey($apiKey);
            $roles = $user->getRoles();
            $providerKey = 'cspro_oauth_provider';
            /*  $tokenStorage = new PreAuthenticatedToken(
              $user, $apiKey, $providerKey, $roles
              ); */ //deprecation https://github.com/symfony/symfony/issues/44396
            $tokenStorage = new PreAuthenticatedToken(
                    $user, $providerKey, $roles
            );

            //set tokenstorage for authorization
            $this->tokenStorage->setToken($tokenStorage);

            if (!$this->canUserLogin($user)) {
                return $this->login(); //redirect ??
            }

            //check if the access_token in available and then set the cookie 
            //if it succeeds redirect to the targetpath with the cookie set correctly with the token.
            $targetPath = $this->getLandingPage();
            $response = $this->redirect($targetPath);
            $response->headers->setCookie(new Cookie("access_token", $jsonResponse['access_token']));
            $response->headers->setCookie(new Cookie("username", $this->userName));
            return $response;
        } else {
            $this->logger->error('Failed to contact API:' . $response->getBody());
            $this->lastError = 'Failed to contact authentication server. Please contact your site administrator.';
            $this->lastErrorDetails = "Error code: {$response->getStatusCode()}. Message: {$response->getBody()}";
            return $this->login(); //redirect ??
        }
    }

    public function canUserLogin($user) {
        //check is granted for the user
        $hasLoginAccess = $this->isGranted('ROLE_USERS_ALL') || $this->isGranted('ROLE_DATA_ALL') ||
                $this->isGranted('ROLE_REPORTS_ALL') || $this->isGranted('ROLE_APPS_ALL') || $this->isGranted('ROLE_ROLES_ALL') || $this->isGranted('ROLE_SETTINGS_ALL');

        if (empty($user)) {
            $this->logger->warning('Login failed. User not found.');
            $this->lastError = 'Login failed. Unable to retrieve user information';
        } elseif ($user->getRoleId() == User::STANDARD_USER) {
            $this->logger->warning('Login failed. User is standard user.');
            $this->lastError = 'Login failed. Insufficient privileges.';
        } elseif ($hasLoginAccess || $user->getRoleId() == User::ADMINISTRATOR) {//built-in administrator
            return true;
        } else { //any other user
            $this->logger->warning('Login failed. User does not have login access. Role=' . $user->getRoleId());
            $this->lastError = 'Login failed. Insufficient privileges.';
        }
        return false;
    }

    #[Route('/logout', name: 'logout', methods: ['GET'])]
    public function logoutAction(): Response {
        $this->lastError = "";
        $this->lastErrorDetails = "";
        $this->userName = "";
        $response = $this->redirectToRoute('login');
        $response->headers->clearCookie('access_token');
        $response->headers->clearCookie('username');
        return $response;
    }

    public function getLandingPage() {
        $targetPath = '';
        if ($this->isGranted('ROLE_DATA_ALL')) {
            $targetPath = 'dashboard';
        } elseif ($this->isGranted('ROLE_REPORTS_ALL')) {
            $targetPath = 'sync-report';
        } elseif ($this->isGranted('ROLE_APPS_ALL')) {
            $targetPath = 'apps';
        } elseif ($this->isGranted('ROLE_USERS_ALL')) {
            $targetPath = 'users';
        } elseif ($this->isGranted('ROLE_ROLES_ALL')) {
            $targetPath = 'roles';
        }
        return $targetPath;
    }

}
