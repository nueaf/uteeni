<?php

namespace Nueaf\Uteeni\QueryBuilder;

class ActiveRecordQueryFilterColumnValueSubquery extends ActiveRecordQueryFilter
{

    public function __construct($query, $outerColumn, $subquery, $subqueryColumn, $aggregate = "MAX", $operator = "=")
    {
        $this->query = $query;
        $this->outerColumn = $outerColumn;
        $this->operator = $operator;
        $this->aggregate = $aggregate;
        $this->subquery = $subquery;

        $this->subqueryColumn = $this->subquery->translateColumnName($subqueryColumn);
    }

    public function __toString()
    {
        $operation = "{$this->aggregate}({$this->subqueryColumn["expression"]})";
        $subquery = $this->subquery->getSqlForField($operation);
        return "{$this->outerColumn["expression"]} {$this->operator} ($subquery)";
    }
}