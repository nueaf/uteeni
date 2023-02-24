<?php

namespace Nueaf\Uteeni\QueryBuilder;

use Exception;

/**
 * Filter class for comparing a field with a certain value.
 */
class ActiveRecordQueryFilterColumnValue extends ActiveRecordQueryFilter
{
    /**
     * @var null
     */
    private $_cached_string;
    /**
     * @var null
     */
    private $_old_value;
    /**
     * @var mixed
     */
    private $value;
    private string $operator;
    private string $column;
    private ActiveRecordQuery $query;

    /**
     * Constructor for the filter
     *
     * @param ActiveRecordQuery $query The query instance the filter relates to
     * @param string $alias The alias of the table the matching column is placed in
     * @param string $column The name of the column in the alias table
     * @param mixed $value The value the column should match
     * @param string $operator The operator used for comparison. Allows inverting the match.
     */
    public function __construct(ActiveRecordQuery $query, $column, $value, $operator = "=")
    {
        $this->query = $query;
        $this->column = $column;
        $this->operator = $operator;
        $this->value = $value;

        $this->_old_value = null;
        $this->_cached_string = null;
    }

    public function __toString()
    {
        if ($this->_cached_string == null || $this->_old_value != $this->value) {
            $model = $this->query->getAlias($this->column["alias"]);
            $obj = new $model();
            try {
                $value = $obj->prepare_property($this->column["column"], $this->value);
            } catch (Exception $e) {
                error_log("Error happened during formatting of query's where clause: " . $e->getMessage());
                throw $e;
            }
            if (strtoupper($value) == "NULL") {
                if ($this->operator == "=" || $this->operator == "==") $this->operator = "IS";
                if ($this->operator == "!=" || $this->operator == "<>") $this->operator = "IS NOT";
            }

            $this->_cached_string = "{$this->column["expression"]} {$this->operator} $value";
            $this->_old_value = $this->value;
        }
        return $this->_cached_string;
    }
}