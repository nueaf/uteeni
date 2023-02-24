<?php

namespace Nueaf\Uteeni\QueryBuilder;

class ActiveRecordQueryFilterColumnValueSubqueryMatch extends ActiveRecordQueryFilter
{

    private $subqueryColumn;
    private $subquery;
    /**
     * @var mixed|string
     */
    private $aggregate;
    /**
     * @var mixed|string
     */
    private $operator;
    private $value;
    private $query;

    public function __construct($query, $value, $subquery, $subqueryColumn, $aggregate = "MAX", $operator = "=")
    {
        $this->query = $query;
        $this->value = $value;
        $this->operator = $operator;
        $this->aggregate = $aggregate;
        $this->subquery = $subquery;

        $this->subqueryColumn = $this->subquery->translateColumnName($subqueryColumn);
    }

    public function __toString()
    {
        $operation = "{$this->aggregate}({$this->subqueryColumn["expression"]})";
        $subquery = $this->subquery->getSqlForField($operation);
        return "{$this->value} {$this->operator} ($subquery)";
    }
}