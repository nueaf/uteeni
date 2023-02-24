<?php

namespace Nueaf\Uteeni\QueryBuilder;

class ActiveRecordQueryFilterCustomString extends ActiveRecordQueryFilter
{
    private string $query; 
    private string $filterString;
    
    public function __construct($query, $filterString)
    {
        $this->query = $query;
        $this->filterString = $filterString;
    }

    public function __toString()
    {
        return "(" . $this->filterString . ")";
    }
}