<?php
namespace Nueaf\Uteeni;
class Criterion
{
    
    public $model;
    
    public $property;
    
    public $operator;
    
    public $value;
    
    function __construct($property = null,$value = null,$operator = '=',$model = null)
    {
        $this->property    = $property;
        $this->value    = $value;
        $this->operator    = $operator;
        $this->model    = $model;
        
    }
    
    function __toString()
    {
        if ($this->model) {
            $meta = $this->model->getProperties();
            $value = $this->meta[$this->property] ? $this->model->prepare_property($this->property, $this->value) : $this->value;
            return $this->property . ' ' .$this->operator . ' ' .$value;
        } else {
            return $this->property . ' ' .$this->operator . ' ' .$this->value;
        }
    }
}