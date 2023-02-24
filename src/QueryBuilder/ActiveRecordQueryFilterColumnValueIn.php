<?php

namespace Nueaf\Uteeni\QueryBuilder;

/**
 * Filter class for comparing a field with a list of values.
 */
class ActiveRecordQueryFilterColumnValueIn extends ActiveRecordQueryFilter
{
    private string $operator;
    private array $values;
    private string $column;
    private ActiveRecordQuery $query;

    /**
     * Constructor for the filter
     *
     * @param ActiveRecordQuery $query The query instance the filter relates to
     * @param string $alias The alias of the table the matching column is placed in
     * @param string $column The name of the column in the alias table
     * @param array $values The values the column should be within
     * @param string $operator The operator used for comparison. Allows inverting the match.
     */
    public function __construct(ActiveRecordQuery $query, $column, array $values, $operator = "IN")
    {
        $this->query = $query;
        $this->column = $column;
        $this->values = $values;
        $this->operator = $operator;
    }

    public function __toString()
    {
        $model = $this->query->getAlias($this->column["alias"]);
        $obj = new $model();

        $values = array();
        foreach ($this->values as $value) {
            $values[] = $obj->prepare_property($this->column["column"], $value);
        }
        $values = implode(",", $values);

        return "{$this->column["expression"]} {$this->operator} ($values)";
    }
}