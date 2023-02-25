<?php

namespace Nueaf\Uteeni\QueryBuilder;

use Exception;
use Nueaf\Uteeni\Database;
use Nueaf\Uteeni\SqlSyntaxor;
use PDO;

/**
 * @method ActiveRecordQuery filterBy($col, $value, $operator = "=") {
 * @method ActiveRecordQuery filterByOr(ActiveRecordQueryFilter $part1, ActiveRecordQueryFilter $part2) {
 * @method ActiveRecordQuery filterByAnd(ActiveRecordQueryFilter $part1, ActiveRecordQueryFilter $part2) {
 * @method ActiveRecordQuery filterBySubQuery($col, ActiveRecordSubQuery $subquery, $subqueryCol, $aggregate = "MAX", $operator = "=") {
 * @method ActiveRecordQuery filterByIn($col, array $value, $operator = "IN") {
 * @method ActiveRecordQuery filterByInSubQuery($col, ActiveRecordSubQuery $subquery, $subqueryCol, $operator = "IN") {
 * @method ActiveRecordQuery filterByMatch($col1, $col2, $operator = "=") {
 */
class ActiveRecordQuery
{
    /**
     * Indicates Ascending sorting
     */
    const SORT_ASC = "ASC";

    /**
     * Indicates descending sorting
     */
    const SORT_DESC = "DESC";

    const JOIN_INNER = "INNER";

    const JOIN_LEFT = "LEFT";

    protected $obj;
    protected $aliases = array();
    protected $mainAlias = null;
    protected $sorts = array();
    protected $limit = 0;
    protected $offset = 0;
    protected $groups = array();
    protected $aggregates = array();
    protected $calculatedColumns = array();
    protected $joins = array();
    protected $filters = array();
    protected $fullJoin = false;

    /**
     * Constructor for the query object.
     *
     * The constuctor expects to known the table or view model to select from.
     *
     * @param string $model The name of the model class to be used as base for the query
     * @throws Exception
     * @throws Exception
     */
    public function __construct($model)
    {
        if (!class_exists($model)) {
            throw new Exception("Could not find class for model: $model");
        }

        if (!is_subclass_of($model, "ActiveRecord")) {
            throw new Exception("Class is not an ActiveRecord class: $model");
        }

        $this->obj = new $model();
        $this->mainAlias = $this->createAlias($model);

        //Set up default sorting
        $this->setSort();
    }

    /**
     * @param $class
     * @param $join
     * @return string
     */
    public static function reverseJoin($class, $join): string
    {
        $trimmedJoin = trim($join, ".");
        $remainingJoin = null;
        if (strpos($trimmedJoin, ".")) {
            list($firstJoin, $remainingJoin) = explode(".", $trimmedJoin, 2);
        } else {
            $firstJoin = $trimmedJoin;
        }

        $obj = new $class();
        $reversed = $obj->reversedJoin($firstJoin);

        $result = "";
        if ($remainingJoin) {
            $result = self::reverseJoin($reversed["class"], $remainingJoin) . "uteeni";
        }
        $result .= $reversed["assoc"];

        if (substr($join, 0, 1) == ".") {
            $result = ".$result";
        }
        if (substr($join, -1, 1) == ".") {
            $result = "$result.";
        }

        return $result;
    }

    /**
     * @param $modelName
     * @return mixed|string
     */
    protected function createAlias($modelName)
    {
        $alias_base = strtolower(preg_replace('/[a-z]/', '', $modelName));
        if ($alias_base == "") {
            $alias_base = $modelName[0];
        }

        $alias = $alias_base;
        $number = 0;
        while ($this->getAlias($alias)) {
            $number++;
            $alias = $alias_base . $number;
        }

        $this->aliases[$alias] = $modelName;

        return $alias;
    }

    /**
     * @return mixed|string|null
     */
    public function getMainAlias()
    {
        return $this->mainAlias;
    }

    /**
     * @param $fromAlias
     * @param $joinType
     * @return array|mixed|null[]
     */
    protected function getJoinedAliases($fromAlias = null, $joinType = null)
    {
        if ($joinType === null) {
            return $this->aliases;
        }

        if ($fromAlias == null) {
            $fromAlias = $this->mainAlias;
        }

        $aliases = array($fromAlias => $this->getAlias($fromAlias));

        if (array_key_exists($fromAlias, $this->joins)) {
            foreach ($this->joins[$fromAlias] as $fromAssoc => $joinInfos) {
                foreach ($joinInfos as $joinInfo) {
                    if ($joinType == null || $joinType == $joinInfo["assoc_type"]) {
                        $newAliases = $this->getJoinedAliases($joinInfo["remoteAlias"], $joinType);
                        $aliases = array_merge($aliases, $newAliases);
                    }
                }
            }
        }

        return $aliases;
    }

    /**
     * @param $alias
     * @param $joinType
     * @return array
     */
    protected function getAliasColumns($alias = null, $joinType = null): array
    {
        $result = array();
        foreach ($this->getJoinedAliases($alias, $joinType) as $alias => $model) {
            $aliasColumns = array();
            $obj = new $model();
            foreach ($obj->getProperties() as $name => $meta) {
                $aliasColumns[$name] = "$alias.$name";
            }
            $result[$alias] = $aliasColumns;
        }
        return $result;
    }

