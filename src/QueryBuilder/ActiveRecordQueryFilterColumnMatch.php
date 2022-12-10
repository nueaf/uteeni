<?php

namespace Nueaf\Uteeni\QueryBuilder;

class ActiveRecordQueryFilterColumnMatch extends ActiveRecordQueryFilter
{

    public function __construct($query, $column1, $column2, $operator = "=")
    {
        $this->query = $query;
        $this->column1 = $column1;
        $this->column2 = $column2;
        $this->operator = $operator;
    }

    public function __toString()
    {
        return "{$this->column1["expression"]} {$this->operator} {$this->column2["expression"]}";
    }

}