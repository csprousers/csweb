<?php
namespace AppBundle\CSPro;
use Symfony\Component\HttpFoundation\Response;
//This class formats the JSON error response. It allows only a handful of http status codes to be used for statuses 
//The json response sent will be as shown {"type": "error", "status": "httpstatus" , "code": non_numeric_error_code , message: "error message"}
//TODO: help_uri  and additional "errors" details section if needed
class CSProResponse  extends Response
{
	protected  $type = 'success';
	public static $statusCodes = [200 => 'success', 201 => 'created', 202 => 'accepted', 204 => 'no_content', 302 => 'redirect', 304 => 'not_modified', 400 => 'bad_request', 401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 409 => 'conflict', 412 => 'precondition_failed', 500 => 'internal_server_error', 503 => 'unavailable'];
	
	public function __construct($parameters = '', $status = 200, $headers = [])
    {
        parent::__construct($parameters, $status, $headers);
		$this->headers->set('Content-Type', 'application/json');
    }
	
    public static function create($parameters ='', $status = 200, $headers = [])
    {
        return new static($parameters, $status, $headers);
    }

	public function isInvalid() : bool
    {
        return !(array_key_exists($this->statusCode,CSProResponse::$statusCodes));
    }
	public function setError($status, $code=null, $message = null)
    {
		$this->setStatusCode($status);

		$parameters = ['type' =>  'error', 'status' => $status, 'code' =>  $code, 'message' => $message];

		//set the default error code
		if(is_null($code)){
			$parameters['code'] = CSProResponse::$statusCodes[$status];
		}
		if(is_null($message)){
			$parameters['message'] ='';
		}
		if (!$this->headers->has('Content-Type') || 'text/javascript' === $this->headers->get('Content-Type')) {
            $this->headers->set('Content-Type', 'application/json');
        }
        $this->setContent(json_encode($parameters, JSON_THROW_ON_ERROR));
		//TODO: Error detail and ErrorUri
	}

}