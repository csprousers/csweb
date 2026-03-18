<?php

namespace App\EventListener;

// src/EventListener/ExceptionListener.php
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use App\CSPro\CSProResponse;
use Psr\Log\LoggerInterface;

class ExceptionListener {

    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function onKernelException(ExceptionEvent $event) {
        try {
            $exception = $event->getThrowable();
            $this->logger->error('Uncaught exception: ' . $exception->getMessage(), ['exception' => $exception]);
            if ($exception instanceof AccessDeniedException || $exception instanceof AccessDeniedHttpException) {
                $message = $exception->getMessage() ?: 'You do not have permission to access this resource';
                $response = new CSProResponse($message, CSProResponse::HTTP_FORBIDDEN);
                $event->setResponse($response);
                return;
            }
            // Customize the response object to display the exception details
            $response = new CSProResponse('Internal Server Error', CSProResponse::HTTP_INTERNAL_SERVER_ERROR);
            // Set the response to the event
            $event->setResponse($response);
        } catch (\Throwable $e) {
            // Handle or log the exception occurring in the listener itself
            $this->logger->critical('Exception in exception listener: ' . $e->getMessage());
        }
    }

}
