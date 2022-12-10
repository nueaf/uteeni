<?php

namespace Nueaf\Uteeni;

class Criteria {
	private $criteria = array();
	
	function add($criteria,$operand = 'and'){
		$first = TRUE;
		
		if(is_array($criteria)){
			foreach ($criteria as $crit){
				if(!is_a($crit,'criterion')){
					throw new Exception("Criterion object rules not upheld, in multiple criteria");
				}
				if($operand == 'or' && $first){
					$set_operand = 'or';
					$first = FALSE;
				} else {
					$set_operand = 'and';
				}
				$this->criteria[] = array(
					'operand'	=> $set_operand,
					'criterion' => $crit
				);
			}
		} else {
				if(!is_a($criteria,'criterion')){
					throw new Exception("Criterion object rules not upheld");
				}
				$this->criteria[] = array(
					'operand'	=> $operand,
					'criterion' => $criteria
				);
		}
	}
	
	function addor($criteria){
		$this->add($criteria,'or');
	}
	
	function setmodel($model){
		foreach ($this->criteria as $key => $val){
			if (!$this->criteria[$key]->model){
				$this->criteria[$key]->model = $model;
			}
		}
	}
	
	function __toString(){
		$first = TRUE;
		$retval = '';

		foreach ($this->criteria as $criterion){
			$operand = $first ? '' : $criterion['operand'];
			$retval .= $operand.' '.$criterion['criterion'].' ';
			$first = FALSE;
		}

		return $retval;
	}
}