    /**
     * @param $alias
     * @return bool
     */
    public function hasAlias($alias): bool
    {
        return array_key_exists($alias, $this->aliases);
    }

    /**
     * @param $alias
     * @return mixed|null
     */
    public function getAlias($alias)
    {
        if (!$this->hasAlias($alias)) {
            return null;
        }
        return $this->aliases[$alias];
    }

    function buildExpression($expression, &$extra)
    {
        switch ($expression["type"]) {
            case "COLUMN":
                $translation = $extra['query']->translateColumnName($expression["match"], $extra['alias']);
                $extra["columns"][] = $translation;
                return $translation["expression"];
            default:
                if (isset($expression["matches"])) {
                    $str = "";
                    foreach ($expression["matches"] as $match) {
                        $str .= $this->buildExpression($match, $extra);
                    }
                    return $str;
                }
                return $expression["match"];
        }
    }

    /**
     * @param $expression
     * @param $name
     * @param $alias
     * @return void
     * @throws Exception
     */
    public function addCalculatedColumn($expression, $name, $alias = null)
    {
        $tokens = array(
            "COLUMN" => '([a-zA-Z0-9_]+\.)*[a-zA-Z0-9_]+',
            "ADDITION" => '\+',
            "SUBTRACTION" => '\-',
            "MULTIPLICATION" => '\*',
            "DIVISION" => '\/',
            "PAR_START" => '\(',
            "PAR_END" => '\)',
            "FUNC_SUM" => 'SUM',
            "FUNC_COUNT" => 'COUNT',
            "FUNC_CONCAT" => 'CONCAT',
            "FUNC_IF" => 'IF',
            "COMMA" => ",",
            "STR_START" => "\'",
            "STR_END" => "\'",
            "CHAR" => "(.[^'])*.",
            "ENDLINE" => '$',
            "EQUAL" => "=",
            "LT" => "<",
            "GT" => ">",
            "DIFF1" => "<>",
            "DIFF2" => "!=",
            "FUNC_INET_NTOA" => "INET_NTOA",
            "FUNC_INET_ATON" => "INET_ATON",
        );

        $expression_map = array(
            "FULL_EXPRESSION" => array("EXPRESSION,ENDLINE"),
            "EXPRESSION_NO_COMP" => array("PAR_START,EXPRESSION,PAR_END", "CALCULATION", "FUNC,PAR_START,EXPRESSIONS,PAR_END", "STRING", "COLUMN"),
            "EXPRESSION" => array("COMPARISION", "EXPRESSION_NO_COMP"),
            "COMPARISION" => array("EXPRESSION_NO_COMP,COMP_OPERATOR,EXPRESSION_NO_COMP"),
            "EXPRESSIONS" => array("EXPRESSION,COMMA,EXPRESSIONS", "EXPRESSION"),
            "CALCULATION" => array("COLUMN,MATH,EXPRESSION"),
            "MATH" => array("ADDITION", "SUBTRACTION", "MULTIPLICATION", "DIVISION"),
            //"AGGR_FUNC"          => Array("FUNC_SUM", "FUNC_COUNT"), //FIXME support for group by statements
            "FUNC" => array("FUNC_CONCAT", "FUNC_IF", "FUNC_INET_NTOA", "FUNC_INET_ATON"),
            "STRING" => array("STR_START,CHAR,STR_END"),
            "COMP_OPERATOR" => array("EQUAL", "LT", "GT", "DIFF1", "DIFF2")
        );

        $parsed_expression = $this->lex("FULL_EXPRESSION", $expression, $tokens, $expression_map);
        if ($parsed_expression === false) {
            throw new Exception("Could not parse the expression: $expression");
        }

        if ($alias === null) {
            $alias = $this->getMainAlias();
        }
        $extra = array("query" => $this, "alias" => $alias, "columns" => array());
        $result = $this->buildExpression($parsed_expression, $extra);
        $firstColumn = isset($extra["columns"][0]) ? $extra["columns"][0] : null;

        $this->calculatedColumns[$name] = array("expression" => $result, "alias" => $alias, "column" => $firstColumn, "name" => $name);
    }

    function lex($expectation, $string, &$tokens, &$expression_map, $extra = null, $depth = 0, $callMap = array())
    {
        //Circular bailout
        if (array_key_exists($expectation, $callMap) && $callMap[$expectation] == strlen($string)) {
            return false;
        }
        $callMap[$expectation] = strlen($string);

        if (array_key_exists($expectation, $tokens)) {
            //Simple tokens by regex ;)
            $regex = $tokens[$expectation];
            $matches = array();
            preg_match("/^$regex/", $string, $matches);
            if (count($matches) == 0) {
                return false;
            }
            $match = $matches[0];
            return array("type" => $expectation, "match" => $match);
        } else if (array_key_exists($expectation, $expression_map)) {
            //Language constructs
            $sub_map = $expression_map[$expectation];
            foreach ($sub_map as $items) {
                $matches = array();
                $match = "";
                $items = explode(",", $items);
                foreach ($items as $item) {
                    $data = $this->lex($item, substr($string, strlen($match)), $tokens, $expression_map, $extra, $depth + 1, $callMap);
                    if ($data === false) {
                        break;
                    }
                    $matches[] = $data;
                    $match .= $data["match"];
                }
                if (count($matches) != count($items)) {
                    continue;
                }
                return array("matches" => $matches, "match" => $match, "type" => $expectation);
            }
            return false;
        } else {
            throw new Exception("Unknown expected type: $expectation");
        }
    }

