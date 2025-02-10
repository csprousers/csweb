<?php

namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use PDOStatement;
use AppBundle\CSPro\CSProMySQLFormatter;

/**
 * This class is a handler for Monolog, which can be used
 * to write records in a MySQL table
 *
 * Modified from  Class MySQLHandler of * @package wazaari\MysqlHandler
 * to work with Silex/Symfony
 */
class CSProMySQLHandler extends AbstractProcessingHandler
{

    /**
     * @var bool defines whether the MySQL connection is been initialized
     */
    private bool $initialized = false;

    /**
     * @var PDO pdo object of database connection
     */
    protected $pdo;

    /**
     * @var PDOStatement statement to insert a new record
     */
    private $statement;

    /**
     * Constructor of this class, sets the PDO and calls parent constructor
     *
     * @param PDO $pdo                  PDO Connector for the database
     * @param bool $table               Table in the database to store the logs in
     * @param array $additionalFields   Additional Context Parameters to store in database
     * @param bool|int $level           Debug level which this handler should store
     * @param bool $bubble
     */
    public function __construct(
        PdoHelper $pdo = null,
        private $table = "cspro_log",
        private $additionalFields = [],
        $level = Logger::DEBUG,
        $bubble = true
    ) {
        if (!is_null($pdo)) {
            $this->pdo = $pdo;
        }
        parent::__construct($level, $bubble);
        $this->formatter = new CSProMySQLFormatter();
    }

    /**
     * Initializes this handler by creating the table if it not exists
     */
    private function initialize()
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `'.$this->table.'` '
            .'(id SERIAL PRIMARY KEY, channel VARCHAR(255), level_name VARCHAR(255), message LONGTEXT, `context` LONGTEXT, `created_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)'
        );

        //Read out actual columns
        $actualFields = [];
        $rs = $this->pdo->query('SELECT * FROM `'.$this->table.'` LIMIT 0');
        for ($i = 0; $i < $rs->columnCount(); $i++) {
            $col = $rs->getColumnMeta($i);
            $actualFields[] = $col['name'];
        }

        //Calculate changed entries
        $removedColumns = array_diff(
            $actualFields,
            $this->additionalFields,
            ['id', 'channel', 'level_name', 'message', 'created_time', 'context']
        );
        $addedColumns = array_diff($this->additionalFields, $actualFields);

        //Remove columns
        if (!empty($removedColumns)) {
            foreach ($removedColumns as $c) {
                $this->pdo->exec('ALTER TABLE `'.$this->table.'` DROP `'.$c.'`;');
            }
        }

        //Add columns
        if (!empty($addedColumns)) {
            foreach ($addedColumns as $c) {
                $this->pdo->exec('ALTER TABLE `'.$this->table.'` add `'.$c.'` TEXT NULL DEFAULT NULL;');
            }
        }

        //Prepare statement
        $columns = "";
        $fields = "";
        foreach ($this->additionalFields as $f) {
            $columns.= ", $f";
            $fields.= ", :$f";
        }

        $this->statement = $this->pdo->prepare(
            'INSERT INTO `'.$this->table.'` (channel, level_name, message, `context`'.$columns.')
            VALUES (:channel, :level_name, :message, :context'.$fields.')'
        );

        $this->initialized = true;
    }

    /**
     * Writes the record down to the log of the implementing handler
     *
     * @param  $record[]
     * @return void
     */
    protected function write(array $record) : void
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        //'context' contains the array
        $contentArray =['channel' => $record['channel'], 'level_name' => $record['level_name'], 'message' => $record['message'], 'context' => $record['formatted']];
        
        //Use the timestamp of the DB instead  
		// 'created_time' => $record['datetime']->format(\DateTime::RFC3339)
		

        //Fill content array with "null" values if not provided
     /*   if (count($this->additionalFields) > 0){
	        $contentArray = $contentArray + array_combine(
	            $this->additionalFields,
	            array_fill(0, count($this->additionalFields), null)
	        );
        }*/
        $this->statement->execute($contentArray);
    }
   
}
