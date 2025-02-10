<?php

namespace AppBundle\CSPro;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Psr\Log\LoggerInterface;

class CSProJsonValidator {

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function validateEncodedJSON($data, $uri) {
        // Get the schema and data as objects
        $data = json_decode($data, null, 512, JSON_THROW_ON_ERROR);
        $this->validateDecodedJSON($data, $uri);
    }

    public function validateDecodedJSON($data, $uri) {
        // Get the schema and data as objects
        $strMsg = "JSON does not validate. Violations:\n";
        if (null == $data) {
            throw new HttpException(404, $strMsg . json_last_error_msg());
        }

        $schemaId = 'file://CSProSchema';
        $jsonSchemaObject = null;
        if (extension_loaded('apcu') && ini_get('apc.enabled')) {
            $jsonSchemaObject = apcu_fetch($schemaId, $bFound);
            if ($bFound === false)
                $jsonSchemaObject = null;
        }

        if ($jsonSchemaObject === null) {
            // Schema must be decoded before it can be used for validation
            $jsonSchema = file_get_contents('file://' . realpath(__DIR__ . '/swagger.json'));
            $jsonSchemaObject = json_decode($jsonSchema, null, 512, JSON_THROW_ON_ERROR);
        }
        // The SchemaStorage can resolve references, loading additional schemas from file as needed, etc.
        $schemaStorage = new \JsonSchema\SchemaStorage();

        // This does two things:
        // 1) Mutates $jsonSchemaObject to normalize the references (to file://mySchema#/definitions/integerData, etc)
        // 2) Tells $schemaStorage that references to file://mySchema... should be resolved by looking in $jsonSchemaObject
        $schemaStorage->addSchema($schemaId, $jsonSchemaObject);
        $factory = new \JsonSchema\Constraints\Factory($schemaStorage);

        // Provide $schemaStorage to the Validator so that references can be resolved during validation
        $jsonValidator = new \JsonSchema\Validator($factory);

        // Do validation (use isValid() and getErrors() to check the result)
        //https://github.com/justinrainbow/json-schema/issues/301
        $jsonValidator->validate($data, (object) ['$ref' => $schemaId . $uri]);
        //store the $jsonSchemaObject object for caching
        if (extension_loaded('apcu') && ini_get('apc.enabled')) {
            apcu_store($schemaId, $jsonSchemaObject);
        }
        if ($jsonValidator->isValid()) {
            $this->logger->debug('No Validation Errors:');
            return;
        } else {
            foreach ($jsonValidator->getErrors() as $error) {
                $strMsg .= sprintf("[%s] %s\n", $error ['property'], $error ['message']);
            }
            $this->logger->error('validtion errors: ' . $strMsg);
            throw new HttpException(404, $strMsg);
        }
    }

}
