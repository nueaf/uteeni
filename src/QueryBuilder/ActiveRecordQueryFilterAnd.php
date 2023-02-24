<?php

namespace Nueaf\Uteeni\QueryBuilder;

/**
 * Simple filter which combines two other filters with an AND statment
 *
 * The statement will be placed in paranteses.
 */
class ActiveRecordQueryFilterAnd extends ActiveRecordQueryFilter
{

    private array $parts;
    
    /**
     * Constructor for the filter.
     *
     * @param ActiveRecordQueryFilter $part1 The left part of the expression
     * @param ActiveRecordQueryFilter $part2 The right part of the expression
     */
    public function __construct(array $parts)
    {
        $this->parts = $parts;
    }

    public function __toString()
    {
        return "(" . implode(" AND ", $this->parts) . ")";
    }
}