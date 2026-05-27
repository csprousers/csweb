<?php

namespace App\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\PdoHelper;
use App\Service\OAuthHelper;
use App\CSPro\CSProResponse;
use Psr\Log\LoggerInterface;
use App\Security\MessagesVoter;

class MessagesController extends AbstractController implements ApiTokenAuthenticatedController {

    public function __construct(private OAuthHelper $oauthService, private PdoHelper $pdo, private LoggerInterface $logger) {
    }

    #[Route('/messages/', methods: ['POST'])]
    function syncMessage(Request $request): Response {
        $this->denyAccessUnlessGranted(MessagesVoter::MESSAGES_WRITE);
        $deviceId = $request->headers->get('x-csw-device');
        // the content will look like: {"timestamp":"2024-05-14T21:43:02Z","name":"my message","value":"with a value"}
        $receivedMessage = json_decode($request->getContent(), null, 512, JSON_THROW_ON_ERROR);

        $name = $receivedMessage->name;
        $value = isset($receivedMessage->value) ? json_encode($receivedMessage->value) : null;

        $stm = $this->pdo->prepare('INSERT INTO `cspro_messages` (`username`, `device`, `name`, `value`)
                                    VALUES (:username, :device, :name, :value)');

        $stm->bindParam(':username', $this->getUser()->getUserIdentifier());
        $stm->bindParam(':device', $deviceId);
        $stm->bindParam(':name', $name);
        $stm->bindParam(':value', $value);
        $stm->execute();

        // end users can fill in a response here as desired
        $messageResponse = null;

        return CSProResponse::createJsonResponse($messageResponse);
    }
}