    /**
     * @param $column
     * @param $alias
     * @param $allowSelectColumns
     * @return array
     * @throws Exception
     */
    public function translateColumnName($column, $alias = null, $allowSelectColumns = false): array
    {
        //If no alias is given, we need to find the alias
        if ($alias == null) {
            //Find out an alias an return the result with alias set
            if (strpos($column, ".")) {
                list($firstPart, $remainder) = explode(".", $column, 2);

                //See if the first part is an alias
                if ($this->hasAlias($firstPart)) {
                    return $this->translateColumnName($remainder, $firstPart, $allowSelectColumns);
                }
            }

            if (array_key_exists($column, $this->calculatedColumns)) {
                $result = array("name" => $column, "alias" => $this->calculatedColumns[$column]["column"]["alias"], "column" => $this->calculatedColumns[$column]["column"]["column"]);
                if ($allowSelectColumns) {
                    $result["expression"] = $column;
                } else {
                    $result["expression"] = $this->calculatedColumns[$column]["expression"];
                }
                return $result;
            }

            //Assume it should be the alias of the base table
            return $this->translateColumnName($column, $this->mainAlias, $allowSelectColumns);
        }

        while (strpos($column, ".")) {
            list($ref, $column) = explode(".", $column, 2);
            if (array_key_exists($alias, $this->joins) && array_key_exists($ref, $this->joins[$alias])) {
                if (count($this->joins[$alias][$ref]) != 1) {
                    throw new Exception("Association is joined several times. Cant select which to use.");
                }
                $alias = $this->joins[$alias][$ref][0]["remoteAlias"];
            } else {
                $alias = $this->join($ref, $alias);
            }
        }

        $cls = $this->getAlias($alias);
        $obj = new $cls();
        $properties = $obj->getProperties();
        if (!array_key_exists($column, $properties)) {
            if ($allowSelectColumns && (array_key_exists($column, $this->buildAggregateSelect()) || array_key_exists($column, $this->buildNormalSelect()))) {
                //Allow the names of aliases from aggregate columns and grouped values. This can be used for order by, and potentially having clauses
                $alias = null;
            } else {
                throw new Exception("Could not find property $column on alias $alias ($cls)");
            }
        }

        $expression = "$alias.$column";
        if ($alias == null) {
            $expression = $column;
        }

        return array("alias" => $alias, "column" => $column, "expression" => $expression, "name" => $column);
    }

    /**
     * @param $association
     * @param $alias
     * @param $type
     * @param $forceNew
     * @param ActiveRecordQueryFilter|null $extraFilter
     * @param $extraFilterType
     * @return mixed|string
     * @throws Exception
     */
    public function join($association, $alias = null, $type = ActiveRecordQuery::JOIN_INNER, $forceNew = false, ActiveRecordQueryFilter $extraFilter = null, $extraFilterType = "AND")
    {
        if ($alias == null) {
            $alias = $this->mainAlias;
        }

        while (strpos($association, ".")) {
            list($firstJoin, $association) = explode(".", $association, 2);
            $alias = $this->join($firstJoin, $alias, $type, $forceNew);
        }

        $model = $this->getAlias($alias);
        $obj = new $model();
        if (!$obj->hasAssociation($association)) {
            throw new Exception("No association by name '$association' on $alias ($model).");
        }
        $associationInfo = $obj->getAssociationInfo($association);
        if ($associationInfo["ass_type"] == "has_and_belongs_to_many") {
            throw new Exception("Cannot join across Many2Many relations");
        }

        if (!array_key_exists($alias, $this->joins)) {
            $this->joins[$alias] = array();
        }
        if (!array_key_exists($association, $this->joins[$alias])) {
            $this->joins[$alias][$association] = array();
        }

        if (!$forceNew) {
            $match = null;
            foreach ($this->joins[$alias][$association] as $join) {
                if ($join["type"] == $type) {
                    if ($match != null) {
                        throw new Exception("Several potential joins for same condition found!");
                    }
                    $match = $join["remoteAlias"];
                }
            }
            if ($match != null) {
                return $match;
            }
        }

        $model = $associationInfo["real_class"];
        $obj = new $model();
        $tableName = $obj->getTableName();

        $newAlias = $this->createAlias($model);

        $assType = $associationInfo["ass_type"];
        if ($assType == "belongs_to") {
            $asstype = "has_one";
        }

        $this->joins[$alias][$association][] = array(
            "type" => $type,
            "assoc_type" => $assType,
            "remoteTable" => $tableName,
            "remoteAlias" => $newAlias,
            "remoteProperty" => $associationInfo["class_property"],
            "localAlias" => $alias,
            "localProperty" => $associationInfo["local_property"],
            "extraFilter" => $extraFilter,
            "extraFilterType" => $extraFilterType
        );

        return $newAlias;
    }

