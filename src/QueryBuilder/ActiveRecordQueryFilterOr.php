<?php

namespace Nueaf\Uteeni\QueryBuilder;

/**
 * Simple filter which combines two other filters with an OR statment
 *
 * The statement will be placed in paranteses.
 */
class ActiveRecordQueryFilterOr extends ActiveRecordQueryFilter
{
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
        return "(" . implode(" OR ", $this->parts) . ")";
    }
}