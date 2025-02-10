<?php
namespace AppBundle\Service;

use AppBundle\CSPro\CSProOAuthPdo;
use \OAuth2\Server;
use \OAuth2\GrantType\UserCredentials;
use \OAuth2\GrantType\RefreshToken;
use Psr\Log\LoggerInterface;


class OAuthHelper extends Server {

    private $storage;

    public function __construct($database_host, $database_name, $database_user, $database_password) {

        $dsn = 'mysql:host=' . $database_host . ';dbname=' . $database_name . ';charset=utf8mb4';
        $this->storage = new CSProOAuthPdo(['dsn' => $dsn, 'username' => $database_user, 'password' => $database_password]);
        parent::__construct($this->storage);
        // Add the "User Credentials" grant type
        $this->addGrantType(new \OAuth2\GrantType\UserCredentials($this->storage));

        // the refresh token grant request will have a "refresh_token" field
        // with a new refresh token on each request
        $grantType = new \OAuth2\GrantType\RefreshToken($this->storage, ['always_issue_new_refresh_token' => true, 'refresh_token_lifetime' => 2_419_200]);
        // add the grant type to your OAuth server
        $this->addGrantType($grantType);
    }

}
