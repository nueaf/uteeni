<?php

namespace Nueaf\Uteeni\QueryBuilder;

class ActiveRecordQueryFilterCustomString extends ActiveRecordQueryFilter
{
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