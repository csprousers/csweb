<?php

namespace AppBundle\CSPro\User;

use AppBundle\CSPro\User\User;
use Psr\Log\LoggerInterface;

class UserValidator {

    private $errors;
    public function __construct(private $rolesMap, private $logger)
    {
        $this->errors = [];
    }
    public function isValid() {
        return count($this->errors) == 0;
    }

    public function getErrors() {
        return $this->errors;
    }

    public function reset() {
        $this->errors = [];
    }

    public function addError($errMsg) {
        array_push($this->errors, $errMsg);
    }

    // validate user data
    public function validate(User $user,  $lineNumber = null) {
       // $this->logger->debug('validating user ' . print_r($user, true));
        $errMsg = "";
        if ((strlen($user->getUsername()) < 4) && !ctype_alnum($user->getUsername())) {
            $errMsg = $errMsg . "Invalid User Name;";
        }

        if (!ctype_alpha($user->getLastName())) {
            $errMsg = $errMsg . "Invalid Last Name;";
        }
        if (!ctype_alpha($user->getFirstName())) {
            $errMsg = $errMsg . "Invalid First Name;";
        }
        if (strlen(trim($user->getPassword())) < 8){
            $errMsg = $errMsg . "Password must be at least 8 characters;";
        }
        if(!array_key_exists($user->getRoleId(),$this->rolesMap)){  
           $errMsg = $errMsg . 'Invalid Role Type: ' . $user->getRoleId();
        }

        $email = $user->getEmail();
        if (!empty(trim($email))) {
            $isValidEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
            if ($isValidEmail === false) {
                $errMsg = $errMsg . 'Invalid email address;';
            }
        }

        if(!empty($errMsg)){
            if (isset($lineNumber)) {
                $errMsg = "Error: " . $errMsg . " Line number:" . $lineNumber . " User:[" . $user->toString() . "]";
            } else{
                $errMsg =  "Error: " . $errMsg . " User:[" . $user->toString() . "]";
            }
        }
        if (!empty($errMsg)) {//add the error message if there are errors
            $this->addError($errMsg);
        }
        
        return empty($errMsg);
    }

    public function validateImportUsers($content, $headerRow) {
        
        //remove any blanks lines
        $content = preg_replace('/^[ \t]*[\r\n]+/m', '', $content);
        $lines = array_filter(explode("\n", $content), 'strlen');

        $user = new User(null, null, null, null, null);
        $this->reset();
        
        $this->logger->debug("Number of lines: " . count($lines) );
        $lineCount = count($lines);
        if ($headerRow === true)
            $lineCount = $lineCount > 0 ? --$lineCount : 0;
        
        if($lineCount === 0){
            $this->addError('Import file is empty.');
            return false;
        }
        
        $this->logger->debug("Inside Validate Import");
        $startLine = 0;
        if($headerRow === true){
            $startLine = 1;
            $this->logger->debug("Ignoring header row");
        }
        for ($i = $startLine; $i < count($lines); $i++) {

            $csv = str_getcsv($lines[$i], ",", '"', "\n");
            if ($csv[0] == null)
                break;
            //set the user attributes to null if they are not set
            for ($attrIndex = 0; $attrIndex < 7; $attrIndex++) {
                if (!isset($csv[$attrIndex])){
                    $csv[$attrIndex] = null;
                }
                else{
                    $csv[$attrIndex] = trim($csv[$attrIndex]);
                }
            }
            if (empty(trim($csv[5])))
                $csv[5] = null; //email
            if (empty(trim($csv[5])))
                $csv[6] = null; //phone
            $user->setAllMembers($csv[0], $csv[1], $csv[2], $csv[3], $csv[4], $csv[5], $csv[6]);
            $lineNumber = $i +1;
            $this->validate($user,$lineNumber);
        }
        $isValid = $this->isValid();
        return $isValid;
    }

}