    /**
     * Will make the query eager load all joins and not just has_ones. Beware as this can potentially give you ALOT of data
     *
     * send false to turn Off again
     */
    public function fullJoin($fullJoin = true)
    {
        $this->fullJoin = $fullJoin;
    }

    /**
     * Set the sorting for the query.
     *
     * This method will overwrite all other sortings, which have previously been
     * set on the query.
     *
     * If $column is set to null all sorting options will be removed. In this
     * case a default sorting may be added if the base table holds a
     * default_order property.
     *
     * The $column property is first evaluated during execution, but may be in
     * the forms:
     * - [columnname] The name of the column on the base table
     * - [associationname].[columnname] The name of the column on the associated
     * table. Cascading associations deepers is also allowed. If the associated
     * table is allready joined during execution that join will be used,
     * otherwise a join will be made simply for the ordering process.
     * - [aliasname].[columnname] The name of a column on an aliased table.
     * - [aliasname].[association].[columnname] The name of a column on an
     * association on an aliased table. Same rules apply here as for
     * [associationname].[columnname];
     *
     * @param string $column
     * @param string $direction
     */
    public function setSort($column = null, $direction = ActiveRecordQuery::SORT_ASC): ActiveRecordQuery
    {
        $this->sorts = array();
        if ($column) {
            $this->sorts[$column] = $direction;
        } else {
            if ($this->obj->default_order) {
                $this->sorts[$this->obj->default_order] = ActiveRecordQuery::SORT_ASC;
            }
        }

        return $this;
    }

    /**
     * Adds a less specific sorting at the end of other sort criteria.
     *
     * In case a column allready known is given the new sorting isn't added, but
     * the direction will be updated to the new direction. this will also be the
     * case of the two sorts [associationname].[columnname] and
     * [aliasname].[columnname] appears to be the same during evaluation.
     *
     * @param string $column
     * @param string $direction
     */
    public function addSort($column = null, $direction = ActiveRecordQuery::SORT_ASC): ActiveRecordQuery
    {
        if ($column) {
            $this->sorts[$column] = $direction;
        }
        return $this;
    }

    /**
     * @return int|string|null
     */
    public function getSort()
    {
        if (count($this->sorts) == 0) {
            return null;
        }
        $sorts = array_keys($this->sorts);
        return $sorts[0];
    }

