<?php
namespace AppBundle\Service;

use Aura\Sql\ExtendedPdo;

class PdoHelper extends ExtendedPdo {
     private $dsn;
     public function __construct($database_host, $database_name, $database_user, $database_password)
     {
         $this->dsn = 'mysql:host=' . $database_host . ';dbname=' . $database_name . ';charset=utf8mb4';
         parent::__construct($this->dsn,$database_user,$database_password);
     }
     public function getDsn() : string {
         return $this->dsn;
     }
}