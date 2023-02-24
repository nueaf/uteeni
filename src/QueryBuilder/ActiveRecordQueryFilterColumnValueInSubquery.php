<?php

namespace Nueaf\Uteeni\QueryBuilder;

class ActiveRecordQueryFilterColumnValueInSubquery extends ActiveRecordQueryFilter
{

    private $subqueryColumn;
    private $subquery;
    /**
     * @var mixed|string
     */
    private $operator;
    private $outerColumn;
    private $query;

    public function __construct($query, $outerColumn, $subquery, $subqueryColumn, $operator = "IN")
    {
        $this->query = $query;
        $this->outerColumn = $outerColumn;
        $this->operator = $operator;
        $this->subquery = $subquery;

        $this->subqueryColumn = $this->subquery->translateColumnName($subqueryColumn);
    }

    public function __toString()
    {
        $subquery = $this->subquery->getSqlForField($this->subqueryColumn["expression"]);
        return "{$this->outerColumn["expression"]} {$this->operator} ($subquery)";
    }
}