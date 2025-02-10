<?php
namespace AppBundle\CSPro;
use Aura\Sql\ExtendedPdo;
class CSProSchemaValidator
{
	/**
	 * @var PDO pdo object of database connection
	 */
	protected $pdo;
	protected  $app;

	function __construct(protected $currentVersion, ExtendedPdo $pdo = null, $logger =null) {
		$this->pdo = $pdo;
		$this->logger = $logger;
	}
	public function  isSchemaUpgradable(){ return getSchemaVersion() < $this->currentVersion ?  true :  false;}
	public function  isValidSchema(){
		return $this->getSchemaVersion() == $this->currentVersion ?  true :  false;
	}
	public function getSchemaVersion(){
		$app = [];
  //get the schema_version from the cspro_config table.
		if(!isset($this->pdo))throw new \Exception('PDO null pointer exception');
		$schemaVersion = 0;
		try {
			$result = $this->pdo->query("SELECT 1 FROM `cspro_config` LIMIT 1");
		} catch(\Exception $e) {
			$app['monolog']->addError('Failed to query cspro_config table', ["context" => (string)$e]);
			throw new \Exception('Failed to query cspro_config table', 0,  $e);
		}
		if($result != false){
			try {
				$schemaVersion = $this->pdo->fetchValue('SELECT value FROM `cspro_config` where name="schema_version"');
			}
			catch(\Exception $e) {
				$app['monolog']->addError('Failed to getSchemaVersion', ["context" => (string)$e]);
				throw new \Exception('Failed to getSchemaVersion', 0,  $e);
			}
		}
		return $schemaVersion;
	}
	//future functions to update schema changes
	public function upgradeSchema(){}
}