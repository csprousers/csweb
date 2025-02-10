<?php
namespace AppBundle\CSPro\Dictionary;

/**
* Language in a CSPro dictionary.
*
*/
class Language
{
	/**
    * Create from name and label.
	*
    * @param string $name
    * @param string $label
	*/
	public function __construct(private $name, private $label)
 {
 }
	
	public function getName(){
		return $this->name;
	}

	public function setName($name){
		$this->name = $name;
	}

	public function getLabel(){
		return $this->label;
	}

	public function setLabel($label){
		$this->label = $label;
	}
};