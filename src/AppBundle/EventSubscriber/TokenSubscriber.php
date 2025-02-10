<?php

namespace AppBundle\EventSubscriber;

use AppBundle\Controller\ui\TokenAuthenticatedController;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Psr\Log\LoggerInterface;
use AppBundle\Security\ApiKeyUserProvider;
use AppBundle\Service\OAuthHelper;
use AppBundle\CSPro\CSProResponse;
use Symfony\Component\HttpFoundation\Request;

//checks if the access token is available before handling a request
class TokenSubscriber implements EventSubscriberInterface {

    public function __construct(private ApiKeyUserProvider $apikeyUserProvider, private UrlGeneratorInterface $router, private TokenStorageInterface $tokenStorage,  private OAuthHelper $oauthService, string $timeZone, private LoggerInterface $logger) {
        // Set default timezone to avoid warnings when using date/time functions 
        // and timezone not set in php.ini
       date_default_timezone_set($timeZone);
    }

    public function verifyResourceRequest(Request $request) {
        // ...  
        //getContent becomes empty when doing verifyResourceRequest check in the before event  on PUT requests 
        //one way to avoid is to remove this check on PUT requests before events. 
        //alternative is to call the getContent  on PUT requests. If this sttops working remove the before event and figure out 
        //to authenticate the token in PUT methods  -https://forum.phalconphp.com/discussion/6422/sending-put-request-with-content-type-other-than-texthtml-fails
        //this bug is due to the call to verifyResourceRequest in  bshaefer's oauth package.
        if ($request->getMethod() == 'PUT')
            $content = $request->getContent();

        if ($request->cookies->has('access_token')) {//if access token is set use it to set the HTTP_AUTHORIZATION
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $request->cookies->get('access_token');
        }

        if (!$this->oauthService->verifyResourceRequest(\OAuth2\Request::createFromGlobals())) {
            //$app['server']->getResponse()->send(); //send 401 response - Unauthorized;
            $response = new CSProResponse();
            $response->setError(401, 'Unauthorized', 'Unauthorized ');
            $response->headers->set('Content-Length', strlen($response->getContent()));
            return $response;
        }
        return null;
    }

    public function onKernelController(ControllerEvent $event) {
        $controller = $event->getController();

        /*
         * $controller passed can be either a class or a Closure.
         * This is not usual in Symfony but it may happen.
         * If it is a class, it comes in array format
         */
        if (!is_array($controller)) {
            return;
        }
        //Except login controller all other UI controllers should be derived from TokenAuthenticatedController
        //login controller once gets authenticated sets access_token cookie that is passed into the 
        //requests from other controllers. Validate before sending a response for the UI as all UI
        //requests now may not be requesting from the api endpoints (example RolesController)
        if ($controller[0] instanceof TokenAuthenticatedController) {
            if (!$event->getRequest()->cookies->has('access_token')) {
                $this->logger->debug("Missing token in the request. Redirecting to login screen.");
                $event->setController(function() {
                    $url = $this->router->generate('logout');
                    $this->logger->debug("Redirecting to url $url");
                    return new RedirectResponse($url);
                });
            } else {
                $request = $event->getRequest();
                $response = $this->verifyResourceRequest($request);
                if ($response !== null) {
                    $event->setController(function()use($response) {
                        $this->logger->info("Unauthorized: Redirecting response to login page");
                        $url = $this->router->generate('logout');
                        $this->logger->debug("Redirecting to url $url");
                        return new RedirectResponse($url);
                    });
                } else {
                    //set the token storage. 
                    $apiKey = $request->cookies->get('access_token');
                    $this->logger->debug("dashboard: setting token storage" . $apiKey);
                    $user = $this->apikeyUserProvider->loadUserByApiKey($apiKey);
                  //  $this->logger->debug("dashboard: setting token storage" . print_r($user, true));
                    $roles = $user->getRoles();
                    $providerKey = 'cspro_oauth_provider';
                    /*$tokenStorage = new PreAuthenticatedToken(
                            $user, $apiKey, $providerKey, $roles
                    );*/  //deprecation https://github.com/symfony/symfony/issues/44396
                    $tokenStorage = new PreAuthenticatedToken(
                            $user, $providerKey, $roles
                     );
                    //set tokenstorage for authorization
                   $this->tokenStorage->setToken($tokenStorage);
                }
            }
        }
    }

    public static function getSubscribedEvents() {
        return [KernelEvents::CONTROLLER => 'onKernelController'];
    }

}
