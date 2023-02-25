<?php

namespace Nueaf\Uteeni\QueryBuilder;

class ActiveRecordSubQuery extends ActiveRecordQuery
{
    protected $outerQuery;

    /**
     * @param ActiveRecordQuery $outerQuery
     * @param $model
     * @throws \Exception
     */
    public function __construct(ActiveRecordQuery $outerQuery, $model)
    {
        $this->outerQuery = $outerQuery;
        parent::__construct($model);
    }

    /**
     * @param $modelName
     * @return mixed|string
     */
    public function createAlias($modelName)
    {
        return $this->outerQuery->createAlias($modelName);
    }

    /**
     * @param $alias
     * @return bool
     */
    public function hasAlias($alias): bool
    {
        return $this->outerQuery->hasAlias($alias);
    }

    /**
     * @param $alias
     * @return mixed|null
     */
    public function getAlias($alias)
    {
        return $this->outerQuery->getAlias($alias);
    }

    /**
     * @param $fromAlias
     * @param $joinType
     * @return array|mixed|null[]
     */
    public function getJoinedAliases($fromAlias = null, $joinType = null)
    {
        $local = parent::getJoinedAliases($fromAlias, $joinType);
        $outer = $this->outerQuery->getJoinedAliases($fromAlias, $joinType);
        return array_merge($outer, $local);
    }
}