    /**
     * @param $column
     * @return $this
     */
    public function setGroup($column = null): ActiveRecordQuery
    {
        $this->groups = array();
        if ($column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    /**
     * @param $column
     * @return $this
     */
    public function addGroup($column): ActiveRecordQuery
    {
        if ($column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    /**
     * @return mixed|null
     */
    public function getGroup()
    {
        if (count($this->groups) == 0) {
            return null;
        }
        return $this->groups[0];
    }

    /**
     * @return array|mixed
     */
    public function getGroups()
    {
        return $this->groups;
    }

    /**
     * @param $column
     * @param $aggregate
     * @return $this
     */
    public function setAggregate($column = null, $aggregate = null): ActiveRecordQuery
    {
        $this->aggregates = array();
        if ($column) {
            $this->aggregates[] = array("values" => $column, "aggregate" => $aggregate);
        }

        return $this;
    }

    /**
     * @param $column
     * @param $aggregate
     * @return $this
     */
    public function addAggregate($column, $aggregate): ActiveRecordQuery
    {
        $this->aggregates[] = array("values" => $column, "aggregate" => $aggregate);
        return $this;
    }

    /**
     * @return array|mixed
     */
    public function getAggregates()
    {
        return $this->aggregates;
    }

    /**
     * @return mixed|null
     */
    public function getSortDirection()
    {
        if (count($this->sorts) == 0) {
            return null;
        }
        $sorts = array_values($this->sorts);
        return $sorts[0];
    }

    /**
     * @return array|mixed
     */
    public function getSorts()
    {
        return $this->sorts;
    }

    /**
     * @return array
     * @throws Exception
     */
    protected function translateSorts(): array
    {
        $orderTranslations = array();
        foreach ($this->sorts as $col => $direction) {
            $col = $this->translateColumnName($col, null, true);
            $orderTranslations[$col["expression"]] = $direction;
        }

        $order = array();
        foreach ($orderTranslations as $col => $direction) {
            $order[] = "$col $direction";
        }

        return $order;
    }

    /**
     * @return array
     * @throws Exception
     */
    protected function translateGroups(): array
    {
        $groupTranslations = array();
        foreach ($this->groups as $group) {
            $col = $this->translateColumnName($group);
            $groupTranslations[$group] = $col["expression"];
        }

        return $groupTranslations;
    }

    /**
     * @return array
     * @throws Exception
     */
    protected function translateAggregates(): array
    {
        $aggregateTranslations = array();
        foreach ($this->aggregates as $index => $aggregateInfo) {

            if ($aggregateInfo["values"]) {
                $col = $this->translateColumnName($aggregateInfo["values"]);
                $name = strtolower($aggregateInfo["aggregate"]) . "_" . str_replace(".", "_", $aggregateInfo["values"]);
                switch (strtoupper($aggregateInfo["aggregate"])) {
                    case "MIN_DISTINCT":
                        $aggregateTranslations[$name] = "MIN(DISTINCT {$col["expression"]}) AS $name";
                        break;
                    case "MAX_DISTINCT":
                        $aggregateTranslations[$name] = "MAX(DISTINCT {$col["expression"]}) AS $name";
                        break;
                    case "GROUP_CONCAT_DISTINCT":
                        $aggregateTranslations[$name] = "GROUP_CONCAT(DISTINCT {$col["expression"]}) AS $name";
                        break;
                    case "COUNT_DISTINCT":
                        $aggregateTranslations[$name] = "COUNT(DISTINCT {$col["expression"]}) AS $name";
                        break;
                    default:
                        $aggregateTranslations[$name] = strtoupper($aggregateInfo["aggregate"]) . "({$col["expression"]}) AS $name";
                }
            } else {
                $aggregateTranslations["agg" . $index] = $aggregateInfo["aggregate"];
            }
        }

        return $aggregateTranslations;
    }

    /**
     * @param $limit
     * @return $this
     */
    public function setLimit($limit): ActiveRecordQuery
    {
        $this->limit = intval($limit);
        return $this;
    }

    /**
     * @return int|mixed
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * @param $offset
     * @return $this
     */
    public function setOffset($offset): ActiveRecordQuery
    {
        $this->offset = intval($offset);
        return $this;
    }

    /**
     * @return int|mixed
     */
    public function getOffset()
    {
        return $this->offset;
    }

    /**
     * @param $name
     * @param $args
     * @return $this
     * @throws Exception
     */
    public function __call($name, $args)
    {
        if (method_exists($this, "get" . ucfirst($name))) {
            return $this->addFilter(call_user_func_array(array($this, "get" . ucfirst($name)), $args));
        } else {
            throw new Exception("Unknown method: $name");
        }
    }

    /**
     * @param $col
     * @param $value
     * @param $operator
     * @return ActiveRecordQueryFilterColumnValue
     * @throws Exception
     */
    public function getFilterBy($col, $value, $operator = "="): ActiveRecordQueryFilterColumnValue
    {
        $col = $this->translateColumnName($col);
        return new ActiveRecordQueryFilterColumnValue($this, $col, $value, $operator);
    }

    /**
     * @return ActiveRecordQueryFilterOr
     */
    public function getFilterByOr(): ActiveRecordQueryFilterOr
    {
        $args = func_get_args();
        return new ActiveRecordQueryFilterOr($args);
    }

    /**
     * @return ActiveRecordQueryFilterAnd
     */
    public function getFilterByAnd(): ActiveRecordQueryFilterAnd
    {
        $args = func_get_args();
        return new ActiveRecordQueryFilterAnd($args);
    }

    /**
     * @param $col
     * @param ActiveRecordSubQuery $subquery
     * @param $subqueryCol
     * @param $aggregate
     * @param $operator
     * @return ActiveRecordQueryFilterColumnValueSubquery
     * @throws Exception
     */
    public function getFilterBySubQuery($col, ActiveRecordSubQuery $subquery, $subqueryCol, $aggregate = "MAX", $operator = "="): ActiveRecordQueryFilterColumnValueSubquery
    {
        $col = $this->translateColumnName($col);
        return new ActiveRecordQueryFilterColumnValueSubquery($this, $col, $subquery, $subqueryCol, $aggregate, $operator);
    }

    /**
     * @param $value
     * @param ActiveRecordSubQuery $subquery
     * @param $subqueryCol
     * @param $aggregate
     * @param $operator
     * @return ActiveRecordQueryFilterColumnValueSubqueryMatch
     */
    public function getFilterBySubQueryMatch($value, ActiveRecordSubQuery $subquery, $subqueryCol, $aggregate = "MAX", $operator = "="): ActiveRecordQueryFilterColumnValueSubqueryMatch
    {
        return new ActiveRecordQueryFilterColumnValueSubqueryMatch($this, $value, $subquery, $subqueryCol, $aggregate, $operator);
    }

    /**
     * @param $col
     * @param array $value
     * @param $operator
     * @return ActiveRecordQueryFilterColumnValueIn
     * @throws Exception
     */
    public function getFilterByIn($col, array $value, $operator = "IN"): ActiveRecordQueryFilterColumnValueIn
    {
        $col = $this->translateColumnName($col);
        return new ActiveRecordQueryFilterColumnValueIn($this, $col, $value, $operator);
    }

    /**
     * @param $col
     * @param ActiveRecordSubQuery $subquery
     * @param $subqueryCol
     * @param $operator
     * @return ActiveRecordQueryFilterColumnValueInSubquery
     * @throws Exception
     */
    public function getFilterByInSubQuery($col, ActiveRecordSubQuery $subquery, $subqueryCol, $operator = "IN"): ActiveRecordQueryFilterColumnValueInSubquery
    {
        $col = $this->translateColumnName($col);
        return new ActiveRecordQueryFilterColumnValueInSubquery($this, $col, $subquery, $subqueryCol, $operator);
    }

    /**
     * @param $col1
     * @param $col2
     * @param $operator
     * @return ActiveRecordQueryFilterColumnMatch
     * @throws Exception
     */
    public function getFilterByMatch($col1, $col2, $operator = "="): ActiveRecordQueryFilterColumnMatch
    {
        $col1 = $this->translateColumnName($col1);
        $col2 = $this->translateColumnName($col2);

        return new ActiveRecordQueryFilterColumnMatch($this, $col1, $col2, $operator);
    }

    /**
     * @param ActiveRecordQueryFilter $filter
     * @return $this
     */
    public function addFilter(ActiveRecordQueryFilter $filter): ActiveRecordQuery
    {
        $this->filters[] = $filter;
        return $this;
    }

    /**
     * @param $model
     * @return ActiveRecordSubQuery
     * @throws Exception
     */
    public function addSubQuery($model): ActiveRecordSubQuery
    {
        return new ActiveRecordSubQuery($this, $model);
    }

    /**
     * @return int|mixed
     * @throws Exception
     */
    public function get_count()
    {
        $order = $this->translateSorts(); //This may result in more joined tables and hence a different count
        $group = $this->translateGroups(); //This may result in more joined tables and hence a different count
        $select = array("COUNT(1) AS cnt");

        $result = $this->executeQuery($select, $group);

        if (count($group)) {
            return $result->rowCount();
        } else {
            $count = $result->fetchColumn();
        }

        return $count;
    }

    /**
     * @param $aliasColumns
     * @return array
     * @throws Exception
     */
    protected function buildNormalSelect($aliasColumns = null): array
    {
        if (!$aliasColumns) {
            $aliasColumns = $this->getAliasColumns();
        }

        $select = array();
        foreach ($aliasColumns as $alias => $columns) {
            foreach ($columns as $realName => $alias) {
                $name = str_replace(".", "_", $alias);
                $select[$name] = "$alias AS " . $name;
            }
        }
        foreach ($this->calculatedColumns as $name => $calculatedColumn) {
            if (array_key_exists($name, $select)) {
                throw new Exception("Alias used twice in select: '$name'");
            }
            $select[$name] = $calculatedColumn["expression"] . " AS $name";
        }

        return $select;
    }

    /**
     * @return array
     * @throws Exception
     */
    protected function buildAggregateSelect(): array
    {
        $select = array();

        foreach ($this->translateAggregates() as $name => $selectColumn) {
            $select[$name] = $selectColumn;
        }
        foreach ($this->translateGroups() as $name => $groupColumn) {
            $name = str_replace(".", "_", $name);
            $select[$name] = $groupColumn . " AS " . $name;
        }

        return $select;
    }

    /**
     * @return array
     */
    protected function buildJoins(): array
    {
        $joins = array();

        foreach ($this->joins as $fromAlias => $joinAssocs) {
            foreach ($joinAssocs as $fromAssoc => $joinInfos) {
                foreach ($joinInfos as $joinInfo) {
                    $model = $this->getAlias($joinInfo["remoteAlias"]);
                    $join = "{$joinInfo["type"]} JOIN {$joinInfo["remoteTable"]} AS {$joinInfo["remoteAlias"]} ON {$joinInfo["remoteAlias"]}.{$joinInfo["remoteProperty"]}={$joinInfo["localAlias"]}.{$joinInfo["localProperty"]}";
                    $join .= $joinInfo["extraFilter"] ? " {$joinInfo['extraFilterType']} {$joinInfo['extraFilter']}" : "";

                    $joins[] = $join;
                }
            }
        }

        return $joins;
    }

    /**
     * Only has_one associations are linked by this method, as has_many may be
     * halfed by a limit elsewhere in the query.
     *
     * @param string $indexBy The name of the field or property to index the result by
     * @return array
     */
    public function execute($indexBy = null, $dontHydrate = false): array
    {
        $groups = $this->translateGroups();
        if (count($groups)) {
            $result = $this->executeGrouped($groups);
        } else {
            $result = $this->executeNormal($dontHydrate);
        }

        if ($indexBy) {
            $callback = fn($item) => is_array($item) ? $item[$indexBy] : $item->$indexBy;
            $keys = array_map($callback, $result);

            if (count($keys)) {
                $result = array_combine($keys, $result);
            }
        }

        return $result;
    }

    /**
     * @param $groups
     * @return array
     * @throws Exception
     */
    protected function executeGrouped($groups): array
    {
        $order = $this->translateSorts();
        $select = $this->buildAggregateSelect();
        $handle = $this->executeQuery($select, $groups, $order, $this->limit, $this->offset);

        $result = array();
        while (($row = $handle->fetch(PDO::FETCH_OBJ))) {
            $result[] = $row;
        }
        return $result;
    }

    /**
     * @param $dontHydrate
     * @return array
     * @throws Exception
     */
    protected function executeNormal($dontHydrate): array
    {
        $order = $this->translateSorts();
        if ($this->fullJoin) {
            $aliasColumns = $this->getAliasColumns($this->mainAlias);
        } else {
            $aliasColumns = $this->getAliasColumns($this->mainAlias, "has_one");
        }
        $select = $this->buildNormalSelect($aliasColumns);

        $foundEntities = array();
        foreach ($aliasColumns as $alias => $columns) {
            $foundEntities[$this->getAlias($alias)] = array();
        }

        $calcualtedColumns = array();
        foreach ($this->calculatedColumns as $calcualtedColumn) {
            if (!array_key_exists($calcualtedColumn["alias"], $calcualtedColumns)) {
                $calcualtedColumns[$calcualtedColumn["alias"]] = array();
            }
            $calcualtedColumns[$calcualtedColumn["alias"]][] = $calcualtedColumn["name"];
        }

        $result = array();
        $handle = $this->executeQuery($select, null, $order, $this->limit, $this->offset);
        while (($row = $handle->fetch(PDO::FETCH_ASSOC))) {

            $aliasElements = array();

            foreach ($aliasColumns as $alias => $columns) {
                $model = $this->getAlias($alias);
                $fields = array();
                foreach ($columns as $realName => $aliasColumn) {
                    $fields[$realName] = $row[str_replace(".", "_", $aliasColumn)];
                }
                if (array_key_exists($alias, $calcualtedColumns)) {
                    foreach ($calcualtedColumns[$alias] as $calcualtedColumn) {
                        $fields[$calcualtedColumn] = $row[$calcualtedColumn];
                    }
                }

                $tmp = new $model();

                $priHash = array();
                foreach ($tmp->find_primaries() as $primary) {
                    $priHash[] = $fields[$primary];
                }
                $priHash = serialize($priHash);
                if (array_key_exists($priHash, $foundEntities[$model])) {
                    $tmp = $foundEntities[$model][$priHash];
                } else {
                    if ($dontHydrate) {
                        $tmp = (object)$fields;
                    } else {
                        $tmp->hydrate($fields);
                    }
                    $foundEntities[$model][$priHash] = $tmp;

                    if ($alias == $this->mainAlias) {
                        $result[] = $tmp;
                    }
                }

                //Add the element to aliasElement if it held any values. Ignore missing left joins
                if (count(array_filter($fields)) == 0) {
                    continue;
                }
                $aliasElements[$alias] = $tmp;
            }

            //Take care of the relations between the joined elements
            $this->addJoinData($this->mainAlias, $aliasElements);
        }

        return $result;
    }

    /**
     * @param $alias
     * @param $aliasElements
     * @return bool
     */
    protected function addJoinData($alias, &$aliasElements): bool
    {
        if (!array_key_exists($alias, $this->joins)) {
            return false;
        }

        foreach ($this->joins[$alias] as $association => $infos) {
            $aliases = array();
            //Get inner joins first (better chance for them holding data ;)
            foreach ($infos as $info) {
                if (($this->fullJoin || $info["assoc_type"] == "has_one") && $info["type"] == self::JOIN_INNER) {
                    if ($info["assoc_type"] == "has_one") {
                        $aliases[$association] = $info["remoteAlias"];
                    } else {
                        $aliases[$association][] = $info["remoteAlias"];
                    }
                }
            }
            foreach ($infos as $info) {
                if (($this->fullJoin || $info["assoc_type"] == "has_one") && $info["type"] == self::JOIN_LEFT) {
                    if ($info["assoc_type"] == "has_one") {
                        $aliases[$association] = $info["remoteAlias"];
                    } else {
                        $aliases[$association][] = $info["remoteAlias"];
                    }
                }
            }

            foreach ($aliases as $association => $referred_alias) {
                if (is_array($referred_alias)) {
                    foreach ($referred_alias as $ref_alias) {
                        if (isset($aliasElements[$ref_alias])) {
                            $aliasElements[$alias]->{$association}[] = $aliasElements[$ref_alias];
                            $this->addJoinData($ref_alias, $aliasElements);
                        } else {
                            $aliasElements[$alias]->{$association} = array();
                        }
                    }
                } else {
                    $aliasElements[$alias]->$association = $aliasElements[$referred_alias];
                    $this->addJoinData($referred_alias, $aliasElements);
                }
            }
        }

        return true;
    }

    /**
     * @param $select
     * @param $group
     * @param $order
     * @param $limit
     * @param $offset
     * @return string
     * @throws Exception
     */
    protected function getSql($select, $group = null, $order = null, $limit = null, $offset = null): string
    {
        $alias = $this->mainAlias;
        $table = $this->obj->getTableName();
        $table = "$table AS $alias";

        $joins = $this->buildJoins();

        $options = array(
            "SELECT" => implode(", ", $select),
            "TABLE" => $table,
            "JOINS" => implode(" ", $joins)
        );
        if ($order) {
            $options["ORDERFIELD"] = implode(", ", $order);
        }
        if ($limit) {
            $options["LIMIT"] = $limit;
        }
        if ($offset) {
            $options["OFFSET"] = $offset;
        }
        if (count($this->filters)) {
            $options["WHERE"] = implode(" AND ", $this->filters);
        }
        if (count($group)) {
            if (!array_key_exists("WHERE", $options)) {
                $options["WHERE"] = "1";
            }
            $options["WHERE"] .= " GROUP BY " . implode(",", $group);
        }

        $db = Database::connect($this->obj->getDatabaseName());
        $sql = SQLSyntaxor::getSelectSQL($options, $db->getAttribute(PDO::ATTR_DRIVER_NAME));

        return $sql;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function __toString()
    {
        $groups = $this->translateGroups();
        $order = $this->translateSorts();
        if (count($groups)) {
            $select = $this->buildAggregateSelect();
        } else {
            $aliasColumns = $this->getAliasColumns($this->mainAlias, "has_one");
            $select = $this->buildNormalSelect($aliasColumns);
        }

        return $this->getSql($select, $groups, $order, $this->limit, $this->offset);
    }

    /**
     * @param $field
     * @return string
     * @throws Exception
     */
    public function getSqlForField($field): string
    {
        $groups = $this->translateGroups();
        $order = $this->translateSorts();
        $select = array($field);
        return $this->getSql($select, $groups, $order, $this->limit, $this->offset);
    }

    /**
     * @param $select
     * @param $group
     * @param $order
     * @param $limit
     * @param $offset
     * @return \PDOStatement
     * @throws Exception
     */
    protected function executeQuery($select, $group = null, $order = null, $limit = null, $offset = null): \PDOStatement
    {
        $sql = $this->getSql($select, $group, $order, $limit, $offset);
        $db = Database::connect($this->obj->getDatabaseName());

        $start = microtime(true);
        $result = $db->query($sql);
        $end = microtime(true);

        if (!$result) {
            if (isset($_SERVER['system_environment']) && $_SERVER['system_environment'] == "development" && !headers_sent()) {
                header("AR-QUERY-$start: " . ($end * 1000 - $start * 1000) . " ms - " . $sql, false);
            }
            throw new Exception("Error executing query: $sql - " . print_r($db->errorInfo(), 1));
        }

        if (isset($_SERVER['system_environment']) && $_SERVER['system_environment'] == "development" && !headers_sent()) {
            header("AR-QUERY-$start: " . $result->rowCount() . " rows - " . ($end * 1000 - $start * 1000) . " ms - " . $sql, false);
        }

        return $result;
    }

    /**
     * @param $model
     * @param $fieldMap
     * @return mixed
     * @throws Exception
     */
    public function doInsertFromSelect($model, $fieldMap)
    {
        $group = $this->translateGroups();
        $order = $this->translateSorts();

        $select = array();
        foreach ($fieldMap as $to => $from) {
            if ($from === null) {
                $from = "NULL";
            } else if (strlen($from) == 0 || $from[0] != "'") {
                $from = $this->translateColumnName($from, null, true);
                $from = $from["expression"];
            }
            $select[$to] = $from;
        }

        $model = new $model();

        $selectSql = $this->getSql(array_values($select), $group, $order, $this->limit, $this->offset);
        $sql = "INSERT INTO {$model->table_name} (" . implode(",", array_keys($select)) . ") $selectSql";

        $result = $this->obj->getDatabaseConnection()->query($sql);
        if ($result === false) {
            $result = $this->obj->getDatabaseConnection()->errorInfo();
        }
        return $result;
    }

    /**
     * @param $fieldMap
     * @return mixed
     * @throws Exception
     */
    public function doUpdateFromSelect($fieldMap)
    {
        $group = $this->translateGroups();
        $order = array();

        $updates = array();
        foreach ($fieldMap as $to => $from) {
            if ($from === null) {
                $from = "NULL";
            } else if (strlen($from) == 0 || $from[0] != "'") {
                $from = $this->translateColumnName($from, null, true);
                $from = $from["expression"];
            }
            $to = $this->translateColumnName($to);
            $to = $to["expression"];

            $updates[] = "$to = $from";
        }

        $selectSql = $this->getSql(array(), $group, $order, $this->limit, $this->offset);
        $sql = preg_replace("/^SELECT \\* FROM /", "UPDATE ", $selectSql);
        $sql = preg_replace("/ WHERE /", " SET " . implode(", ", $updates) . " WHERE ", $sql);

        $result = $this->obj->getDatabaseConnection()->query($sql);
        if ($result === false) {
            $result = $this->obj->getDatabaseConnection()->errorInfo();
        }
        return $result;
    }

    /**
     * @param $alias
     * @return mixed
     * @throws Exception
     */
    public function doDestroyFromSelect($alias = null)
    {
        if ($alias == null) {
            $alias = $this->getMainAlias();
        }
        if (!$this->hasAlias($alias)) {
            $alias = $this->join($alias);
        }

        $group = $this->translateGroups();
        $order = array();
        $select = array("*");

        $selectSql = $this->getSql(array_values($select), $group, $order, $this->limit, $this->offset);
        $sql = "DELETE $alias." . preg_replace("/^SELECT /", "", $selectSql);

        $result = $this->obj->getDatabaseConnection()->query($sql);
        return $result;
    }
}
