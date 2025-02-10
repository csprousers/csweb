<?php

namespace AppBundle\Security;

use AppBundle\CSPro\CSProResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

class ApiAccessDeniedHandler implements AccessDeniedHandlerInterface {

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException) {
        $this->logger->error($accessDeniedException->getMessage());
        $response = new CSProResponse ();
        $response->setError(403, 'access_denied', 'Access denied');
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

}
